<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Server-side grading against all test cases (including hidden).
 */
final class CodingSubmissionGrader
{
    private const PASS_PERCENT = 40;

    public function __construct(
        private readonly CodeExecutionService $executor = new CodeExecutionService(),
    ) {
    }

    /**
     * Grade every question in a mock/contest attempt (hidden cases included).
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $answersByQuestionId
     * @return array<string, mixed>
     */
    public function gradeTestItems(array $items, array $answersByQuestionId, ?int $timeLimitMs = null): array
    {
        $questionResults = [];
        $score = 0.0;
        $correct = 0;
        $incorrect = 0;
        $skipped = 0;
        $testsPassed = 0;
        $testsTotal = 0;
        $maxExecutionMs = 0;

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $qid = (string) ($item['id'] ?? '');
            $ans = is_array($answersByQuestionId[$qid] ?? null) ? $answersByQuestionId[$qid] : [];
            $language = trim((string) ($ans['language'] ?? 'Python'));
            $code = (string) ($ans['code'] ?? '');
            $marks = (float) ($item['marks'] ?? 2);

            if ($this->isStarter($item, $language, $code)) {
                $skipped += 1;
                $caseResults = [];
                foreach ((array) ($item['testCases'] ?? []) as $tc) {
                    if (!is_array($tc)) {
                        continue;
                    }
                    $caseResults[] = [
                        'id' => (string) ($tc['id'] ?? ''),
                        'label' => !empty($tc['sample']) ? 'Sample' : 'Hidden',
                        'sample' => !empty($tc['sample']),
                        'status' => 'Not Run',
                        'passed' => false,
                    ];
                }
                $questionResults[] = [
                    'index' => $index + 1,
                    'id' => $qid,
                    'title' => (string) ($item['title'] ?? ''),
                    'status' => 'Skipped',
                    'marks' => $marks,
                    'marksObtained' => 0.0,
                    'language' => $language,
                    'testsPassed' => 0,
                    'testsTotal' => count($caseResults),
                    'caseResults' => $caseResults,
                ];
                continue;
            }

            $graded = $this->grade($item, $language, $code, $timeLimitMs);
            $maxExecutionMs = max($maxExecutionMs, (int) ($graded['executionTimeMs'] ?? 0));
            $tp = (int) ($graded['testsPassed'] ?? 0);
            $tt = (int) ($graded['testsTotal'] ?? 0);
            $testsPassed += $tp;
            $testsTotal += $tt;

            $marksObtained = 0.0;
            $qStatus = 'Incorrect';
            if ($tt > 0 && $tp === $tt) {
                $qStatus = 'Correct';
                $marksObtained = $marks;
                $score += $marks;
                $correct += 1;
            } else {
                $marksObtained = $tt > 0 ? round($marks * ($tp / $tt), 2) : 0.0;
                $score += $marksObtained;
                $incorrect += 1;
            }

            $caseResults = [];
            foreach ($graded['caseResults'] ?? [] as $cr) {
                if (!is_array($cr)) {
                    continue;
                }
                $caseResults[] = [
                    'id' => (string) ($cr['id'] ?? ''),
                    'label' => !empty($cr['sample']) ? 'Sample' : 'Hidden',
                    'sample' => !empty($cr['sample']),
                    'status' => (string) ($cr['status'] ?? 'Wrong Answer'),
                    'passed' => !empty($cr['passed']),
                ];
            }

            $questionResults[] = [
                'index' => $index + 1,
                'id' => $qid,
                'title' => (string) ($item['title'] ?? ''),
                'status' => $qStatus,
                'marks' => $marks,
                'marksObtained' => $marksObtained,
                'language' => $language,
                'testsPassed' => $tp,
                'testsTotal' => $tt,
                'caseResults' => $caseResults,
            ];
        }

        $totalMarks = array_reduce(
            $items,
            static fn (float $sum, $item): float => $sum + (float) (is_array($item) ? ($item['marks'] ?? 2) : 2),
            0.0
        );
        if ($totalMarks <= 0) {
            $totalMarks = array_reduce(
                $questionResults,
                static fn (float $sum, array $qr): float => $sum + (float) ($qr['marks'] ?? 0),
                0.0
            );
        }
        $percentage = $totalMarks > 0 ? round(($score / $totalMarks) * 1000) / 10 : 0.0;

        return [
            'score' => round($score, 2),
            'totalMarks' => $totalMarks,
            'percentage' => $percentage,
            'passed' => $percentage >= self::PASS_PERCENT,
            'status' => $percentage >= self::PASS_PERCENT ? 'Passed' : 'Failed',
            'correct' => $correct,
            'incorrect' => $incorrect,
            'skipped' => $skipped,
            'questions' => count($questionResults),
            'testsPassed' => $testsPassed,
            'testsTotal' => $testsTotal,
            'executionTimeMs' => $maxExecutionMs,
            'questionResults' => $questionResults,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isStarter(array $item, string $language, string $code): bool
    {
        $starters = is_array($item['starterCode'] ?? null) ? $item['starterCode'] : [];
        $starter = trim((string) ($starters[$language] ?? $starters['Python'] ?? ''));
        $code = trim($code);

        return $code === '' || ($starter !== '' && $code === $starter);
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
