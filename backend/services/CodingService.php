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
            if (!$wantContests && $contest !== 'none') {
                continue;
            }
            $byUser[$uid][] = $row;
        }
        $out = [];
        $allPercents = [];
        $bests = [];
        $totalAttempts = 0;
        foreach ($byUser as $uid => $hist) {
            $row = $this->summarizeDirectoryUser($uid, $hist);
            if (!$this->directoryRowMatches($row, $filters)) {
                continue;
            }
            $totalAttempts += (int) ($row['testsAttempted'] ?? 0);
            $allPercents[] = (float) ($row['averageScore'] ?? 0);
            $bests[] = (float) ($row['bestScore'] ?? 0);
            $out[] = $row;
        }
        usort($out, static fn ($a, $b) => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        $summary = [
            'students' => count($out),
            'withAttempts' => count($out),
            'totalAttempts' => $totalAttempts,
            'avgPercentage' => $allPercents === [] ? 0 : (int) round(array_sum($allPercents) / count($allPercents)),
            'avgBestScore' => $bests === [] ? 0 : (int) round(array_sum($bests) / count($bests)),
            'highestBestScore' => $bests === [] ? 0 : (int) max($bests),
        ];
        if ($wantContests) {
            $contests = [];
            foreach ($byUser as $uid => $hist) {
                $profile = $this->summarizeDirectoryUser($uid, $hist);
                if (!$this->directoryRowMatches($profile, $filters)) {
                    continue;
                }
                foreach ($hist as $row) {
                    $tid = (string) ($row['testId'] ?? '');
                    if ($tid === '') {
                        continue;
                    }
                    if (!isset($contests[$tid])) {
                        $contests[$tid] = [
                            'title' => (string) ($row['testTitle'] ?? 'Contest'),
                            'participants' => [],
                        ];
                    }
                    $contests[$tid]['participants'][] = [
                        'userId' => $uid,
                        'name' => (string) ($profile['name'] ?? 'Student'),
                        'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                        'percentage' => $row['percentage'] ?? 0,
                        'status' => $row['resultStatus'] ?? '',
                    ];
                }
            }
            return [
                'view' => 'contests',
                'contests' => array_values($contests),
                'summary' => $summary,
                'scope' => AptitudeAccessService::scopeInfo($user),
            ];
        }
        return [
            'rows' => $out,
            'summary' => $summary,
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
        $course = trim((string) ($student['academic']['course'] ?? $student['course'] ?? ''));
        $classBatch = (string) StaffContext::studentClassBatch($student);
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
        $classBatch = trim((string) ($filters['class'] ?? $filters['classBatch'] ?? ''));
        $course = trim((string) ($filters['course'] ?? ''));
        $userType = trim((string) ($filters['userType'] ?? ''));
        if ($departmentId !== '' && (string) ($row['departmentId'] ?? '') !== $departmentId) {
            return false;
        }
        if ($classBatch !== '' && strcasecmp((string) ($row['classBatch'] ?? ''), $classBatch) !== 0) {
            return false;
        }
        if ($course !== '' && strcasecmp((string) ($row['course'] ?? ''), $course) !== 0) {
            return false;
        }
        if ($userType !== '' && strcasecmp((string) ($row['userType'] ?? ''), $userType) !== 0) {
            return false;
        }
        return true;
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
