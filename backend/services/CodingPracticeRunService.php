<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Utils\CodingDeployInfo;

/**
 * Run student source against practice problem test cases (full inputs from DB).
 */
final class CodingPracticeRunService
{
    public function __construct(
        private ?CodeExecutionService $executor = null
    ) {
        $this->executor = $executor ?? new CodeExecutionService();
    }

    /**
     * @param array<string, mixed> $problem normalized problem with full testCases (including hidden input)
     * @return array<string, mixed>
     */
    public function run(
        array $problem,
        string $language,
        string $source,
        string $customStdin,
        int $timeLimitMs = 3000
    ): array {
        $source = (string) $source;
        $customStdin = (string) $customStdin;
        $stdinForCustom = $this->effectiveCustomStdin($problem, $customStdin);
        pms_coding_exec_debug_log('practice_run', [
            'language' => $language,
            'source' => $source,
            'stdin' => $stdinForCustom,
        ]);
        $custom = $this->executeOnce($problem, $language, $source, $stdinForCustom, $timeLimitMs);

        $all = array_values(array_filter(
            (array) ($problem['testCases'] ?? []),
            static fn ($tc): bool => is_array($tc)
        ));
        $cases = [];
        $chainOpen = false;
        $hiddenNum = 0;

        foreach ($all as $i => $tc) {
            if ($i > 0 && !$chainOpen) {
                break;
            }
            $sample = !empty($tc['sample']);
            $label = trim((string) ($tc['label'] ?? ''));
            if ($label === '') {
                if ($sample) {
                    $label = 'Sample Test Case';
                } else {
                    $hiddenNum += 1;
                    $label = 'Hidden Test Case ' . $hiddenNum;
                }
            }
            $input = (string) ($tc['input'] ?? '');
            $ran = $this->executeOnce($problem, $language, $source, $input, $timeLimitMs);
            $cases[] = [
                'id' => (string) ($tc['id'] ?? ('tc-' . ($i + 1))),
                'label' => $label,
                'sample' => $sample,
                'hidden' => !$sample,
                'revealed' => true,
                'input' => $sample ? $input : '',
                'expected' => $sample ? $ran['expected'] : '',
                'output' => $ran['stdout'],
                'stderr' => $ran['stderr'],
                'stderrTrace' => $ran['stderrTrace'],
                'errorSummary' => $ran['errorSummary'],
                'errorDetail' => $ran['errorDetail'],
                'status' => $ran['status'],
                'passed' => $ran['passed'],
                'execution' => $ran['execution'] ?? null,
            ];
            if ($i === 0) {
                $chainOpen = $ran['passed'];
            } elseif (!$ran['passed']) {
                $chainOpen = false;
            }
        }

        $passedCount = count(array_filter($cases, static fn (array $c): bool => !empty($c['passed'])));

        return [
            'overall' => $custom['status'],
            'custom' => [
                'input' => $stdinForCustom,
                'output' => $custom['stdout'],
                'expected' => $custom['expected'],
                'stderr' => $custom['stderr'],
                'stderrTrace' => $custom['stderrTrace'],
                'errorSummary' => $custom['errorSummary'],
                'errorDetail' => $custom['errorDetail'],
                'status' => $custom['status'],
                'passed' => $custom['passed'],
                'durationMs' => $custom['durationMs'],
                'execution' => $custom['execution'] ?? null,
            ],
            'results' => array_map(
                static fn (array $c, int $idx): array => array_merge($c, ['index' => $idx + 1]),
                $cases,
                array_keys($cases)
            ),
            'passedCount' => $passedCount,
            'totalCount' => count($all),
            'visibleCount' => count($cases),
            'at' => (int) round(microtime(true) * 1000),
            'trace' => pms_coding_exec_debug_trace([
                'source' => $source,
                'stdinCustom' => $stdinForCustom,
                'customExecution' => $custom['execution'] ?? null,
            ]),
            'meta' => CodingDeployInfo::meta(),
        ];
    }

