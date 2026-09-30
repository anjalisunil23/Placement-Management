<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Isolated compile/run inside per-job directories (used only by coding-exec worker).
 */
final class CodingSandboxEngine
{
    /** @var array<string, string> */
    private const BIN_ENV_KEYS = [
        'g++' => 'CODING_GXX_PATH',
        'c++' => 'CODING_GXX_PATH',
        'gcc' => 'CODING_GCC_PATH',
        'cc' => 'CODING_GCC_PATH',
        'python3' => 'CODING_PYTHON_PATH',
        'python' => 'CODING_PYTHON_PATH',
        'node' => 'CODING_NODE_PATH',
        'javac' => 'CODING_JAVAC_PATH',
        'java' => 'CODING_JAVA_PATH',
    ];

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function execute(array $job): array
    {
        $started = microtime(true);
        $limits = CodingExecutionConfig::limits(
            isset($job['time_limit_ms']) ? (int) $job['time_limit_ms'] : ((int) ($job['time_limit_sec'] ?? 2) * 1000)
        );
        if (!empty($job['run_user'])) {
            $limits['run_user'] = (string) $job['run_user'];
        }
        if (!empty($job['sandbox_root'])) {
            $limits['sandbox_root'] = (string) $job['sandbox_root'];
        }
        $maxSource = (int) ($job['max_source_bytes'] ?? $limits['max_source_bytes']);
        $maxIo = (int) ($job['max_io_bytes'] ?? $limits['max_io_bytes']);
        $source = substr((string) ($job['source_code'] ?? $job['source'] ?? ''), 0, $maxSource);
        $stdin = substr((string) ($job['input'] ?? $job['stdin'] ?? ''), 0, $maxIo);
        $language = (string) ($job['language'] ?? 'python');
        $limitSec = (int) ($job['time_limit_sec'] ?? $limits['time_limit_sec']);

        $layout = $this->createSandboxLayout($limits['sandbox_root']);
        if ($layout === null) {
            return $this->fail('Runtime Error', 'Could not create sandbox.', $started);
        }

        try {
            $work = $layout['source'];
            $lang = $this->normalizeLanguage($language);

            return match ($lang) {
                'python' => $this->runPython($work, $layout, $source, $stdin, $limitSec, $limits, $started),
                'javascript' => $this->runNode($work, $layout, $source, $stdin, $limitSec, $limits, $started),
                'c' => $this->runNative($work, $layout, $source, $stdin, $limitSec, $limits, $started, 'c'),
                'cpp' => $this->runNative($work, $layout, $source, $stdin, $limitSec, $limits, $started, 'cpp'),
                'java' => $this->runJava($work, $layout, $source, $stdin, $limitSec, $limits, $started),
                default => $this->fail('Runtime Error', 'Unsupported language.', $started),
            };
        } finally {
            $this->removeDir($layout['root']);
        }
    }

