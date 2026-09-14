<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingAttemptModel;
use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Response;

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
            $view = CodingTestModel::publicView($row, false);
            unset($view['items']);
            $out[] = $view;
        }
        return $out;
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
     * @return array<string, mixed>
     */
    public function start(array $user, string $testId): array
    {
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Only students can take mock tests.');
        }
        $test = $this->tests->findById($testId);
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
        $duration = max(1, (int) ($test['duration'] ?? 20));
        $startedAt = (int) round(microtime(true) * 1000);
        $endsAt = $startedAt + $duration * 60 * 1000;
        $attemptId = $this->attempts->start([
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'testId' => $testId,
            'testTitle' => (string) ($test['title'] ?? ''),
            'contestType' => (string) ($test['contestType'] ?? 'none'),
            'endsAt' => $endsAt,
        ]);
        $pub = CodingTestModel::publicView($test, true);
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
            $history[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'testId' => (string) ($row['testId'] ?? ''),
                'testTitle' => (string) ($row['testTitle'] ?? ''),
                'submittedAt' => $row['submittedAt'] ?? '',
                'score' => $row['score'] ?? 0,
                'totalMarks' => $row['totalMarks'] ?? 0,
                'percentage' => $row['percentage'] ?? 0,
                'status' => $row['resultStatus'] ?? $row['status'] ?? '',
                'contestType' => $row['contestType'] ?? 'none',
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
        foreach ($byUser as $uid => $hist) {
            $row = $this->summarizeDirectoryUser($uid, $hist);
            if (!$this->directoryRowMatches($row, $filters)) {
                continue;
            }
            $out[] = $row;
        }
        $course = trim((string) ($filters['course'] ?? ''));
        if ($course !== '' && !$wantContests) {
            $out = $this->mergeBranchRoster($user, $filters, $out, $byUser);
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
        if ($departmentId !== '' && (string) ($row['departmentId'] ?? '') !== $departmentId) {
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
     * @param array<string, mixed> $viewer
     * @param array<string, mixed> $filters
     * @param array<int, array<string, mixed>> $out
     * @param array<string, list<array<string, mixed>>> $byUser
     * @return array<int, array<string, mixed>>
     */
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

    private function mergeBranchRoster(array $viewer, array $filters, array $out, array $byUser): array
    {
        $wantCourse = trim((string) ($filters['course'] ?? ''));
        if ($wantCourse === '') {
            return $out;
        }
        $seen = [];
        foreach ($out as $row) {
            $uid = (string) ($row['userId'] ?? '');
            if ($uid !== '') {
                $seen[$uid] = true;
            }
        }
        $allowed = AptitudeAccessService::authorizedSubjectUserIds($viewer);
        $deptId = trim((string) ($filters['department'] ?? ''));
        $query = $deptId !== '' ? ['departmentId' => $deptId] : [];
        foreach ((new StudentModel())->findAll($query, 3000) as $student) {
            $uid = trim((string) ($student['userId'] ?? ''));
            if ($uid === '' || isset($seen[$uid])) {
                continue;
            }
            if (is_array($allowed) && !in_array($uid, $allowed, true)) {
                continue;
            }
            $classBatch = StaffContext::studentClassBatch($student);
            $branch = $this->studentBranchLabel($student, [], $classBatch);
            if (!self::courseLabelMatches($branch, $wantCourse, $classBatch)) {
                continue;
            }
            $out[] = $this->summarizeDirectoryUser($uid, $byUser[$uid] ?? []);
            $seen[$uid] = true;
        }
        return $out;
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
            $currentKey = CodingTestModel::contestPeriodKey($type);
            $previousKey = CodingTestModel::previousContestPeriodKey($type);
            $current = [];
            $previous = [];
            foreach ($raw as $p) {
                $key = CodingTestModel::contestPeriodKey($type, $p['submittedAt'] ?? '');
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
                'contestScheduleLabel' => $test !== [] ? CodingTestModel::contestScheduleLabel($test) : '',
                'winnersPublished' => $winnersPublished,
                'winners' => $winnersPublished ? array_slice($previous, 0, 3) : [],
                'participants' => $winnersPublished ? $previous : [],
                'liveParticipants' => $current,
                'participantCount' => count($current),
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
}
