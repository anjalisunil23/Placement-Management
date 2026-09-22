<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingAttemptModel;
use PMS\Models\CodingCompanyProblemSetModel;
use PMS\Models\CodingPracticeSubmissionModel;
use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;
use PMS\Models\CompanyModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

final class CodingService
{
    private CodingTestModel $tests;
    private CodingProblemBankModel $bank;
    private CodingAttemptModel $attempts;
    private CodingPracticeSubmissionModel $practiceSubmissions;

    public function __construct()
    {
        $this->tests = new CodingTestModel();
        $this->bank = new CodingProblemBankModel();
        $this->attempts = new CodingAttemptModel();
        $this->practiceSubmissions = new CodingPracticeSubmissionModel();
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
        AptitudeAccessService::requireCodingManager($user);
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
        AptitudeAccessService::requireCodingManager($user);
        $data = AptitudeAccessService::applyTestDepartmentScope($user, $data);
        $data = AptitudeAccessService::sanitizeContestFields($user, $data);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            Response::error('Enter a test title.', 422);
        }
        $data = $this->requireContestBankSource($data);
        $data = $this->resolveTestItems($data);
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
        AptitudeAccessService::requireCodingManager($user);
        $existing = $this->tests->findById($id);
        if (!$existing) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($user, $existing);
        $data = AptitudeAccessService::applyTestDepartmentScope($user, $data);
        $data = AptitudeAccessService::sanitizeContestFields($user, $data);
        if (!AptitudeAccessService::canManageCodingContests($user)) {
            $data['contestType'] = $existing['contestType'] ?? 'none';
            $data['contestWeekday'] = $existing['contestWeekday'] ?? 1;
            $data['contestMonthDay'] = $existing['contestMonthDay'] ?? 1;
        }
        $merged = array_merge($existing, $data);
        if (CodingTestModel::normalizeContestType((string) ($merged['contestType'] ?? 'none')) !== 'none') {
            $data = $this->requireContestBankSource($merged);
        }
        $data = $this->resolveTestItems(array_merge($existing, $data));
        $this->tests->saveExisting($id, $data);
        $doc = $this->tests->findById($id);
        return CodingTestModel::publicView($doc ?: $existing, true);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteTest(array $user, string $id): void
    {
        AptitudeAccessService::requireCodingManager($user);
        if (!Security::isValidId($id)) {
            Response::error('Invalid coding test id.', 400);
        }
        $existing = $this->tests->findById($id);
        if (!$existing) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($user, $existing);
        $contestType = CodingTestModel::normalizeContestType((string) ($existing['contestType'] ?? 'none'));
        if (in_array($contestType, ['weekly', 'monthly'], true) && !AptitudeAccessService::canManageCodingContests($user)) {
            Response::forbidden('You cannot delete coding contests.');
        }
        if (!$this->tests->delete($id)) {
            Response::error('Could not delete test.', 500);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listBank(array $user, ?string $category = null, ?string $difficulty = null): array
    {
        AptitudeAccessService::requireCodingManager($user);
        return $this->bank->listProblems($category, $difficulty);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateAiBankProblems(array $user, array $body): array
    {
        AptitudeAccessService::requireCodingManager($user);
        try {
            return (new CodingAiProblemService())->generateFromRequest($body);
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), 503);
        } catch (\Throwable $e) {
            error_log('[PMS coding AI] generate failed: ' . $e->getMessage());
            $msg = trim($e->getMessage());
            Response::error($msg !== '' ? $msg : 'AI generation failed. Please try again.', 503);
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
        AptitudeAccessService::requireCodingManager($user);
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
        AptitudeAccessService::requireCodingManager($user);
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
        AptitudeAccessService::requireCodingManager($user);
        if (!Security::isValidId($id)) {
            Response::notFound('Problem not found.');
        }
        if (!$this->bank->findById($id)) {
            Response::notFound('Problem not found.');
        }
        if (!$this->bank->delete($id)) {
            Response::error('Could not delete problem.', 500);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $ids
     * @return array<string, mixed>
     */
    public function bulkDeleteBankProblems(array $user, array $ids): array
    {
        AptitudeAccessService::requireCodingManager($user);
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
    public function attemptResult(array $user, string $attemptId): array
    {
        $attempt = $this->attempts->findById($attemptId);
        if (!$attempt) {
            Response::notFound('Attempt not found.');
        }
        if (!AptitudeAccessService::canViewAttempt($user, $attempt)) {
            Response::forbidden('You cannot view this attempt result.');
        }
        if (($attempt['status'] ?? '') !== 'submitted') {
            Response::forbidden('Result is available only after submission.');
        }
        $seconds = max(0, (int) ($attempt['timeTakenSeconds'] ?? 0));
        $minutes = intdiv($seconds, 60);
        $timeTakenLabel = (string) ($attempt['timeTakenLabel'] ?? sprintf('%02d:%02d', $minutes, $seconds % 60));
        $score = (float) ($attempt['score'] ?? 0);
        $totalMarks = (float) ($attempt['totalMarks'] ?? 0);
        $percentage = (float) ($attempt['percentage'] ?? 0);
        $status = (string) ($attempt['resultStatus'] ?? $attempt['status'] ?? '');
        if ($status === 'submitted') {
            $status = $percentage >= 40 ? 'Passed' : 'Failed';
        }
        return [
            'attemptId' => $attemptId,
            'testId' => (string) ($attempt['testId'] ?? ''),
            'testTitle' => (string) ($attempt['testTitle'] ?? ''),
            'score' => $score,
            'totalMarks' => $totalMarks,
            'percentage' => $percentage,
            'passed' => stripos($status, 'pass') !== false,
            'status' => $status,
            'correct' => (int) ($attempt['correct'] ?? 0),
            'incorrect' => (int) ($attempt['incorrect'] ?? 0),
            'skipped' => (int) ($attempt['skipped'] ?? 0),
            'questions' => (int) ($attempt['questions'] ?? count((array) ($attempt['questionResults'] ?? []))),
            'testsPassed' => (int) ($attempt['testsPassed'] ?? 0),
            'testsTotal' => (int) ($attempt['testsTotal'] ?? 0),
            'timeTakenSeconds' => $seconds,
            'timeTakenLabel' => $timeTakenLabel,
            'questionResults' => array_values((array) ($attempt['questionResults'] ?? [])),
            'contestType' => (string) ($attempt['contestType'] ?? 'none'),
            'winnersPublished' => !empty($attempt['winnersPublished']),
            'contestClosed' => !empty($attempt['contestClosed']),
            'submittedAt' => $attempt['submittedAt'] ?? '',
        ];
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
        $resultType = $this->normalizeDirectoryResultType((string) ($filters['resultType'] ?? ''));
        if ($resultType === 'contests') {
            return $this->contestResultsDirectory($user, $filters);
        }

        $allowed = AptitudeAccessService::authorizedSubjectUserIds($user);
        if (is_array($allowed) && $allowed === []) {
            return $this->emptyAttemptDirectory($user);
        }

        $role = \PMS\Middleware\AuthMiddleware::resolvedRole($user);
        $rows = $this->attempts->findAll(['status' => 'submitted'], 2000, 0, ['submittedAt' => -1]);
        /** @var array<string, array<string, mixed>> $testCache */
        $testCache = [];
        /** @var array<string, list<array<string, mixed>>> $attemptsByKey */
        $attemptsByKey = [];
        foreach ($rows as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid === '' || (is_array($allowed) && !in_array($uid, $allowed, true))) {
                continue;
            }
            $testId = (string) ($attempt['testId'] ?? '');
            if ($testId === '') {
                continue;
            }
            if (!isset($testCache[$testId])) {
                $testCache[$testId] = $this->tests->findById($testId) ?: [];
            }
            if (!$this->attemptMatchesDirectoryResultType($attempt, $testCache[$testId], $resultType)) {
                continue;
            }
            $problemId = trim((string) ($attempt['problemItemId'] ?? ''));
            $key = $uid . '|' . $testId . '|' . $problemId;
            $attemptsByKey[$key][] = $attempt;
        }
        foreach ($attemptsByKey as &$group) {
            usort($group, static function (array $a, array $b): int {
                $ta = strtotime((string) ($a['submittedAt'] ?? $a['completedAt'] ?? '')) ?: 0;
                $tb = strtotime((string) ($b['submittedAt'] ?? $b['completedAt'] ?? '')) ?: 0;

                return $ta <=> $tb;
            });
        }
        unset($group);

        $out = [];
        /** @var array<string, array<string, mixed>> $profileCache */
        $profileCache = [];
        foreach ($attemptsByKey as $group) {
            if ($group === []) {
                continue;
            }
            $finalAttempt = $group[count($group) - 1];
            $uid = (string) ($finalAttempt['userId'] ?? '');
            $testId = (string) ($finalAttempt['testId'] ?? '');
            $test = $testCache[$testId] ?? [];
            if (!AptitudeAccessService::canViewSubject($user, $uid)) {
                continue;
            }
            if (!isset($profileCache[$uid])) {
                $profileCache[$uid] = $this->summarizeDirectoryUser($uid, $group);
            }
            $profile = $profileCache[$uid];
            if ($role === 'staff') {
                $staffCtx = StaffContext::resolve($user);
                $assigned = StaffContext::assignedClassBatches($staffCtx);
                $studentClass = (string) ($profile['classBatch'] ?? '');
                if ($assigned === [] || !StaffContext::classBatchMatchesAssigned($studentClass, $assigned)) {
                    continue;
                }
            }
            if (($profile['userType'] ?? '') !== 'student') {
                if ($resultType === 'company' || in_array($role, ['staff', 'placement_officer'], true)) {
                    continue;
                }
            }
            if ($role !== 'admin' && ($profile['userType'] ?? '') === 'alumni') {
                continue;
            }
            if (!$this->matchesDirectoryFilters($profile, $filters)) {
                continue;
            }

            $attemptId = (string) ($finalAttempt['_id'] ?? '');
            $marksObtained = (float) ($finalAttempt['score'] ?? 0);
            $totalMarks = (float) ($finalAttempt['totalMarks'] ?? $test['totalMarks'] ?? 0);
            $percentage = (float) ($finalAttempt['percentage'] ?? 0);
            $out[] = [
                'attemptId' => $attemptId,
                'userId' => $uid,
                'name' => (string) ($profile['name'] ?? 'User'),
                'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                'studentCode' => (string) ($profile['studentCode'] ?? $profile['registerNumber'] ?? ''),
                'classBatch' => (string) ($profile['classBatch'] ?? ''),
                'testId' => $testId,
                'testTitle' => (string) ($finalAttempt['testTitle'] ?? $test['title'] ?? 'Coding test'),
                'attemptCount' => count($group),
                'marksObtained' => $marksObtained,
                'totalMarks' => $totalMarks,
                'score' => $marksObtained,
                'percentage' => $percentage,
                'completedAt' => $finalAttempt['completedAt'] ?? $finalAttempt['submittedAt'] ?? null,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['completedAt'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['completedAt'] ?? '')) ?: 0;
            if ($tb !== $ta) {
                return $tb <=> $ta;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $percentages = array_map(static fn ($r) => (float) ($r['percentage'] ?? 0), $out);
        $studentIds = [];
        foreach ($out as $row) {
            $uid = (string) ($row['userId'] ?? '');
            if ($uid !== '') {
                $studentIds[$uid] = true;
            }
        }

        return [
            'view' => 'attempts',
            'rows' => $out,
            'scope' => AptitudeAccessService::scopeInfo($user),
            'summary' => [
                'attemptCount' => count($out),
                'students' => count($studentIds),
                'avgPercentage' => $percentages === [] ? 0 : round(array_sum($percentages) / count($percentages), 1),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function publishContestResults(array $admin, string $id, bool $published): array
    {
        AptitudeAccessService::requireCodingManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid coding test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        if (!CodingTestModel::isContest($test)) {
            Response::error('Results can only be published for weekly or monthly contests.', 422);
        }
        if ($published) {
            if (CodingTestModel::contestStatus($test) !== 'COMPLETED') {
                Response::error('Results can only be published after the contest has ended.', 422);
            }
            if (CodingTestModel::resultsPublished($test)) {
                Response::error('Contest results are already published.', 422);
            }
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
        if (!$this->tests->update($id, $patch)) {
            Response::error('Could not update contest results visibility.', 500);
        }
        $fresh = $this->tests->findById($id) ?: $test;

        return CodingTestModel::publicView($fresh, true);
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function contestResultsPreview(array $admin, string $id): array
    {
        AptitudeAccessService::requireCodingManager($admin);
        if (!Security::isValidId($id)) {
            Response::error('Invalid coding test id.', 400);
        }
        $test = $this->tests->findById($id);
        if (!$test) {
            Response::notFound('Coding test not found.');
        }
        AptitudeAccessService::assertTestManageable($admin, $test);
        if (!CodingTestModel::isContest($test)) {
            Response::error('Contest results are available only for weekly or monthly contests.', 422);
        }
        if (CodingTestModel::contestStatus($test) !== 'COMPLETED') {
            Response::error('Results preview is available only after the contest has ended.', 422);
        }

        $participants = $this->contestLeaderboardRows($test);
        $window = CodingTestModel::contestWindow($test);
        $view = CodingTestModel::publicView($test, true);

        return [
            'contest' => array_merge($view, [
                'participantCount' => count($participants),
                'contestStartAt' => $window['start'] ?? null,
                'contestEndAt' => $window['end'] ?? null,
            ]),
            'participants' => $participants,
            'summary' => [
                'participantCount' => count($participants),
                'resultStatus' => CodingTestModel::resultStatus($test),
                'resultPublishedAt' => CodingTestModel::resultPublishedAt($test),
            ],
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
        $classBatch = trim((string) ($filters['class'] ?? $filters['classBatch'] ?? ''));
        if ($classBatch !== '') {
            $rowClass = trim((string) ($row['classBatch'] ?? ''));
            if ($rowClass === '' || !self::classLabelMatches($rowClass, $classBatch)) {
                return false;
            }
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
        AptitudeAccessService::requireCodingManager($user);
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
            AptitudeAccessService::requireCodingManager($user);
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
        AptitudeAccessService::requireCodingManager($user);
        if (!Security::isValidId($id)) {
            Response::notFound('Company problem set not found.');
        }
        $model = new CodingCompanyProblemSetModel();
        if ($model->findById($id) === null) {
            Response::notFound('Company problem set not found.');
        }
        if (!$model->deleteSet($id)) {
            Response::error('Could not delete company problem set.', 500);
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

    /**
     * @return array<string, mixed>
     */
    private function emptyAttemptDirectory(array $user): array
    {
        return [
            'view' => 'attempts',
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($user),
            'summary' => [
                'attemptCount' => 0,
                'students' => 0,
                'avgPercentage' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContestDirectory(array $user): array
    {
        return [
            'view' => 'contests',
            'contests' => [],
            'completedContests' => [],
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($user),
            'summary' => [
                'contestCount' => 0,
                'publishedCount' => 0,
                'pendingCount' => 0,
                'totalParticipants' => 0,
                'uniqueParticipants' => 0,
                'avgPercentage' => 0,
                'highestScore' => 0,
            ],
        ];
    }

    private function normalizeDirectoryResultType(string $resultType): string
    {
        $resultType = strtolower(trim($resultType));
        if ($resultType === 'contests' || $resultType === 'company') {
            return $resultType;
        }

        return 'tests';
    }

    /**
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $test
     */
    private function attemptMatchesDirectoryResultType(array $attempt, array $test, string $resultType): bool
    {
        $resultType = $this->normalizeDirectoryResultType($resultType);
        $contest = CodingTestModel::normalizeContestType((string) ($attempt['contestType'] ?? $test['contestType'] ?? 'none'));
        $isCompany = CodingTestModel::isCompanyTest($test)
            || CodingTestModel::normalizeTestKind((string) ($attempt['testKind'] ?? '')) === 'company'
            || trim((string) ($attempt['companyId'] ?? '')) !== '';
        if ($resultType === 'contests') {
            return in_array($contest, ['weekly', 'monthly'], true);
        }
        if ($resultType === 'company') {
            return $isCompany;
        }

        return !in_array($contest, ['weekly', 'monthly'], true) && !$isCompany;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $filters
     */
    private function matchesDirectoryFilters(array $profile, array $filters): bool
    {
        return $this->directoryRowMatches($profile, $filters);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function contestResultsDirectory(array $user, array $filters): array
    {
        $allowed = AptitudeAccessService::authorizedSubjectUserIds($user);
        if (is_array($allowed) && $allowed === []) {
            return $this->emptyContestDirectory($user);
        }

        $attempts = $this->attempts->findAll(['status' => 'submitted'], 5000, 0, ['submittedAt' => -1]);
        /** @var array<string, int> $countsByTest */
        $countsByTest = [];
        /** @var array<string, true> $uniqueUsers */
        $uniqueUsers = [];
        $percentageSum = 0.0;
        $percentageCount = 0;
        $highestScore = 0.0;
        /** @var array<string, array<string, mixed>> $testCache */
        $testCache = [];
        /** @var array<string, array<string, mixed>> $profileCache */
        $profileCache = [];

        foreach ($attempts as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            $testId = (string) ($attempt['testId'] ?? '');
            if ($uid === '' || $testId === '' || (is_array($allowed) && !in_array($uid, $allowed, true))) {
                continue;
            }
            if (!isset($testCache[$testId])) {
                $testCache[$testId] = $this->tests->findById($testId) ?: [];
            }
            $test = $testCache[$testId];
            if (!CodingTestModel::isContest($test)) {
                continue;
            }
            if (!isset($profileCache[$uid])) {
                $profileCache[$uid] = $this->summarizeDirectoryUser($uid, [$attempt]);
            }
            if (!$this->matchesDirectoryFilters($profileCache[$uid], $filters)) {
                continue;
            }
            $countsByTest[$testId] = ($countsByTest[$testId] ?? 0) + 1;
            $uniqueUsers[$uid] = true;
            $pct = (float) ($attempt['percentage'] ?? 0);
            $percentageSum += $pct;
            $percentageCount++;
            if ($pct > $highestScore) {
                $highestScore = $pct;
            }
        }

        $contests = [];
        $listed = [];
        foreach ($countsByTest as $testId => $count) {
            if ($count <= 0) {
                continue;
            }
            $test = $testCache[$testId] ?? $this->tests->findById($testId) ?: [];
            if ($test === [] || !CodingTestModel::isContest($test)) {
                continue;
            }
            if (CodingTestModel::contestStatus($test) !== 'COMPLETED') {
                continue;
            }
            $window = CodingTestModel::contestWindow($test);
            $entry = $this->contestDirectoryEntry($test, $testId, $window);
            $entry['participantCount'] = $count;
            $contests[] = $entry;
            $listed[$testId] = true;
        }

        foreach ($testCache as $testId => $test) {
            if (isset($listed[$testId]) || $test === [] || !CodingTestModel::isContest($test)) {
                continue;
            }
            if (CodingTestModel::contestStatus($test) !== 'COMPLETED') {
                continue;
            }
            $window = CodingTestModel::contestWindow($test);
            $contests[] = $this->contestDirectoryEntry($test, $testId, $window);
        }

        usort($contests, static fn (array $a, array $b): int => strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));

        $totalParticipants = array_sum($countsByTest);
        $publishedCount = count(array_filter(
            $contests,
            static fn (array $c): bool => CodingTestModel::resultsPublished($c)
                || strtoupper((string) ($c['resultStatus'] ?? '')) === 'PUBLISHED'
        ));

        return [
            'view' => 'contests',
            'contests' => $contests,
            'completedContests' => $contests,
            'rows' => [],
            'scope' => AptitudeAccessService::scopeInfo($user),
            'summary' => [
                'contestCount' => count($contests),
                'publishedCount' => $publishedCount,
                'pendingCount' => max(0, count($contests) - $publishedCount),
                'totalParticipants' => $totalParticipants,
                'uniqueParticipants' => count($uniqueUsers),
                'avgPercentage' => $percentageCount === 0 ? 0 : round($percentageSum / $percentageCount, 1),
                'highestScore' => $highestScore,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @param array{start:?string,end:?string} $window
     * @return array<string, mixed>
     */
    private function contestDirectoryEntry(array $test, string $testId, array $window): array
    {
        return [
            'testId' => $testId,
            'id' => $testId,
            'title' => (string) ($test['title'] ?? 'Contest'),
            'category' => (string) ($test['category'] ?? ''),
            'contestType' => CodingTestModel::normalizeContestType((string) ($test['contestType'] ?? 'none')),
            'contestScheduleLabel' => CodingTestModel::contestScheduleLabel($test),
            'contestStatus' => CodingTestModel::contestStatus($test),
            'contestStartAt' => $window['start'] ?? null,
            'contestEndAt' => $window['end'] ?? null,
            'resultsPublished' => CodingTestModel::resultsPublished($test),
            'resultStatus' => CodingTestModel::resultStatus($test),
            'resultPublishedAt' => CodingTestModel::resultPublishedAt($test),
            'participantCount' => 0,
            'participants' => [],
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @return array<int, array<string, mixed>>
     */
    private function contestLeaderboardRows(array $test): array
    {
        $testId = (string) ($test['_id'] ?? $test['id'] ?? '');
        if ($testId === '') {
            return [];
        }
        $attempts = $this->attempts->findAll(['testId' => $testId, 'status' => 'submitted'], 5000, 0, ['submittedAt' => -1]);
        if ($attempts === []) {
            return [];
        }

        $rows = [];
        foreach ($attempts as $attempt) {
            $uid = (string) ($attempt['userId'] ?? '');
            if ($uid === '') {
                continue;
            }
            $profile = $this->summarizeDirectoryUser($uid, [$attempt]);
            $rows[] = [
                'userId' => $uid,
                'name' => (string) ($profile['name'] ?? 'User'),
                'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                'studentCode' => (string) ($profile['studentCode'] ?? $profile['registerNumber'] ?? ''),
                'classBatch' => (string) ($profile['classBatch'] ?? ''),
                'marksObtained' => (float) ($attempt['score'] ?? 0),
                'totalMarks' => (float) ($attempt['totalMarks'] ?? $test['totalMarks'] ?? 0),
                'score' => (float) ($attempt['score'] ?? 0),
                'percentage' => (float) ($attempt['percentage'] ?? 0),
                'timeTakenLabel' => '—',
                'timeTakenSeconds' => 0,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $sa = (float) ($a['percentage'] ?? 0);
            $sb = (float) ($b['percentage'] ?? 0);
            if ($sb !== $sa) {
                return $sb <=> $sa;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return $rows;
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requireContestBankSource(array $data): array
    {
        if (CodingTestModel::normalizeContestType((string) ($data['contestType'] ?? 'none')) === 'none') {
            return $data;
        }

        $source = strtolower(trim((string) ($data['questionSource'] ?? 'random')));
        if (!in_array($source, ['manual', 'random'], true)) {
            $source = 'random';
        }
        $data['questionSource'] = $source;

        if ($source === 'manual') {
            $bankRules = array_values(array_filter((array) ($data['bankFilterRules'] ?? []), 'is_array'));
            $bankIds = array_values(array_filter((array) ($data['bankProblemIds'] ?? []), static fn ($id): bool => is_string($id) && trim($id) !== ''));
            if ($bankRules === [] && $bankIds === []) {
                Response::error('Contest problems must be picked from the question bank. Select problems by topic.', 422);
            }
            $data['bankFilterRules'] = $bankRules;
            $data['bankProblemIds'] = $bankIds;
            $data['randomRules'] = [];
            $data['items'] = [];

            return $data;
        }

        $rules = array_values(array_filter((array) ($data['randomRules'] ?? []), 'is_array'));
        if ($rules === []) {
            Response::error('Contest problems must be picked from the problem bank. Add at least one topic and difficulty rule.', 422);
        }
        $data['randomRules'] = $rules;
        $data['bankFilterRules'] = [];
        $data['bankProblemIds'] = [];
        $data['items'] = [];

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function resolveTestItems(array $data): array
    {
        $source = strtolower(trim((string) ($data['questionSource'] ?? 'manual')));
        if (!in_array($source, ['manual', 'random'], true)) {
            $source = 'manual';
        }
        $data['questionSource'] = $source;

        if ($source === 'random') {
            $rules = array_values(array_filter((array) ($data['randomRules'] ?? []), 'is_array'));
            if ($rules === []) {
                Response::error('Add at least one topic + difficulty rule for random selection.', 422);
            }
            $expected = max(0, (int) ($data['questionCount'] ?? 0));
            $ruleTotal = array_sum(array_map(static fn (array $r): int => max(0, (int) ($r['count'] ?? 0)), $rules));
            if ($expected > 0 && $ruleTotal > 0 && $expected !== $ruleTotal) {
                Response::error('Total problems must match the sum of random rule counts.', 422);
            }
            try {
                $items = $this->bank->pickRandomByRules($rules);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            if ($items === []) {
                Response::error('Could not pick problems from the bank for the given rules.', 422);
            }
            $data['items'] = $items;
            $data['questionCount'] = count($items);
            $data['bankProblemIds'] = [];
            $data['bankFilterRules'] = [];
            if (!empty($rules[0]['category'])) {
                $data['category'] = CodingTestModel::normalizeCategory((string) $rules[0]['category']);
            }
            if (!empty($rules[0]['difficulty'])) {
                $data['difficulty'] = CodingTestModel::normalizeDifficulty((string) $rules[0]['difficulty']);
            }

            return $data;
        }

        $bankIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => trim((string) $id), (array) ($data['bankProblemIds'] ?? [])),
            static fn ($id) => $id !== '' && Security::isValidId($id)
        )));
        $inline = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
        $filterRules = array_values(array_filter((array) ($data['bankFilterRules'] ?? []), 'is_array'));
        $items = [];

        if ($filterRules !== []) {
            try {
                $items = $this->bank->resolveByRulesWithPreferred($filterRules, $bankIds);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 422);
            }
            $bankIds = array_values(array_filter(array_map(
                static fn (array $q): string => trim((string) ($q['bankId'] ?? '')),
                $items
            )));
        } elseif ($bankIds !== []) {
            $items = $this->bank->problemsByIds($bankIds);
        }

        foreach ($inline as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $title = trim((string) ($q['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            if (empty($q['id'])) {
                $q['id'] = 'p-' . ($i + 1);
            }
            $items[] = $q;
        }

        if ($items === []) {
            Response::error('Add at least one problem to the test.', 422);
        }

        $data['items'] = $items;
        $data['questionCount'] = count($items);
        $data['bankProblemIds'] = $bankIds;
        $data['randomRules'] = [];
        if (trim((string) ($data['category'] ?? '')) === '' && $items !== []) {
            $data['category'] = CodingTestModel::normalizeCategory((string) ($items[0]['category'] ?? 'Algorithms'));
        }
        if (trim((string) ($data['difficulty'] ?? '')) === '' && $items !== []) {
            $data['difficulty'] = CodingTestModel::normalizeDifficulty((string) ($items[0]['difficulty'] ?? 'Medium'));
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listPracticeProblems(array $user, ?string $category = null, ?string $difficulty = null): array
    {
        $this->requirePracticeViewer($user);
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        $statusMap = AptitudeAccessService::canTake($user)
            ? $this->practiceSubmissions->statusMapForUser($uid)
            : [];
        $rows = $this->bank->listProblems($category, $difficulty, 2000);
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $view = CodingProblemBankModel::publicView($row, $id);
            $stat = $statusMap[$id] ?? null;
            $view['practiceStatus'] = $stat ? (string) ($stat['status'] ?? 'attempted') : 'unsolved';
            $view['attemptCount'] = $stat ? (int) ($stat['attemptCount'] ?? 0) : 0;
            $out[] = $view;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getPracticeProblem(array $user, string $id): array
    {
        $this->requirePracticeViewer($user);
        if (!Security::isValidId($id)) {
            Response::error('Invalid problem id.', 400);
        }
        $row = $this->bank->findById($id);
        if (!$row) {
            Response::notFound('Problem not found.');
        }
        $view = CodingProblemBankModel::publicView($row, $id);
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        if ($uid !== '' && AptitudeAccessService::canTake($user)) {
            $stat = $this->practiceSubmissions->statusMapForUser($uid)[$id] ?? null;
            $view['practiceStatus'] = $stat ? (string) ($stat['status'] ?? 'attempted') : 'unsolved';
            $view['attemptCount'] = $stat ? (int) ($stat['attemptCount'] ?? 0) : 0;
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listPracticeSubmissions(array $user): array
    {
        AptitudeAccessService::requirePortalUser($user);
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Practice submissions are available to students only.');
        }
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        $rows = $this->practiceSubmissions->listByUser($uid, 100);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'bankProblemId' => (string) ($row['bankProblemId'] ?? ''),
                'problemTitle' => (string) ($row['problemTitle'] ?? ''),
                'language' => (string) ($row['language'] ?? 'Python'),
                'status' => !empty($row['accepted']) ? 'Accepted' : 'Wrong Answer',
                'accepted' => !empty($row['accepted']),
                'testsPassed' => (int) ($row['testsPassed'] ?? 0),
                'testsTotal' => (int) ($row['testsTotal'] ?? 0),
                'score' => (float) ($row['score'] ?? 0),
                'totalMarks' => (float) ($row['totalMarks'] ?? 0),
                'percentage' => (float) ($row['percentage'] ?? 0),
                'timeTakenSeconds' => (int) ($row['timeTakenSeconds'] ?? 0),
                'submittedAt' => (string) ($row['submittedAt'] ?? ''),
                'dateLabel' => self::formatDateLabel($row['submittedAt'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submitPracticeProblem(array $user, string $bankProblemId, array $body): array
    {
        AptitudeAccessService::requirePortalUser($user);
        if (!AptitudeAccessService::canTake($user)) {
            Response::forbidden('Only students can submit practice solutions.');
        }
        if (!Security::isValidId($bankProblemId)) {
            Response::error('Invalid problem id.', 400);
        }
        $row = $this->bank->findById($bankProblemId);
        if (!$row) {
            Response::notFound('Problem not found.');
        }
        $uid = (string) ($user['_id'] ?? $user['id'] ?? '');
        $testsPassed = max(0, (int) ($body['testsPassed'] ?? 0));
        $testsTotal = max(0, (int) ($body['testsTotal'] ?? 0));
        $accepted = !empty($body['accepted']) || ($testsTotal > 0 && $testsPassed === $testsTotal);
        $payload = [
            'userId' => $uid,
            'bankProblemId' => $bankProblemId,
            'problemTitle' => (string) ($body['problemTitle'] ?? $row['title'] ?? ''),
            'language' => (string) ($body['language'] ?? 'Python'),
            'status' => $accepted ? 'accepted' : 'wrong',
            'accepted' => $accepted,
            'testsPassed' => $testsPassed,
            'testsTotal' => $testsTotal,
            'score' => (float) ($body['score'] ?? ($accepted ? ($row['marks'] ?? 2) : 0)),
            'totalMarks' => (float) ($body['totalMarks'] ?? ($row['marks'] ?? 2)),
            'percentage' => (float) ($body['percentage'] ?? ($accepted ? 100 : 0)),
            'timeTakenSeconds' => max(0, (int) ($body['timeTakenSeconds'] ?? 0)),
        ];
        $submissionId = $this->practiceSubmissions->record($payload);
        $stat = $this->practiceSubmissions->statusMapForUser($uid)[$bankProblemId] ?? null;

        return [
            'submissionId' => $submissionId,
            'bankProblemId' => $bankProblemId,
            'accepted' => $accepted,
            'status' => $accepted ? 'Accepted' : 'Wrong Answer',
            'testsPassed' => $testsPassed,
            'testsTotal' => $testsTotal,
            'score' => $payload['score'],
            'totalMarks' => $payload['totalMarks'],
            'percentage' => $payload['percentage'],
            'practiceStatus' => $stat ? (string) ($stat['status'] ?? 'attempted') : ($accepted ? 'solved' : 'attempted'),
            'attemptCount' => $stat ? (int) ($stat['attemptCount'] ?? 0) : 1,
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function requirePracticeViewer(array $user): void
    {
        AptitudeAccessService::requirePortalUser($user);
        if (!AptitudeAccessService::canTake($user) && !AptitudeAccessService::canManageCoding($user)) {
            Response::forbidden('You cannot browse coding problems.');
        }
    }
}
