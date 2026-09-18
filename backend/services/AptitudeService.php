<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\AptitudeAttemptModel;
use PMS\Models\AptitudeQuestionBankModel;
use PMS\Models\AptitudeTestModel;
use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Aptitude mock tests — listing, attempts, progress summaries.
 */
final class AptitudeService
{
    private AptitudeTestModel $tests;
    private AptitudeAttemptModel $attempts;

    /** @var array<string, true>|null */
    private ?array $contestTestIdSetCache = null;

    public function __construct()
    {
        $this->tests = new AptitudeTestModel();
        $this->attempts = new AptitudeAttemptModel();
    }

    public function ensureSeeded(): void
    {
        if ($this->tests->count([]) > 0) {
            return;
        }
        $this->tests->createTest([
            'title' => 'Quantitative Aptitude — Basics',
            'description' => 'Arithmetic, percentages, and ratios for placement screening.',
            'category' => 'Quantitative Aptitude',
            'difficulty' => 'Easy',
            'durationMinutes' => 15,
            'totalMarks' => 3,
            'negativeMarking' => false,
            'negativeMarks' => 0,
            'instructions' => "1. Each question has one correct option.\n2. No negative marking.\n3. Do not refresh while the test is in progress.",
            'status' => 'published',
            'questions' => [
                [
                    'id' => 'q1',
                    'type' => 'mcq',
                    'prompt' => 'What is 15% of 240?',
                    'options' => ['24', '36', '30', '48'],
                    'correctIndex' => 1,
                    'marks' => 1,
                    'category' => 'Quantitative Aptitude',
                    'explanation' => '15% of 240 = 0.15 × 240 = 36.',
                ],
                [
                    'id' => 'q2',
                    'type' => 'mcq',
                    'prompt' => 'If A:B = 2:3 and B:C = 4:5, then A:C is?',
                    'options' => ['8:15', '2:5', '4:5', '8:9'],
                    'correctIndex' => 0,
                    'marks' => 1,
                    'category' => 'Quantitative Aptitude',
                    'explanation' => 'A:B = 2:3 and B:C = 4:5. Make B common: A:B = 8:12 and B:C = 12:15, so A:C = 8:15.',
                ],
                [
                    'id' => 'q3',
                    'type' => 'mcq',
                    'prompt' => 'A train covers 120 km in 2 hours. Average speed?',
                    'options' => ['40 km/h', '50 km/h', '60 km/h', '80 km/h'],
                    'correctIndex' => 2,
                    'marks' => 1,
                    'category' => 'Quantitative Aptitude',
                    'explanation' => 'Average speed = distance / time = 120 / 2 = 60 km/h.',
                ],
            ],
        ]);
        $this->tests->createTest([
            'title' => 'Logical Reasoning — Starter',
            'description' => 'Patterns and simple deductions.',
            'category' => 'Logical Reasoning',
            'difficulty' => 'Medium',
            'durationMinutes' => 12,
            'totalMarks' => 2,
            'negativeMarking' => true,
            'negativeMarks' => 0.25,
            'instructions' => "1. MCQ only — choose one option per question.\n2. Wrong answers deduct 0.25 marks.\n3. Unanswered questions score zero.",
            'status' => 'published',
            'questions' => [
                [
                    'id' => 'l1',
                    'type' => 'mcq',
                    'prompt' => 'Find the next number: 2, 6, 12, 20, ?',
                    'options' => ['28', '30', '32', '36'],
                    'correctIndex' => 1,
                    'marks' => 1,
                    'category' => 'Logical Reasoning',
                    'explanation' => 'The differences increase by 2 each time: +4, +6, +8, then +10. 20 + 10 = 30.',
                ],
                [
                    'id' => 'l2',
                    'type' => 'mcq',
                    'prompt' => 'All roses are flowers. Some flowers fade quickly. Which follows?',
                    'options' => [
                        'All roses fade quickly',
                        'Some roses may fade quickly',
                        'No roses fade',
                        'None of these',
                    ],
                    'correctIndex' => 1,
                    'marks' => 1,
                    'category' => 'Logical Reasoning',
                    'explanation' => 'All roses are flowers, and some flowers fade quickly, so some roses may fade quickly. It is not true that all roses fade.',
                ],
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublished(bool $includeAnswers = false): array
    {
        $this->ensureSeeded();
        return array_map(
            static fn ($t) => AptitudeTestModel::publicView($t, $includeAnswers),
            $this->tests->published(200)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPublishedForUser(array $user, bool $includeAnswers = false): array
    {
        $this->ensureSeeded();
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        $completedIds = [];
        $inProgressIds = [];
        if ($userId !== '') {
            foreach ($this->attempts->forUser($userId, 200) as $attempt) {
                $tid = (string) ($attempt['testId'] ?? '');
                if ($tid === '') {
                    continue;
                }
                if (($attempt['status'] ?? '') === 'completed') {
                    $completedIds[$tid] = true;
                }
                if (($attempt['status'] ?? '') === 'in_progress') {
                    $inProgressIds[$tid] = true;
                }
            }
        }
        $rows = array_values(array_filter(
            $this->tests->published(200),
            static function ($t) use ($user, $completedIds, $inProgressIds): bool {
                if (!AptitudeAccessService::testVisibleToTaker($user, $t)) {
                    return false;
                }
                if (!AptitudeTestModel::isContest($t)) {
                    return true;
                }
                $id = (string) ($t['_id'] ?? '');
                if (isset($completedIds[$id])) {
                    return false;
                }
                if (!AptitudeTestModel::isContestOpen($t)) {
                    return false;
                }

                return true;
            }
        ));

        $tests = $this->attachListStats(array_map(
            static fn ($t) => AptitudeTestModel::publicView($t, $includeAnswers),
            $rows
        ));
        foreach ($tests as &$test) {
            $id = (string) ($test['id'] ?? '');
            $test['alreadyAttempted'] = isset($completedIds[$id]);
            $test['attemptInProgress'] = isset($inProgressIds[$id]);
            if (AptitudeTestModel::isContest($test) && !AptitudeTestModel::resultsPublished($test)) {
                $test['averagePercentage'] = null;
            }
        }
        unset($test);

        return $tests;
    }

    /**
     * @param array<int, array<string, mixed>> $tests
     * @return array<int, array<string, mixed>>
     */
    private function attachListStats(array $tests): array
    {
        if ($tests === []) {
            return $tests;
        }
        $sums = [];
        $counts = [];
        foreach ($this->attempts->completed([], 2000) as $attempt) {
            $tid = (string) ($attempt['testId'] ?? '');
            if ($tid === '') {
                continue;
            }
            $sums[$tid] = ($sums[$tid] ?? 0.0) + (float) ($attempt['percentage'] ?? 0);
            $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }
        foreach ($tests as &$test) {
            $id = (string) ($test['id'] ?? '');
            $n = (int) ($counts[$id] ?? 0);
            $test['attemptCount'] = $n;
            $test['averagePercentage'] = $n > 0 ? round($sums[$id] / $n, 1) : null;
        }
        unset($test);

        return $tests;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listAllForAdmin(array $user): array
    {
        $this->ensureSeeded();
        $role = AuthMiddleware::resolvedRole($user);
        if ($role === 'admin') {
            $rows = $this->tests->findAll([], 200);
        } elseif ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            $deptOid = Security::toObjectId((string) ($ctx['departmentId'] ?? ''));
            $rows = $deptOid !== null
                ? $this->tests->findAll(['departmentId' => $deptOid], 200)
                : [];
        } elseif ($role === 'staff' || ($user['role'] ?? '') === 'staff') {
            $ctx = StaffContext::resolve($user);
            $deptOid = Security::toObjectId((string) ($ctx['departmentId'] ?? ''));
            $rows = $deptOid !== null && StaffContext::assignedClassBatches($ctx) !== []
                ? $this->tests->findAll(['departmentId' => $deptOid], 200)
                : [];
        } else {
            $rows = [];
        }

        $views = array_map(
            static fn ($t) => AptitudeTestModel::publicView($t, true),
            $rows
        );

        return $this->attachListStats($views);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function start(array $user, string $testId): array
    {
        AptitudeAccessService::requireTaker($user);
        if (!Security::isValidId($testId)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($testId);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        if (($test['status'] ?? '') !== 'published') {
            Response::forbidden('This aptitude test is not published yet.');
        }
        if (!AptitudeAccessService::testVisibleToTaker($user, $test)) {
            Response::forbidden('This aptitude test is not available for your department.');
        }
        if (AptitudeTestModel::isContest($test)) {
            $life = AptitudeTestModel::contestStatus($test);
            if ($life === 'UPCOMING') {
                Response::forbidden('This contest is not open yet. Check the weekly or monthly schedule.');
            }
            if ($life === 'COMPLETED') {
                Response::forbidden('This contest has ended. You can no longer start or submit answers.');
            }
        } elseif (!AptitudeTestModel::isContestOpen($test)) {
            Response::forbidden('This contest is not open today. Check the weekly or monthly schedule.');
        }
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        $examQuestions = [];
        if (AptitudeTestModel::isContest($test)) {
            $existing = $this->attempts->forUserAndTest($userId, $testId, 20);
            foreach ($existing as $row) {
                if (($row['status'] ?? '') === 'completed') {
                    Response::forbidden('You can take this contest only once.');
                }
            }
            foreach ($existing as $row) {
                if (($row['status'] ?? '') === 'in_progress') {
                    $stored = array_values((array) ($row['examQuestions'] ?? []));
                    $examTest = $this->examViewFromQuestions($test, $stored);

                    return [
                        'attemptId' => (string) ($row['_id'] ?? ''),
                        'test' => $examTest,
                    ];
                }
            }
            try {
                $examQuestions = $this->contestQuestionsFromBank($test);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            if ($examQuestions === []) {
                Response::error('This contest has no questions in the question bank yet.', 422);
            }
        }
        $ctx = AptitudeAccessService::subjectContext($user);
        $examTest = $examQuestions !== []
            ? $this->examViewFromQuestions($test, $examQuestions)
            : AptitudeTestModel::publicView($test, false);
        $clientQuestions = array_values((array) ($examTest['questions'] ?? []));
        $attemptId = $this->attempts->startAttempt([
            'testId' => $testId,
            'userId' => $userId,
            'subjectType' => $ctx['subjectType'],
            'studentId' => $ctx['studentId'],
            'alumniId' => $ctx['alumniId'],
            'departmentId' => $ctx['departmentId'],
            'classBatch' => $ctx['classBatch'],
            'course' => $ctx['course'],
            'semester' => $ctx['semester'],
            'batch' => $ctx['batch'],
            'totalQuestions' => count($clientQuestions),
            'examQuestions' => $examQuestions !== [] ? $examQuestions : $clientQuestions,
        ]);

        return [
            'attemptId' => $attemptId,
            'test' => $examTest,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $answers map questionId => optionIndex
     * @param array<string, mixed> $meta markedForReview, timeTakenSeconds, autoSubmitted
     * @return array<string, mixed>
     */
    public function submit(array $user, string $attemptId, array $answers, array $meta = []): array
    {
        AptitudeAccessService::requireTaker($user);
        $attempt = $this->attempts->findById($attemptId);
        if (!$attempt) {
            Response::notFound('Attempt not found.');
        }
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ((string) ($attempt['userId'] ?? '') !== $userId) {
            Response::forbidden('This attempt does not belong to you.');
        }
        $test = $this->tests->findById((string) ($attempt['testId'] ?? ''));
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        if (($attempt['status'] ?? '') === 'completed') {
            return $this->buildResultPayload($attempt, $test, $user);
        }
        if (AptitudeTestModel::isContest($test) && AptitudeTestModel::contestStatus($test) === 'COMPLETED') {
            Response::forbidden('This contest has ended. Submissions are closed.');
        }

        $durationSec = max(60, (int) ($test['durationMinutes'] ?? 30) * 60);
        $startedAt = $this->parseTime($attempt['startedAt'] ?? null);
        $elapsed = $startedAt > 0 ? max(0, time() - $startedAt) : 0;
        $timeTaken = isset($meta['timeTakenSeconds'])
            ? max(0, (int) $meta['timeTakenSeconds'])
            : $elapsed;
        if ($timeTaken > $durationSec + 30) {
            $timeTaken = $durationSec;
        }

        $scored = $this->scoreAttempt($test, $answers, $this->attemptExamQuestions($attempt));
        $scored['markedForReview'] = array_values(array_filter(
            array_map('strval', (array) ($meta['markedForReview'] ?? []))
        ));
        $scored['timeTakenSeconds'] = $timeTaken;
        $scored['autoSubmitted'] = !empty($meta['autoSubmitted']);

        $this->attempts->completeAttempt($attemptId, $scored);
        $fresh = $this->attempts->findById($attemptId) ?: array_merge($attempt, $scored, ['status' => 'completed']);
        if (AptitudeTestModel::isContest($test)) {
            $this->recomputeContestRanks((string) ($test['_id'] ?? ''));
            $fresh = $this->attempts->findById($attemptId) ?: $fresh;
        } else {
            $rankInfo = $this->computeRank((string) ($test['_id'] ?? ''), (float) ($scored['percentage'] ?? 0));
            if ($rankInfo['rank'] !== null) {
                $this->attempts->update($attemptId, [
                    'rank' => $rankInfo['rank'],
                    'percentile' => $rankInfo['percentile'],
                ]);
                $fresh['rank'] = $rankInfo['rank'];
                $fresh['percentile'] = $rankInfo['percentile'];
            }
        }

        return $this->buildResultPayload($fresh, $test, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function attemptResult(array $user, string $attemptId): array
    {
        $attempt = $this->attempts->findById($attemptId);
        if (!$attempt) {
            Response::notFound('Attempt not found.');
        }
        if (!AptitudeAccessService::canViewAttempt($user, $attempt)) {
            Response::forbidden('You cannot view this attempt result.');
        }
        if (($attempt['status'] ?? '') !== 'completed') {
            Response::forbidden('Result is available only after submission.');
        }
        $test = $this->tests->findById((string) ($attempt['testId'] ?? ''));
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        // Rebuild analysis if missing (older attempts)
        if (empty($attempt['questionAnalysis'])) {
            $scored = $this->scoreAttempt($test, (array) ($attempt['answers'] ?? []), $this->attemptExamQuestions($attempt));
            $attempt = array_merge($attempt, [
                'questionAnalysis' => $scored['questionAnalysis'],
                'wrongCount' => $scored['wrongCount'],
                'unansweredCount' => $scored['unansweredCount'],
                'totalMarks' => $scored['totalMarks'],
                'marksObtained' => $scored['marksObtained'],
            ]);
        }
        return $this->buildResultPayload($attempt, $test, $user);
    }

    /**
     * Bulk-add questions to a test (JSON array or parsed CSV rows).
     *
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>>|string $payload
     * @return array<string, mixed>
     */
    public function bulkAddToTest(array $admin, string $testId, $payload, bool $replace = false): array
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($testId)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($testId);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        $rows = $this->parseQuestionPayload($payload, (string) ($test['category'] ?? 'General Aptitude'));
        if ($rows === []) {
            Response::error('No valid MCQ questions found in upload.', 422);
        }
        if ($replace) {
            $this->tests->updateTest($testId, array_merge($test, ['questions' => $rows]));
            $doc = $this->tests->findById($testId);
            return [
                'added' => count($rows),
                'total' => count($rows),
                'test' => AptitudeTestModel::publicView($doc ?: [], true),
            ];
        }
        return $this->tests->appendQuestions($testId, $rows, (string) ($test['category'] ?? 'General Aptitude'));
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>>|string $payload
     * @return array<string, mixed>
     */
    public function bulkAddToBank(array $admin, $payload, string $category = 'General Aptitude'): array
    {
        AptitudeAccessService::requireManager($admin);
        $rows = $this->parseQuestionPayload($payload, $category);
        if ($rows === []) {
            Response::error('No valid MCQ questions found in upload.', 422);
        }
        $bank = new AptitudeQuestionBankModel();
        $result = $bank->bulkInsert(
            $rows,
            $category,
            (string) ($admin['_id'] ?? $admin['id'] ?? '')
        );
        return $result;
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function getAiServiceStatus(array $admin): array
    {
        AptitudeAccessService::requireManager($admin);
        return (new AptitudeAiQuestionService())->checkStatus();
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function generateAiBankQuestions(array $admin, array $body): array
    {
        try {
            return (new AptitudeAiQuestionService())->generateForUser($admin, $body);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 503);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude AI] generate failed: ' . $e->getMessage());
            Response::error('AI question generation is temporarily unavailable. Please try again.', 503);
        }
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function saveAiBankQuestions(array $admin, array $questions, string $category = 'General Aptitude'): array
    {
        if ($questions === []) {
            Response::error('No questions selected to save.', 422);
        }

        try {
            return (new AptitudeAiQuestionService())->saveApprovedForUser($admin, $questions, $category);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude AI] save failed: ' . $e->getMessage());
            Response::error('Could not save AI questions. Please try again.', 500);
        }
    }

    /**
     * @param array<string, mixed> $admin
     */
    public function deleteBankQuestion(array $admin, string $id): void
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($id)) {
            Response::notFound('Question not found.');
        }
        $bank = new AptitudeQuestionBankModel();
        if (!$bank->findById($id)) {
            Response::notFound('Question not found.');
        }
        if (!$bank->delete($id)) {
            Response::error('Could not delete question.', 500);
        }
    }

    /**
     * @param array<string, mixed> $admin
     */
    public function deleteTest(array $admin, string $id): void
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        $contestType = AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if (in_array($contestType, ['weekly', 'monthly'], true) && !AptitudeAccessService::canManageContests($admin)) {
            Response::forbidden('You cannot delete aptitude contests.');
        }
        if (!$this->tests->delete($id)) {
            Response::error('Could not delete test.', 500);
        }
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function publishContestResults(array $admin, string $id, bool $published): array
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        if (!AptitudeTestModel::isContest($test)) {
            Response::error('Results can only be published for weekly or monthly contests.', 422);
        }
        if ($published) {
            if (AptitudeTestModel::contestStatus($test) !== 'COMPLETED') {
                Response::error('Results can only be published after the contest has ended.', 422);
            }
            if (AptitudeTestModel::resultsPublished($test)) {
                Response::error('Contest results are already published.', 422);
            }
            $this->recomputeContestRanks($id);
            $patch = [
                'resultsPublished' => true,
                'resultPublishedAt' => DocumentHelper::now(),
            ];
        } else {
            $patch = [
                'resultsPublished' => false,
                'resultPublishedAt' => null,
            ];
        }
        if (!$this->tests->updateTest($id, $patch)) {
            Response::error('Could not update contest results visibility.', 500);
        }
        $fresh = $this->tests->findById($id) ?: $test;

        return AptitudeTestModel::publicView($fresh, true);
    }

    /**
     * Completed contests for admin review and publishing.
     *
     * @param array<string, mixed> $admin
     * @return array<int, array<string, mixed>>
     */
    public function listCompletedContests(array $admin): array
    {
        AptitudeAccessService::requireDirectoryViewer($admin);
        $rows = [];
        foreach ($this->listAllForAdmin($admin) as $test) {
            if (!AptitudeTestModel::isContest($test)) {
                continue;
            }
            if (($test['contestStatus'] ?? '') !== 'COMPLETED') {
                continue;
            }
            $testId = (string) ($test['id'] ?? '');
            $participantCount = $this->contestParticipantCount($testId);
            $window = is_array($test['contestWindow'] ?? null) ? $test['contestWindow'] : [];
            $rows[] = array_merge($test, [
                'participantCount' => $participantCount,
                'contestStartAt' => $window['start'] ?? null,
                'contestEndAt' => $window['end'] ?? null,
            ]);
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['contestEndAt'] ?? ''), (string) ($a['contestEndAt'] ?? '')));

        return $rows;
    }

    /**
     * Admin preview of contest leaderboard before/after publishing.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function contestResultsPreview(array $admin, string $id): array
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        if (!AptitudeTestModel::isContest($test)) {
            Response::error('Contest results are available only for weekly or monthly contests.', 422);
        }
        if (AptitudeTestModel::contestStatus($test) !== 'COMPLETED') {
            Response::error('Results preview is available only after the contest has ended.', 422);
        }

        $participants = $this->contestLeaderboardRows($test);
        $window = AptitudeTestModel::contestWindow($test);
        $view = AptitudeTestModel::publicView($test, true);

        return [
            'contest' => array_merge($view, [
                'participantCount' => count($participants),
                'contestStartAt' => $window['start'] ?? null,
                'contestEndAt' => $window['end'] ?? null,
            ]),
            'participants' => $participants,
            'summary' => [
                'participantCount' => count($participants),
                'resultStatus' => AptitudeTestModel::resultStatus($test),
                'resultPublishedAt' => AptitudeTestModel::resultPublishedAt($test),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateContestSchedule(array $admin, string $id, array $patch): array
    {
        AptitudeAccessService::requireManager($admin);
        if (!AptitudeAccessService::canManageContests($admin)) {
            Response::forbidden('You cannot manage contest schedules.');
        }
        if (!Security::isValidId($id)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        if (!AptitudeTestModel::isContest($test)) {
            Response::error('Schedule can only be set for weekly or monthly contests.', 422);
        }
        $type = AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        $data = ['contestType' => $type];
        if ($type === 'weekly') {
            $data['contestWeekday'] = max(1, min(7, (int) ($patch['contestWeekday'] ?? $test['contestWeekday'] ?? 1)));
        } else {
            $data['contestMonthDay'] = max(1, min(28, (int) ($patch['contestMonthDay'] ?? $test['contestMonthDay'] ?? 1)));
        }
        if (!$this->tests->updateTest($id, $data)) {
            Response::error('Could not update contest schedule.', 500);
        }
        $fresh = $this->tests->findById($id) ?: $test;

        return AptitudeTestModel::publicView($fresh, true);
    }

    /**
     * @param array<string, mixed> $admin
     * @param string[] $bankIds
     * @return array<string, mixed>
     */
    public function addBankQuestionsToTest(array $admin, string $testId, array $bankIds): array
    {
        AptitudeAccessService::requireManager($admin);
        $test = $this->tests->findById($testId);
        if (!$test) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        $bank = new AptitudeQuestionBankModel();
        $rows = $bank->questionsByIds($bankIds);
        if ($rows === []) {
            Response::error('No bank questions found for the given ids.', 422);
        }
        return $this->tests->appendQuestions($testId, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBank(?string $category = null, ?string $difficulty = null): array
    {
        $bank = new AptitudeQuestionBankModel();
        $category = $category !== null && trim($category) !== '' ? trim($category) : null;
        $difficulty = $difficulty !== null && trim($difficulty) !== '' ? trim($difficulty) : null;

        return [
            'questions' => $bank->listQuestions($category, $difficulty, 1000),
            'summary' => $bank->countByDifficulty($category),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function myProgress(array $user): array
    {
        // Always use authenticated user id — never accept a client-supplied userId.
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ($userId === '' || !Security::isValidId($userId)) {
            Response::forbidden('Invalid session.');
        }
        $rows = $this->attempts->forUser($userId, 200);
        return $this->summarizeSubject($userId, $rows, true, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function progressForUserId(string $userId): array
    {
        if (!Security::isValidId($userId)) {
            Response::notFound('User not found.');
        }
        $rows = $this->attempts->forUser($userId, 200);
        return $this->summarizeSubject($userId, $rows, true);
    }

    /**
     * Directory of progress for staff / PO / admin with filters.
     * Scope is derived from the authenticated principal; client filters cannot expand it.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function directory(array $viewer, array $filters = []): array
    {
        AptitudeAccessService::requireDirectoryViewer($viewer);

        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($viewer);
        $filters = AptitudeAccessService::sanitizeDirectoryFilters($viewer, $filters);
        $dbFilter = AptitudeAccessService::completedAttemptsFilter($viewer);
        $resultType = trim((string) ($filters['resultType'] ?? ''));
        if ($dbFilter === null) {
            return $resultType === 'contests'
                ? $this->emptyContestDirectory($viewer)
                : $this->emptyDirectory($viewer);
        }
        if ($resultType === 'contests') {
            return $this->contestResultsDirectory($viewer, $filters, $dbFilter);
        }

        // Query only attempts in the viewer's authorized subject set (not a general dump).
        $completed = $this->attempts->completed(
            array_merge(
                array_diff_key($dbFilter, ['status' => true]),
                $this->attemptFiltersFromDirectoryFilters($filters)
            ),
            2000
        );

        $rows = $this->buildTestAttemptRows($viewer, $filters, $completed, $role);
        $scope = AptitudeAccessService::scopeInfo($viewer);
        $percentages = array_map(static fn ($r) => (float) ($r['percentage'] ?? 0), $rows);
        $studentIds = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['userId'] ?? '');
            if ($uid !== '') {
                $studentIds[$uid] = true;
            }
        }

        return [
            'view' => 'attempts',
            'rows' => $rows,
            'scope' => $scope,
            'summary' => [
                'attemptCount' => count($rows),
                'students' => count($studentIds),
                'avgPercentage' => $percentages === [] ? 0 : round(array_sum($percentages) / count($percentages), 1),
            ],
            'tests' => array_map(
                static fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'category' => $t['category']],
                $this->listPublished(false)
            ),
        ];
    }

    /**
     * One row per student/test with attempt count and final attempt score.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @param array<int, array<string, mixed>> $completed
     * @return array<int, array<string, mixed>>
     */
    private function buildTestAttemptRows(array $viewer, array $filters, array $completed, string $role): array
    {
        $contestTests = $this->contestTestIdSet();
        $testIds = [];
        $userIds = [];
        foreach ($completed as $attempt) {
            $testId = (string) ($attempt['testId'] ?? '');
            if ($testId === '' || isset($contestTests[$testId])) {
                continue;
            }
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            $testIds[$testId] = true;
            $userIds[$uid] = true;
        }

        $testCache = $this->tests->findByIds(array_keys($testIds));
        $profileCache = $this->batchDirectoryProfiles(array_keys($userIds));

        /** @var array<string, array<int, array<string, mixed>>> $attemptsByUserTest */
        $attemptsByUserTest = [];
        foreach ($completed as $attempt) {
            $testId = (string) ($attempt['testId'] ?? '');
            $uid = (string) ($attempt['userId'] ?? '');
            if ($testId === '' || $uid === '' || isset($contestTests[$testId])) {
                continue;
            }
            $key = $uid . '|' . $testId;
            $attemptsByUserTest[$key][] = $attempt;
        }
        foreach ($attemptsByUserTest as &$group) {
            usort($group, static function (array $a, array $b): int {
                $ta = strtotime((string) ($a['completedAt'] ?? $a['createdAt'] ?? '')) ?: 0;
                $tb = strtotime((string) ($b['completedAt'] ?? $b['createdAt'] ?? '')) ?: 0;

                return $ta <=> $tb;
            });
        }
        unset($group);

        $rows = [];
        foreach ($attemptsByUserTest as $key => $group) {
            if ($group === []) {
                continue;
            }
            $finalAttempt = $group[count($group) - 1];
            $testId = (string) ($finalAttempt['testId'] ?? '');
            $uid = (string) ($finalAttempt['userId'] ?? '');
            if ($testId === '' || $uid === '' || isset($contestTests[$testId])) {
                continue;
            }
            if (!AptitudeAccessService::canViewSubject($viewer, $uid)) {
                continue;
            }

            $profile = $profileCache[$uid] ?? $this->summarizeSubjectCached($uid, $group, null, false);
            if (in_array($role, ['staff', 'placement_officer'], true) && ($profile['userType'] ?? '') !== 'student') {
                continue;
            }
            if ($role !== 'admin' && ($profile['userType'] ?? '') === 'alumni') {
                continue;
            }
            if (!$this->matchesFilters($profile, $group, $filters)) {
                continue;
            }

            $test = $testCache[$testId] ?? $this->tests->findById($testId) ?: [];
            $attemptId = (string) ($finalAttempt['_id'] ?? '');
            $marksObtained = (float) ($finalAttempt['marksObtained'] ?? $finalAttempt['score'] ?? 0);
            $totalMarks = (float) ($finalAttempt['totalMarks'] ?? $test['totalMarks'] ?? 0);
            $percentage = (float) ($finalAttempt['percentage'] ?? 0);

            $rows[] = [
                'attemptId' => $attemptId,
                'userId' => $uid,
                'name' => (string) ($profile['name'] ?? 'User'),
                'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                'studentCode' => (string) ($profile['studentCode'] ?? $profile['registerNumber'] ?? ''),
                'classBatch' => (string) ($profile['classBatch'] ?? $finalAttempt['classBatch'] ?? ''),
                'testId' => $testId,
                'testTitle' => (string) ($test['title'] ?? 'Test'),
                'attemptCount' => count($group),
                'marksObtained' => $marksObtained,
                'totalMarks' => $totalMarks,
                'score' => $marksObtained,
                'percentage' => $percentage,
                'completedAt' => $finalAttempt['completedAt'] ?? null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['completedAt'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['completedAt'] ?? '')) ?: 0;
            if ($tb !== $ta) {
                return $tb <=> $ta;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     */
    private function filterAttemptsByResultType(array $attempts, string $resultType): array
    {
        $resultType = strtolower(trim($resultType));
        if ($resultType !== 'tests' && $resultType !== 'contests') {
            return $attempts;
        }

        $contestTests = $this->contestTestIdSet();

        return array_values(array_filter(
            $attempts,
            static function (array $attempt) use ($resultType, $contestTests): bool {
                $testId = (string) ($attempt['testId'] ?? '');
                if ($testId === '') {
                    return $resultType === 'tests';
                }
                $isContest = isset($contestTests[$testId]);

                return $resultType === 'contests' ? $isContest : !$isContest;
            }
        ));
    }

    /**
     * Filter dropdown values from local departments / students tables (scoped by RBAC).
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function progressFilterOptions(array $viewer, array $filters = []): array
    {
        AptitudeAccessService::requireDirectoryViewer($viewer);
        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($viewer);
        $filters = AptitudeAccessService::sanitizeDirectoryFilters($viewer, $filters);

        $departmentId = trim((string) ($filters['department'] ?? ''));
        $branch = trim((string) ($filters['course'] ?? ''));
        $finalYearOnly = $role === 'staff';

        $departments = $this->loadProgressDepartments($viewer, $role);
        $branchSet = [];
        $batchSet = [];

        $filterCtx = $this->progressPlacementFilterCtx($viewer, $role, $departmentId);
        if ($filterCtx !== null) {
            $filterSvc = new PlacementFilterService();
            foreach ($filterSvc->fetchProgramOptions($filterCtx) as $program) {
                $program = trim((string) $program);
                if ($program !== '') {
                    $branchSet[$program] = true;
                }
            }
            $batchSource = $branch !== '' ? $branch : '';
            foreach ($filterSvc->fetchBatchOptions($filterCtx, $batchSource, '', $finalYearOnly) as $batch) {
                $batch = trim((string) $batch);
                if ($batch !== '') {
                    $batchSet[$batch] = true;
                }
            }
        } else {
            foreach ($this->studentsForProgressFilters($viewer, $role, $filters) as $student) {
                $label = self::studentBranchLabelStatic($student);
                if ($label !== '') {
                    $branchSet[$label] = true;
                }
                $batch = StaffContext::studentClassBatch($student);
                if ($batch !== '') {
                    $batchSet[$batch] = true;
                }
            }
        }

        if ($role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            foreach (StaffContext::assignedClassBatches($ctx) as $assignedBatch) {
                $assignedBatch = trim((string) $assignedBatch);
                if ($assignedBatch !== '') {
                    $batchSet[$assignedBatch] = true;
                }
            }
        }

        $branchList = array_keys($branchSet);
        $batchList = array_keys($batchSet);
        sort($branchList, SORT_NATURAL | SORT_FLAG_CASE);
        sort($batchList, SORT_NATURAL | SORT_FLAG_CASE);

        if ($branch !== '' && $filterCtx !== null) {
            $batchList = (new PlacementFilterService())->fetchBatchOptions($filterCtx, $branch, '', $finalYearOnly);
        }

        $types = [
            ['value' => 'student', 'label' => 'Students'],
        ];
        if ($role === 'admin') {
            $types[] = ['value' => 'alumni', 'label' => 'Alumni'];
        }

        return [
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'departments' => $departments,
            'branches' => $branchList,
            'batches' => $batchList,
            'types' => $types,
        ];
    }

    /**
     * @return array{profile:array<string,mixed>,departmentId:string,department:array<string,mixed>|null}|null
     */
    private function progressPlacementFilterCtx(array $viewer, string $role, string $departmentId): ?array
    {
        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($viewer);
            if (empty($ctx['departmentId'])) {
                return null;
            }
            $dept = is_array($ctx['department'] ?? null)
                ? $ctx['department']
                : (new DepartmentModel())->findById((string) $ctx['departmentId']);

            return [
                'profile' => is_array($ctx['profile'] ?? null) ? $ctx['profile'] : [],
                'departmentId' => (string) $ctx['departmentId'],
                'department' => is_array($dept) ? $dept : null,
            ];
        }

        if ($role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            if (empty($ctx['departmentId'])) {
                return null;
            }
            $dept = is_array($ctx['department'] ?? null)
                ? $ctx['department']
                : (new DepartmentModel())->findById((string) $ctx['departmentId']);

            return [
                'profile' => is_array($ctx['profile'] ?? null) ? $ctx['profile'] : [],
                'departmentId' => (string) $ctx['departmentId'],
                'department' => is_array($dept) ? $dept : null,
            ];
        }

        if ($role === 'admin' && $departmentId !== '') {
            $dept = (new DepartmentModel())->findById($departmentId);

            return [
                'profile' => [],
                'departmentId' => $departmentId,
                'department' => is_array($dept) ? $dept : null,
            ];
        }

        return null;
    }

    private function idsEqual(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }
        $leftNorm = (string) (Security::toObjectId($left) ?: $left);
        $rightNorm = (string) (Security::toObjectId($right) ?: $right);

        return strcasecmp($leftNorm, $rightNorm) === 0;
    }

    /**
     * @return array<int, array{id:string,code:string,name:string}>
     */
    private function loadProgressDepartments(array $viewer, string $role): array
    {
        if ($role === 'admin') {
            $rows = [];
            $seen = [];
            foreach ((new DepartmentModel())->findAll([], 300) as $dept) {
                $code = strtoupper(trim((string) ($dept['code'] ?? '')));
                $name = trim((string) ($dept['name'] ?? ''));
                $id = (string) ($dept['_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                if ($name === '' && $code === '') {
                    continue;
                }
                if (!DepartmentModel::isStudentAcademicDepartment($code, $name)
                    && !preg_match('/^(MCA|BCA|BTECH|MTECH|CSE|ECE|ME|CE|EEE|AI|CS|INMCA)/i', $code . ' ' . $name)) {
                    continue;
                }
                $seen[$id] = true;
                $rows[] = [
                    'id' => $id,
                    'code' => $code,
                    'name' => $name !== '' ? $name : $code,
                ];
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $rows;
        }

        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($viewer);
            $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : null;
            if (!$dept && !empty($ctx['departmentId'])) {
                $dept = (new DepartmentModel())->findById((string) $ctx['departmentId']);
            }
            if (!$dept) {
                $fallbackName = AptitudeAccessService::departmentDisplayName(
                    (string) ($ctx['departmentId'] ?? ''),
                    $viewer
                );
                if (empty($ctx['departmentId']) && $fallbackName === '') {
                    return [];
                }

                return [[
                    'id' => (string) ($ctx['departmentId'] ?? ''),
                    'code' => '',
                    'name' => $fallbackName !== '' ? $fallbackName : 'Department',
                ]];
            }

            return [[
                'id' => (string) ($dept['_id'] ?? $ctx['departmentId'] ?? ''),
                'code' => strtoupper(trim((string) ($dept['code'] ?? ''))),
                'name' => trim((string) ($dept['name'] ?? '')) ?: AptitudeAccessService::departmentDisplayName((string) ($ctx['departmentId'] ?? ''), $viewer),
            ]];
        }

        if ($role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : null;
            if (!$dept && !empty($ctx['departmentId'])) {
                $dept = (new DepartmentModel())->findById((string) $ctx['departmentId']);
            }
            if (!$dept) {
                $fallbackName = AptitudeAccessService::departmentDisplayName(
                    (string) ($ctx['departmentId'] ?? ''),
                    $viewer
                );
                if (empty($ctx['departmentId']) && $fallbackName === '') {
                    return [];
                }

                return [[
                    'id' => (string) ($ctx['departmentId'] ?? ''),
                    'code' => '',
                    'name' => $fallbackName !== '' ? $fallbackName : 'Department',
                ]];
            }

            return [[
                'id' => (string) ($dept['_id'] ?? $ctx['departmentId'] ?? ''),
                'code' => strtoupper(trim((string) ($dept['code'] ?? ''))),
                'name' => trim((string) ($dept['name'] ?? '')) ?: AptitudeAccessService::departmentDisplayName((string) ($ctx['departmentId'] ?? ''), $viewer),
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function studentsForProgressFilters(array $viewer, string $role, array $filters): array
    {
        $students = $this->studentsInViewerScope($viewer, $role);
        $departmentId = trim((string) ($filters['department'] ?? ''));
        $branch = trim((string) ($filters['course'] ?? ''));
        $batch = trim((string) ($filters['class'] ?? $filters['classBatch'] ?? ''));

        return array_values(array_filter($students, function (array $student) use ($departmentId, $branch, $batch): bool {
            if ($departmentId !== '' && !$this->idsEqual((string) ($student['departmentId'] ?? ''), $departmentId)) {
                return false;
            }
            if ($branch !== '') {
                $studentBranch = self::studentBranchLabelStatic($student);
                if (strcasecmp($studentBranch, $branch) !== 0
                    && strcasecmp(
                        DepartmentProgrammeCatalog::resolveProgrammeCode($studentBranch),
                        DepartmentProgrammeCatalog::resolveProgrammeCode($branch)
                    ) !== 0) {
                    return false;
                }
            }
            if ($batch !== '') {
                $studentBatch = StaffContext::studentClassBatch($student);
                if (strcasecmp($studentBatch, $batch) !== 0
                    && strcasecmp(
                        DepartmentProgrammeCatalog::normalizeCode($studentBatch),
                        DepartmentProgrammeCatalog::normalizeCode($batch)
                    ) !== 0) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function studentsInViewerScope(array $viewer, string $role): array
    {
        if ($role === 'admin') {
            return (new StudentModel())->findAll([], 5000);
        }

        if ($role === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($viewer);
            if (empty($ctx['departmentId'])) {
                return [];
            }

            return (new StudentModel())->findAll(PlacementOfficerContext::studentCollectionFilter($ctx), 5000);
        }

        if ($role === 'staff') {
            $ctx = StaffContext::resolve($viewer);
            if (StaffContext::assignedClassBatches($ctx) === [] || empty($ctx['departmentId'])) {
                return [];
            }
            $students = (new StudentModel())->findAll(StaffContext::studentCollectionFilter($ctx), 5000);

            return array_values(array_filter(
                $students,
                static fn (array $student): bool => StaffContext::studentMatchesScope($student, $ctx)
            ));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $student
     */
    private function studentBranchLabel(array $student): string
    {
        return self::studentBranchLabelStatic($student);
    }

    /**
     * @param array<string, mixed> $student
     */
    private static function studentProgrammeLabelStatic(array $student): string
    {
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $candidates = [
            $student['stud_course'] ?? '',
            $academic['course'] ?? '',
            $student['programme'] ?? '',
            $student['course'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        $batch = StaffContext::studentClassBatch($student);
        if ($batch !== '') {
            $code = DepartmentProgrammeCatalog::resolveProgrammeCode($batch);
            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    private static function studentBranchLabelStatic(array $student): string
    {
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $candidates = [
            $student['stud_branch'] ?? '',
            $student['branchName'] ?? '',
            $student['branch_name'] ?? '',
            $academic['branch'] ?? '',
            $personal['course'] ?? '',
            $student['stud_course'] ?? '',
            $academic['course'] ?? '',
            $student['course'] ?? '',
            $student['programme'] ?? '',
        ];
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        $batch = StaffContext::studentClassBatch($student);
        if ($batch === '') {
            return '';
        }

        $code = DepartmentProgrammeCatalog::resolveProgrammeCode($batch);
        if ($code !== '') {
            return $code;
        }

        $norm = DepartmentProgrammeCatalog::normalizeCode($batch);
        if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
            return 'INMCA';
        }
        if (str_starts_with($norm, 'MCA')) {
            return 'MCA';
        }
        if (str_contains($norm, 'BCA')) {
            return 'BCA';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    private function emptyDirectory(array $viewer): array
    {
        return [
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'summary' => [
                'subjects' => 0,
                'students' => 0,
                'avgPercentage' => 0,
                'avgBestScore' => 0,
                'highestBestScore' => 0,
                'totalAttempts' => 0,
                'withAttempts' => 0,
            ],
            'tests' => array_map(
                static fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'category' => $t['category']],
                $this->listPublished(false)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContestDirectory(array $viewer): array
    {
        return [
            'view' => 'contests',
            'contests' => [],
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'summary' => [
                'contestCount' => 0,
                'totalParticipants' => 0,
                'uniqueParticipants' => 0,
                'avgPercentage' => 0,
                'highestScore' => 0,
            ],
        ];
    }

    /**
     * Contest leaderboard grouped by test with participant scores and details.
     *
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $dbFilter
     * @return array<string, mixed>
     */
    private function contestResultsDirectory(array $viewer, array $filters, array $dbFilter): array
    {
        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($viewer);
        $contestTestIds = array_keys($this->contestTestIdSet());
        if ($contestTestIds === []) {
            return $this->emptyContestDirectory($viewer);
        }

        $testOids = [];
        foreach ($contestTestIds as $testId) {
            $oid = Security::toObjectId($testId);
            if ($oid !== null) {
                $testOids[] = $oid;
            }
        }
        if ($testOids === []) {
            return $this->emptyContestDirectory($viewer);
        }

        $completed = $this->attempts->completed(
            array_merge(
                array_diff_key($dbFilter, ['status' => true]),
                $this->attemptFiltersFromDirectoryFilters($filters),
                ['testId' => ['$in' => $testOids]]
            ),
            2000
        );

        $testCache = $this->tests->findByIds($contestTestIds);
        $userIds = [];
        foreach ($completed as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
        }
        $userCache = $this->batchDirectoryProfiles(array_keys($userIds));

        /** @var array<string, array<int, array<string, mixed>>> $byTest */
        $byTest = [];

        foreach ($completed as $attempt) {
            if (($attempt['status'] ?? '') !== 'completed') {
                continue;
            }
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid === '' || !AptitudeAccessService::canViewSubject($viewer, $uid)) {
                continue;
            }

            $testId = (string) ($attempt['testId'] ?? '');
            if ($testId === '') {
                continue;
            }

            $test = $testCache[$testId] ?? null;
            if (!$test) {
                continue;
            }

            $profile = $userCache[$uid] ?? $this->summarizeSubjectCached($uid, [$attempt], null, false);

            if (in_array($role, ['staff', 'placement_officer'], true) && ($profile['userType'] ?? '') !== 'student') {
                continue;
            }
            if ($role !== 'admin' && ($profile['userType'] ?? '') === 'alumni') {
                continue;
            }
            if (!$this->matchesFilters($profile, [$attempt], $filters)) {
                continue;
            }

            $byTest[$testId][] = $this->contestParticipantRow($attempt, $test, $profile);
        }

        $contests = [];
        $allParticipants = [];
        $uniqueUsers = [];

        foreach ($byTest as $testId => $participants) {
            $test = $testCache[$testId] ?? [];
            usort($participants, static function (array $a, array $b): int {
                $pa = (float) ($a['percentage'] ?? 0);
                $pb = (float) ($b['percentage'] ?? 0);
                if ($pb !== $pa) {
                    return $pb <=> $pa;
                }
                $ta = (int) ($a['timeTakenSeconds'] ?? PHP_INT_MAX);
                $tb = (int) ($b['timeTakenSeconds'] ?? PHP_INT_MAX);
                return $ta <=> $tb;
            });
            foreach ($participants as $i => &$participant) {
                $participant['rank'] = $i + 1;
                $allParticipants[] = $participant;
                $uniqueUsers[(string) ($participant['userId'] ?? '')] = true;
            }
            unset($participant);

            $window = AptitudeTestModel::contestWindow($test);
            $contests[] = $this->contestDirectoryEntry($test, $testId, $participants, $window);
        }

        $contests = $this->mergeCompletedContestsIntoDirectory($viewer, $contests, $testCache);

        usort($contests, static fn (array $a, array $b): int => strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));

        $percentages = array_map(static fn (array $p): float => (float) ($p['percentage'] ?? 0), $allParticipants);
        $publishedCount = count(array_filter(
            $contests,
            static fn (array $c): bool => AptitudeTestModel::resultsPublished($c)
                || strtoupper((string) ($c['resultStatus'] ?? '')) === 'PUBLISHED'
        ));

        return [
            'view' => 'contests',
            'contests' => $contests,
            'completedContests' => $contests,
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'summary' => [
                'contestCount' => count($contests),
                'publishedCount' => $publishedCount,
                'pendingCount' => max(0, count($contests) - $publishedCount),
                'totalParticipants' => count($allParticipants),
                'uniqueParticipants' => count($uniqueUsers),
                'avgPercentage' => $percentages === [] ? 0 : round(array_sum($percentages) / count($percentages), 1),
                'highestScore' => $percentages === [] ? 0 : max($percentages),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @param array<int, array<string, mixed>> $participants
     * @param array{start:?string,end:?string} $window
     * @return array<string, mixed>
     */
    private function contestDirectoryEntry(array $test, string $testId, array $participants, array $window): array
    {
        return [
            'testId' => $testId,
            'id' => $testId,
            'title' => (string) ($test['title'] ?? 'Contest'),
            'category' => (string) ($test['category'] ?? ''),
            'contestType' => AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none')),
            'contestScheduleLabel' => AptitudeTestModel::contestScheduleLabel($test),
            'contestStatus' => AptitudeTestModel::contestStatus($test),
            'contestStartAt' => $window['start'] ?? null,
            'contestEndAt' => $window['end'] ?? null,
            'resultsPublished' => AptitudeTestModel::resultsPublished($test),
            'resultStatus' => AptitudeTestModel::resultStatus($test),
            'resultPublishedAt' => AptitudeTestModel::resultPublishedAt($test),
            'participantCount' => count($participants),
            'participants' => $participants,
        ];
    }

    /**
     * Ensure every completed contest appears even when filters yield zero attempts.
     *
     * @param array<string, mixed> $viewer
     * @param array<int, array<string, mixed>> $contests
     * @param array<string, array<string, mixed>> $testCache
     * @return array<int, array<string, mixed>>
     */
    private function mergeCompletedContestsIntoDirectory(array $viewer, array $contests, array $testCache): array
    {
        $byId = [];
        foreach ($contests as $contest) {
            $id = (string) ($contest['testId'] ?? $contest['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $contest;
            }
        }

        foreach ($this->listCompletedContests($viewer) as $completed) {
            $id = (string) ($completed['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (isset($byId[$id])) {
                $byId[$id] = array_merge($completed, [
                    'participants' => $byId[$id]['participants'] ?? [],
                    'participantCount' => (int) ($byId[$id]['participantCount'] ?? count($byId[$id]['participants'] ?? [])),
                ]);
                continue;
            }
            $test = $testCache[$id] ?? $this->tests->findById($id) ?: [];
            $window = AptitudeTestModel::contestWindow($test);
            $byId[$id] = $this->contestDirectoryEntry($test, $id, [], $window);
            if ($completed !== []) {
                $byId[$id] = array_merge($byId[$id], $completed, [
                    'participants' => [],
                    'participantCount' => (int) ($completed['participantCount'] ?? 0),
                ]);
            }
        }

        return array_values($byId);
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $test
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function contestParticipantRow(array $attempt, array $test, array $profile): array
    {
        $pub = $this->publicAttempt(
            $attempt,
            (string) ($test['title'] ?? ''),
            (string) ($test['category'] ?? '')
        );

        return array_merge($pub, [
            'userId' => (string) ($profile['userId'] ?? $attempt['userId'] ?? ''),
            'name' => (string) ($profile['name'] ?? 'User'),
            'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
            'studentCode' => (string) ($profile['studentCode'] ?? $profile['registerNumber'] ?? ''),
            'classBatch' => (string) ($profile['classBatch'] ?? $attempt['classBatch'] ?? ''),
            'course' => (string) ($profile['course'] ?? $attempt['course'] ?? ''),
            'rank' => isset($attempt['rank']) ? (int) $attempt['rank'] : null,
            'percentile' => $attempt['percentile'] ?? null,
            'contestType' => AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none')),
        ]);
    }

    /**
     * Compare two or more subjects (must all be in viewer scope).
     *
     * @param array<string, mixed> $viewer
     * @param string[] $userIds
     * @return array<string, mixed>
     */
    public function compare(array $viewer, array $userIds): array
    {
        AptitudeAccessService::requireDirectoryViewer($viewer);
        $ids = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if (count($ids) < 2) {
            Response::error('Select at least two students to compare.', 422);
        }
        if (count($ids) > 6) {
            Response::error('Compare up to 6 students at a time.', 422);
        }
        $subjects = [];
        foreach ($ids as $uid) {
            AptitudeAccessService::requireCanViewSubject($viewer, $uid);
            $subjects[] = $this->progressForUserId($uid);
        }
        return [
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'subjects' => $subjects,
        ];
    }

    /**
     * Company-scoped aptitude for one eligible student.
     * Company identity comes from the authenticated session; studentId is verified via applications.
     *
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    public function companyStudentProgress(array $viewer, string $studentId): array
    {
        if (!Security::isValidId($studentId)) {
            Response::notFound('Student not found.');
        }
        if (AptitudeAccessService::companyForUser($viewer) === null) {
            Response::forbidden('Company account required.');
        }
        if (!AptitudeAccessService::companyCanViewStudent($viewer, $studentId)) {
            Response::forbidden('Aptitude progress is only available for your applicants.');
        }
        $student = (new StudentModel())->findById($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        $userId = (string) ($student['userId'] ?? '');
        if ($userId === '') {
            Response::notFound('Student account not found.');
        }
        $rows = $this->attempts->forUser($userId, 200);
        $summary = $this->summarizeSubject($userId, $rows, true);
        $user = (new UserModel())->findById($userId) ?: [];
        return [
            'profile' => [
                'name' => (string) ($user['name'] ?? $student['name'] ?? ''),
                'registerNumber' => (string) ($student['registerNumber'] ?? ''),
                'department' => AptitudeAccessService::departmentDisplayName(
                    (string) ($student['departmentId'] ?? ''),
                    $student
                ),
                'cgpa' => $student['academic']['cgpa'] ?? $student['cgpa'] ?? null,
                'batch' => (string) ($student['batch'] ?? $student['academic']['batch'] ?? ''),
                'course' => (string) ($student['academic']['course'] ?? $student['course'] ?? ''),
            ],
            'aptitude' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function createTest(array $admin, array $data): array
    {
        AptitudeAccessService::requireManager($admin);
        $data = AptitudeAccessService::applyTestDepartmentScope($admin, $data);
        $data = AptitudeAccessService::sanitizeContestFields($admin, $data);
        $data = $this->requireContestBankSource($data);
        $data = $this->resolveTestQuestions($data);
        $id = $this->tests->createTest(array_merge($data, [
            'createdBy' => (string) ($admin['_id'] ?? $admin['id'] ?? ''),
        ]));
        $doc = $this->tests->findById($id);
        return AptitudeTestModel::publicView($doc ?: [], true);
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTest(array $admin, string $id, array $data): array
    {
        AptitudeAccessService::requireManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid aptitude test id.', 400);
        }
        $existing = $this->tests->findById($id);
        if (!$existing) {
            Response::notFound('Aptitude test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $existing);
        $data = AptitudeAccessService::sanitizeTestUpdate($admin, $data);
        $merged = array_merge($existing, $data);
        if (AptitudeTestModel::isContest($merged)) {
            $data = $this->requireContestBankSource($merged);
        }
        $data = $this->resolveTestQuestions($data);
        $this->tests->updateTest($id, $data);
        $doc = $this->tests->findById($id);
        return AptitudeTestModel::publicView($doc ?: [], true);
    }

    /**
     * Contests always draw from the question bank via random rules.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requireContestBankSource(array $data): array
    {
        if (!AptitudeTestModel::isContest($data)) {
            return $data;
        }
        $data['questionSource'] = 'random';
        $rules = array_values(array_filter((array) ($data['randomRules'] ?? []), 'is_array'));
        if ($rules === []) {
            Response::error('Contest questions must be generated from the question bank. Add at least one category and difficulty rule.', 422);
        }
        $data['randomRules'] = $rules;
        $data['bankQuestionIds'] = [];

        return $data;
    }

    /**
     * @param array<string, mixed> $test
     * @return array<int, array<string, mixed>>
     */
    private function contestQuestionsFromBank(array $test): array
    {
        $bank = new AptitudeQuestionBankModel();
        $rules = array_values(array_filter((array) ($test['randomRules'] ?? []), 'is_array'));
        if ($rules !== []) {
            return $bank->pickRandomByRules($rules);
        }
        $ids = array_values(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            (array) ($test['bankQuestionIds'] ?? [])
        )));
        if ($ids !== []) {
            return $bank->questionsByIds($ids);
        }

        return AptitudeTestModel::normalizedQuestions($test);
    }

    /**
     * @param array<string, mixed> $test
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    private function examViewFromQuestions(array $test, array $questions): array
    {
        return AptitudeTestModel::publicView(array_merge($test, [
            'questions' => $questions,
            'questionCount' => count($questions),
        ]), false);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<int, array<string, mixed>>|null
     */
    private function attemptExamQuestions(array $attempt): ?array
    {
        $rows = array_values(array_filter((array) ($attempt['examQuestions'] ?? []), 'is_array'));
        foreach ($rows as $q) {
            if (array_key_exists('correctIndex', $q) || array_key_exists('explanation', $q)) {
                return $rows;
            }
        }

        return null;
    }

    /**
     * Resolve inline, bank-picked, or random-bank questions before persisting a test.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveTestQuestions(array $data): array
    {
        $source = strtolower(trim((string) ($data['questionSource'] ?? 'manual'))) === 'random'
            ? 'random'
            : 'manual';
        $data['questionSource'] = $source;

        if ($source === 'random') {
            $rules = array_values(array_filter((array) ($data['randomRules'] ?? []), 'is_array'));
            if ($rules === []) {
                Response::error('Add at least one category + difficulty rule for random selection.', 422);
            }
            $expected = max(0, (int) ($data['questionCount'] ?? 0));
            $ruleTotal = array_sum(array_map(static fn (array $r): int => max(0, (int) ($r['count'] ?? 0)), $rules));
            if ($expected > 0 && $ruleTotal > 0 && $expected !== $ruleTotal) {
                Response::error('Total questions must match the sum of random rule counts.', 422);
            }
            try {
                $questions = (new AptitudeQuestionBankModel())->pickRandomByRules($rules);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            if ($questions === []) {
                Response::error('Could not pick questions from the bank for the given rules.', 422);
            }
            $data['questions'] = $questions;
            $data['questionCount'] = count($questions);
            $data['bankQuestionIds'] = [];
            $firstCategory = (string) ($rules[0]['category'] ?? '');
            if ($firstCategory !== '') {
                $data['category'] = $firstCategory;
            }
            if (!empty($rules[0]['difficulty'])) {
                $data['difficulty'] = (string) $rules[0]['difficulty'];
            }
            return $data;
        }

        $bankIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => trim((string) $id), (array) ($data['bankQuestionIds'] ?? [])),
            static fn ($id) => $id !== '' && Security::isValidId($id)
        )));
        $inline = array_values(array_filter((array) ($data['questions'] ?? []), 'is_array'));
        $filterRules = array_values(array_filter((array) ($data['bankFilterRules'] ?? []), 'is_array'));
        $questions = [];
        $bank = new AptitudeQuestionBankModel();

        if ($source === 'manual' && $filterRules !== []) {
            try {
                $questions = $bank->resolveByRulesWithPreferred($filterRules, $bankIds);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            $bankIds = array_values(array_filter(array_map(
                static fn (array $q): string => trim((string) ($q['bankId'] ?? '')),
                $questions
            )));
        } elseif ($bankIds !== []) {
            $questions = $bank->questionsByIds($bankIds);
        }
        foreach ($inline as $i => $q) {
            $norm = AptitudeTestModel::normalizeMcq(
                $q,
                (string) ($q['category'] ?? $data['category'] ?? 'General Aptitude'),
                count($questions) + (int) $i
            );
            if ($norm !== null) {
                $questions[] = $norm;
            }
        }

        if ($questions === []) {
            Response::error('Select questions from the bank or add at least one MCQ.', 422);
        }

        $expected = max(0, (int) ($data['questionCount'] ?? 0));
        if ($expected > 0 && count($questions) !== $expected) {
            Response::error('Total questions must match selected bank questions and manual MCQs.', 422);
        }

        $data['questions'] = $questions;
        $data['questionCount'] = count($questions);
        $data['bankQuestionIds'] = $bankIds;
        $data['randomRules'] = [];
        if (trim((string) ($data['category'] ?? '')) === '' && $questions !== []) {
            $data['category'] = (string) ($questions[0]['category'] ?? 'General Aptitude');
        }
        if (trim((string) ($data['difficulty'] ?? '')) === '' && $questions !== []) {
            $data['difficulty'] = (string) ($questions[0]['difficulty'] ?? 'Medium');
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function uploadRichTextImage(array $user): array
    {
        AptitudeAccessService::requireManager($user);
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            Response::error('No image uploaded.', 422);
        }
        $config = require dirname(__DIR__) . '/config/app.php';
        $error = Security::validateUploadedFile(
            $_FILES['image'],
            2 * 1024 * 1024,
            Security::allowedPhotoExtensions()
        );
        if ($error) {
            Response::error($error, 400);
        }
        $ext = strtolower(pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        $userId = preg_replace('/[^a-z0-9]/i', '', (string) ($user['_id'] ?? $user['id'] ?? 'user'));
        $hintName = 'apt_' . ($userId !== '' ? $userId : 'user') . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_APTITUDE_IMAGES,
                $hintName,
                $_FILES['image']
            );
        } catch (\Throwable) {
            Response::error('Failed to save image.', 500);
        }
        $filename = $storage->storedNameFromUri($path);

        return ['url' => $storage->mediaUrl(ObjectStorageService::FOLDER_APTITUDE_IMAGES, $filename)];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    private function resultVisibilityForUser(array $user, array $test): string
    {
        if (!AptitudeTestModel::isContest($test)) {
            return 'full';
        }
        if (AptitudeAccessService::canManage($user) || AptitudeAccessService::canViewDirectory($user)) {
            return 'full';
        }

        return AptitudeTestModel::resultsPublished($test) ? 'published' : 'pending';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyResultVisibility(array $payload, string $mode): array
    {
        $payload['resultVisibility'] = $mode;
        if ($mode === 'full') {
            return $payload;
        }
        $payload['questionAnalysis'] = [];
        $payload['categoryScores'] = [];
        if ($mode === 'pending') {
            $payload['score'] = null;
            $payload['marksObtained'] = null;
            $payload['percentage'] = null;
            $payload['accuracy'] = null;
            $payload['correctAnswers'] = null;
            $payload['correctCount'] = null;
            $payload['incorrectAnswers'] = null;
            $payload['wrongCount'] = null;
            $payload['unansweredQuestions'] = null;
            $payload['unansweredCount'] = null;
            $payload['rank'] = null;
            $payload['percentile'] = null;
            $payload['resultPublishedAt'] = null;
            $payload['message'] = 'The contest has ended. The result will be available after the administrator publishes it.';
        }
        if ($mode === 'published') {
            $payload['percentile'] = null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $test
     * @param array<string, mixed>|null $viewer
     * @return array<string, mixed>
     */
    private function buildResultPayload(array $attempt, array $test, ?array $viewer = null): array
    {
        $base = $this->publicAttempt(
            $attempt,
            (string) ($test['title'] ?? ''),
            (string) ($test['category'] ?? ''),
            $viewer
        );
        $durationSec = max(60, (int) ($test['durationMinutes'] ?? 30) * 60);
        $timeTaken = (int) ($attempt['timeTakenSeconds'] ?? 0);
        if ($timeTaken <= 0) {
            $started = $this->parseTime($attempt['startedAt'] ?? null);
            $ended = $this->parseTime($attempt['completedAt'] ?? null);
            if ($started > 0 && $ended > $started) {
                $timeTaken = $ended - $started;
            }
        }

        $payload = array_merge($base, [
            'testName' => (string) ($test['title'] ?? ''),
            'maximumScore' => (float) ($attempt['totalMarks'] ?? $test['totalMarks'] ?? 0),
            'score' => (float) ($attempt['marksObtained'] ?? $attempt['score'] ?? 0),
            'correctAnswers' => (int) ($attempt['correctCount'] ?? 0),
            'incorrectAnswers' => (int) ($attempt['wrongCount'] ?? 0),
            'unansweredQuestions' => (int) ($attempt['unansweredCount'] ?? 0),
            'timeTakenSeconds' => $timeTaken,
            'timeTakenLabel' => $this->formatDuration($timeTaken),
            'durationSeconds' => $durationSec,
            'rank' => $attempt['rank'] ?? null,
            'percentile' => $attempt['percentile'] ?? null,
            'autoSubmitted' => !empty($attempt['autoSubmitted']),
            'questionAnalysis' => $this->withQuestionExplanations(
                array_values((array) ($attempt['questionAnalysis'] ?? [])),
                $test
            ),
            'negativeMarking' => filter_var($test['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'negativeMarks' => (float) ($test['negativeMarks'] ?? 0),
            'resultsPublished' => AptitudeTestModel::resultsPublished($test),
            'resultStatus' => AptitudeTestModel::resultStatus($test),
            'resultPublishedAt' => AptitudeTestModel::resultPublishedAt($test),
            'contestStatus' => AptitudeTestModel::contestStatus($test),
        ]);

        $mode = $viewer ? $this->resultVisibilityForUser($viewer, $test) : 'full';

        return $this->applyResultVisibility($payload, $mode);
    }

    /**
     * Fill missing analysis explanations from the test, or a correct-option fallback.
     *
     * @param array<int, mixed> $analysis
     * @param array<string, mixed> $test
     * @return array<int, array<string, mixed>>
     */
    private function withQuestionExplanations(array $analysis, array $test): array
    {
        $questions = AptitudeTestModel::normalizedQuestions($test);
        $byId = [];
        foreach ($questions as $i => $q) {
            $qid = (string) ($q['id'] ?? '');
            if ($qid !== '') {
                $byId[$qid] = $q;
            }
            $bankId = trim((string) ($q['bankId'] ?? ''));
            if ($bankId !== '') {
                $byId[$bankId] = $q;
            }
            $byId['#' . $i] = $q;
        }
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        $out = [];
        foreach ($analysis as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $exp = $this->explanationText($row['explanation'] ?? $row['solution'] ?? '');
            if ($exp === '') {
                $qid = (string) ($row['id'] ?? $row['questionId'] ?? '');
                $q = ($qid !== '' && isset($byId[$qid])) ? $byId[$qid] : ($questions[$i] ?? null);
                $exp = is_array($q) ? $this->explanationText($q['explanation'] ?? $q['solution'] ?? '') : '';
            }
            if ($exp === '') {
                $idx = isset($row['correctAnswerIndex']) && is_numeric($row['correctAnswerIndex'])
                    ? (int) $row['correctAnswerIndex']
                    : null;
                $ans = trim((string) ($row['correctAnswer'] ?? ''));
                $letter = $idx !== null && isset($letters[$idx]) ? $letters[$idx] : '';
                if ($letter !== '' && $ans !== '') {
                    $exp = 'The correct option is ' . $letter . '. ' . $ans . '.';
                } elseif ($ans !== '') {
                    $exp = 'The correct answer is ' . $ans . '.';
                }
            }
            $row['explanation'] = $exp;
            $out[] = $row;
        }

        return $out;
    }

    private function explanationText(mixed $value): string
    {
        $html = trim((string) $value);
        if ($html === '') {
            return '';
        }
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? '' : $html;
    }

    private function contestParticipantCount(string $testId): int
    {
        if ($testId === '') {
            return 0;
        }
        $oid = Security::toObjectId($testId);
        if ($oid === null) {
            return 0;
        }

        return count($this->attempts->completed(['testId' => $oid], 5000));
    }

    /**
     * @param array<string, mixed> $test
     * @return array<int, array<string, mixed>>
     */
    private function contestLeaderboardRows(array $test): array
    {
        $testId = (string) ($test['_id'] ?? '');
        $oid = Security::toObjectId($testId);
        if ($oid === null) {
            return [];
        }
        $attempts = $this->attempts->completed(['testId' => $oid], 5000);
        if ($attempts === []) {
            return [];
        }

        $userIds = [];
        foreach ($attempts as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
        }
        $profiles = $this->batchDirectoryProfiles(array_keys($userIds));
        $rows = [];
        foreach ($attempts as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            $profile = $profiles[$uid] ?? $this->summarizeSubjectCached($uid, [$attempt], null, false);
            $rows[] = $this->contestParticipantRow($attempt, $test, $profile);
        }

        usort($rows, static function (array $a, array $b): int {
            $sa = (float) ($a['marksObtained'] ?? $a['score'] ?? 0);
            $sb = (float) ($b['marksObtained'] ?? $b['score'] ?? 0);
            if ($sb !== $sa) {
                return $sb <=> $sa;
            }
            $ta = (int) ($a['timeTakenSeconds'] ?? PHP_INT_MAX);
            $tb = (int) ($b['timeTakenSeconds'] ?? PHP_INT_MAX);

            return $ta <=> $tb;
        });
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $rows;
    }

    private function recomputeContestRanks(string $testId): void
    {
        $test = $this->tests->findById($testId);
        if (!$test || !AptitudeTestModel::isContest($test)) {
            return;
        }
        $oid = Security::toObjectId($testId);
        if ($oid === null) {
            return;
        }
        $attempts = $this->attempts->completed(['testId' => $oid], 5000);
        if ($attempts === []) {
            return;
        }

        usort($attempts, static function (array $a, array $b): int {
            $sa = (float) ($a['marksObtained'] ?? $a['score'] ?? 0);
            $sb = (float) ($b['marksObtained'] ?? $b['score'] ?? 0);
            if ($sb !== $sa) {
                return $sb <=> $sa;
            }
            $ta = (int) ($a['timeTakenSeconds'] ?? PHP_INT_MAX);
            $tb = (int) ($b['timeTakenSeconds'] ?? PHP_INT_MAX);

            return $ta <=> $tb;
        });

        $n = count($attempts);
        foreach ($attempts as $i => $attempt) {
            $attemptId = (string) ($attempt['_id'] ?? '');
            if ($attemptId === '') {
                continue;
            }
            $rank = $i + 1;
            $better = $i;
            $percentile = $n > 0 ? round(($better / $n) * 100, 1) : null;
            $this->attempts->update($attemptId, [
                'rank' => $rank,
                'percentile' => $percentile,
            ]);
        }
    }

    /**
     * @return array{rank:?int,percentile:?float}
     */
    private function computeRank(string $testId, float $percentage): array
    {
        if ($testId === '') {
            return ['rank' => null, 'percentile' => null];
        }
        $oid = Security::toObjectId($testId);
        if ($oid === null) {
            return ['rank' => null, 'percentile' => null];
        }
        $peers = $this->attempts->completed(['testId' => $oid], 2000);
        if ($peers === []) {
            return ['rank' => 1, 'percentile' => 100.0];
        }
        $scores = [];
        foreach ($peers as $p) {
            $scores[] = (float) ($p['percentage'] ?? 0);
        }
        rsort($scores, SORT_NUMERIC);
        $rank = 1;
        foreach ($scores as $i => $s) {
            if ($percentage >= $s) {
                $rank = $i + 1;
                break;
            }
            $rank = $i + 2;
        }
        $n = count($scores);
        $better = 0;
        foreach ($scores as $s) {
            if ($percentage > $s) {
                $better++;
            }
        }
        $percentile = $n > 0 ? round(($better / $n) * 100, 1) : null;
        return ['rank' => $rank, 'percentile' => $percentile];
    }

    private function parseTime(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_array($value) && isset($value['$date'])) {
            $value = $value['$date'];
        }
        $ts = strtotime((string) $value);
        return $ts === false ? 0 : $ts;
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m >= 60) {
            $h = intdiv($m, 60);
            $m = $m % 60;
            return sprintf('%dh %02dm %02ds', $h, $m, $s);
        }
        return sprintf('%dm %02ds', $m, $s);
    }

    /**
     * @param array<int, array<string, mixed>>|string $payload
     * @return array<int, array<string, mixed>>
     */
    private function parseQuestionPayload($payload, string $fallbackCategory): array
    {
        if (is_string($payload)) {
            $trim = trim($payload);
            if ($trim === '') {
                return [];
            }
            if ($trim[0] === '[' || $trim[0] === '{') {
                $decoded = json_decode($trim, true);
                if (isset($decoded['questions']) && is_array($decoded['questions'])) {
                    $payload = $decoded['questions'];
                } elseif (is_array($decoded)) {
                    $payload = isset($decoded[0]) ? $decoded : [$decoded];
                } else {
                    $payload = $this->parseCsvQuestions($trim, $fallbackCategory);
                }
            } else {
                $payload = $this->parseCsvQuestions($trim, $fallbackCategory);
            }
        }
        if (!is_array($payload)) {
            return [];
        }
        // Associative single question
        if ($payload !== [] && !isset($payload[0]) && isset($payload['prompt'])) {
            $payload = [$payload];
        }
        $out = [];
        foreach (array_values($payload) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            // CSV-style keys
            if (!isset($row['options']) && (isset($row['optionA']) || isset($row['option1']) || isset($row['A']))) {
                $opts = [];
                foreach (['optionA', 'optionB', 'optionC', 'optionD', 'optionE', 'option1', 'option2', 'option3', 'option4', 'A', 'B', 'C', 'D'] as $k) {
                    if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                        $opts[] = trim((string) $row[$k]);
                    }
                }
                $row['options'] = $opts;
            }
            if (!isset($row['correctIndex']) && isset($row['correct'])) {
                $row['correctIndex'] = $this->parseCorrectIndex((string) $row['correct'], (array) ($row['options'] ?? []));
            }
            if (!isset($row['prompt']) && isset($row['question'])) {
                $row['prompt'] = $row['question'];
            }
            $norm = AptitudeTestModel::normalizeMcq($row, $fallbackCategory, (int) $i);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvQuestions(string $csv, string $fallbackCategory): array
    {
        $lines = preg_split('/\R/', $csv) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn ($l) => $l !== ''));
        if ($lines === []) {
            return [];
        }
        $header = str_getcsv(array_shift($lines));
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $cols[$i] ?? '';
            }
            // Map common headers
            $mapped = [
                'prompt' => $row['prompt'] ?? $row['question'] ?? $row['question text'] ?? '',
                'optionA' => $row['optiona'] ?? $row['option_a'] ?? $row['a'] ?? $row['option1'] ?? '',
                'optionB' => $row['optionb'] ?? $row['option_b'] ?? $row['b'] ?? $row['option2'] ?? '',
                'optionC' => $row['optionc'] ?? $row['option_c'] ?? $row['c'] ?? $row['option3'] ?? '',
                'optionD' => $row['optiond'] ?? $row['option_d'] ?? $row['d'] ?? $row['option4'] ?? '',
                'correct' => $row['correct'] ?? $row['answer'] ?? $row['correctindex'] ?? $row['correct_option'] ?? 'A',
                'marks' => $row['marks'] ?? $row['mark'] ?? 1,
                'explanation' => $row['explanation'] ?? $row['solution'] ?? '',
                'category' => $row['category'] ?? $fallbackCategory,
                'difficulty' => $row['difficulty'] ?? $row['level'] ?? $row['difficulty level'] ?? 'Medium',
            ];
            $rows[] = $mapped;
        }
        return $this->parseQuestionPayload($rows, $fallbackCategory);
    }

    /**
     * @param array<int, string> $options
     */
    private function parseCorrectIndex(string $correct, array $options): int
    {
        $c = trim($correct);
        if ($c === '') {
            return 0;
        }
        if (is_numeric($c)) {
            $idx = (int) $c;
            // 1-based from CSV?
            if ($idx >= 1 && $idx <= count($options) && !isset($options[$idx])) {
                return $idx - 1;
            }
            return max(0, min(count($options) - 1, $idx));
        }
        $letter = strtoupper($c);
        if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'E') {
            return ord($letter) - ord('A');
        }
        foreach ($options as $i => $opt) {
            if (strcasecmp(trim((string) $opt), $c) === 0) {
                return (int) $i;
            }
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function publicAttempt(array $attempt, string $title = '', string $category = '', ?array $viewer = null): array
    {
        $test = $this->tests->findById((string) ($attempt['testId'] ?? '')) ?: [];
        if ($title === '') {
            $title = (string) ($test['title'] ?? '');
            $category = (string) ($test['category'] ?? '');
        }
        $contestType = AptitudeTestModel::normalizeContestType(
            (string) ($attempt['contestType'] ?? $test['contestType'] ?? 'none')
        );
        $timeTaken = (int) ($attempt['timeTakenSeconds'] ?? 0);
        if ($timeTaken <= 0) {
            $started = $this->parseTime($attempt['startedAt'] ?? null);
            $ended = $this->parseTime($attempt['completedAt'] ?? null);
            if ($started > 0 && $ended > $started) {
                $timeTaken = $ended - $started;
            }
        }
        $totalMarks = (float) ($attempt['totalMarks'] ?? 0);
        $marksObtained = (float) ($attempt['marksObtained'] ?? $attempt['score'] ?? 0);

        $payload = [
            'id' => (string) ($attempt['_id'] ?? ''),
            'attemptId' => (string) ($attempt['_id'] ?? ''),
            'testId' => (string) ($attempt['testId'] ?? ''),
            'testTitle' => $title,
            'category' => $category,
            'contestType' => $contestType,
            'contestScheduleLabel' => AptitudeTestModel::contestScheduleLabel($test),
            'status' => (string) ($attempt['status'] ?? ''),
            'score' => $attempt['score'] ?? null,
            'marksObtained' => $marksObtained,
            'totalMarks' => $totalMarks > 0 ? $totalMarks : null,
            'maximumScore' => $totalMarks > 0 ? $totalMarks : null,
            'percentage' => $attempt['percentage'] ?? null,
            'accuracy' => $attempt['accuracy'] ?? null,
            'correctCount' => $attempt['correctCount'] ?? null,
            'wrongCount' => $attempt['wrongCount'] ?? null,
            'unansweredCount' => $attempt['unansweredCount'] ?? null,
            'totalQuestions' => $attempt['totalQuestions'] ?? null,
            'categoryScores' => $attempt['categoryScores'] ?? [],
            'timeTakenSeconds' => $timeTaken,
            'timeTakenLabel' => $this->formatDuration($timeTaken),
            'startedAt' => $attempt['startedAt'] ?? null,
            'completedAt' => $attempt['completedAt'] ?? null,
            'resultsPublished' => AptitudeTestModel::resultsPublished($test),
        ];
        if ($viewer) {
            $payload = $this->applyResultVisibility($payload, $this->resultVisibilityForUser($viewer, $test));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $test
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $answers
     * @return array<string, int|string>
     */
    private function parseSubmittedAnswers(array $answers): array
    {
        $parsed = [];
        foreach ($answers as $key => $value) {
            $k = (string) $key;
            if (is_array($value)) {
                if (isset($value['index']) && is_numeric($value['index'])) {
                    $parsed[$k] = (int) $value['index'];
                }
                if (!empty($value['option'])) {
                    $parsed[$k . '__opt'] = AptitudeTestModel::sanitizeOptionText((string) $value['option']);
                }
                continue;
            }
            if (is_numeric($value)) {
                $parsed[$k] = (int) $value;
            }
        }

        return $parsed;
    }

    /**
     * @param array<string, int|string> $parsed
     * @param array<string, mixed> $q
     */
    private function resolvePickedIndex(array $parsed, array $q, int $position): int
    {
        $keys = [];
        foreach ([
            (string) ($q['id'] ?? ''),
            (string) ($q['bankId'] ?? ''),
            'q' . ($position + 1),
            (string) $position,
        ] as $candidate) {
            if ($candidate !== '' && !in_array($candidate, $keys, true)) {
                $keys[] = $candidate;
            }
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $parsed) && is_int($parsed[$key]) && $parsed[$key] >= 0) {
                return $parsed[$key];
            }
        }

        $options = array_values(array_map(
            static fn ($o) => AptitudeTestModel::sanitizeOptionText((string) $o),
            (array) ($q['options'] ?? [])
        ));
        foreach ($keys as $key) {
            $optKey = $key . '__opt';
            if (!array_key_exists($optKey, $parsed)) {
                continue;
            }
            $want = strtolower((string) $parsed[$optKey]);
            foreach ($options as $oi => $opt) {
                if (strtolower($opt) === $want) {
                    return (int) $oi;
                }
            }
        }

        return -1;
    }

    private function scoreAttempt(array $test, array $answers, ?array $examQuestions = null): array
    {
        $category = AptitudeTestModel::normalizeCategory((string) ($test['category'] ?? 'General Aptitude'));
        $questions = $examQuestions
            ? AptitudeTestModel::normalizedQuestions(['questions' => $examQuestions, 'category' => $category])
            : AptitudeTestModel::normalizedQuestions($test);
        $parsedAnswers = $this->parseSubmittedAnswers($answers);

        $total = count($questions);
        $correct = 0;
        $wrong = 0;
        $unanswered = 0;
        $marksObtained = 0.0;
        $totalMarks = 0.0;
        $negativeMarking = filter_var($test['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = $negativeMarking ? (float) ($test['negativeMarks'] ?? 0) : 0.0;
        $categoryTotals = [];
        $categoryCorrect = [];
        $categoryMarks = [];
        $normalized = [];
        $analysis = [];

        foreach ($questions as $position => $q) {
            $qid = (string) ($q['id'] ?? '');
            $cat = (string) ($q['category'] ?? $category);
            $qMarks = (float) ($q['marks'] ?? 1);
            $totalMarks += $qMarks;
            $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0) + 1;
            $picked = $this->resolvePickedIndex($parsedAnswers, $q, (int) $position);
            $normalized[$qid] = $picked;
            $options = array_values((array) ($q['options'] ?? []));
            $correctIndex = (int) ($q['correctIndex'] ?? -1);
            $correctText = ($correctIndex >= 0 && isset($options[$correctIndex])) ? (string) $options[$correctIndex] : '';
            $studentText = ($picked >= 0 && isset($options[$picked])) ? (string) $options[$picked] : null;

            $marksForQ = 0.0;
            $status = 'unanswered';
            if ($picked < 0) {
                $unanswered++;
            } elseif ($picked === $correctIndex) {
                $correct++;
                $marksForQ = $qMarks;
                $marksObtained += $qMarks;
                $categoryCorrect[$cat] = ($categoryCorrect[$cat] ?? 0) + 1;
                $categoryMarks[$cat] = ($categoryMarks[$cat] ?? 0) + $qMarks;
                $status = 'correct';
            } else {
                $wrong++;
                if ($negativeMarks > 0) {
                    $marksForQ = -$negativeMarks;
                    $marksObtained -= $negativeMarks;
                }
                $status = 'incorrect';
            }

            $analysis[] = [
                'id' => $qid,
                'questionId' => $qid,
                'question' => (string) ($q['prompt'] ?? ''),
                'options' => $options,
                'studentAnswerIndex' => $picked >= 0 ? $picked : null,
                'selected_answer' => $picked >= 0 ? $picked : null,
                'studentAnswer' => $studentText,
                'correctAnswerIndex' => $correctIndex >= 0 ? $correctIndex : null,
                'correctAnswer' => $correctText,
                'explanation' => (string) ($q['explanation'] ?? ''),
                'marks' => $qMarks,
                'marksObtained' => round($marksForQ, 2),
                'isCorrect' => $status === 'correct',
                'status' => $status,
                'category' => $cat,
            ];
        }

        if ($totalMarks <= 0) {
            $totalMarks = (float) ($test['totalMarks'] ?? max(1, $total));
        }
        if ($marksObtained < 0) {
            $marksObtained = 0.0;
        }
        $marksObtained = round($marksObtained, 2);
        $pct = $totalMarks > 0 ? round(($marksObtained / $totalMarks) * 100, 1) : 0.0;
        $accuracy = ($correct + $wrong) > 0
            ? round(($correct / ($correct + $wrong)) * 100, 1)
            : 0.0;

        $categoryScores = [];
        foreach ($categoryTotals as $cat => $n) {
            $c = (int) ($categoryCorrect[$cat] ?? 0);
            $categoryScores[$cat] = [
                'correct' => $c,
                'total' => $n,
                'marks' => round((float) ($categoryMarks[$cat] ?? 0), 2),
                'percentage' => $n > 0 ? round(($c / $n) * 100, 1) : 0.0,
            ];
        }

        return [
            'answers' => $normalized,
            'score' => $marksObtained,
            'marksObtained' => $marksObtained,
            'totalMarks' => round($totalMarks, 2),
            'correctCount' => $correct,
            'wrongCount' => $wrong,
            'unansweredCount' => $unanswered,
            'totalQuestions' => $total,
            'percentage' => $pct,
            'accuracy' => $accuracy,
            'categoryScores' => $categoryScores,
            'questionAnalysis' => $analysis,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     * @return array<string, mixed>
     */
    /**
     * @return array<string, true>
     */
    private function contestTestIdSet(): array
    {
        if ($this->contestTestIdSetCache !== null) {
            return $this->contestTestIdSetCache;
        }

        $set = [];
        foreach ($this->tests->findAll(['status' => 'published'], 500, 0, ['createdAt' => -1]) as $test) {
            $id = (string) ($test['_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $type = AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'));
            if (in_array($type, ['weekly', 'monthly'], true)) {
                $set[$id] = true;
            }
        }

        return $this->contestTestIdSetCache = $set;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function attemptFiltersFromDirectoryFilters(array $filters): array
    {
        $classBatch = trim((string) ($filters['class'] ?? $filters['classBatch'] ?? ''));
        if ($classBatch === '') {
            return [];
        }

        return ['classBatch' => $classBatch];
    }

    /**
     * @param array<int, string> $userIds
     * @return array<string, array<string, mixed>>
     */
    private function batchDirectoryProfiles(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $users = (new UserModel())->findByIds($userIds);
        $studentOids = [];
        foreach ($userIds as $uid) {
            $oid = Security::toObjectId($uid);
            if ($oid !== null) {
                $studentOids[] = $oid;
            }
        }

        $studentsByUser = [];
        if ($studentOids !== []) {
            foreach ((new StudentModel())->findAll(['userId' => ['$in' => $studentOids]], 5000) as $student) {
                $uid = (string) ($student['userId'] ?? '');
                if ($uid !== '') {
                    $studentsByUser[$uid] = $student;
                }
            }
        }

        $profiles = [];
        foreach ($userIds as $uid) {
            $user = $users[$uid] ?? [];
            $student = $studentsByUser[$uid] ?? null;
            if ($student) {
                $register = (string) ($student['registerNumber'] ?? '');
                $profiles[$uid] = [
                    'userId' => $uid,
                    'studentId' => (string) ($student['_id'] ?? ''),
                    'name' => (string) ($user['name'] ?? $student['name'] ?? 'User'),
                    'userType' => 'student',
                    'registerNumber' => $register,
                    'studentCode' => $register,
                    'departmentId' => (string) ($student['departmentId'] ?? ''),
                    'departmentName' => AptitudeAccessService::departmentDisplayName(
                        (string) ($student['departmentId'] ?? ''),
                        $student
                    ),
                    'classBatch' => StaffContext::studentClassBatch($student),
                    'course' => self::studentBranchLabelStatic($student),
                    'programme' => self::studentProgrammeLabelStatic($student),
                    'semester' => trim((string) ($student['academic']['semester'] ?? $student['semester'] ?? '')),
                    'batch' => trim((string) ($student['batch'] ?? $student['academic']['batch'] ?? '')),
                ];
                continue;
            }

            $profiles[$uid] = [
                'userId' => $uid,
                'studentId' => null,
                'name' => (string) ($user['name'] ?? 'User'),
                'userType' => (string) ($user['role'] ?? 'unknown'),
                'registerNumber' => '',
                'studentCode' => '',
                'departmentId' => '',
                'departmentName' => '',
                'classBatch' => '',
                'course' => '',
                'semester' => '',
                'batch' => '',
            ];
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed>|null $profileRow
     * @return array<string, mixed>
     */
    private function summarizeSubjectCached(
        string $userId,
        array $attempts,
        ?array $profileRow,
        bool $includeHistory,
        ?array $historyViewer = null
    ): array {
        if ($profileRow === null) {
            return $this->summarizeSubject($userId, $attempts, $includeHistory, $historyViewer);
        }

        $completed = array_values(array_filter(
            $attempts,
            static fn ($a) => ($a['status'] ?? '') === 'completed'
        ));
        $scoredForStats = $completed;
        if ($historyViewer) {
            $testCache = [];
            $scoredForStats = array_values(array_filter(
                $completed,
                function (array $a) use ($historyViewer, &$testCache): bool {
                    $testId = (string) ($a['testId'] ?? '');
                    if ($testId === '') {
                        return true;
                    }
                    if (!isset($testCache[$testId])) {
                        $testCache[$testId] = $this->tests->findById($testId) ?: [];
                    }

                    return $this->resultVisibilityForUser($historyViewer, $testCache[$testId]) !== 'pending';
                }
            ));
        }
        $percentages = array_map(static fn ($a) => (float) ($a['percentage'] ?? 0), $scoredForStats);
        $best = $percentages === [] ? 0.0 : max($percentages);
        $avg = $percentages === [] ? 0.0 : round(array_sum($percentages) / count($percentages), 1);

        $categoryAgg = [];
        foreach ($scoredForStats as $a) {
            foreach ((array) ($a['categoryScores'] ?? []) as $cat => $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $categoryAgg[$cat]['correct'] = ($categoryAgg[$cat]['correct'] ?? 0) + (int) ($stats['correct'] ?? 0);
                $categoryAgg[$cat]['total'] = ($categoryAgg[$cat]['total'] ?? 0) + (int) ($stats['total'] ?? 0);
            }
        }
        $categoryWise = [];
        foreach ($categoryAgg as $cat => $stats) {
            $t = (int) $stats['total'];
            $c = (int) $stats['correct'];
            $categoryWise[$cat] = [
                'correct' => $c,
                'total' => $t,
                'percentage' => $t > 0 ? round(($c / $t) * 100, 1) : 0.0,
            ];
        }

        $accuracies = [];
        foreach ($scoredForStats as $a) {
            if (isset($a['accuracy']) && $a['accuracy'] !== null) {
                $accuracies[] = (float) $a['accuracy'];
            } elseif (isset($a['correctCount'], $a['wrongCount'])) {
                $c = (int) $a['correctCount'];
                $w = (int) $a['wrongCount'];
                if ($c + $w > 0) {
                    $accuracies[] = round(($c / ($c + $w)) * 100, 1);
                }
            }
        }
        $accuracyAvg = $accuracies === [] ? $avg : round(array_sum($accuracies) / count($accuracies), 1);

        $history = [];
        if ($includeHistory) {
            foreach ($completed as $a) {
                $history[] = $this->publicAttempt($a, '', '', $historyViewer);
            }
        }

        $recent = $scoredForStats[0] ?? null;
        $first = $completed[0] ?? [];

        return array_merge($profileRow, [
            'testsAttempted' => count($completed),
            'bestScore' => $best,
            'averageScore' => $avg,
            'percentage' => $avg,
            'accuracy' => $accuracyAvg,
            'overallScore' => $avg,
            'recentScore' => $recent ? (float) ($recent['percentage'] ?? 0) : 0.0,
            'recentPerformance' => $recent ? (float) ($recent['percentage'] ?? 0) : 0.0,
            'categoryPerformance' => $categoryWise,
            'categoryWise' => $categoryWise,
            'history' => $history,
            'classBatch' => (string) ($profileRow['classBatch'] ?? '') !== ''
                ? (string) $profileRow['classBatch']
                : (string) ($first['classBatch'] ?? ''),
            'course' => (string) ($profileRow['course'] ?? '') !== ''
                ? (string) $profileRow['course']
                : (string) ($first['course'] ?? ''),
            'semester' => (string) ($profileRow['semester'] ?? '') !== ''
                ? (string) $profileRow['semester']
                : (string) ($first['semester'] ?? ''),
            'batch' => (string) ($profileRow['batch'] ?? '') !== ''
                ? (string) $profileRow['batch']
                : (string) ($first['batch'] ?? ''),
        ]);
    }

    private function summarizeSubject(string $userId, array $attempts, bool $includeHistory, ?array $historyViewer = null): array
    {
        $completed = array_values(array_filter(
            $attempts,
            static fn ($a) => ($a['status'] ?? '') === 'completed'
        ));
        $scoredForStats = $completed;
        if ($historyViewer) {
            $scoredForStats = array_values(array_filter(
                $completed,
                function (array $a) use ($historyViewer): bool {
                    $test = $this->tests->findById((string) ($a['testId'] ?? '')) ?: [];

                    return $this->resultVisibilityForUser($historyViewer, $test) !== 'pending';
                }
            ));
        }
        $percentages = array_map(static fn ($a) => (float) ($a['percentage'] ?? 0), $scoredForStats);
        $best = $percentages === [] ? 0.0 : max($percentages);
        $avg = $percentages === [] ? 0.0 : round(array_sum($percentages) / count($percentages), 1);

        $categoryAgg = [];
        foreach ($scoredForStats as $a) {
            foreach ((array) ($a['categoryScores'] ?? []) as $cat => $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $categoryAgg[$cat]['correct'] = ($categoryAgg[$cat]['correct'] ?? 0) + (int) ($stats['correct'] ?? 0);
                $categoryAgg[$cat]['total'] = ($categoryAgg[$cat]['total'] ?? 0) + (int) ($stats['total'] ?? 0);
            }
        }
        $categoryWise = [];
        foreach ($categoryAgg as $cat => $stats) {
            $t = (int) $stats['total'];
            $c = (int) $stats['correct'];
            $categoryWise[$cat] = [
                'correct' => $c,
                'total' => $t,
                'percentage' => $t > 0 ? round(($c / $t) * 100, 1) : 0.0,
            ];
        }

        $user = (new UserModel())->findById($userId) ?: [];
        $profile = AptitudeAccessService::loadSubjectProfile($userId);
        $userType = $profile['type'] ?? ((string) ($user['role'] ?? 'unknown'));
        $name = (string) ($user['name'] ?? 'User');
        $register = '';
        $dept = (string) ($profile['departmentId'] ?? '');
        $classBatch = '';
        $course = '';
        $programme = '';
        $semester = '';
        $batch = '';
        if (($profile['type'] ?? '') === 'student' && !empty($profile['student'])) {
            $st = $profile['student'];
            $register = (string) ($st['registerNumber'] ?? '');
            $classBatch = StaffContext::studentClassBatch($st);
            $course = self::studentBranchLabelStatic($st);
            $programme = self::studentProgrammeLabelStatic($st);
            $semester = trim((string) ($st['academic']['semester'] ?? $st['semester'] ?? ''));
            $batch = trim((string) ($st['batch'] ?? $st['academic']['batch'] ?? ''));
            $name = (string) ($user['name'] ?? $st['name'] ?? $name);
        }

        $accuracies = [];
        foreach ($scoredForStats as $a) {
            if (isset($a['accuracy']) && $a['accuracy'] !== null) {
                $accuracies[] = (float) $a['accuracy'];
            } elseif (isset($a['correctCount'], $a['wrongCount'])) {
                $c = (int) $a['correctCount'];
                $w = (int) $a['wrongCount'];
                if ($c + $w > 0) {
                    $accuracies[] = round(($c / ($c + $w)) * 100, 1);
                }
            }
        }
        $accuracyAvg = $accuracies === [] ? $avg : round(array_sum($accuracies) / count($accuracies), 1);

        $history = [];
        if ($includeHistory) {
            foreach ($completed as $a) {
                $history[] = $this->publicAttempt($a, '', '', $historyViewer);
            }
        }

        $recent = $scoredForStats[0] ?? null;
        $first = $completed[0] ?? [];

        $deptName = AptitudeAccessService::departmentDisplayName(
            $dept,
            is_array($profile['student'] ?? null) ? $profile['student'] : []
        );

        return [
            'userId' => $userId,
            'studentId' => ($profile['studentId'] ?? null),
            'name' => $name,
            'userType' => $userType,
            'registerNumber' => $register,
            'studentCode' => $register,
            'departmentId' => $dept,
            'departmentName' => $deptName,
            'classBatch' => $classBatch !== '' ? $classBatch : (string) ($first['classBatch'] ?? ''),
            'course' => $course !== '' ? $course : (string) ($first['course'] ?? ''),
            'programme' => $programme !== '' ? $programme : (string) ($first['programme'] ?? ''),
            'semester' => $semester !== '' ? $semester : (string) ($first['semester'] ?? ''),
            'batch' => $batch !== '' ? $batch : (string) ($first['batch'] ?? ''),
            'testsAttempted' => count($completed),
            'bestScore' => $best,
            'averageScore' => $avg,
            'percentage' => $avg,
            'accuracy' => $accuracyAvg,
            'overallScore' => $avg,
            'recentScore' => $recent ? (float) ($recent['percentage'] ?? 0) : 0.0,
            'recentPerformance' => $recent ? (float) ($recent['percentage'] ?? 0) : 0.0,
            'categoryPerformance' => $categoryWise,
            'categoryWise' => $categoryWise,
            'history' => $history,
        ];
    }

    private static function classLabelMatches(string $studentClass, string $want): bool
    {
        $studentClass = trim($studentClass);
        $want = trim($want);
        if ($studentClass === '' || $want === '') {
            return false;
        }
        if (strcasecmp($studentClass, $want) === 0) {
            return true;
        }
        $compactA = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $studentClass));
        $compactB = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $want));
        if ($compactA !== '' && $compactA === $compactB) {
            return true;
        }

        return strcasecmp(ClassInchargeRegistry::cohortKey($studentClass), ClassInchargeRegistry::cohortKey($want)) === 0;
    }

    private static function courseLabelMatches(string $studentCourse, string $want, string $studentClass, string $programme = ''): bool
    {
        $want = trim($want);
        if ($want === '') {
            return true;
        }
        foreach ([$studentCourse, $programme] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && strcasecmp($candidate, $want) === 0) {
                return true;
            }
        }
        $wantCode = DepartmentProgrammeCatalog::resolveProgrammeCode($want);
        foreach ([$studentCourse, $programme, $studentClass] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $rowCode = DepartmentProgrammeCatalog::resolveProgrammeCode($candidate);
            if ($wantCode !== '' && $rowCode !== '' && strcasecmp($wantCode, $rowCode) === 0) {
                return true;
            }
        }
        $compactWant = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $wantCode !== '' ? $wantCode : $want));
        foreach ([$studentCourse, $programme] as $candidate) {
            $compactCourse = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($candidate)));
            if ($compactCourse !== '' && $compactWant !== '' && (str_starts_with($compactCourse, $compactWant) || str_starts_with($compactWant, $compactCourse))) {
                return true;
            }
        }
        $compactClass = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $studentClass));

        return $compactClass !== '' && $compactWant !== '' && str_starts_with($compactClass, $compactWant);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $attempts
     * @param array<string, mixed> $filters
     */
    private function matchesFilters(array $summary, array $attempts, array $filters): bool
    {
        $batch = trim((string) ($filters['batch'] ?? ''));
        $classBatch = trim((string) ($filters['class'] ?? $filters['classBatch'] ?? ''));
        $course = trim((string) ($filters['course'] ?? ''));
        $semester = trim((string) ($filters['semester'] ?? ''));
        $departmentId = trim((string) ($filters['department'] ?? $filters['departmentId'] ?? ''));
        $testId = trim((string) ($filters['test'] ?? $filters['testId'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $userType = trim((string) ($filters['userType'] ?? ''));

        if ($batch !== '' && strcasecmp((string) ($summary['batch'] ?? ''), $batch) !== 0) {
            return false;
        }
        if ($classBatch !== '') {
            $rowClass = trim((string) ($summary['classBatch'] ?? ''));
            if ($rowClass === '' || !self::classLabelMatches($rowClass, $classBatch)) {
                return false;
            }
        } else {
            if ($departmentId !== '' && !$this->idsEqual((string) ($summary['departmentId'] ?? ''), $departmentId)) {
                return false;
            }
            if ($course !== '' && !self::courseLabelMatches(
                (string) ($summary['course'] ?? ''),
                $course,
                (string) ($summary['classBatch'] ?? ''),
                (string) ($summary['programme'] ?? '')
            )) {
                return false;
            }
        }
        if ($semester !== '' && strcasecmp((string) ($summary['semester'] ?? ''), $semester) !== 0) {
            return false;
        }
        if ($userType !== '' && strcasecmp((string) ($summary['userType'] ?? ''), $userType) !== 0) {
            return false;
        }
        if ($category !== '') {
            $cats = array_keys((array) ($summary['categoryWise'] ?? []));
            $hit = false;
            foreach ($cats as $c) {
                if (strcasecmp((string) $c, $category) === 0) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }
        if ($testId !== '') {
            $hit = false;
            foreach ($attempts as $a) {
                if ((string) ($a['testId'] ?? '') === $testId && ($a['status'] ?? '') === 'completed') {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }
        return true;
    }
}
