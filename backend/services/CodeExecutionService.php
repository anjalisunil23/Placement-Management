<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Sandboxed compile/run for coding practice (g++/gcc, javac, python3, node).
 */
final class CodeExecutionService
{
    private const MAX_SOURCE_BYTES = 65536;
    private const MAX_IO_BYTES = 65536;

    /**
     * @return array<string, mixed>
     */
    public function run(string $language, string $source, string $stdin = '', int $timeLimitMs = 3000): array
    {
        $started = microtime(true);
        $source = substr($source, 0, self::MAX_SOURCE_BYTES);
        $stdin = substr($stdin, 0, self::MAX_IO_BYTES);
        $limitSec = max(1, min(15, (int) ceil($timeLimitMs / 1000)));
        $lang = $this->normalizeLanguage($language);
        $work = $this->makeWorkDir();
        if ($work === null) {
            return $this->fail('Runtime Error', 'Could not create execution workspace.', $started);
        }

        try {
            return match ($lang) {
                'python' => $this->runPython($work, $source, $stdin, $limitSec, $started),
                'javascript' => $this->runNode($work, $source, $stdin, $limitSec, $started),
                'c' => $this->runNative($work, $source, $stdin, $limitSec, $started, 'c'),
                'cpp' => $this->runNative($work, $source, $stdin, $limitSec, $started, 'cpp'),
                'java' => $this->runJava($work, $source, $stdin, $limitSec, $started),
                default => $this->fail('Runtime Error', 'Unsupported language.', $started),
            };
        } finally {
            $this->removeDir($work);
        }
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
     * @return array<string, mixed>
     */
    private function runPython(string $dir, string $source, string $stdin, int $limitSec, float $started): array
    {
        file_put_contents($dir . '/main.py', $source);
        $bin = $this->resolveBin(['python3', 'python']);
        if ($bin === null) {
            return $this->fail('Runtime Error', 'Python is not available on the server.', $started);
        }

        return $this->wrapRun($this->exec([$bin, 'main.py'], $dir, $stdin, $limitSec), $started);
    }

    /**
     * @return array<string, mixed>
     */
    private function runNode(string $dir, string $source, string $stdin, int $limitSec, float $started): array
    {
        file_put_contents($dir . '/main.js', $source);
        $bin = $this->resolveBin(['node']);
        if ($bin === null) {
            return $this->fail('Runtime Error', 'Node.js is not available on the server.', $started);
        }

        return $this->wrapRun($this->exec([$bin, 'main.js'], $dir, $stdin, $limitSec), $started);
    }

    /**
     * @return array<string, mixed>
     */
    private function runNative(string $dir, string $source, string $stdin, int $limitSec, float $started, string $kind): array
    {
        $isCpp = $kind === 'cpp';
        $srcFile = $isCpp ? 'main.cpp' : 'main.c';
        file_put_contents($dir . '/' . $srcFile, $source);
        $compiler = $this->resolveBin($isCpp ? ['g++', 'c++'] : ['gcc', 'cc']);
        if ($compiler === null) {
            return $this->fail(
                'Runtime Error',
                ($isCpp ? 'C++' : 'C') . ' compiler (g++) is not installed on the server. Contact your administrator.',
                $started
            );
        }
        $out = $dir . '/prog';
        $compileArgs = [$compiler, '-O2', '-o', $out, $srcFile];
        if ($isCpp) {
            array_splice($compileArgs, 1, 0, ['-std=c++17']);
        }
        $compile = $this->exec($compileArgs, $dir, null, min(10, $limitSec + 5));
        if ($compile['exit'] !== 0) {
            return $this->compilationFail($compile['stderr'] ?: $compile['stdout'], $started);
        }
        $runCmd = [$out];
        if (PHP_OS_FAMILY !== 'Windows' && $this->resolveBin(['timeout']) !== null) {
            $runCmd = ['timeout', (string) $limitSec, $out];
        }

        return $this->wrapRun($this->exec($runCmd, $dir, $stdin, $limitSec + 1), $started);
    }

    /**
     * @return array<string, mixed>
     */
    private function runJava(string $dir, string $source, string $stdin, int $limitSec, float $started): array
    {
        if (!preg_match('/public\s+class\s+(\w+)/', $source, $m)) {
            return $this->compilationFail("Compilation failed\nerror: public class not found (expected public class Main)", $started);
        }
        $class = $m[1];
        file_put_contents($dir . '/' . $class . '.java', $source);
        $javac = $this->resolveBin(['javac']);
        $java = $this->resolveBin(['java']);
        if ($javac === null || $java === null) {
            return $this->fail('Runtime Error', 'Java JDK is not available on the server.', $started);
        }
        $compile = $this->exec([$javac, $class . '.java'], $dir, null, min(12, $limitSec + 8));
        if ($compile['exit'] !== 0) {
            return $this->compilationFail($compile['stderr'] ?: $compile['stdout'], $started);
        }
        $runCmd = [$java, '-cp', $dir, $class];
        if (PHP_OS_FAMILY !== 'Windows' && $this->resolveBin(['timeout']) !== null) {
            $runCmd = ['timeout', (string) $limitSec, $java, '-cp', $dir, $class];
        }

        return $this->wrapRun($this->exec($runCmd, $dir, $stdin, $limitSec + 1), $started);
    }

    /**
     * @param list<string> $command
     * @return array{exit:int, stdout:string, stderr:string, timedOut:bool}
     */
    private function exec(array $command, string $cwd, ?string $stdin, int $timeoutSec): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, $cwd, null);
        if (!is_resource($process)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'Failed to start process.', 'timedOut' => false];
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
            $stdout = substr($stdout, 0, self::MAX_IO_BYTES);
            $stderr = substr($stderr, 0, self::MAX_IO_BYTES);
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

        if ($timedOut) {
            return ['exit' => 124, 'stdout' => substr($stdout, 0, self::MAX_IO_BYTES), 'stderr' => 'Time Limit Exceeded', 'timedOut' => true];
        }

        return [
            'exit' => (int) $exit,
            'stdout' => substr($stdout, 0, self::MAX_IO_BYTES),
            'stderr' => substr($stderr, 0, self::MAX_IO_BYTES),
            'timedOut' => false,
        ];
    }

    /**
     * @param array{exit:int, stdout:string, stderr:string, timedOut:bool} $run
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
            ];
        }

        return [
            'ok' => true,
            'status' => 'OK',
            'stdout' => $run['stdout'],
            'stderr' => '',
            'timedOut' => false,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compilationFail(string $message, float $started): array
    {
        return [
            'ok' => false,
            'status' => 'Compilation Error',
            'stdout' => '',
            'stderr' => trim($message) !== '' ? trim($message) : 'Compilation failed.',
            'timedOut' => false,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $status, string $message, float $started): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'stdout' => '',
            'stderr' => $message,
            'timedOut' => false,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @param list<string> $names
     */
    private function resolveBin(array $names): ?string
    {
        foreach ($names as $name) {
            $envKey = 'CODING_' . strtoupper(preg_replace('/[^A-Z0-9]+/i', '_', $name)) . '_PATH';
            $fromEnv = trim((string) ($_ENV[$envKey] ?? ''));
            if ($fromEnv !== '' && is_executable($fromEnv)) {
                return $fromEnv;
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
            if (PHP_OS_FAMILY === 'Windows' && is_file($full . '.exe')) {
                return $full . '.exe';
            }
        }

        return null;
    }

    private function makeWorkDir(): ?string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms-code-exec';
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            return null;
        }
        $dir = $base . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0700, true)) {
            return null;
        }

        return $dir;
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