    /**
     * When Custom Input is empty, run against the first sample case so Run Code matches sample tests.
     *
     * @param array<string, mixed> $problem
     */
    private function effectiveCustomStdin(array $problem, string $customStdin): string
    {
        if (trim($customStdin) !== '') {
            return $customStdin;
        }
        $fallback = $this->resolveSampleStdin($problem);

        return $fallback !== '' ? $fallback : $customStdin;
    }

    /**
     * Sample / example stdin when Custom Input is empty (matches client CodingData.resolveSampleInput).
     *
     * @param array<string, mixed> $problem
     */
    private function resolveSampleStdin(array $problem): string
    {
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc) || empty($tc['sample'])) {
                continue;
            }
            $input = (string) ($tc['input'] ?? '');
            if (trim($input) !== '') {
                return $input;
            }
        }
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $input = (string) ($ex['input'] ?? '');
            if (trim($input) !== '') {
                return $input;
            }
        }
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $input = (string) ($tc['input'] ?? '');
            if (trim($input) !== '') {
                return $input;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $problem
     * @return array<string, mixed>
     */
    private function executeOnce(
        array $problem,
        string $language,
        string $source,
        string $stdin,
        int $timeLimitMs
    ): array {
        $exec = $this->executor->run($language, $source, $stdin, $timeLimitMs);
        $expected = $this->expectedFor($problem, $stdin);
        $stdout = CodingTestCaseChecker::normalize((string) ($exec['stdout'] ?? ''));
        $expectedNorm = CodingTestCaseChecker::normalize($expected);
        $succeeded = CodingTestCaseChecker::executionSucceeded($exec);
        $passed = $succeeded && $stdout === $expectedNorm;
        $displayStatus = $this->statusFromExec($exec, $passed, $stdout, $expectedNorm);
        $stderr = $succeeded ? '' : (string) ($exec['stderrTrace'] ?? $exec['stderr'] ?? '');

        return [
            'stdout' => $stdout,
            'expected' => $expected,
            'stderr' => $stderr,
            'stderrTrace' => $stderr,
            'errorSummary' => $succeeded ? '' : (string) ($exec['errorSummary'] ?? ''),
            'errorDetail' => $succeeded ? '' : (string) ($exec['errorDetail'] ?? ''),
            'status' => $displayStatus,
            'passed' => $passed,
            'durationMs' => (int) ($exec['durationMs'] ?? 0),
            'execution' => [
                'exitCode' => (int) ($exec['exit_code'] ?? -1),
                'engineStatus' => (string) ($exec['status'] ?? ''),
                'stdout' => (string) ($exec['stdout'] ?? ''),
                'stderr' => (string) ($exec['stderr'] ?? ''),
                'timedOut' => !empty($exec['timedOut']),
                'succeeded' => $succeeded,
                'execEngine' => (string) ($exec['execEngine'] ?? ''),
                'failureDetail' => $succeeded
                    ? ''
                    : trim((string) ($exec['stderrTrace'] ?? $exec['stderr'] ?? $exec['errorSummary'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $problem
     */
    private function expectedFor(array $problem, string $stdin): string
    {
        $want = CodingTestCaseChecker::normalize($stdin);
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            if (CodingTestCaseChecker::normalize((string) ($tc['input'] ?? '')) === $want) {
                return CodingTestCaseChecker::normalize((string) ($tc['expected'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $exec
     */
    private function statusFromExec(array $exec, bool $passed, string $stdout, string $expectedNorm): string
    {
        $status = (string) ($exec['status'] ?? '');
        if (!empty($exec['timedOut']) || $status === 'Time Limit Exceeded') {
            return 'Time Limit Exceeded';
        }
        if ($status === 'Compilation Error' || $status === 'Syntax Error') {
            return 'Compilation Error';
        }
        if (!CodingTestCaseChecker::executionSucceeded($exec)) {
            return 'Runtime Error';
        }
        if ($passed) {
            return 'Passed';
        }
        if ($stdout === '' && $expectedNorm !== '') {
            return 'Execution Successful';
        }

        return 'Wrong Answer';
    }
}
