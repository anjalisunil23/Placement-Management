<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Remote compile/run via Piston API (used when g++/gcc/jdk are not on shared hosting).
 *
 * @see https://github.com/engineer-man/piston
 */
final class PistonExecutionClient
{
    private string $baseUrl;
    private int $timeoutSec;
    private string $lastError = '';

    public function __construct(?string $baseUrl = null, int $timeoutSec = 25)
    {
        $url = trim((string) ($baseUrl ?? $_ENV['CODING_PISTON_URL'] ?? 'https://emkc.org/api/v2/piston'));
        $this->baseUrl = rtrim($url, '/');
        $this->timeoutSec = max(5, min(60, $timeoutSec));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, mixed>|null null when request failed entirely
     */
    public function run(string $language, string $source, string $stdin, int $timeLimitMs, float $started): ?array
    {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->lastError = 'Piston URL is not configured.';
            return null;
        }
        $pistonLang = $this->mapLanguage($language, $source);
        if ($pistonLang === null) {
            $this->lastError = 'Unsupported language for remote runner.';
            return null;
        }

        $payload = json_encode([
            'language' => $pistonLang['language'],
            'version' => $pistonLang['version'],
            'files' => [
                ['name' => $pistonLang['filename'], 'content' => $source],
            ],
            'stdin' => $stdin,
            'run_timeout' => max(1000, min(15000, $timeLimitMs)),
            'compile_timeout' => 10000,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $this->lastError = 'Could not encode execution payload.';
            return null;
        }

        $response = $this->post($this->baseUrl . '/execute', $payload);
        if ($response === null) {
            return null;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $compile = is_array($response['compile'] ?? null) ? $response['compile'] : null;
        $run = is_array($response['run'] ?? null) ? $response['run'] : null;
        if ($compile !== null && (int) ($compile['code'] ?? 0) !== 0) {
            $stderr = trim((string) ($compile['stderr'] ?? $compile['output'] ?? 'Compilation failed.'));
            return [
                'ok' => false,
                'status' => 'Compilation Error',
                'stdout' => (string) ($compile['stdout'] ?? ''),
                'stderr' => $stderr !== '' ? $stderr : 'Compilation failed.',
                'timedOut' => false,
                'durationMs' => $durationMs,
            ];
        }
        if ($run === null) {
            $this->lastError = 'Remote runner returned an empty run result.';
            return null;
        }
        $stdout = (string) ($run['stdout'] ?? '');
        $stderr = trim((string) ($run['stderr'] ?? ''));
        $code = (int) ($run['code'] ?? 0);
        $signal = $run['signal'] ?? null;
        if ($signal === 'SIGKILL' || str_contains(strtolower((string) $stderr), 'time limit')) {
            return [
                'ok' => false,
                'status' => 'Time Limit Exceeded',
                'stdout' => $stdout,
                'stderr' => 'Time Limit Exceeded',
                'timedOut' => true,
                'durationMs' => $durationMs,
            ];
        }
        if ($code !== 0) {
            $err = $stderr !== '' ? $stderr : trim((string) ($run['output'] ?? 'Runtime Error'));
            return [
                'ok' => false,
                'status' => 'Runtime Error',
                'stdout' => $stdout,
                'stderr' => $err !== '' ? $err : 'Runtime Error',
                'timedOut' => false,
                'durationMs' => $durationMs,
            ];
        }

        return [
            'ok' => true,
            'status' => 'OK',
            'stdout' => $stdout,
            'stderr' => '',
            'timedOut' => false,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Piston language ids from GET /runtimes (emkc.org).
     *
     * @return array{language:string,version:string,filename:string}|null
     */
    private function mapLanguage(string $language, string $source): ?array
    {
        $raw = strtolower(trim($language));
        return match ($raw) {
            'python' => ['language' => 'python', 'version' => '3.10.0', 'filename' => 'main.py'],
            'javascript', 'js' => ['language' => 'javascript', 'version' => '18.15.0', 'filename' => 'main.js'],
            'c' => ['language' => 'c', 'version' => '10.2.0', 'filename' => 'main.c'],
            'c++', 'cpp' => ['language' => 'c++', 'version' => '10.2.0', 'filename' => 'main.cpp'],
            'java' => $this->mapJava($source),
            default => null,
        };
    }

    /**
     * @return array{language:string,version:string,filename:string}
     */
    private function mapJava(string $source): array
    {
        $class = 'Main';
        if (preg_match('/public\s+class\s+(\w+)/', $source, $m)) {
            $class = $m[1];
        }

        return ['language' => 'java', 'version' => '15.0.2', 'filename' => $class . '.java'];
    }

    private function post(string $url, string $body): ?array
    {
        $text = null;
        $httpCode = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                $this->lastError = 'Could not initialize HTTP client (curl).';
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSec,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSec),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $text = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($text === false) {
                $this->lastError = 'Remote runner request failed: ' . (curl_error($ch) ?: 'curl error');
                curl_close($ch);
                return null;
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $body,
                    'timeout' => $this->timeoutSec,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);
            $text = @file_get_contents($url, false, $ctx);
            if (!is_string($text) || $text === '') {
                $this->lastError = 'Remote runner request failed (HTTP streams). Check allow_url_fopen and outbound HTTPS.';
                return null;
            }
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $httpCode = (int) $m[1];
            }
        }

        if (!is_string($text) || $text === '') {
            $this->lastError = 'Remote runner returned an empty response.';
            return null;
        }
        if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
            $snippet = trim(substr($text, 0, 240));
            $this->lastError = 'Remote runner HTTP ' . $httpCode . ($snippet !== '' ? ': ' . $snippet : '.');
            return null;
        }

        $json = json_decode($text, true);
        if (!is_array($json)) {
            $this->lastError = 'Remote runner returned invalid JSON.';
            return null;
        }
        if (isset($json['message']) && !isset($json['run'])) {
            $this->lastError = (string) $json['message'];
            return null;
        }

        return $json;
    }
}
