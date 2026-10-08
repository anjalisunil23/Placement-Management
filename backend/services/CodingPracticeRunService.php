<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Utils\CodingDeployInfo;
use PMS\Utils\CodingExpectedOracle;
use PMS\Utils\CodingInputValidator;

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

        $inputCheck = CodingInputValidator::validate($problem, $stdinForCustom);
        if (empty($inputCheck['ok'])) {
            return $this->customInputErrorResult($problem, $stdinForCustom, $inputCheck);
        }

        $oracle = CodingExpectedOracle::resolve(
            $problem,
            $stdinForCustom,
            is_array($inputCheck['parsed'] ?? null) ? $inputCheck['parsed'] : null,
            $this->executor
        );
        $custom = $this->executeOnce(
            $problem,
            $language,
            $source,
            $stdinForCustom,
            $timeLimitMs,
            null,
            $oracle['expected'],
            $oracle['error']
        );

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
            $ran = $this->executeOnce($problem, $language, $source, $input, $timeLimitMs, $tc);
            $cases[] = [
                'id' => (string) ($tc['id'] ?? ('tc-' . ($i + 1))),
                'label' => $label,
                'sample' => $sample,
                'hidden' => !$sample,
                'revealed' => true,
                'input' => $sample ? $input : '',
                'expected' => $sample ? $ran['expected'] : '',
                'output' => $sample ? $ran['stdout'] : '',
                'stderr' => $sample ? $ran['stderr'] : '',
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
    /**
     * @param array<string, mixed> $problem
     * @param array<string, mixed>|null $testCase when set, use this row's expected (hidden/sample grading)
     */
    /**
     * @param array<string, mixed> $problem
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    private function customInputErrorResult(array $problem, string $stdin, array $check): array
    {
        $all = array_values(array_filter(
            (array) ($problem['testCases'] ?? []),
            static fn ($tc): bool => is_array($tc)
        ));
        $summary = (string) ($check['summary'] ?? 'Custom Input Error');
        $detail = (string) ($check['detail'] ?? '');
        $cases = [];
        foreach ($all as $i => $tc) {
            if ($i > 0) {
                break;
            }
            $sample = !empty($tc['sample']);
            $label = trim((string) ($tc['label'] ?? ''));
            if ($label === '') {
                $label = $sample ? 'Sample Test Case' : 'Hidden Test Case 1';
            }
            $cases[] = [
                'id' => (string) ($tc['id'] ?? 'tc-1'),
                'label' => $label,
                'sample' => $sample,
                'hidden' => !$sample,
                'revealed' => false,
                'input' => '',
                'expected' => '',
                'output' => '',
                'stderr' => '',
                'status' => 'Not Run',
                'passed' => false,
                'index' => 1,
            ];
        }

        return [
            'overall' => 'Custom Input Error',
            'custom' => [
                'input' => $stdin,
                'output' => '',
                'expected' => '',
                'stderr' => $detail !== '' ? $detail : $summary,
                'stderrTrace' => $detail,
                'errorSummary' => $summary,
                'errorDetail' => $detail,
                'status' => 'Custom Input Error',
                'passed' => false,
                'durationMs' => 0,
                'execution' => [
                    'exitCode' => 0,
                    'engineStatus' => 'not_run',
                    'stdout' => '',
                    'stderr' => $detail,
                    'timedOut' => false,
                    'succeeded' => false,
                    'execEngine' => '',
                    'failureDetail' => $detail,
                ],
            ],
            'results' => $cases,
            'passedCount' => 0,
            'totalCount' => count($all),
            'visibleCount' => count($cases),
            'at' => (int) round(microtime(true) * 1000),
            'trace' => pms_coding_exec_debug_trace([
                'source' => '',
                'stdinCustom' => $stdin,
                'customInputError' => $summary,
            ]),
            'meta' => CodingDeployInfo::meta(),
        ];
    }

    private function executeOnce(
        array $problem,
        string $language,
        string $source,
        string $stdin,
        int $timeLimitMs,
        ?array $testCase = null,
        mixed $expectedOverride = false,
        string $oracleError = ''
    ): array {
        $stdin = str_replace("\r\n", "\n", str_replace("\r", "\n", $stdin));
        $exec = $this->executor->run($language, $source, $stdin, $timeLimitMs);
        $expectedRaw = $expectedOverride !== false
            ? ($expectedOverride === null ? null : (string) $expectedOverride)
            : $this->resolveExpected($problem, $stdin, $testCase);
        $stdout = CodingTestCaseChecker::normalize((string) ($exec['stdout'] ?? ''));
        $expectedNorm = $expectedRaw === null ? '' : CodingTestCaseChecker::normalize($expectedRaw);
        $succeeded = CodingTestCaseChecker::executionSucceeded($exec);
        $passed = $oracleError === '' && CodingTestCaseChecker::passed($exec, $stdout, $expectedRaw);
        $displayStatus = CodingTestCaseChecker::displayStatus($exec, $passed, $expectedRaw);
        $stderr = $succeeded ? '' : (string) ($exec['stderrTrace'] ?? $exec['stderr'] ?? '');
        $errorSummary = $succeeded ? '' : (string) ($exec['errorSummary'] ?? '');
        $errorDetail = $succeeded ? '' : (string) ($exec['errorDetail'] ?? '');
        if ($oracleError !== '') {
            $passed = false;
            if ($succeeded) {
                $displayStatus = 'Judge Error';
                $stderr = $oracleError;
                $errorSummary = $oracleError;
                $errorDetail = $oracleError;
            }
        }

        return [
            'stdout' => $stdout,
            'expected' => $expectedRaw === null ? '' : $expectedNorm,
            'stderr' => $stderr,
            'stderrTrace' => $stderr,
            'errorSummary' => $errorSummary,
            'errorDetail' => $errorDetail,
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
     * @param array<string, mixed>|null $testCase
     */
    private function resolveExpected(array $problem, string $stdin, ?array $testCase = null): ?string
    {
        if ($testCase !== null) {
            $raw = CodingTestCaseChecker::expectedFromTestCase($testCase);
            if ($raw !== null) {
                return $raw;
            }
        }

        return $this->expectedFor($problem, $stdin);
    }

    /**
     * @param array<string, mixed> $problem
     */
    private function expectedFor(array $problem, string $stdin): ?string
    {
        $want = CodingTestCaseChecker::normalize($stdin);
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            if (CodingTestCaseChecker::normalize((string) ($tc['input'] ?? '')) !== $want) {
                continue;
            }
            $raw = CodingTestCaseChecker::expectedFromTestCase($tc);
            if ($raw !== null) {
                return $raw;
            }
        }
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            if (CodingTestCaseChecker::normalize((string) ($ex['input'] ?? '')) !== $want) {
                continue;
            }

            return (string) ($ex['output'] ?? $ex['expected'] ?? '');
        }

        return null;
    }
}
