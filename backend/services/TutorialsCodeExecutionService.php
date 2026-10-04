<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Runs and grades Tutorials programming exercises through an isolated runner.
 * Student source is never executed with PHP eval, exec, or shell functions.
 */
class TutorialsCodeExecutionService
{
    /** @var list<string> */
    public const LANGUAGES = ['python', 'javascript', 'java', 'c', 'cpp'];

    public function __construct(private ?TutorialsPistonClient $client = null)
    {
        $this->client = $client ?? new TutorialsPistonClient();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function configurationMessage(): string
    {
        return $this->client->configurationMessage();
    }

    /**
     * @return array{ok: bool, status: string, stdout: string, stderr: string, timedOut: bool, durationMs: int}
     */
    public function run(string $language, string $source, string $stdin, int $timeLimitMs): array
    {
        $this->assertRunnable($language, $source);
        if (!$this->client->isConfigured()) {
            throw new \RuntimeException($this->client->configurationMessage());
        }

        return $this->client->execute($language, $source, $stdin, $timeLimitMs);
    }

    /**
     * Grade server-owned test cases. Hidden expected output is not returned.
     *
     * @param list<array<string, mixed>> $cases
     * @return array<string, mixed>
     */
    public function grade(string $language, string $source, array $cases, int $timeLimitMs): array
    {
        $this->assertRunnable($language, $source);
        if (!$this->client->isConfigured()) {
            throw new \RuntimeException($this->client->configurationMessage());
        }
        if ($cases === []) {
            throw new \InvalidArgumentException('This exercise has no test cases to submit against.');
        }
        $results = [];
        $passed = 0;
        $duration = 0;
        $timedOut = false;
        foreach (array_slice($cases, 0, 20) as $index => $case) {
            if (!is_array($case)) {
                continue;
            }
            $sample = ($case['sample'] ?? false) === true;
            $expected = (string) ($case['expectedOutput'] ?? '');
            $stdin = (string) ($case['stdin'] ?? '');
            $ran = $this->client->execute($language, $source, $stdin, $timeLimitMs);
            $duration += (int) ($ran['durationMs'] ?? 0);
            $ok = ($ran['ok'] ?? false) === true && !$ran['timedOut'] && $this->outputsMatch((string) ($ran['stdout'] ?? ''), $expected);
            if (($ran['timedOut'] ?? false) === true) {
                $timedOut = true;
            }
            if ($ok) {
                $passed++;
            }
            if ($sample) {
                $results[] = [
                    'index' => $index + 1,
                    'sample' => true,
                    'passed' => $ok,
                    'status' => (string) ($ran['status'] ?? ''),
                    'stdin' => $stdin,
                    'expectedOutput' => $expected,
                    'stdout' => (string) ($ran['stdout'] ?? ''),
                    'stderr' => (string) ($ran['stderr'] ?? ''),
                    'timedOut' => ($ran['timedOut'] ?? false) === true,
                    'durationMs' => (int) ($ran['durationMs'] ?? 0),
                ];
            } else {
                $results[] = [
                    'index' => $index + 1,
                    'sample' => false,
                    'passed' => $ok,
                    'status' => $ok ? 'Passed' : (($ran['timedOut'] ?? false) === true ? 'Time Limit Exceeded' : 'Failed'),
                    'timedOut' => ($ran['timedOut'] ?? false) === true,
                ];
            }
        }
        $total = count($results);
        $failed = $total - $passed;

        return [
            'passed' => $total > 0 && $passed === $total,
            'status' => $timedOut && $passed !== $total ? 'Time Limit Exceeded' : ($passed === $total ? 'Passed' : 'Failed'),
            'testsTotal' => $total,
            'testsPassed' => $passed,
            'testsFailed' => $failed,
            'durationMs' => $duration,
            'results' => $results,
        ];
    }

    public function outputsMatch(string $actual, string $expected): bool
    {
        return $this->normalizeOutput($actual) === $this->normalizeOutput($expected);
    }

    private function assertRunnable(string $language, string $source): void
    {
        if (!in_array(strtolower(trim($language)), self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('This exercise language cannot be executed.');
        }
        if (trim($source) === '') {
            throw new \InvalidArgumentException('Source code is required.');
        }
        if (strlen($source) > 65536) {
            throw new \InvalidArgumentException('Source code is too long.');
        }
    }

    private function normalizeOutput(string $value): string
    {
        $value = str_replace("\r\n", "\n", $value);
        $lines = array_map(static fn (string $line): string => rtrim($line), explode("\n", $value));

        return rtrim(implode("\n", $lines));
    }
}
