<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Server-side grading against all test cases (including hidden).
 */
final class CodingSubmissionGrader
{
    public function __construct(
        private readonly CodeExecutionService $executor = new CodeExecutionService(),
    ) {
    }

    /**
     * @param array<string, mixed> $problem full bank row (with hidden test I/O)
     * @return array<string, mixed>
     */
    public function grade(array $problem, string $language, string $sourceCode, ?int $timeLimitMs = null): array
    {
        $sourceCode = trim($sourceCode);
        if ($sourceCode === '') {
            return $this->emptyGrade($problem, 'Skipped');
        }

        $cases = array_values(array_filter(
            (array) ($problem['testCases'] ?? []),
            static fn ($tc): bool => is_array($tc) && trim((string) ($tc['expected'] ?? '')) !== ''
        ));
        $total = count($cases);
        if ($total === 0) {
            return $this->emptyGrade($problem, 'Wrong Answer');
        }

        $passed = 0;
        $caseResults = [];
        $overallStatus = 'Accepted';
        $maxDurationMs = 0;
        $lastError = '';

        foreach ($cases as $index => $tc) {
            $input = (string) ($tc['input'] ?? '');
            $expected = (string) ($tc['expected'] ?? '');
            $exec = $this->executor->run($language, $sourceCode, $input, $timeLimitMs ?? 3000);
            $maxDurationMs = max($maxDurationMs, (int) ($exec['durationMs'] ?? 0));
            $verdict = CodingTestCaseChecker::verdictFromExecution($exec, $expected);
            $ok = $verdict === 'Accepted';
            if ($ok) {
                $passed += 1;
            } else {
                $overallStatus = $verdict;
                $lastError = (string) ($exec['stderr'] ?? $verdict);
            }
            $caseResults[] = [
                'id' => (string) ($tc['id'] ?? ('tc-' . ($index + 1))),
                'sample' => !empty($tc['sample']),
                'status' => $verdict,
                'passed' => $ok,
            ];
            if ($verdict !== 'Accepted' && $verdict !== 'Wrong Answer') {
                break;
            }
        }

        if ($passed === $total) {
            $overallStatus = 'Accepted';
        } elseif ($overallStatus === 'Accepted') {
            $overallStatus = 'Wrong Answer';
        }

        $marks = (float) ($problem['marks'] ?? 2);
        $score = $total > 0 ? round($marks * ($passed / $total), 2) : 0.0;
        $percentage = $total > 0 ? round(100 * ($passed / $total), 1) : 0.0;

        return [
            'accepted' => $passed === $total && $total > 0,
            'status' => $overallStatus,
            'testsPassed' => $passed,
            'testsTotal' => $total,
            'score' => $score,
            'totalMarks' => $marks,
            'percentage' => $percentage,
            'executionTimeMs' => $maxDurationMs,
            'memoryUsedKb' => 0,
            'caseResults' => $caseResults,
            'stderr' => $lastError,
        ];
    }

    /**
     * @param array<string, mixed> $problem
     * @return array<string, mixed>
     */
    private function emptyGrade(array $problem, string $status): array
    {
        $marks = (float) ($problem['marks'] ?? 2);

        return [
            'accepted' => false,
            'status' => $status,
            'testsPassed' => 0,
            'testsTotal' => 0,
            'score' => 0.0,
            'totalMarks' => $marks,
            'percentage' => 0.0,
            'executionTimeMs' => 0,
            'memoryUsedKb' => 0,
            'caseResults' => [],
            'stderr' => '',
        ];
    }
}
