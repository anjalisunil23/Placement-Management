<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingAttemptModel;
use PMS\Models\CodingCompanyProblemSetModel;
use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;
use PMS\Models\CompanyModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

final class CodingService
{
    private CodingTestModel $tests;
    private CodingProblemBankModel $bank;
    private CodingAttemptModel $attempts;

    public function __construct()
    {
        $this->tests = new CodingTestModel();
        $this->bank = new CodingProblemBankModel();
        $this->attempts = new CodingAttemptModel();
        try {
            (new CodingSeedService($this->bank, $this->tests))->ensureSeeded();
        } catch (\Throwable $e) {
            error_log('[PMS coding seed] ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listPublishedForUser(array $user): array
    {
        $rows = $this->tests->findAll(['status' => 'published'], 200, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            if (!$this->studentCanSeeTest($user, $row)) {
                continue;
            }
            if (CodingTestModel::shouldSplitForStudentList($row)) {
                foreach ((array) ($row['items'] ?? []) as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $out[] = $this->problemTestListMeta($row, $item, (int) $index);
                }
                continue;
            }
            $view = CodingTestModel::publicView($row, false);
            unset($view['items']);
            $out[] = $view;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function problemTestListMeta(array $parent, array $item, int $index): array
    {
        $parentId = (string) ($parent['_id'] ?? $parent['id'] ?? '');
        $problemId = (string) ($item['id'] ?? ('p' . ($index + 1)));
        $view = CodingTestModel::publicView($parent, false);
        unset($view['items']);
        $marks = (float) ($item['marks'] ?? 2);
        $duration = CodingTestModel::singleProblemDuration($item, $parent);
        $difficulty = (string) ($item['difficulty'] ?? $parent['difficulty'] ?? 'Medium');

        return array_merge($view, [
            'id' => CodingTestModel::composeProblemTestId($parentId, $problemId),
            'parentTestId' => $parentId,
            'problemItemId' => $problemId,
            'title' => (string) ($item['title'] ?? ('Problem ' . ($index + 1))),
            'description' => (string) ($item['description'] ?? ''),
            'difficulty' => $difficulty,
            'questions' => 1,
            'questionCount' => 1,
            'marks' => $marks,
            'totalMarks' => $marks,
            'duration' => $duration,
            'durationMinutes' => $duration,
            'bundleTitle' => (string) ($parent['title'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $test
     * @return array<string, mixed>|null
     */
    private function buildSingleProblemTestView(array $test, string $problemId): ?array
    {
        $full = CodingTestModel::publicView($test, true);
        $item = null;
        $index = 0;
        foreach ((array) ($full['items'] ?? []) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['id'] ?? '') === $problemId) {
                $item = $row;
                $index = (int) $i;
                break;
            }
        }
        if ($item === null) {
            return null;
        }

        $meta = $this->problemTestListMeta($test, $item, $index);

        return array_merge($meta, [
            'items' => [$item],
            'instructions' => array_values((array) ($test['instructions'] ?? [])),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $test
     */
    private function studentCanSeeTest(array $user, array $test): bool
    {
        if (!AptitudeAccessService::canTake($user)) {
            return false;
        }
        $testDept = (string) ($test['departmentId'] ?? '');
        if ($testDept === '') {
            return true;
        }
        $ctx = AptitudeAccessService::subjectContext($user);
        $studentDept = (string) ($ctx['departmentId'] ?? '');
        if ($studentDept === '') {
            return true;
        }
        return $studentDept === $testDept;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listAllForAdmin(array $user): array
    {
        AptitudeAccessService::requireManager($user);
        $rows = $this->tests->findAll([], 500, 0, ['createdAt' => -1]);
        $out = [];
        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($user);
        $dept = '';
        if ($role === 'placement_officer') {
            $dept = (string) (PlacementOfficerContext::resolve($user)['departmentId'] ?? '');
        } elseif ($role === 'staff' || ($user['role'] ?? '') === 'staff') {
            $dept = (string) (StaffContext::resolve($user)['departmentId'] ?? '');
        }
        foreach ($rows as $row) {
            if ($role !== 'admin') {
                $testDept = (string) ($row['departmentId'] ?? '');
                if ($testDept !== '' && ($dept === '' || $testDept !== $dept)) {
                    continue;
                }
            }
            $out[] = CodingTestModel::publicView($row, true);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTest(array $user, array $data): array
    {
        AptitudeAccessService::requireManager($user);
        $data = AptitudeAccessService::applyTestDepartmentScope($user, $data);
        $data = AptitudeAccessService::sanitizeContestFields($user, $data);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            Response::error('Enter a test title.', 422);
        }
        $id = $this->tests->saveNew($data);
        $doc = $this->tests->findById($id);
        return CodingTestModel::publicView($doc ?: ['id' => $id], true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTest(array $user, string $id, array $data): array
    {
        AptitudeAccessService::requireManager($user);
        $existing = $this->tests->findById($id);
        if (!$existing) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($user, $existing);
        $data = AptitudeAccessService::applyTestDepartmentScope($user, $data);
        $data = AptitudeAccessService::sanitizeContestFields($user, $data);
        if (!AptitudeAccessService::canManageContests($user)) {
            $data['contestType'] = $existing['contestType'] ?? 'none';
            $data['contestWeekday'] = $existing['contestWeekday'] ?? 1;
            $data['contestMonthDay'] = $existing['contestMonthDay'] ?? 1;
        }
        $this->tests->saveExisting($id, $data);
        $doc = $this->tests->findById($id);
        return CodingTestModel::publicView($doc ?: $existing, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteTest(array $user, string $id): void
    {
        AptitudeAccessService::requireManager($user);
        $existing = $this->tests->findById($id);
        if (!$existing) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($user, $existing);
        $this->tests->delete($id);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listBank(array $user, ?string $category = null, ?string $difficulty = null): array
    {
        AptitudeAccessService::requireManager($user);
        return $this->bank->listProblems($category, $difficulty);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateAiBankProblems(array $user, array $body): array
    {
        AptitudeAccessService::requireManager($user);
        $category = (string) ($body['category'] ?? 'Programming');
        $topic = trim((string) ($body['topic'] ?? $category));
        $difficulty = (string) ($body['difficulty'] ?? 'Medium');
        $count = (int) ($body['count'] ?? 5);
        $instructions = (string) ($body['instructions'] ?? '');
        $count = max(1, min(10, $count));
        try {
            return (new CodingAiProblemService())->generate($category, $topic, $difficulty, $count, $instructions);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 503);
        } catch (\Throwable $e) {
            error_log('[PMS coding AI] generate failed: ' . $e->getMessage());
            Response::error('AI generation failed. Please try again.', 503);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $problems
     * @return array<string, mixed>
     */
    public function saveAiBankProblems(array $user, array $problems): array
    {
        AptitudeAccessService::requireManager($user);
        if ($problems === []) {
            Response::error('No problems selected to save.', 422);
        }
        try {
            return (new CodingAiProblemService())->saveApproved($problems);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[PMS coding AI] save failed: ' . $e->getMessage());
            Response::error('Could not save AI problems. Please try again.', 500);
            return [];
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveBankProblem(array $user, array $data, ?string $id = null): array
    {
        AptitudeAccessService::requireManager($user);
        if (trim((string) ($data['title'] ?? '')) === '') {
            Response::error('Enter a problem title.', 422);
        }
        $savedId = $this->bank->saveProblem($data, $id);
        $doc = $this->bank->findById($savedId);
        return array_merge(CodingProblemBankModel::normalize($doc ?: $data), ['id' => $savedId]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteBankProblem(array $user, string $id): void
    {
        AptitudeAccessService::requireManager($user);
        if (!$this->bank->findById($id)) {
            Response::notFound('Problem not found.');
        }
        $this->bank->delete($id);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $ids
     * @return array<string, mixed>
     */
    public function bulkDeleteBankProblems(array $user, array $ids): array
    {
        AptitudeAccessService::requireManager($user);
        $deleted = 0;
        $notFound = 0;

        foreach (array_values(array_unique(array_filter(array_map('strval', $ids)))) as $id) {
            if (!Security::isValidId($id)) {
                $notFound++;
                continue;
            }
            if (!$this->bank->findById($id)) {
                $notFound++;
                continue;
            }
            if ($this->bank->delete($id)) {
                $deleted++;
            }
        }

        if ($deleted === 0) {
            Response::error('No problems were deleted.', 422);
        }

        return [
            'deleted' => $deleted,
            'notFound' => $notFound,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function start(array $user, string $testId): array
    {
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Only students can take mock tests.');
        }
        [$parentId, $problemId] = CodingTestModel::parseProblemTestId($testId);
        $test = $this->tests->findById($parentId);
        if (!$test || ($test['status'] ?? '') !== 'published') {
            Response::notFound('Coding test not found.');
        }
        if (!$this->studentCanSeeTest($user, $test)) {
            Response::forbidden('This coding test is not available for your account.');
        }
        if (!CodingTestModel::isContestOpen($test)) {
            $when = CodingTestModel::contestScheduleLabel($test) ?: 'the scheduled day';
            Response::error('This contest is not open today. It runs on ' . $when . '.', 422);
        }
        if ($problemId !== '') {
            $pub = $this->buildSingleProblemTestView($test, $problemId);
            if ($pub === null) {
                Response::notFound('Coding problem not found.');
            }
            $duration = max(1, (int) ($pub['duration'] ?? 20));
            $attemptTitle = (string) ($pub['title'] ?? $test['title'] ?? '');
        } else {
            $pub = CodingTestModel::publicView($test, true);
            $duration = max(1, (int) ($test['duration'] ?? 20));
            $attemptTitle = (string) ($test['title'] ?? '');
        }
        $startedAt = (int) round(microtime(true) * 1000);
        $endsAt = $startedAt + $duration * 60 * 1000;
        $attemptId = $this->attempts->start([
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'testId' => $parentId,
            'testTitle' => $attemptTitle,
            'contestType' => (string) ($test['contestType'] ?? 'none'),
            'testKind' => CodingTestModel::normalizeTestKind((string) ($test['testKind'] ?? 'regular')),
            'companyId' => trim((string) ($test['companyId'] ?? '')) !== '' ? (string) $test['companyId'] : null,
            'problemItemId' => $problemId !== '' ? $problemId : null,
            'contestStartTime' => (string) ($test['contestStartTime'] ?? '09:00'),
            'periodKey' => CodingTestModel::periodKey($test),
            'contestWindowBounds' => CodingTestModel::contestWindowBounds($test),
            'endsAt' => $endsAt,
        ]);
        $answers = [];
        foreach ((array) ($pub['items'] ?? []) as $item) {
            $sample = null;
            foreach ((array) ($item['testCases'] ?? []) as $tc) {
                if (!empty($tc['sample'])) {
                    $sample = $tc;
                    break;
                }
            }
            $answers[(string) ($item['id'] ?? '')] = [
                'language' => 'Python',
                'code' => $item['starterCode']['Python'] ?? '',
                'customInput' => $sample['input'] ?? '',
                'lastRun' => null,
            ];
        }
        return [
            'attemptId' => $attemptId,
            'test' => $pub,
            'answers' => $answers,
            'startedAt' => $startedAt,
            'endsAt' => $endsAt,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function submit(array $user, string $attemptId, array $result): array
    {
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Only students can submit mock tests.');
        }
        $attempt = $this->attempts->findById($attemptId);
        if (!$attempt) {
            Response::notFound('Attempt not found.');
        }
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ((string) ($attempt['userId'] ?? '') !== $uid) {
            Response::forbidden('This attempt does not belong to you.');
        }
        $result['userId'] = $uid;
        $contestType = CodingTestModel::normalizeContestType((string) ($attempt['contestType'] ?? 'none'));
        $result['contestType'] = $contestType;
        if (in_array($contestType, ['weekly', 'monthly'], true)) {
            $test = $this->tests->findById((string) ($attempt['testId'] ?? ''));
            $open = is_array($test) && CodingTestModel::isContestOpen($test);
            $result['contestClosed'] = !$open;
            $result['winnersPublished'] = !$open;
            if (is_array($test)) {
                $result['contestStartTime'] = (string) ($attempt['contestStartTime'] ?? $test['contestStartTime'] ?? '09:00');
                $result['periodKey'] = (string) ($attempt['periodKey'] ?? CodingTestModel::periodKey($test, $attempt['submittedAt'] ?? null));
                $result['contestWindowBounds'] = is_array($attempt['contestWindowBounds'] ?? null)
                    ? $attempt['contestWindowBounds']
                    : CodingTestModel::contestWindowBounds($test, $attempt['submittedAt'] ?? null);
            }
        } else {
            $result['contestClosed'] = true;
            $result['winnersPublished'] = false;
        }
        $this->attempts->complete($attemptId, $result);
        return $result;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function myProgress(array $user): array
    {
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        $rows = $this->attempts->findAll(['userId' => $uid, 'status' => 'submitted'], 50, 0, ['submittedAt' => -1]);
        $history = [];
        $solved = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['questionResults'] ?? []) as $qr) {
                if (!is_array($qr)) {
                    continue;
                }
                if (($qr['status'] ?? '') === 'Correct' && ($qr['id'] ?? '') !== '') {
                    $solved[(string) $qr['id']] = true;
                }
            }
            $parentTestId = (string) ($row['testId'] ?? '');
            $problemItemId = trim((string) ($row['problemItemId'] ?? ''));
            $listTestId = $problemItemId !== ''
                ? CodingTestModel::composeProblemTestId($parentTestId, $problemItemId)
                : $parentTestId;
            $history[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'testId' => $parentTestId,
                'listTestId' => $listTestId,
                'problemItemId' => $problemItemId !== '' ? $problemItemId : null,
                'testTitle' => (string) ($row['testTitle'] ?? ''),
                'submittedAt' => $row['submittedAt'] ?? '',
                'score' => $row['score'] ?? 0,
                'totalMarks' => $row['totalMarks'] ?? 0,
                'percentage' => $row['percentage'] ?? 0,
                'status' => $row['resultStatus'] ?? $row['status'] ?? '',
                'contestType' => $row['contestType'] ?? 'none',
                'testKind' => $row['testKind'] ?? 'regular',
                'companyId' => $row['companyId'] ?? null,
                'dateLabel' => self::formatDateLabel($row['submittedAt'] ?? ''),
            ];
        }
        $percents = array_map(static fn($h) => (float) ($h['percentage'] ?? 0), $history);
        $best = $history[0] ?? null;
        foreach ($history as $h) {
            if ($best === null || (float) ($h['percentage'] ?? 0) > (float) ($best['percentage'] ?? 0)) {
                $best = $h;
            }
        }
        return [
            'problemsSolved' => count($solved),
            'bestScore' => $best ? (($best['score'] ?? 0) . ' / ' . ($best['totalMarks'] ?? 0)) : '0',
            'averageScore' => $percents === [] ? '0%' : (round(array_sum($percents) / count($percents), 1) . '%'),
            'recentScore' => $history !== [] ? (($history[0]['percentage'] ?? 0) . '%') : '0%',
            'history' => $history,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function directory(array $user, array $filters): array
    {
        AptitudeAccessService::requireDirectoryViewer($user);
        $filters = AptitudeAccessService::sanitizeDirectoryFilters($user, $filters);
        $allowed = AptitudeAccessService::authorizedSubjectUserIds($user);
        $wantContests = (($filters['resultType'] ?? '') === 'contests');
        $courseLookup = trim((string) ($filters['course'] ?? ''));
        $rows = $this->attempts->findAll(['status' => 'submitted'], 2000, 0, ['submittedAt' => -1]);
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            if (is_array($allowed) && !in_array($uid, $allowed, true)) {
                continue;
            }
            $contest = CodingTestModel::normalizeContestType((string) ($row['contestType'] ?? 'none'));
            if ($wantContests && $contest === 'none') {
                continue;
            }
            if (!$wantContests && $contest !== 'none' && $courseLookup === '') {
                continue;
            }
            $byUser[$uid][] = $row;
        }
        $out = [];
        $byReg = [];
        foreach ($byUser as $uid => $hist) {
            $row = $this->summarizeDirectoryUser($uid, $hist);
            foreach ($this->registerKeys($row) as $key) {
                $byReg[$key] = (string) $uid;
            }
            if (!$this->directoryRowMatches($row, $filters)) {
                continue;
            }
            $out[] = $row;
        }
        $course = trim((string) ($filters['course'] ?? ''));
        if ($course !== '' && !$wantContests) {
            $branchRows = $this->mergeBranchRoster($user, $filters, $out, $byUser, $byReg);
            if ($branchRows !== []) {
                $out = $branchRows;
            }
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        $allPercents = [];
        $bests = [];
        $totalAttempts = 0;
        $withAttempts = 0;
        foreach ($out as $row) {
            $attempts = (int) ($row['testsAttempted'] ?? 0);
            $totalAttempts += $attempts;
            if ($attempts > 0) {
                $withAttempts++;
                $allPercents[] = (float) ($row['averageScore'] ?? 0);
                $bests[] = (float) ($row['bestScore'] ?? 0);
            }
        }
        $summary = [
            'students' => count($out),
            'withAttempts' => $withAttempts,
            'totalAttempts' => $totalAttempts,
            'avgPercentage' => $allPercents === [] ? 0 : (int) round(array_sum($allPercents) / count($allPercents)),
            'avgBestScore' => $bests === [] ? 0 : (int) round(array_sum($bests) / count($bests)),
            'highestBestScore' => $bests === [] ? 0 : (int) max($bests),
        ];
        if ($wantContests) {
            $boardFilters = $filters;
            $boardFilters['q'] = '';
            $boardFilters['class'] = '';
            $boardFilters['course'] = '';
            return [
                'view' => 'contests',
                'contests' => $this->buildContestBoards($user, $byUser, $boardFilters, true),
                'summary' => $summary,
                'scope' => AptitudeAccessService::scopeInfo($user),
            ];
        }
        $q = trim((string) ($filters['q'] ?? ''));
        $course = trim((string) ($filters['course'] ?? ''));
        $needsFilter = $q === '' && $course === '';
        return [
            'rows' => $needsFilter ? [] : $out,
            'summary' => $needsFilter ? [
                'students' => 0,
                'withAttempts' => 0,
                'totalAttempts' => 0,
                'avgPercentage' => 0,
                'avgBestScore' => 0,
                'highestBestScore' => 0,
            ] : $summary,
            'needsFilter' => $needsFilter,
            'scope' => AptitudeAccessService::scopeInfo($user),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $hist
     * @return array<string, mixed>
     */
    private function summarizeDirectoryUser(string $uid, array $hist): array
    {
        $percents = array_map(static fn ($h) => (float) ($h['percentage'] ?? 0), $hist);
        $avg = $percents === [] ? 0 : (int) round(array_sum($percents) / count($percents));
        $best = $percents === [] ? 0 : (int) max($percents);
        $profile = AptitudeAccessService::loadSubjectProfile($uid) ?: [];
        $student = is_array($profile['student'] ?? null) ? $profile['student'] : ((new StudentModel())->findByUserId($uid) ?: []);
        $userDoc = (new UserModel())->findById($uid) ?: [];
        $cats = [];
        foreach ($hist as $h) {
            $title = (string) ($h['testTitle'] ?? 'Coding');
            $cats[$title] = (int) ($h['percentage'] ?? 0);
        }
        $classBatch = StaffContext::studentClassBatch($student);
        if ($classBatch === '') {
            $classBatch = StaffContext::studentClassBatch($userDoc);
        }
        $course = $this->studentBranchLabel($student, $userDoc, $classBatch);
        return [
            'userId' => $uid,
            'name' => (string) ($userDoc['name'] ?? $student['name'] ?? 'Student'),
            'userType' => (string) ($profile['type'] ?? ($userDoc['role'] ?? 'student')),
            'registerNumber' => (string) ($student['registerNumber'] ?? $student['studentId'] ?? ''),
            'studentCode' => (string) ($student['registerNumber'] ?? ''),
            'departmentId' => (string) ($profile['departmentId'] ?? $student['departmentId'] ?? ''),
            'classBatch' => $classBatch,
            'course' => $course,
            'testsAttempted' => count($hist),
            'averageScore' => $avg,
            'bestScore' => $best,
            'accuracy' => $avg,
            'recentScore' => (int) ($percents[0] ?? 0),
            'categoryPerformance' => $cats,
            'history' => $hist,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function directoryRowMatches(array $row, array $filters): bool
    {
        $departmentId = trim((string) ($filters['department'] ?? $filters['departmentId'] ?? ''));
        $course = trim((string) ($filters['course'] ?? ''));
        $userType = trim((string) ($filters['userType'] ?? ''));
        $q = strtolower(trim((string) ($filters['q'] ?? $filters['search'] ?? '')));
        if ($q !== '') {
            $name = strtolower((string) ($row['name'] ?? ''));
            $reg = strtolower((string) ($row['registerNumber'] ?? $row['studentCode'] ?? $row['studentId'] ?? ''));
            if (!str_contains($name, $q) && !str_contains($reg, $q)) {
                return false;
            }
        }
        if ($departmentId !== '' && (string) ($row['departmentId'] ?? '') !== $departmentId && (string) ($row['departmentId'] ?? '') !== '') {
            return false;
        }
        if ($course !== '' && !self::courseLabelMatches((string) ($row['course'] ?? ''), $course, (string) ($row['classBatch'] ?? ''))) {
            return false;
        }
        if ($userType !== '' && strcasecmp((string) ($row['userType'] ?? ''), $userType) !== 0) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $userDoc
     */
    private function studentBranchLabel(array $student, array $userDoc, string $classBatch): string
    {
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        foreach ([
            $student['stud_branch'] ?? '',
            $student['branch'] ?? '',
            $academic['branch'] ?? '',
            $academic['course'] ?? '',
            $student['course'] ?? '',
            $student['stud_course'] ?? '',
            $student['programme'] ?? '',
            $userDoc['course'] ?? '',
            $userDoc['programme'] ?? '',
            $userDoc['branch'] ?? '',
        ] as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                $code = DepartmentProgrammeCatalog::resolveProgrammeCode($value);
                return $code !== '' ? $code : $value;
            }
        }
        if ($classBatch !== '') {
            $code = DepartmentProgrammeCatalog::resolveProgrammeCode($classBatch);
            if ($code !== '') {
                return $code;
            }
            $norm = DepartmentProgrammeCatalog::normalizeCode($classBatch);
            if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
                return 'INMCA';
            }
            if (str_starts_with($norm, 'MCA')) {
                return 'MCA';
            }
            if (str_contains($norm, 'BCA')) {
                return 'BCA';
            }
        }
        return '';
    }

    private function mergeBranchRoster(array $viewer, array $filters, array $out, array $byUser, array $byReg = []): array
    {
        $wantCourse = trim((string) ($filters['course'] ?? ''));
        if ($wantCourse === '') {
            return $out;
        }
        $programme = DepartmentProgrammeCatalog::resolveProgrammeCode($wantCourse);
        if ($programme === '') {
            $programme = $wantCourse;
        }

        $officer = new OfficerDataService();
        $ctx = $this->directoryOfficerContext($viewer);
        $roster = [];
        try {
            $roster = $officer->listAesProgrammeStudents($ctx, $programme);
        } catch (\Throwable $e) {
            error_log('[PMS coding branch roster AES] ' . $e->getMessage());
        }
        try {
            foreach ($officer->listLocalProgrammeStudents($ctx, $programme) as $row) {
                $roster[] = $row;
            }
        } catch (\Throwable $e) {
            error_log('[PMS coding branch roster local] ' . $e->getMessage());
        }

        $merged = $this->attachCodingStatsToRoster($filters, $byUser, $byReg, $roster);
        return $merged !== [] ? $merged : $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, list<array<string, mixed>>> $byUser
     * @param array<string, string> $byReg
     * @param array<int, array<string, mixed>> $roster
     * @return array<int, array<string, mixed>>
     */
    private function attachCodingStatsToRoster(array $filters, array $byUser, array $byReg, array $roster): array
    {
        if ($byReg === []) {
            foreach ($byUser as $uid => $hist) {
                $sum = $this->summarizeDirectoryUser((string) $uid, is_array($hist) ? $hist : []);
                foreach ($this->registerKeys($sum) as $key) {
                    $byReg[$key] = (string) $uid;
                }
            }
        }

        foreach ($roster as $src) {
            if (!is_array($src)) {
                continue;
            }
            $fromRow = $this->rosterSourceUserId($src);
            if ($fromRow === '') {
                continue;
            }
            foreach ($this->registerKeys($src) as $key) {
                $byReg[$key] = $fromRow;
            }
        }

        $seenUid = [];
        $seenReg = [];
        $out = [];
        $studentModel = new StudentModel();
        foreach ($roster as $src) {
            if (!is_array($src)) {
                continue;
            }
            $regKeys = $this->registerKeys($src);
            $uid = $this->rosterSourceUserId($src);
            if ($uid === '') {
                foreach ($regKeys as $key) {
                    if (isset($byReg[$key])) {
                        $uid = $byReg[$key];
                        break;
                    }
                }
            }
            if ($uid === '') {
                foreach ($regKeys as $key) {
                    $local = $studentModel->findByRegisterNumber($key);
                    $found = trim((string) ($local['userId'] ?? ''));
                    if ($found !== '') {
                        $uid = $found;
                        $byReg[$key] = $found;
                        break;
                    }
                }
            }

            $existingIdx = null;
            if ($uid !== '' && isset($seenUid[$uid])) {
                $existingIdx = $seenUid[$uid];
            }
            if ($existingIdx === null) {
                foreach ($regKeys as $key) {
                    if (isset($seenReg[$key])) {
                        $existingIdx = $seenReg[$key];
                        break;
                    }
                }
            }
            if ($existingIdx !== null) {
                if ($uid !== '' && trim((string) ($out[$existingIdx]['userId'] ?? '')) === '') {
                    $out[$existingIdx] = $this->applyRosterIdentity(
                        $this->summarizeDirectoryUser($uid, $byUser[$uid] ?? []),
                        $src,
                        $out[$existingIdx]
                    );
                    $seenUid[$uid] = $existingIdx;
                }
                continue;
            }

            $row = $uid !== ''
                ? $this->summarizeDirectoryUser($uid, $byUser[$uid] ?? [])
                : $this->emptyDirectoryRowFromRoster($src);
            $row = $this->applyRosterIdentity($row, $src);

            $searchFilters = $filters;
            $searchFilters['class'] = '';
            $searchFilters['course'] = '';
            $searchFilters['department'] = '';
            if (!$this->directoryRowMatches($row, $searchFilters)) {
                continue;
            }

            $out[] = $row;
            $idx = count($out) - 1;
            if ($uid !== '') {
                $seenUid[$uid] = $idx;
            }
            foreach ($regKeys as $key) {
                $seenReg[$key] = $idx;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $src
     */
    private function rosterSourceUserId(array $src): string
    {
        $uid = trim((string) ($src['userId'] ?? ''));
        if ($uid !== '') {
            return $uid;
        }
        $userDoc = is_array($src['user'] ?? null) ? $src['user'] : [];

        return trim((string) ($userDoc['id'] ?? $userDoc['_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $src
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    private function applyRosterIdentity(array $row, array $src, ?array $previous = null): array
    {
        if (is_array($previous)) {
            foreach (['name', 'registerNumber', 'studentCode', 'classBatch', 'course'] as $field) {
                if (trim((string) ($row[$field] ?? '')) === '' && trim((string) ($previous[$field] ?? '')) !== '') {
                    $row[$field] = $previous[$field];
                }
            }
        }
        $userDoc = is_array($src['user'] ?? null) ? $src['user'] : [];
        $name = trim((string) ($src['displayName'] ?? $src['name'] ?? ($userDoc['name'] ?? '')));
        if ($name !== '') {
            $row['name'] = $name;
        }
        $primaryReg = strtoupper(trim((string) ($src['registerNumber'] ?? $src['admno'] ?? $src['studentCode'] ?? '')));
        if ($primaryReg !== '') {
            $row['registerNumber'] = $primaryReg;
            $row['studentCode'] = $primaryReg;
        }
        $classBatch = trim((string) ($src['classBatch'] ?? $src['stud_class'] ?? ''));
        if ($classBatch !== '') {
            $row['classBatch'] = $classBatch;
        }
        $course = $this->studentBranchLabel($src, $userDoc, $classBatch !== '' ? $classBatch : (string) ($row['classBatch'] ?? ''));
        if ($course !== '') {
            $row['course'] = $course;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $viewer
     * @return array<string, mixed>
     */
    private function directoryOfficerContext(array $viewer): array
    {
        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($viewer);
        if ($role === 'staff' || ($viewer['role'] ?? '') === 'staff') {
            return StaffContext::officerCompatible(StaffContext::resolve($viewer));
        }

        return PlacementOfficerContext::resolve($viewer);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function registerKeys(array $row): array
    {
        $keys = [];
        foreach (['registerNumber', 'admno', 'registerno', 'studentCode', 'studentId'] as $field) {
            $raw = strtoupper(trim((string) ($row[$field] ?? '')));
            if ($raw === '') {
                continue;
            }
            $keys[$raw] = true;
            $compact = strtoupper((string) preg_replace('/[^A-Z0-9]/', '', $raw));
            if ($compact !== '') {
                $keys[$compact] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, mixed> $src
     * @return array<string, mixed>
     */
    private function emptyDirectoryRowFromRoster(array $src): array
    {
        $reg = strtoupper(trim((string) ($src['registerNumber'] ?? $src['admno'] ?? $src['studentCode'] ?? '')));
        $classBatch = trim((string) ($src['classBatch'] ?? $src['stud_class'] ?? ''));
        $userDoc = is_array($src['user'] ?? null) ? $src['user'] : [];
        $dept = is_array($src['department'] ?? null) ? $src['department'] : [];

        return [
            'userId' => '',
            'name' => (string) ($src['displayName'] ?? $src['name'] ?? ($userDoc['name'] ?? 'Student')),
            'userType' => 'student',
            'registerNumber' => $reg,
            'studentCode' => $reg,
            'departmentId' => (string) ($src['departmentId'] ?? $dept['id'] ?? ''),
            'classBatch' => $classBatch,
            'course' => $this->studentBranchLabel($src, $userDoc, $classBatch),
            'testsAttempted' => 0,
            'averageScore' => 0,
            'bestScore' => 0,
            'accuracy' => 0,
            'recentScore' => 0,
            'categoryPerformance' => [],
            'history' => [],
        ];
    }

    private static function courseLabelMatches(string $studentCourse, string $want, string $studentClass): bool
    {
        $want = trim($want);
        if ($want === '') {
            return true;
        }
        $studentCourse = trim($studentCourse);
        if ($studentCourse !== '' && strcasecmp($studentCourse, $want) === 0) {
            return true;
        }
        $wantCode = DepartmentProgrammeCatalog::resolveProgrammeCode($want);
        $rowCode = DepartmentProgrammeCatalog::resolveProgrammeCode($studentCourse !== '' ? $studentCourse : $studentClass);
        if ($wantCode !== '' && $rowCode !== '' && strcasecmp($wantCode, $rowCode) === 0) {
            return true;
        }
        $compactCourse = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $studentCourse));
        $compactWant = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $wantCode !== '' ? $wantCode : $want));
        if ($compactCourse !== '' && $compactWant !== '' && (str_starts_with($compactCourse, $compactWant) || str_starts_with($compactWant, $compactCourse))) {
            return true;
        }
        $compactClass = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $studentClass));
        return $compactClass !== '' && $compactWant !== '' && str_starts_with($compactClass, $compactWant);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function subjectProgress(array $user, string $userId): array
    {
        AptitudeAccessService::requireDirectoryViewer($user);
        $allowed = AptitudeAccessService::authorizedSubjectUserIds($user);
        if (is_array($allowed) && !in_array($userId, $allowed, true)) {
            Response::forbidden('You cannot view this student.');
        }
        $student = (new StudentModel())->findByUserId($userId) ?: [];
        $userDoc = (new UserModel())->findById($userId) ?: [];
        $hist = $this->attempts->findAll(['userId' => $userId, 'status' => 'submitted'], 50, 0, ['submittedAt' => -1]);
        $history = [];
        foreach ($hist as $row) {
            $history[] = [
                'testTitle' => (string) ($row['testTitle'] ?? ''),
                'percentage' => $row['percentage'] ?? 0,
                'score' => $row['score'] ?? 0,
                'totalMarks' => $row['totalMarks'] ?? 0,
                'status' => $row['resultStatus'] ?? '',
                'contestType' => $row['contestType'] ?? 'none',
                'submittedAt' => $row['submittedAt'] ?? '',
                'dateLabel' => self::formatDateLabel($row['submittedAt'] ?? ''),
            ];
        }
        return [
            'name' => (string) ($userDoc['name'] ?? $student['name'] ?? 'Student'),
            'registerNumber' => (string) ($student['registerNumber'] ?? $student['studentId'] ?? ''),
            'classBatch' => (string) StaffContext::studentClassBatch($student),
            'history' => $history,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function contestBoard(array $user): array
    {
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        $officerView = AptitudeAccessService::canViewDirectory($user);
        $allowed = $officerView ? AptitudeAccessService::authorizedSubjectUserIds($user) : null;
        $byUser = [];
        foreach ($this->attempts->findAll(['status' => 'submitted'], 2000, 0, ['submittedAt' => -1]) as $row) {
            if (CodingTestModel::normalizeContestType((string) ($row['contestType'] ?? 'none')) === 'none') {
                continue;
            }
            $id = (string) ($row['userId'] ?? '');
            if ($id === '') {
                continue;
            }
            if (is_array($allowed) && !in_array($id, $allowed, true)) {
                continue;
            }
            $byUser[$id][] = $row;
        }
        $filters = $officerView ? AptitudeAccessService::sanitizeDirectoryFilters($user, []) : [];
        $contests = $this->buildContestBoards($user, $byUser, $filters, true);
        if (!$officerView) {
            foreach ($contests as $i => $contest) {
                $mine = null;
                $pool = array_merge(
                    (array) ($contest['liveParticipants'] ?? []),
                    (array) ($contest['participants'] ?? [])
                );
                foreach ($pool as $p) {
                    if ((string) ($p['userId'] ?? '') === $uid) {
                        $mine = $p;
                        break;
                    }
                }
                $contests[$i]['myResult'] = $mine;
                $contests[$i]['liveParticipants'] = [];
                if (empty($contest['winnersPublished'])) {
                    $contests[$i]['participants'] = $mine ? [$mine] : [];
                    $contests[$i]['winners'] = [];
                }
            }
        }
        return [
            'contests' => $contests,
            'myUserId' => $uid,
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byUser
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildContestBoards(array $viewer, array $byUser, array $filters, bool $officerView): array
    {
        $tests = [];
        foreach ($this->tests->findAll([], 500, 0, ['createdAt' => -1]) as $row) {
            if (CodingTestModel::normalizeContestType((string) ($row['contestType'] ?? 'none')) === 'none') {
                continue;
            }
            if (($row['status'] ?? '') !== 'published') {
                continue;
            }
            $tests[(string) ($row['_id'] ?? $row['id'] ?? '')] = $row;
        }
        $grouped = [];
        foreach ($byUser as $uid => $hist) {
            $profile = $this->summarizeDirectoryUser((string) $uid, $hist);
            if ($officerView && !$this->directoryRowMatches($profile, $filters)) {
                continue;
            }
            foreach ($hist as $row) {
                if (CodingTestModel::normalizeContestType((string) ($row['contestType'] ?? 'none')) === 'none') {
                    continue;
                }
                $tid = (string) ($row['testId'] ?? '');
                if ($tid === '') {
                    continue;
                }
                $grouped[$tid][] = [
                    'userId' => (string) $uid,
                    'name' => (string) ($profile['name'] ?? 'Student'),
                    'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                    'classBatch' => (string) ($profile['classBatch'] ?? ''),
                    'percentage' => (float) ($row['percentage'] ?? 0),
                    'score' => (float) ($row['score'] ?? 0),
                    'totalMarks' => (float) ($row['totalMarks'] ?? 0),
                    'status' => (string) ($row['resultStatus'] ?? ''),
                    'submittedAt' => $row['submittedAt'] ?? '',
                    'testTitle' => (string) ($row['testTitle'] ?? ''),
                ];
            }
        }
        $out = [];
        $ids = array_unique(array_merge(array_keys($tests), array_keys($grouped)));
        foreach ($ids as $tid) {
            $test = $tests[$tid] ?? [];
            $raw = $grouped[$tid] ?? [];
            $title = (string) ($test['title'] ?? ($raw[0]['testTitle'] ?? 'Contest'));
            $type = $test !== []
                ? CodingTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none'))
                : CodingTestModel::normalizeContestType((string) ($raw[0]['contestType'] ?? 'weekly'));
            $currentKey = $test !== [] ? CodingTestModel::periodKey($test) : CodingTestModel::contestPeriodKey($type);
            $previousKey = CodingTestModel::previousContestPeriodKey($type);
            $current = [];
            $previous = [];
            foreach ($raw as $p) {
                $key = $test !== []
                    ? CodingTestModel::periodKey($test, $p['submittedAt'] ?? '')
                    : CodingTestModel::contestPeriodKey($type, $p['submittedAt'] ?? '');
                if ($key === $previousKey) {
                    $previous[] = $p;
                } else {
                    $current[] = $p;
                }
            }
            $current = self::rankContestParticipants($current);
            $previous = self::rankContestParticipants($previous);
            $open = $test !== [] ? CodingTestModel::isContestOpen($test) : true;
            $winnersPublished = $previous !== [];
            $out[] = [
                'id' => $tid,
                'title' => $title !== '' ? $title : 'Contest',
                'contestType' => $type,
                'contestOpen' => $open,
                'contestClosed' => !$open,
                'contestStartTime' => $test !== [] ? (string) ($test['contestStartTime'] ?? '09:00') : '09:00',
                'contestScheduleLabel' => $test !== [] ? CodingTestModel::contestScheduleLabel($test) : '',
                'contestWindowBounds' => $test !== [] ? CodingTestModel::contestWindowBounds($test) : [],
                'winnersPublished' => $winnersPublished,
                'winners' => $winnersPublished ? array_slice($previous, 0, 3) : [],
                'participants' => $winnersPublished ? $previous : [],
                'liveParticipants' => $current,
                'participantCount' => count($current),
                'periodKey' => $currentKey,
                'currentPeriodKey' => $currentKey,
                'previousPeriodKey' => $previousKey,
            ];
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function rankContestParticipants(array $rows): array
    {
        $best = [];
        foreach ($rows as $p) {
            $uid = (string) ($p['userId'] ?? '');
            $slot = $uid !== '' ? $uid : ('row-' . count($best));
            if (isset($best[$slot]) && ($p['percentage'] ?? 0) <= ($best[$slot]['percentage'] ?? 0)) {
                continue;
            }
            $best[$slot] = $p;
        }
        $rows = array_values($best);
        usort($rows, static function ($a, $b) {
            $cmp = ($b['percentage'] <=> $a['percentage']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ($b['score'] <=> $a['score']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['submittedAt'] ?? ''), (string) ($b['submittedAt'] ?? ''));
        });
        foreach ($rows as $i => $p) {
            $rows[$i]['rank'] = $i + 1;
            $rows[$i]['points'] = (int) round(($p['percentage'] ?? 0) * 10);
        }
        return $rows;
    }

    private static function formatDateLabel(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        return date('d M Y', $ts);
    }

    /**
     * @return array<string, mixed>
     */
    public function listCompanyBlockCompanies(): array
    {
        $rows = (new CompanyModel())->listEnriched(500);
        $companies = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['_id'] ?? ''));
            $name = trim((string) ($row['companyName'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $companies[] = ['id' => $id, 'name' => $name];
        }
        usort($companies, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['name'] ?? ''),
            (string) ($b['name'] ?? '')
        ));

        return ['companies' => $companies];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listCompanyBlockForAdmin(array $user): array
    {
        AptitudeAccessService::requireManager($user);
        $model = new CodingCompanyProblemSetModel();
        $sets = $model->listSummaries();
        $blocks = $this->mergeCompanyBlocks($model->listCompanyBlocks(), $this->companyBlocksFromTests(false));

        return [
            'sets' => $sets,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listCompanyBlockForStudent(array $user): array
    {
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Students only.');
        }
        $model = new CodingCompanyProblemSetModel();
        $blocks = $this->mergeCompanyBlocks($model->listStudentCompanyBlocks(), $this->companyBlocksFromTests(true));

        return ['blocks' => $blocks];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getCompanyBlockSet(array $user, string $id, bool $student = false): array
    {
        if ($student) {
            if (!AptitudeAccessService::canTake($user)) {
                Response::forbidden('Students only.');
            }
        } else {
            AptitudeAccessService::requireManager($user);
        }
        $model = new CodingCompanyProblemSetModel();
        $set = $model->findById($id);
        if ($set === null) {
            Response::notFound('Company problem set not found.');
        }
        if ($student && trim((string) ($set['companyId'] ?? '')) === '') {
            Response::notFound('Company problem set not found.');
        }

        return $model->publicDetail($set);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteCompanyBlockSet(array $user, string $id): void
    {
        AptitudeAccessService::requireManager($user);
        $model = new CodingCompanyProblemSetModel();
        if ($model->findById($id) === null) {
            Response::notFound('Company problem set not found.');
        }
        if (!$model->deleteSet($id)) {
            Response::error('Could not delete company problem set.', 422);
        }
    }

    /**
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $extra
     * @return list<array<string, mixed>>
     */
    private function mergeCompanyBlocks(array $base, array $extra): array
    {
        /** @var array<string, array<string, mixed>> $map */
        $map = [];
        foreach (array_merge($base, $extra) as $block) {
            $companyId = trim((string) ($block['companyId'] ?? ''));
            if ($companyId === '') {
                continue;
            }
            if (!isset($map[$companyId])) {
                $map[$companyId] = [
                    'companyId' => $companyId,
                    'companyName' => (string) ($block['companyName'] ?? 'Company'),
                    'setCount' => 0,
                    'problemCount' => 0,
                    'sets' => [],
                ];
            }
            $map[$companyId]['setCount'] += (int) ($block['setCount'] ?? 0);
            $map[$companyId]['problemCount'] += (int) ($block['problemCount'] ?? 0);
            foreach ((array) ($block['sets'] ?? []) as $set) {
                if (!is_array($set)) {
                    continue;
                }
                $sid = (string) ($set['id'] ?? '');
                $exists = false;
                foreach ($map[$companyId]['sets'] as $existing) {
                    if ((string) ($existing['id'] ?? '') === $sid) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $map[$companyId]['sets'][] = $set;
                }
            }
            if (trim((string) ($block['companyName'] ?? '')) !== '') {
                $map[$companyId]['companyName'] = (string) $block['companyName'];
            }
        }
        $out = array_values($map);
        usort($out, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['companyName'] ?? ''),
            (string) ($b['companyName'] ?? '')
        ));

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function companyBlocksFromTests(bool $publishedOnly): array
    {
        $rows = $this->tests->findAll([], 500, 0, ['createdAt' => -1]);
        /** @var array<string, array<string, mixed>> $map */
        $map = [];
        foreach ($rows as $row) {
            if (!CodingTestModel::isCompanyTest($row)) {
                continue;
            }
            if ($publishedOnly && ($row['status'] ?? '') !== 'published') {
                continue;
            }
            $companyId = trim((string) ($row['companyId'] ?? ''));
            if ($companyId === '') {
                continue;
            }
            $companyName = trim((string) ($row['companyName'] ?? ''));
            if ($companyName === '') {
                $companyName = 'Company';
            }
            if (!isset($map[$companyId])) {
                $map[$companyId] = [
                    'companyId' => $companyId,
                    'companyName' => $companyName,
                    'setCount' => 0,
                    'problemCount' => 0,
                    'sets' => [],
                ];
            }
        }

        return array_values($map);
    }
}
