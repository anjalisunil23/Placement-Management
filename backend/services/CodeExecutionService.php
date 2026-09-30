<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Code execution facade for the PHP API layer.
 *
 * Student code is never compiled inside the web/FPM request: jobs run in
 * backend/coding-exec/worker.php (CLI) with CodingSandboxEngine isolation.
 */
final class CodeExecutionService
{
    private string $lastRemoteErrors = '';

    /**
     * @return array<string, mixed>
     */
    public function run(string $language, string $source, string $stdin = '', int $timeLimitMs = 3000): array
    {
        $started = microtime(true);
        $limits = CodingExecutionConfig::limits($timeLimitMs);
        $source = substr($source, 0, $limits['max_source_bytes']);
        $stdin = substr($stdin, 0, $limits['max_io_bytes']);

        $local = $this->invokeWorker([
            'language' => $language,
            'source_code' => $source,
            'input' => $stdin,
            'time_limit_ms' => $timeLimitMs,
            'time_limit_sec' => $limits['time_limit_sec'],
            'memory_limit_mb' => $limits['memory_limit_mb'],
            'process_limit' => $limits['process_limit'],
            'file_size_kb' => $limits['file_size_kb'],
            'max_source_bytes' => $limits['max_source_bytes'],
            'max_io_bytes' => $limits['max_io_bytes'],
            'run_user' => $limits['run_user'],
            'sandbox_root' => $limits['sandbox_root'],
        ]);

        if ($local !== null && ($local['ok'] ?? false) === true) {
            return $local;
        }

        if ($local !== null && !$this->shouldTryRemote($local)) {
            return $local;
        }

        return $this->maybeRemote($language, $source, $stdin, $timeLimitMs, $started, $local ?? $this->failMsg('Runtime Error', 'Sandbox worker failed.', $started));
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private function invokeWorker(array $job): ?array
    {
        $worker = dirname(__DIR__) . '/coding-exec/worker.php';
        if (!is_readable($worker)) {
            return null;
        }

        $jobDir = rtrim((string) ($job['sandbox_root'] ?? sys_get_temp_dir()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jobs';
        if (!is_dir($jobDir) && !@mkdir($jobDir, 0700, true) && !is_dir($jobDir)) {
            return null;
        }

        $id = bin2hex(random_bytes(8));
        $jobFile = $jobDir . DIRECTORY_SEPARATOR . 'job_' . $id . '.json';
        $resultFile = $jobDir . DIRECTORY_SEPARATOR . 'result_' . $id . '.json';
        $job['result_path'] = $resultFile;

        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($jobFile, $encoded) === false) {
            return null;
        }
        @chmod($jobFile, 0600);

        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = [$php, $worker, '--job=' . $jobFile];
        $wall = max(5, (int) (CodingExecutionConfig::limits($job['time_limit_ms'] ?? 3000)['wall_clock_sec']));

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($worker), null);
        if (!is_resource($proc)) {
            @unlink($jobFile);
            return null;
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) - $start > $wall) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        @unlink($jobFile);
        $raw = is_readable($resultFile) ? file_get_contents($resultFile) : $stdout;
        @unlink($resultFile);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $json = json_decode($raw, true);

        return is_array($json) ? $this->normalizeApiShape($json) : null;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeApiShape(array $result): array
    {
        return [
            'ok' => ($result['ok'] ?? false) === true,
            'status' => (string) ($result['status'] ?? 'Runtime Error'),
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'timedOut' => !empty($result['timedOut']),
            'durationMs' => (int) ($result['durationMs'] ?? 0),
            'exit_code' => (int) ($result['exit_code'] ?? 0),
            'memory_used_kb' => (int) ($result['memory_used_kb'] ?? 0),
            'execution_time' => round(((int) ($result['durationMs'] ?? 0)) / 1000, 3),
        ];
    }

    /**
     * @param array<string, mixed> $local
     */
    private function shouldTryRemote(array $local): bool
    {
        if (!$this->remoteFallbackEnabled()) {
            return false;
        }
        $stderr = strtolower((string) ($local['stderr'] ?? ''));

        return str_contains($stderr, 'not installed')
            || str_contains($stderr, 'not available')
            || str_contains($stderr, 'failed to start')
            || str_contains($stderr, 'sandbox worker');
    }

    /**
     * @param array<string, mixed> $local
     * @return array<string, mixed>
     */
    private function maybeRemote(
        string $language,
        string $source,
        string $stdin,
        int $timeLimitMs,
        float $started,
        array $local
    ): array {
        if (($local['ok'] ?? false) === true) {
            return $local;
        }
        if (!$this->remoteFallbackEnabled()) {
            return $local;
        }
        $remote = $this->runRemoteChain($language, $source, $stdin, $timeLimitMs, $started);
        if ($remote !== null) {
            return $this->normalizeApiShape($remote);
        }

        $detail = trim($this->lastRemoteErrors);
        $hint = $detail !== ''
            ? ' Remote runners: ' . $detail
            : ' Install compilers on the execution host or set CODING_REMOTE_BACKENDS=wandbox for optional API fallback.';

        return $this->failMsg(
            'Runtime Error',
            trim(rtrim((string) ($local['stderr'] ?? 'Execution failed.')) . $hint),
            $started
        );
    }

    /** @return array<string, mixed>|null */
    private function runRemoteChain(
        string $language,
        string $source,
        string $stdin,
        int $timeLimitMs,
        float $started
    ): ?array {
        $this->lastRemoteErrors = '';
        $errors = [];
        foreach (CodingExecutionConfig::limits($timeLimitMs)['remote_backends'] as $backend) {
            if ($backend === 'wandbox') {
                $client = new WandboxExecutionClient();
                $result = $client->run($language, $source, $stdin, $timeLimitMs, $started);
                if ($result !== null) {
                    return $result;
                }
                $err = trim($client->lastError());
                if ($err !== '') {
                    $errors[] = 'Wandbox: ' . $err;
                }
                continue;
            }
            if ($backend === 'piston') {
                $pistonUrl = trim((string) ($_ENV['CODING_PISTON_URL'] ?? ''));
                if ($pistonUrl === '' || str_contains(strtolower($pistonUrl), 'emkc.org')) {
                    $errors[] = 'Piston: skipped (use self-hosted CODING_PISTON_URL).';
                    continue;
                }
                $client = new PistonExecutionClient($pistonUrl);
                $result = $client->run($language, $source, $stdin, $timeLimitMs, $started);
                if ($result !== null) {
                    return $result;
                }
                $err = trim($client->lastError());
                if ($err !== '') {
                    $errors[] = 'Piston: ' . $err;
                }
            }
        }
        $this->lastRemoteErrors = implode(' | ', $errors);

        return null;
    }

    private function remoteFallbackEnabled(): bool
    {
        $mode = strtolower(trim((string) ($_ENV['CODING_EXECUTOR'] ?? 'local_then_remote')));
        if ($mode === 'local' || $mode === 'local_only') {
            return false;
        }
        $backends = CodingExecutionConfig::limits()['remote_backends'];
        if ($backends === []) {
            return false;
        }
        $flag = trim((string) ($_ENV['CODING_PISTON_FALLBACK'] ?? 'true'));
        if ($flag === '') {
            return true;
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    private function failMsg(string $status, string $message, float $started): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'stdout' => '',
            'stderr' => $message,
            'timedOut' => false,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'exit_code' => 1,
            'memory_used_kb' => 0,
            'execution_time' => round((microtime(true) - $started), 3),
        ];
    }
}
