<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Utils\CodingExecutionErrorFormatter;

/**
 * Code execution facade for the PHP API layer.
 *
 * Student code is never compiled inside the web/FPM request: jobs run in
 * backend/coding-exec/worker.php (CLI) with CodingSandboxEngine isolation.
 */
final class CodeExecutionService
{
    private string $lastRemoteErrors = '';
    private string $lastWorkerError = '';

    /**
     * @return array<string, mixed>
     */
    public function run(string $language, string $source, string $stdin = '', int $timeLimitMs = 3000): array
    {
        pms_coding_exec_debug_log('pre_run', [
            'language' => $language,
            'source' => $source,
            'stdin' => $stdin,
            'timeLimitMs' => $timeLimitMs,
        ]);
        $raw = $this->runWithoutPresentation($language, $source, $stdin, $timeLimitMs);
        pms_coding_exec_debug_log('post_run_raw', [
            'exit_code' => $raw['exit_code'] ?? null,
            'status' => $raw['status'] ?? null,
            'ok' => $raw['ok'] ?? null,
            'stdout' => (string) ($raw['stdout'] ?? ''),
            'stderr' => (string) ($raw['stderr'] ?? ''),
            'timedOut' => !empty($raw['timedOut']),
            'execEngine' => $raw['execEngine'] ?? null,
        ]);
        $out = CodingExecutionErrorFormatter::enrich($raw, $language, $stdin, $source);
        if (class_exists(\PMS\Utils\CodingExecutionDebug::class, false)
            && \PMS\Utils\CodingExecutionDebug::enabled()) {
            $out['_debug'] = [
                'source' => $source,
                'stdin' => $stdin,
                'raw' => $raw,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function runWithoutPresentation(string $language, string $source, string $stdin, int $timeLimitMs): array
    {
        $started = microtime(true);
        $limits = CodingExecutionConfig::limits($timeLimitMs);
        $source = substr($source, 0, $limits['max_source_bytes']);
        $stdin = substr($stdin, 0, $limits['max_io_bytes']);

        if ($this->remoteOnly()) {
            return $this->runRemoteOnly($language, $source, $stdin, $timeLimitMs, $started);
        }

        $job = [
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
        ];

        $local = null;
        if ($this->useWorkerProcess()) {
            $local = $this->invokeWorker($job);
        }
        if (($local === null || (($local['ok'] ?? false) !== true && $this->workerFailed($local)))
            && $this->useInlineSandbox()) {
            $inline = $this->invokeInlineSandbox($job);
            if ($inline !== null) {
                $local = $inline;
            }
        }

        if ($local !== null && ($local['ok'] ?? false) === true) {
            return $local;
        }

        if ($local !== null && !$this->shouldTryRemote($local) && !$this->preferRemoteAfterLocalFailure($local)) {
            return $local;
        }

        $fail = $local ?? $this->failMsg(
            'Runtime Error',
            trim('Sandbox worker failed.' . ($this->lastWorkerError !== '' ? ' ' . $this->lastWorkerError : '')),
            $started
        );

        return $this->maybeRemote($language, $source, $stdin, $timeLimitMs, $started, $fail);
    }

    /**
     * @param array<string, mixed>|null $local
     */
    private function workerFailed(?array $local): bool
    {
        if ($local === null) {
            return true;
        }
        $stderr = strtolower((string) ($local['stderr'] ?? ''));

        return str_contains($stderr, 'sandbox worker')
            || str_contains($stderr, 'failed to start sandbox process');
    }

    private function useWorkerProcess(): bool
    {
        $mode = strtolower(trim((string) ($_ENV['CODING_EXEC_MODE'] ?? 'worker_then_inline')));

        return $mode === 'worker' || $mode === 'worker_then_inline' || $mode === 'auto';
    }

    private function useInlineSandbox(): bool
    {
        $mode = strtolower(trim((string) ($_ENV['CODING_EXEC_MODE'] ?? 'worker_then_inline')));

        return $mode === 'inline' || $mode === 'worker_then_inline' || $mode === 'auto';
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private function invokeInlineSandbox(array $job): ?array
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        try {
            $engine = new CodingSandboxEngine();

            return $this->normalizeApiShape($engine->execute($job));
        } catch (\Throwable $e) {
            return $this->failMsg('Runtime Error', 'Sandbox execution failed: ' . $e->getMessage(), microtime(true));
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>|null
     */
    private function invokeWorker(array $job): ?array
    {
        $this->lastWorkerError = '';
        if (!function_exists('proc_open')) {
            $this->lastWorkerError = 'proc_open is disabled on this host.';

            return null;
        }

        $worker = dirname(__DIR__) . '/coding-exec/worker.php';
        if (!is_readable($worker)) {
            $this->lastWorkerError = 'Worker script is missing.';

            return null;
        }

        $jobDir = rtrim((string) ($job['sandbox_root'] ?? sys_get_temp_dir()), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jobs';
        if (!is_dir($jobDir) && !@mkdir($jobDir, 0700, true) && !is_dir($jobDir)) {
            $this->lastWorkerError = 'Could not create job directory.';

            return null;
        }

        $id = bin2hex(random_bytes(8));
        $jobFile = $jobDir . DIRECTORY_SEPARATOR . 'job_' . $id . '.json';
        $resultFile = $jobDir . DIRECTORY_SEPARATOR . 'result_' . $id . '.json';
        $job['result_path'] = $resultFile;

        $encoded = json_encode($job, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($jobFile, $encoded) === false) {
            $this->lastWorkerError = 'Could not write job file.';

            return null;
        }
        @chmod($jobFile, 0600);

        $php = $this->resolvePhpCliBinary();
        if ($php === null) {
            @unlink($jobFile);
            $this->lastWorkerError = 'PHP CLI not found (set CODING_PHP_CLI_PATH).';

            return null;
        }

        $cmd = [$php, $worker, '--job=' . $jobFile];
        $wall = max(5, (int) (CodingExecutionConfig::limits($job['time_limit_ms'] ?? 3000)['wall_clock_sec']));

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, dirname($worker), null);
        if (!is_resource($proc)) {
            @unlink($jobFile);
            $this->lastWorkerError = 'Could not start worker process.';

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
            $err = trim($stderr);
            $this->lastWorkerError = $err !== '' ? $err : 'Worker returned no output.';

            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $this->lastWorkerError = 'Worker returned invalid JSON.';

            return null;
        }

        return $this->normalizeApiShape($json);
    }

    private function resolvePhpCliBinary(): ?string
    {
        $candidates = [];
        $fromEnv = trim((string) ($_ENV['CODING_PHP_CLI_PATH'] ?? ''));
        if ($fromEnv !== '') {
            $candidates[] = $fromEnv;
        }
        if (defined('PHP_BINARY') && PHP_BINARY !== '') {
            $bin = PHP_BINARY;
            if (!str_contains(strtolower($bin), 'php-fpm') && !str_contains(strtolower($bin), 'fpm')) {
                $candidates[] = $bin;
            }
        }
        foreach ([
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/cpanel/ea-php81/root/usr/bin/php',
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
        ] as $path) {
            $candidates[] = $path;
        }
        $candidates[] = 'php';

        foreach ($candidates as $bin) {
            if ($bin === 'php') {
                return 'php';
            }
            if (is_file($bin) && is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizeApiShape(array $result): array
    {
        $status = (string) ($result['status'] ?? 'Runtime Error');
        $defaultExit = $status === 'OK' ? 0 : 1;
        $out = [
            'ok' => CodingTestCaseChecker::coerceOkFlag($result['ok'] ?? false),
            'status' => $status,
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
            'timedOut' => !empty($result['timedOut']),
            'durationMs' => (int) ($result['durationMs'] ?? 0),
            'exit_code' => array_key_exists('exit_code', $result)
                ? (int) $result['exit_code']
                : $defaultExit,
            'memory_used_kb' => (int) ($result['memory_used_kb'] ?? 0),
            'execution_time' => round(((int) ($result['durationMs'] ?? 0)) / 1000, 3),
        ];
        $out['ok'] = CodingTestCaseChecker::executionSucceeded($out);
        if ($out['ok'] && $out['status'] !== 'OK') {
            $out['status'] = 'OK';
        }
        if (!empty($result['execEngine'])) {
            $out['execEngine'] = (string) $result['execEngine'];
        }
        foreach (['errorSummary', 'errorDetail', 'stderrTrace'] as $key) {
            if (isset($result[$key]) && (string) $result[$key] !== '') {
                $out[$key] = (string) $result[$key];
            }
        }

        return $out;
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
            || str_contains($stderr, 'sandbox worker')
            || str_contains($stderr, 'execution host')
            || str_contains($stderr, 'proc_open');
    }

    /**
     * Shared hosting often lacks a working local toolchain; still try Wandbox when local sandbox fails.
     *
     * @param array<string, mixed> $local
     */
    private function preferRemoteAfterLocalFailure(array $local): bool
    {
        if (($local['ok'] ?? false) === true || !$this->remoteFallbackEnabled()) {
            return false;
        }
        if ($this->remoteOnly()) {
            return true;
        }
        $appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')));
        if ($appEnv !== 'production') {
            return false;
        }
        $mode = strtolower(trim((string) ($_ENV['CODING_EXECUTOR'] ?? getenv('CODING_EXECUTOR') ?: '')));
        if ($mode !== '' && !in_array($mode, ['local_then_remote', 'auto', 'local'], true)) {
            return false;
        }

        return true;
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
            $remote['execEngine'] = $this->inferExecEngine($remote);

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
        $backends = CodingExecutionConfig::limits($timeLimitMs)['remote_backends'];
        if ($this->wandboxEnabled() && !in_array('wandbox', $backends, true)) {
            array_unshift($backends, 'wandbox');
        }
        foreach ($backends as $backend) {
            if ($backend === 'wandbox') {
                if (!$this->wandboxEnabled()) {
                    continue;
                }
                $client = new WandboxExecutionClient();
                $result = $client->run($language, $source, $stdin, $timeLimitMs, $started);
                if ($result !== null) {
                    $result['execEngine'] = 'wandbox';

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

    private function wandboxEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['CODING_WANDBOX_ENABLED'] ?? 'true')));
        return $flag !== 'false' && $flag !== '0' && $flag !== 'off';
    }

    private function executorMode(): string
    {
        $mode = strtolower(trim((string) ($_ENV['CODING_EXECUTOR'] ?? getenv('CODING_EXECUTOR') ?: '')));
        if ($mode !== '') {
            return $mode;
        }
        $appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));

        return $appEnv === 'production' ? 'remote_only' : 'local_then_remote';
    }

    private function remoteOnly(): bool
    {
        $mode = $this->executorMode();
        if (in_array($mode, ['remote_only', 'wandbox_only', 'remote', 'wandbox'], true)) {
            return true;
        }
        if (in_array($mode, ['local', 'local_only'], true)) {
            return false;
        }
        $backends = CodingExecutionConfig::limits()['remote_backends'];

        return $backends === ['wandbox'] && strtolower(trim((string) ($_ENV['CODING_SKIP_LOCAL'] ?? ''))) === 'true';
    }

    /**
     * @return array<string, mixed>
     */
    private function runRemoteOnly(
        string $language,
        string $source,
        string $stdin,
        int $timeLimitMs,
        float $started
    ): array {
        if (!$this->remoteFallbackEnabled() || !$this->wandboxEnabled()) {
            return $this->failMsg(
                'Runtime Error',
                'Remote execution is disabled. Set CODING_EXECUTOR=remote_only and CODING_REMOTE_BACKENDS=wandbox.',
                $started
            );
        }
        $remote = $this->runRemoteChain($language, $source, $stdin, $timeLimitMs, $started);
        if ($remote !== null) {
            $remote['execEngine'] = $this->inferExecEngine($remote);

            return $this->normalizeApiShape($remote);
        }
        $detail = trim($this->lastRemoteErrors);

        return $this->failMsg(
            'Runtime Error',
            $detail !== '' ? $detail : 'Wandbox execution failed. Check outbound HTTPS to wandbox.org.',
            $started
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function inferExecEngine(array $result): string
    {
        return (string) ($result['execEngine'] ?? 'wandbox');
    }

    private function remoteFallbackEnabled(): bool
    {
        if ($this->remoteOnly()) {
            return true;
        }
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
