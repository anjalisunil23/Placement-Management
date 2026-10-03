<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Remote compile/run via Wandbox (public API, no key).
 *
 * @see https://github.com/melpon/wandbox
 */
final class WandboxExecutionClient
{
    private string $baseUrl;
    private int $timeoutSec;
    private string $lastError = '';

    public function __construct(?string $baseUrl = null, int $timeoutSec = 30)
    {
        $url = trim((string) ($baseUrl ?? $_ENV['CODING_WANDBOX_URL'] ?? 'https://wandbox.org'));
        $this->baseUrl = rtrim($url, '/');
        $this->timeoutSec = max(5, min(60, $timeoutSec));
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(string $language, string $source, string $stdin, int $timeLimitMs, float $started): ?array
    {
        $this->lastError = '';
        $mapped = $this->mapLanguage($language);
        if ($mapped === null) {
            $this->lastError = 'Unsupported language for Wandbox.';
            return null;
        }

        if (($mapped['transform'] ?? '') === 'java_wandbox') {
            $source = $this->adaptJavaForWandbox($source);
        }

        $body = [
            'compiler' => $mapped['compiler'],
            'code' => $source,
            'stdin' => $stdin,
            'options' => $mapped['options'],
        ];
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $this->lastError = 'Could not encode Wandbox payload.';
            return null;
        }

        $response = $this->post($this->baseUrl . '/api/compile.json', $payload);
        if ($response === null) {
            return null;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $compilerErr = trim((string) ($response['compiler_error'] ?? ''));
        $compilerOut = trim((string) ($response['compiler_output'] ?? ''));
        $compileMsg = trim($compilerErr . "\n" . $compilerOut);
        $compileFailed = $compileMsg !== '' && preg_match('/\berror:|fatal error:|compilation terminated/i', $compileMsg) === 1;
        if ($compileFailed) {
            return [
                'ok' => false,
                'status' => 'Compilation Error',
                'stdout' => (string) ($response['compiler_output'] ?? ''),
                'stderr' => $compileMsg,
                'timedOut' => false,
                'durationMs' => $durationMs,
                'exit_code' => 1,
            ];
        }

        $signal = trim((string) ($response['signal'] ?? ''));
        if ($signal !== '' && stripos($signal, 'kill') !== false) {
            return [
                'ok' => false,
                'status' => 'Time Limit Exceeded',
                'stdout' => (string) ($response['program_output'] ?? ''),
                'stderr' => 'Time Limit Exceeded',
                'timedOut' => true,
                'durationMs' => $durationMs,
                'exit_code' => 124,
            ];
        }

        $stdout = (string) ($response['program_output'] ?? '');
        $progErr = trim((string) ($response['program_error'] ?? ''));
        $progMsg = trim((string) ($response['program_message'] ?? ''));
        $status = $response['status'] ?? null;
        if ($status === null || $status === '') {
            $exit = $progErr !== '' ? 1 : 0;
        } elseif (is_numeric($status)) {
            $exit = (int) $status;
        } elseif (preg_match('/^\d+$/', trim((string) $status)) === 1) {
            $exit = (int) trim((string) $status);
        } else {
            $exit = 1;
        }
        $stderr = trim(implode("\n", array_filter([$progErr, $progMsg], static fn (string $s): bool => trim($s) !== '')));
        if ($exit !== 0) {
            $err = $stderr !== '' ? $stderr : 'Runtime Error';

            return [
                'ok' => false,
                'status' => 'Runtime Error',
                'stdout' => $stdout,
                'stderr' => $err,
                'timedOut' => false,
                'durationMs' => $durationMs,
                'exit_code' => $exit,
            ];
        }

        return [
            'ok' => true,
            'status' => 'OK',
            'stdout' => $stdout,
            'stderr' => '',
            'timedOut' => false,
            'durationMs' => $durationMs,
            'exit_code' => 0,
        ];
    }

    /**
     * @return array{compiler:string,options:string,transform?:string}|null
     */
    private function mapLanguage(string $language): ?array
    {
        $raw = strtolower(trim($language));
        return match ($raw) {
            'python' => ['compiler' => 'cpython-3.11.10', 'options' => '', 'transform' => ''],
            'javascript', 'js' => ['compiler' => 'nodejs-20.17.0', 'options' => '', 'transform' => ''],
            'c' => ['compiler' => 'gcc-13.2.0-c', 'options' => 'gnu11', 'transform' => ''],
            'c++', 'cpp' => ['compiler' => 'gcc-13.2.0', 'options' => 'c++17', 'transform' => ''],
            'java' => [
                'compiler' => 'openjdk-jdk-21+35',
                'options' => '',
                'transform' => 'java_wandbox',
            ],
            default => null,
        };
    }

    /**
     * Wandbox compiles `code` as prog.java; use package-private class Prog as the entry class.
     */
    private function adaptJavaForWandbox(string $source): string
    {
        if (preg_match('/public\s+class\s+(\w+)/', $source, $m)) {
            $name = $m[1];
            $source = preg_replace(
                '/\bpublic\s+class\s+' . preg_quote($name, '/') . '\b/',
                'class Prog',
                $source,
                1
            );
            if ($name !== 'Prog') {
                $source = preg_replace('/\b' . preg_quote($name, '/') . '\s*\./', 'Prog.', $source);
            }
        }

        return $source;
    }

    private function post(string $url, string $body): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AJCE-Placements-Coding/1.0',
        ];
        $text = null;
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                $this->lastError = 'Could not initialize HTTP client (curl).';
                return null;
            }
            $sslVerify = filter_var($_ENV['CODING_HTTP_SSL_VERIFY'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
            $caPath = trim((string) ($_ENV['CODING_CACERT_PATH'] ?? ''));
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSec,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSec),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ];
            if ($caPath !== '' && is_readable($caPath)) {
                $opts[CURLOPT_CAINFO] = $caPath;
            }
            curl_setopt_array($ch, $opts);
            $text = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($text === false) {
                $this->lastError = 'Wandbox request failed: ' . (curl_error($ch) ?: 'curl error');
                curl_close($ch);
                return null;
            }
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $body,
                    'timeout' => $this->timeoutSec,
                    'ignore_errors' => true,
                ],
            ]);
            $text = @file_get_contents($url, false, $ctx);
            if (!is_string($text) || $text === '') {
                $this->lastError = 'Wandbox request failed (HTTP streams).';
                return null;
            }
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $httpCode = (int) $m[1];
            }
        }

        if ($httpCode !== 0 && ($httpCode < 200 || $httpCode >= 300)) {
            $this->lastError = 'Wandbox HTTP ' . $httpCode . ': ' . trim(substr((string) $text, 0, 200));
            return null;
        }

        $json = json_decode((string) $text, true);
        if (!is_array($json)) {
            $this->lastError = 'Wandbox returned invalid JSON.';
            return null;
        }

        return $json;
    }
}