    /**
     * @return array{root:string,source:string,input:string,output:string,temp:string}|null
     */
    private function createSandboxLayout(string $baseRoot): ?array
    {
        if (!is_dir($baseRoot) && !@mkdir($baseRoot, 0700, true) && !is_dir($baseRoot)) {
            return null;
        }
        $root = $baseRoot . DIRECTORY_SEPARATOR . 'job_' . bin2hex(random_bytes(8));
        $source = $root . DIRECTORY_SEPARATOR . 'source';
        $input = $root . DIRECTORY_SEPARATOR . 'input';
        $output = $root . DIRECTORY_SEPARATOR . 'output';
        $temp = $root . DIRECTORY_SEPARATOR . 'temp';
        foreach ([$root, $source, $input, $output, $temp] as $dir) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $this->removeDir($root);
                return null;
            }
        }

        return compact('root', 'source', 'input', 'output', 'temp');
    }

    private function normalizeLanguage(string $language): string
    {
        $raw = strtolower(trim($language));
        return match ($raw) {
            'python' => 'python',
            'javascript', 'js' => 'javascript',
            'c' => 'c',
            'c++', 'cpp' => 'cpp',
            'java' => 'java',
            default => $raw,
        };
    }

    /**
     * @param array{root:string,source:string,input:string,output:string,temp:string} $layout
     * @param array<string, mixed> $limits
     * @return array<string, mixed>
     */
    private function runPython(
        string $work,
        array $layout,
        string $source,
        string $stdin,
        int $limitSec,
        array $limits,
        float $started
    ): array {
        file_put_contents($work . '/main.py', $source);
        $bin = $this->resolveBin(['python3', 'python']);
        if ($bin === null) {
            return $this->fail('Runtime Error', 'Python is not available on the execution host.', $started);
        }

        return $this->wrapRun(
            $this->runCommand([$bin, 'main.py'], $work, $layout, $stdin, $limitSec, $limits),
            $started
        );
    }

    /**
     * @param array{root:string,source:string,input:string,output:string,temp:string} $layout
     * @param array<string, mixed> $limits
     */
    private function runNode(
        string $work,
        array $layout,
        string $source,
        string $stdin,
        int $limitSec,
        array $limits,
        float $started
    ): array {
        file_put_contents($work . '/main.js', $source);
        $bin = $this->resolveBin(['node']);
        if ($bin === null) {
            return $this->fail('Runtime Error', 'Node.js is not available on the execution host.', $started);
        }

        return $this->wrapRun(
            $this->runCommand([$bin, 'main.js'], $work, $layout, $stdin, $limitSec, $limits),
            $started
        );
    }

    /**
     * @param array{root:string,source:string,input:string,output:string,temp:string} $layout
     * @param array<string, mixed> $limits
     */
    private function runNative(
        string $work,
        array $layout,
        string $source,
        string $stdin,
        int $limitSec,
        array $limits,
        float $started,
        string $kind
    ): array {
        $isCpp = $kind === 'cpp';
        $srcFile = $isCpp ? 'main.cpp' : 'main.c';
        file_put_contents($work . '/' . $srcFile, $source);
        $compiler = $this->resolveBin($isCpp ? ['g++', 'c++'] : ['gcc', 'cc']);
        if ($compiler === null) {
            $label = $isCpp ? 'C++ compiler (g++)' : 'C compiler (gcc)';

            return $this->fail('Runtime Error', $label . ' is not installed on the execution host.', $started);
        }
        $out = $work . '/prog';
        $compileArgs = [$compiler, '-O2', '-o', $out, $srcFile];
        if ($isCpp) {
            array_splice($compileArgs, 1, 0, ['-std=c++17']);
        }
        $compile = $this->runCommand($compileArgs, $work, $layout, null, min(10, $limitSec + 5), $limits);
        if ($compile['exit'] !== 0) {
            return $this->compilationFail($compile['stderr'] ?: $compile['stdout'], $started);
        }

        return $this->wrapRun(
            $this->runCommand([$out], $work, $layout, $stdin, $limitSec + 1, $limits),
            $started
        );
    }

    /**
     * @param array{root:string,source:string,input:string,output:string,temp:string} $layout
     * @param array<string, mixed> $limits
     */
    private function runJava(
        string $work,
        array $layout,
        string $source,
        string $stdin,
        int $limitSec,
        array $limits,
        float $started
    ): array {
        if (!preg_match('/public\s+class\s+(\w+)/', $source, $m)) {
            return $this->compilationFail("Compilation failed\nerror: public class not found (expected public class Main)", $started);
        }
        $class = $m[1];
        file_put_contents($work . '/' . $class . '.java', $source);
        $javac = $this->resolveBin(['javac']);
        $java = $this->resolveBin(['java']);
        if ($javac === null || $java === null) {
            return $this->fail('Runtime Error', 'Java JDK is not available on the execution host.', $started);
        }
        $compile = $this->runCommand([$javac, $class . '.java'], $work, $layout, null, min(12, $limitSec + 8), $limits);
        if ($compile['exit'] !== 0) {
            return $this->compilationFail($compile['stderr'] ?: $compile['stdout'], $started);
        }

        return $this->wrapRun(
            $this->runCommand([$java, '-cp', $work, $class], $work, $layout, $stdin, $limitSec + 1, $limits),
            $started
        );
    }

    /**
     * @param list<string> $command
     * @param array{root:string,source:string,input:string,output:string,temp:string} $layout
     * @param array<string, mixed> $limits
     * @return array{exit:int, stdout:string, stderr:string, timedOut:bool, memoryExceeded:bool}
     */
    private function runCommand(
        array $command,
        string $cwd,
        array $layout,
        ?string $stdin,
        int $timeoutSec,
        array $limits
    ): array {
        $wrapped = $this->wrapWithSandbox($command, $cwd, $limits);
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => $layout['root'],
            'TMPDIR' => $layout['temp'],
            'LANG' => 'C.UTF-8',
        ];

        return $this->execProcess($wrapped, $cwd, $stdin, $timeoutSec, $env, (int) $limits['max_io_bytes']);
    }

    /**
     * @param list<string> $command
     * @param array<string, mixed> $limits
     * @return list<string>
     */
    private function wrapWithSandbox(array $command, string $cwd, array $limits): array
    {
        $runUser = trim((string) ($limits['run_user'] ?? ''));
        $wrap = dirname(__DIR__) . '/coding-exec/linux-sandbox-wrap.sh';
        $memKb = (int) $limits['memory_limit_mb'] * 1024;
        $fileBlocks = max(256, (int) ceil(((int) $limits['file_size_kb']) / 4));

        if (PHP_OS_FAMILY !== 'Windows' && is_readable($wrap)) {
            $base = [
                'bash',
                $wrap,
                (string) (int) $limits['time_limit_sec'],
                (string) $memKb,
                (string) $fileBlocks,
                (string) (int) $limits['process_limit'],
                $cwd,
            ];
            $cmd = array_merge($base, $command);
            if ($runUser !== '' && $this->resolveBin(['runuser']) !== null) {
                return array_merge(['runuser', '-u', $runUser, '--'], $cmd);
            }
            if ($runUser !== '' && $this->resolveBin(['sudo']) !== null) {
                return array_merge(['sudo', '-u', $runUser, '--'], $cmd);
            }

            return $cmd;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $timeoutBin = $this->resolveBin(['timeout']);
            if ($timeoutBin !== null) {
                return array_merge([$timeoutBin, (string) max(1, (int) $limits['time_limit_sec'])], $command);
            }
        }

        return $command;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit:int, stdout:string, stderr:string, timedOut:bool, memoryExceeded:bool}
     */
    private function execProcess(
        array $command,
        string $cwd,
        ?string $stdin,
        int $timeoutSec,
        array $env,
        int $maxIo
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'Failed to start sandbox process.', 'timedOut' => false, 'memoryExceeded' => false];
        }

        if ($stdin !== null && $stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $stdout = substr($stdout, 0, $maxIo);
            $stderr = substr($stderr, 0, $maxIo);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $start > $timeoutSec) {
                proc_terminate($process, 9);
                $timedOut = true;
                break;
            }
            usleep(25000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $memoryExceeded = $exit === 137 || str_contains(strtolower($stderr), 'cannot allocate memory');

        if ($timedOut) {
            return ['exit' => 124, 'stdout' => $stdout, 'stderr' => 'Time Limit Exceeded', 'timedOut' => true, 'memoryExceeded' => false];
        }

        return [
            'exit' => (int) $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => false,
            'memoryExceeded' => $memoryExceeded,
        ];
    }

    /**
     * @param array{exit:int, stdout:string, stderr:string, timedOut:bool, memoryExceeded:bool} $run
     * @return array<string, mixed>
     */
    private function wrapRun(array $run, float $started): array
    {
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        if ($run['timedOut']) {
            return [
                'ok' => false,
                'status' => 'Time Limit Exceeded',
                'stdout' => $run['stdout'],
                'stderr' => 'Time Limit Exceeded',
                'timedOut' => true,
                'durationMs' => $durationMs,
                'exit_code' => 124,
                'memory_used_kb' => 0,
            ];
        }
        if (!empty($run['memoryExceeded'])) {
            return [
                'ok' => false,
                'status' => 'Memory Limit Exceeded',
                'stdout' => $run['stdout'],
                'stderr' => 'Memory Limit Exceeded',
                'timedOut' => false,
                'durationMs' => $durationMs,
                'exit_code' => $run['exit'],
                'memory_used_kb' => 0,
            ];
        }
        if ($run['exit'] !== 0) {
            $err = trim($run['stderr'] ?: $run['stdout'] ?: 'Runtime Error');

            return [
                'ok' => false,
                'status' => 'Runtime Error',
                'stdout' => $run['stdout'],
                'stderr' => $err,
                'timedOut' => false,
                'durationMs' => $durationMs,
                'exit_code' => $run['exit'],
                'memory_used_kb' => 0,
            ];
        }

        return [
            'ok' => true,
            'status' => 'OK',
            'stdout' => $run['stdout'],
            'stderr' => '',
            'timedOut' => false,
            'durationMs' => $durationMs,
            'exit_code' => 0,
            'memory_used_kb' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function compilationFail(string $message, float $started): array
    {
        return [
            'ok' => false,
            'status' => 'Compilation Error',
            'stdout' => '',
            'stderr' => trim($message) !== '' ? trim($message) : 'Compilation failed.',
            'timedOut' => false,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'exit_code' => 1,
            'memory_used_kb' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function fail(string $status, string $message, float $started): array
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
        ];
    }

    /** @param list<string> $names */
    private function resolveBin(array $names): ?string
    {
        foreach ($names as $name) {
            $key = self::BIN_ENV_KEYS[strtolower($name)] ?? null;
            if ($key !== null) {
                $fromEnv = trim((string) ($_ENV[$key] ?? ''));
                if ($fromEnv !== '' && is_executable($fromEnv)) {
                    return $fromEnv;
                }
            }
            $found = $this->which($name);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function which(string $bin): ?string
    {
        if (str_contains($bin, DIRECTORY_SEPARATOR) && is_executable($bin)) {
            return $bin;
        }
        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $bin;
            if (is_file($full) && is_executable($full)) {
                return $full;
            }
        }

        return null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
