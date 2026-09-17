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
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Aptitude mock tests — listing, attempts, progress summaries.
 */
final class AptitudeService
{
    private AptitudeTestModel $tests;
    private AptitudeAttemptModel $attempts;

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
                ],
                [
                    'id' => 'q2',
                    'type' => 'mcq',
                    'prompt' => 'If A:B = 2:3 and B:C = 4:5, then A:C is?',
                    'options' => ['8:15', '2:5', '4:5', '8:9'],
                    'correctIndex' => 0,
                    'marks' => 1,
                    'category' => 'Quantitative Aptitude',
                ],
                [
                    'id' => 'q3',
                    'type' => 'mcq',
                    'prompt' => 'A train covers 120 km in 2 hours. Average speed?',
                    'options' => ['40 km/h', '50 km/h', '60 km/h', '80 km/h'],
                    'correctIndex' => 2,
                    'marks' => 1,
                    'category' => 'Quantitative Aptitude',
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
        $rows = array_values(array_filter(
            $this->tests->published(200),
            static fn ($t) => AptitudeAccessService::testVisibleToTaker($user, $t)
                && AptitudeTestModel::isContestOpen($t)
        ));

        return array_map(
            static fn ($t) => AptitudeTestModel::publicView($t, $includeAnswers),
            $rows
        );
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

        return array_map(
            static fn ($t) => AptitudeTestModel::publicView($t, true),
            $rows
        );
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
        if (!AptitudeTestModel::isContestOpen($test)) {
            Response::forbidden('This contest is not open today. Check the weekly or monthly schedule.');
        }
        $ctx = AptitudeAccessService::subjectContext($user);
        $attemptId = $this->attempts->startAttempt([
            'testId' => $testId,
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'subjectType' => $ctx['subjectType'],
            'studentId' => $ctx['studentId'],
            'alumniId' => $ctx['alumniId'],
            'departmentId' => $ctx['departmentId'],
            'classBatch' => $ctx['classBatch'],
            'course' => $ctx['course'],
            'semester' => $ctx['semester'],
            'batch' => $ctx['batch'],
            'totalQuestions' => count((array) ($test['questions'] ?? [])),
        ]);

        return [
            'attemptId' => $attemptId,
            'test' => AptitudeTestModel::publicView($test, false),
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
            return $this->buildResultPayload($attempt, $test);
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

        $scored = $this->scoreAttempt($test, $answers);
        $scored['markedForReview'] = array_values(array_filter(
            array_map('strval', (array) ($meta['markedForReview'] ?? []))
        ));
        $scored['timeTakenSeconds'] = $timeTaken;
        $scored['autoSubmitted'] = !empty($meta['autoSubmitted']);

        $this->attempts->completeAttempt($attemptId, $scored);
        $fresh = $this->attempts->findById($attemptId) ?: array_merge($attempt, $scored, ['status' => 'completed']);
        $rankInfo = $this->computeRank((string) ($test['_id'] ?? ''), (float) ($scored['percentage'] ?? 0));
        if ($rankInfo['rank'] !== null) {
            $this->attempts->update($attemptId, [
                'rank' => $rankInfo['rank'],
                'percentile' => $rankInfo['percentile'],
            ]);
            $fresh['rank'] = $rankInfo['rank'];
            $fresh['percentile'] = $rankInfo['percentile'];
        }
        return $this->buildResultPayload($fresh, $test);
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
            $scored = $this->scoreAttempt($test, (array) ($attempt['answers'] ?? []));
            $attempt = array_merge($attempt, [
                'questionAnalysis' => $scored['questionAnalysis'],
                'wrongCount' => $scored['wrongCount'],
                'unansweredCount' => $scored['unansweredCount'],
                'totalMarks' => $scored['totalMarks'],
                'marksObtained' => $scored['marksObtained'],
            ]);
        }
        return $this->buildResultPayload($attempt, $test);
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
        return $this->summarizeSubject($userId, $rows, true);
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
            array_diff_key($dbFilter, ['status' => true]),
            2000
        );

        $byUser = [];
        foreach ($completed as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            if (!AptitudeAccessService::canViewSubject($viewer, $uid)) {
                continue;
            }
            $byUser[$uid][] = $attempt;
        }

        $rows = [];
        foreach ($byUser as $uid => $attempts) {
            $attempts = $this->filterAttemptsByResultType($attempts, $resultType);
            if ($attempts === []) {
                continue;
            }
            $summary = $this->summarizeSubject($uid, $attempts, false);
            if (in_array($role, ['staff', 'placement_officer'], true) && ($summary['userType'] ?? '') !== 'student') {
                continue;
            }
            if ($role !== 'admin' && ($summary['userType'] ?? '') === 'alumni') {
                continue;
            }
            if (!$this->matchesFilters($summary, $attempts, $filters)) {
                continue;
            }
            $rows[] = $summary;
        }

        usort($rows, static fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $bestScores = array_map(static fn ($r) => (float) ($r['bestScore'] ?? 0), $rows);
        $avgScores = array_map(static fn ($r) => (float) ($r['percentage'] ?? 0), $rows);
        $scope = AptitudeAccessService::scopeInfo($viewer);

        return [
            'rows' => $rows,
            'scope' => $scope,
            'summary' => [
                'subjects' => count($rows),
                'students' => count(array_filter($rows, static fn ($r) => ($r['userType'] ?? '') === 'student')),
                'avgPercentage' => $avgScores === [] ? 0 : round(array_sum($avgScores) / count($avgScores), 1),
                'avgBestScore' => $bestScores === [] ? 0 : round(array_sum($bestScores) / count($bestScores), 1),
                'highestBestScore' => $bestScores === [] ? 0 : max($bestScores),
                'totalAttempts' => array_sum(array_map(static fn ($r) => (int) ($r['testsAttempted'] ?? 0), $rows)),
                'withAttempts' => count(array_filter($rows, static fn ($r) => (int) ($r['testsAttempted'] ?? 0) > 0)),
            ],
            'tests' => array_map(
                static fn ($t) => ['id' => $t['id'], 'title' => $t['title'], 'category' => $t['category']],
                $this->listPublished(false)
            ),
        ];
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

        /** @var array<string, string> $contestTypeByTest */
        $contestTypeByTest = [];

        return array_values(array_filter(
            $attempts,
            function (array $attempt) use ($resultType, &$contestTypeByTest): bool {
                $testId = (string) ($attempt['testId'] ?? '');
                if ($testId === '') {
                    return $resultType === 'tests';
                }
                if (!isset($contestTypeByTest[$testId])) {
                    $test = $this->tests->findById($testId);
                    $contestTypeByTest[$testId] = AptitudeTestModel::normalizeContestType(
                        (string) ($test['contestType'] ?? 'none')
                    );
                }
                $isContest = in_array($contestTypeByTest[$testId], ['weekly', 'monthly'], true);

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

        foreach ($this->attemptsInViewerScope($viewer) as $attempt) {
            if ($departmentId !== '' && !$this->idsEqual((string) ($attempt['departmentId'] ?? ''), $departmentId)) {
                continue;
            }
            if ($branch !== '') {
                $attemptCourse = trim((string) ($attempt['course'] ?? ''));
                if ($attemptCourse !== ''
                    && strcasecmp($attemptCourse, $branch) !== 0
                    && strcasecmp(
                        DepartmentProgrammeCatalog::resolveProgrammeCode($attemptCourse),
                        DepartmentProgrammeCatalog::resolveProgrammeCode($branch)
                    ) !== 0) {
                    continue;
                }
            }
            $course = trim((string) ($attempt['course'] ?? ''));
            if ($course !== '') {
                $branchSet[$course] = true;
            }
            $classBatch = trim((string) ($attempt['classBatch'] ?? ''));
            if ($classBatch !== '') {
                $batchSet[$classBatch] = true;
            }
        }

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
     * @return array<int, array<string, mixed>>
     */
    private function attemptsInViewerScope(array $viewer): array
    {
        $dbFilter = AptitudeAccessService::completedAttemptsFilter($viewer);
        if ($dbFilter === null) {
            return [];
        }

        return $this->attempts->completed(
            array_diff_key($dbFilter, ['status' => true]),
            2000
        );
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
    private static function studentBranchLabelStatic(array $student): string
    {
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $candidates = [
            $student['stud_branch'] ?? '',
            $student['branchName'] ?? '',
            $student['branch_name'] ?? '',
            $academic['branch'] ?? '',
            $academic['course'] ?? '',
            $personal['course'] ?? '',
            $student['course'] ?? '',
            $student['stud_course'] ?? '',
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
        $completed = $this->attempts->completed(
            array_diff_key($dbFilter, ['status' => true]),
            2000
        );

        /** @var array<string, array<string, mixed>> $userCache */
        $userCache = [];
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

            $test = $this->tests->findById($testId);
            if (!$test) {
                continue;
            }
            $contestType = AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'));
            if (!in_array($contestType, ['weekly', 'monthly'], true)) {
                continue;
            }

            if (!isset($userCache[$uid])) {
                $userCache[$uid] = $this->summarizeSubject($uid, [], false);
            }
            $profile = $userCache[$uid];

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
            $test = $this->tests->findById($testId) ?: [];
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

            $contests[] = [
                'testId' => $testId,
                'title' => (string) ($test['title'] ?? 'Contest'),
                'category' => (string) ($test['category'] ?? ''),
                'contestType' => AptitudeTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none')),
                'contestScheduleLabel' => AptitudeTestModel::contestScheduleLabel($test),
                'participantCount' => count($participants),
                'participants' => $participants,
            ];
        }

        usort($contests, static fn (array $a, array $b): int => strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));

        $percentages = array_map(static fn (array $p): float => (float) ($p['percentage'] ?? 0), $allParticipants);

        return [
            'view' => 'contests',
            'contests' => $contests,
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($viewer),
            'summary' => [
                'contestCount' => count($contests),
                'totalParticipants' => count($allParticipants),
                'uniqueParticipants' => count($uniqueUsers),
                'avgPercentage' => $percentages === [] ? 0 : round(array_sum($percentages) / count($percentages), 1),
                'highestScore' => $percentages === [] ? 0 : max($percentages),
            ],
        ];
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
        $data = $this->resolveTestQuestions($data);
        $this->tests->updateTest($id, $data);
        $doc = $this->tests->findById($id);
        return AptitudeTestModel::publicView($doc ?: [], true);
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
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    private function buildResultPayload(array $attempt, array $test): array
    {
        $base = $this->publicAttempt(
            $attempt,
            (string) ($test['title'] ?? ''),
            (string) ($test['category'] ?? '')
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

        return array_merge($base, [
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
            'questionAnalysis' => array_values((array) ($attempt['questionAnalysis'] ?? [])),
            'negativeMarking' => filter_var($test['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'negativeMarks' => (float) ($test['negativeMarks'] ?? 0),
        ]);
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
    private function publicAttempt(array $attempt, string $title = '', string $category = ''): array
    {
        if ($title === '') {
            $test = $this->tests->findById((string) ($attempt['testId'] ?? ''));
            $title = (string) ($test['title'] ?? '');
            $category = (string) ($test['category'] ?? '');
        }
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

        return [
            'id' => (string) ($attempt['_id'] ?? ''),
            'attemptId' => (string) ($attempt['_id'] ?? ''),
            'testId' => (string) ($attempt['testId'] ?? ''),
            'testTitle' => $title,
            'category' => $category,
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
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @param array<string, mixed> $answers
     * @return array<string, mixed>
     */
    private function scoreAttempt(array $test, array $answers): array
    {
        $category = AptitudeTestModel::normalizeCategory((string) ($test['category'] ?? 'General Aptitude'));
        $questions = [];
        foreach (array_values((array) ($test['questions'] ?? [])) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, $category, (int) $i);
            if ($norm !== null) {
                $questions[] = $norm;
            }
        }

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

        foreach ($questions as $q) {
            $qid = (string) ($q['id'] ?? '');
            $cat = (string) ($q['category'] ?? $category);
            $qMarks = (float) ($q['marks'] ?? 1);
            $totalMarks += $qMarks;
            $categoryTotals[$cat] = ($categoryTotals[$cat] ?? 0) + 1;
            $picked = array_key_exists($qid, $answers) ? (int) $answers[$qid] : -1;
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
    private function summarizeSubject(string $userId, array $attempts, bool $includeHistory): array
    {
        $completed = array_values(array_filter(
            $attempts,
            static fn ($a) => ($a['status'] ?? '') === 'completed'
        ));
        $percentages = array_map(static fn ($a) => (float) ($a['percentage'] ?? 0), $completed);
        $best = $percentages === [] ? 0.0 : max($percentages);
        $avg = $percentages === [] ? 0.0 : round(array_sum($percentages) / count($percentages), 1);

        $categoryAgg = [];
        foreach ($completed as $a) {
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
        $semester = '';
        $batch = '';
        if (($profile['type'] ?? '') === 'student' && !empty($profile['student'])) {
            $st = $profile['student'];
            $register = (string) ($st['registerNumber'] ?? '');
            $classBatch = StaffContext::studentClassBatch($st);
            $course = trim((string) ($st['academic']['course'] ?? $st['course'] ?? ''));
            $semester = trim((string) ($st['academic']['semester'] ?? $st['semester'] ?? ''));
            $batch = trim((string) ($st['batch'] ?? $st['academic']['batch'] ?? ''));
            $name = (string) ($user['name'] ?? $st['name'] ?? $name);
        }

        $accuracies = [];
        foreach ($completed as $a) {
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
                $history[] = $this->publicAttempt($a);
            }
        }

        $recent = $completed[0] ?? null;
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
        if ($classBatch !== '' && strcasecmp((string) ($summary['classBatch'] ?? ''), $classBatch) !== 0) {
            return false;
        }
        if ($course !== '' && strcasecmp((string) ($summary['course'] ?? ''), $course) !== 0) {
            return false;
        }
        if ($semester !== '' && strcasecmp((string) ($summary['semester'] ?? ''), $semester) !== 0) {
            return false;
        }
        if ($departmentId !== '' && (string) ($summary['departmentId'] ?? '') !== $departmentId) {
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
