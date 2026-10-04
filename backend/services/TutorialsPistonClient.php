<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Isolated Piston-compatible runner for Tutorials practice.
 * Talks only to TUTORIAL_PISTON_URL over HTTP. Does not execute code in PHP.
 */
class TutorialsPistonClient
{
    private string $baseUrl;
    private int $timeoutSec;
    private string $lastError = '';

    public function __construct(?string $baseUrl = null, ?int $timeoutSec = null)
    {
        $url = trim((string) ($baseUrl ?? ($_ENV['TUTORIAL_PISTON_URL'] ?? '')));
        $this->baseUrl = rtrim($url, '/');
        $configuredTimeout = $timeoutSec ?? (int) ($_ENV['TUTORIAL_PISTON_TIMEOUT'] ?? 25);
        $this->timeoutSec = max(5, min(60, $configuredTimeout));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function configurationMessage(): string
    {
        return 'Tutorial code execution is not configured. Set TUTORIAL_PISTON_URL to an isolated Piston runner (POST /execute) that is not this PHP application. Run and Submit stay unavailable until that runner answers.';
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @return array{ok: bool, status: string, stdout: string, stderr: string, timedOut: bool, durationMs: int}
     */
    public function execute(string $language, string $source, string $stdin, int $timeLimitMs): array
    {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            throw new \RuntimeException($this->configurationMessage());
        }
        $mapped = $this->mapLanguage($language, $source);
        if ($mapped === null) {
            throw new \InvalidArgumentException('This exercise language cannot be executed.');
        }
        $started = microtime(true);
        $payload = json_encode([
            'language' => $mapped['language'],
            'version' => '*',
            'files' => [
                ['name' => $mapped['filename'], 'content' => $source],
            ],
            'stdin' => $stdin,
            'run_timeout' => max(200, min(15000, $timeLimitMs)),
            'compile_timeout' => 10000,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('The execution request could not be encoded.');
        }
        $response = $this->post($this->baseUrl . '/api/v2/execute', $payload);
        if ($response === null) {
            $response = $this->post($this->baseUrl . '/execute', $payload);
        }
        if ($response === null) {
            throw new \RuntimeException($this->lastError !== '' ? $this->lastError : 'The isolated runner did not respond.');
        }

        return $this->interpret($response, (int) round((microtime(true) - $started) * 1000));
    }

    /**
     * @param array<string, mixed> $response
     * @return array{ok: bool, status: string, stdout: string, stderr: string, timedOut: bool, durationMs: int}
     */
    protected function interpret(array $response, int $durationMs): array
    {
        $compile = is_array($response['compile'] ?? null) ? $response['compile'] : null;
        $run = is_array($response['run'] ?? null) ? $response['run'] : null;
        if ($compile !== null && (int) ($compile['code'] ?? 0) !== 0) {
            $stderr = trim((string) ($compile['stderr'] ?? $compile['output'] ?? 'Compilation failed.'));

            return [
                'ok' => false,
                'status' => 'Compilation Error',
                'stdout' => $this->limitText((string) ($compile['stdout'] ?? '')),
                'stderr' => $this->limitText($stderr !== '' ? $stderr : 'Compilation failed.'),
                'timedOut' => false,
                'durationMs' => $durationMs,
            ];
        }
        if ($run === null) {
            throw new \RuntimeException('The isolated runner returned an empty result.');
        }
        $stdout = $this->limitText((string) ($run['stdout'] ?? ''));
        $stderr = $this->limitText(trim((string) ($run['stderr'] ?? '')));
        $code = (int) ($run['code'] ?? 0);
        $signal = strtoupper(trim((string) ($run['signal'] ?? '')));
        $timedOut = $signal === 'SIGKILL' || str_contains(strtolower($stderr), 'time limit') || str_contains(strtolower((string) ($run['message'] ?? '')), 'time limit');
        if ($timedOut) {
            return [
                'ok' => false,
                'status' => 'Time Limit Exceeded',
                'stdout' => $stdout,
                'stderr' => 'Execution exceeded the time limit.',
                'timedOut' => true,
                'durationMs' => $durationMs,
            ];
        }
        if ($code !== 0) {
            $err = $stderr !== '' ? $stderr : trim((string) ($run['output'] ?? 'Runtime error'));

            return [
                'ok' => false,
                'status' => 'Runtime Error',
                'stdout' => $stdout,
                'stderr' => $this->limitText($err !== '' ? $err : 'Runtime error'),
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
     * @return array{language: string, filename: string}|null
     */
    private function mapLanguage(string $language, string $source): ?array
    {
        return match (strtolower(trim($language))) {
            'python' => ['language' => 'python', 'filename' => 'main.py'],
            'javascript' => ['language' => 'javascript', 'filename' => 'main.js'],
            'c' => ['language' => 'c', 'filename' => 'main.c'],
            'cpp' => ['language' => 'c++', 'filename' => 'main.cpp'],
            'java' => ['language' => 'java', 'filename' => $this->javaFilename($source)],
            default => null,
        };
    }

    private function javaFilename(string $source): string
    {
        $class = 'Main';
        if (preg_match('/public\s+class\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $match) === 1) {
            $class = $match[1];
        }

        return $class . '.java';
    }

    private function limitText(string $text): string
    {
        if (strlen($text) <= 16384) {
            return $text;
        }

        return substr($text, 0, 16384) . "\n[output truncated]";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function post(string $url, string $body): ?array
    {
        if (!function_exists('curl_init')) {
            $this->lastError = 'The server HTTP client is unavailable, so code cannot be sent to the isolated runner.';

            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastError = 'Could not contact the isolated runner.';

            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSec),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $text = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($text === false) {
            $this->lastError = 'The isolated runner could not be reached.';
            curl_close($ch);

            return null;
        }
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = 'The isolated runner rejected the request.';

            return null;
        }
        $decoded = json_decode((string) $text, true);
        if (!is_array($decoded)) {
            $this->lastError = 'The isolated runner returned an unreadable result.';

            return null;
        }

        return $decoded;
    }
}
