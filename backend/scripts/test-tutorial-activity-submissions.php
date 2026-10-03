<?php

declare(strict_types=1);

/**
 * Tutorial practical activity student submission tests (Phase 4D).
 * Usage: php backend/scripts/test-tutorial-activity-submissions.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleActivityReviewModel;
use PMS\Models\TutorialModuleActivitySubmissionModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialActivityService;
use PMS\Services\TutorialService;

final class DuplicateKeyActivitySubmissionModel extends TutorialModuleActivitySubmissionModel
{
    public function createSubmission(array $data): array
    {
        throw new PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
            23000
        );
    }
}

$failed = 0;
$passed = 0;
$check = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};
$throws = static function (callable $fn, string $label) use ($check): void {
    try {
        $fn();
        $check(false, $label . ' (expected exception)');
    } catch (Throwable $e) {
        $check(true, $label . ' → ' . $e->getMessage());
    }
};
$assertNoSecrets = static function (mixed $payload, string $label) use ($check): void {
    $json = json_encode($payload);
    $check(
        is_string($json)
        && !str_contains($json, 'answerKey')
        && !str_contains($json, 'expectedValue')
        && !str_contains($json, 'modelAnswer')
        && !str_contains($json, 'privateNotes'),
        $label
    );
};

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$service = new TutorialService();
$activities = new TutorialActivityService($service);
$activityModel = new TutorialModuleActivityModel();
$submissionModel = new TutorialModuleActivitySubmissionModel();
$reviewModel = new TutorialModuleActivityReviewModel();

$userIds = [];
$studentIds = [];
$tutorialIds = [];
$moduleIds = [];
$deptIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-act-sub.test',
        'password' => 'tutorial-act-sub-test-pass',
        'role' => $role,
    ]);
    $userIds[] = $id;
    $user = $users->findById($id);
    if (!is_array($user)) {
        throw new RuntimeException('User missing.');
    }

    return $user;
};

try {
    $staff = $makeUser('staff', 'sub_staff');
    $studentUser = $makeUser('student', 'sub_student');
    $otherStudentUser = $makeUser('student', 'sub_other');
    $outsiderUser = $makeUser('student', 'sub_out');
    $staffAsClient = $makeUser('staff', 'sub_staff_client');

    $deptId = $departments->createDepartment([
        'name' => 'Act Sub Dept ' . $suffix,
        'code' => 'AS' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $otherDeptId = $departments->createDepartment([
        'name' => 'Act Sub Other ' . $suffix,
        'code' => 'AO' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $deptIds[] = $deptId;
    $deptIds[] = $otherDeptId;

    $studentId = $students->createProfile((string) $studentUser['_id'], [
        'registerNumber' => 'SS' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $otherStudentId = $students->createProfile((string) $otherStudentUser['_id'], [
        'registerNumber' => 'SO' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $outsiderId = $students->createProfile((string) $outsiderUser['_id'], [
        'registerNumber' => 'SX' . $suffix,
        'departmentId' => $otherDeptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $studentIds = [$studentId, $otherStudentId, $outsiderId];

    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'category ready');

    $course = $service->createTutorial($staff, [
        'title' => 'Submission Course ' . $suffix,
        'description' => 'Student activity submission course.',
        'topic' => 'Practice',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialId = (string) ($course['id'] ?? '');
    $tutorialIds[] = $tutorialId;
    $module = $service->createModule($staff, $tutorialId, [
        'title' => 'Module 1',
        'subtitle' => 'Activities',
        'content' => '<p>Lesson for activities.</p>',
    ]);
    $moduleId = (string) ($module['id'] ?? '');
    $moduleIds[] = $moduleId;
    $check($moduleId !== '', 'module created');

    $draft = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Draft only',
        'instructions' => 'Should not be visible to students.',
        'activityType' => 'short_answer',
        'evaluationMode' => 'tutor_review',
        'status' => 'draft',
    ]);
    $draftId = (string) ($draft['id'] ?? '');

    $noneMode = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Read only activity',
        'instructions' => 'No submissions allowed.',
        'activityType' => 'short_answer',
        'evaluationMode' => 'none',
        'status' => 'draft',
    ]);
    $noneId = (string) ($noneMode['id'] ?? '');

    $typeDefs = [
        'programming_task' => [
            'title' => 'Programming task',
            'instructions' => 'Write a loop.',
            'activityType' => 'programming_task',
            'evaluationMode' => 'tutor_review',
            'config' => ['language' => 'python', 'boilerplate' => 'pass'],
            'answerKey' => ['modelAnswer' => 'for i in range(5): print(i)'],
        ],
        'sql_query' => [
            'title' => 'SQL query',
            'instructions' => 'Select active students.',
            'activityType' => 'sql_query',
            'evaluationMode' => 'tutor_review',
            'config' => ['schemaDescription' => 'students(id, active)'],
            'answerKey' => ['modelAnswer' => 'SELECT * FROM students WHERE active=1'],
        ],
        'numerical' => [
            'title' => 'Numerical',
            'instructions' => 'Compute resistance.',
            'activityType' => 'numerical',
            'evaluationMode' => 'auto_compare',
            'config' => ['unit' => 'ohm', 'tolerance' => 0.1],
            'answerKey' => ['expectedValue' => 5],
        ],
        'short_answer' => [
            'title' => 'Short answer',
            'instructions' => 'Define SWOT.',
            'activityType' => 'short_answer',
            'evaluationMode' => 'self_check',
            'config' => ['selfCheckRubric' => 'Mentions Strengths Weaknesses Opportunities Threats'],
            'answerKey' => ['modelAnswer' => 'SWOT analysis', 'keywords' => ['strengths', 'weaknesses']],
        ],
        'case_study' => [
            'title' => 'Case study',
            'instructions' => 'Analyse the case.',
            'activityType' => 'case_study',
            'evaluationMode' => 'tutor_review',
            'config' => [
                'parts' => [
                    ['id' => 'part-1', 'prompt' => 'Risks?'],
                    ['id' => 'part-2', 'prompt' => 'Mitigations?'],
                ],
            ],
            'answerKey' => ['modelAnswer' => 'Staff rubric'],
        ],
        'analytical_design' => [
            'title' => 'Analytical design',
            'instructions' => 'Design a cooling plan.',
            'activityType' => 'analytical_design',
            'evaluationMode' => 'tutor_review',
            'config' => ['deliverableHint' => 'Include airflow notes.'],
            'answerKey' => ['modelAnswer' => 'Private rubric'],
        ],
    ];

    $publishedIds = [];
    foreach ($typeDefs as $type => $def) {
        $row = $activities->create($staff, $tutorialId, $moduleId, $def);
        $publishedIds[$type] = (string) ($row['id'] ?? '');
    }

    $service->publish($staff, $tutorialId);
    foreach ($publishedIds as $id) {
        $activities->publish($staff, $tutorialId, $moduleId, $id);
    }
    $activities->publish($staff, $tutorialId, $moduleId, $noneId);

    // 1-3 list published, hide draft/archived
    $listed = $activities->listForStudent($studentUser, $tutorialId, $moduleId);
    $listedIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $listed['activities'] ?? []);
    $check(in_array($publishedIds['numerical'], $listedIds, true), '1 authorized student can list published activities');
    $check(!in_array($draftId, $listedIds, true), '2 student cannot see drafts');

    $toArchive = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Soon archived',
        'instructions' => 'Will be archived.',
        'activityType' => 'short_answer',
        'evaluationMode' => 'tutor_review',
        'status' => 'published',
    ]);
    $archiveId = (string) ($toArchive['id'] ?? '');
    $activities->publish($staff, $tutorialId, $moduleId, $archiveId);
    $activities->archive($staff, $tutorialId, $moduleId, $archiveId);
    $listed2 = $activities->listForStudent($studentUser, $tutorialId, $moduleId);
    $listedIds2 = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $listed2['activities'] ?? []);
    $check(!in_array($archiveId, $listedIds2, true), '3 student cannot see archived activities');

    // 4-5 visibility
    $scoped = $service->createTutorial($staff, [
        'title' => 'Scoped Sub Course ' . $suffix,
        'description' => 'Scoped visibility.',
        'topic' => 'Scope',
        'categoryId' => $categoryId,
        'visibility' => 'scoped',
        'departmentIds' => [$deptId],
        'passingYears' => ['2027'],
    ]);
    $scopedTutorialId = (string) ($scoped['id'] ?? '');
    $tutorialIds[] = $scopedTutorialId;
    $scopedModule = $service->createModule($staff, $scopedTutorialId, [
        'title' => 'Scoped module',
        'subtitle' => '',
        'content' => '<p>Scoped</p>',
    ]);
    $scopedModuleId = (string) ($scopedModule['id'] ?? '');
    $moduleIds[] = $scopedModuleId;
    $scopedAct = $activities->create($staff, $scopedTutorialId, $scopedModuleId, [
        'title' => 'Scoped activity',
        'instructions' => 'Dept restricted.',
        'activityType' => 'short_answer',
        'evaluationMode' => 'tutor_review',
    ]);
    $scopedActId = (string) ($scopedAct['id'] ?? '');
    $service->publish($staff, $scopedTutorialId);
    $activities->publish($staff, $scopedTutorialId, $scopedModuleId, $scopedActId);

    $scopedList = $activities->listForStudent($studentUser, $scopedTutorialId, $scopedModuleId);
    $check(($scopedList['count'] ?? 0) >= 1, '4 course visibility enforced for authorized student');
    $throws(static function () use ($activities, $outsiderUser, $scopedTutorialId, $scopedModuleId): void {
        $activities->listForStudent($outsiderUser, $scopedTutorialId, $scopedModuleId);
    }, '5 student cannot access another department restricted activity');

    // 6-7 view without answer keys; student id from auth
    $view = $activities->getForStudent($studentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $assertNoSecrets($view, '6 student can view authorized activity without answer keys');
    $started = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $attemptId = (string) ($started['attempt']['id'] ?? '');
    $rawAttempt = $submissionModel->findById($attemptId);
    $check(
        is_array($rawAttempt) && (string) ($rawAttempt['studentId'] ?? '') === $studentId,
        '7 student ID is derived from authentication'
    );

    // 8-10 start / resume / concurrent
    $check(($started['resumed'] ?? true) === false && ($started['attempt']['status'] ?? '') === 'IN_PROGRESS', '8 student can start an attempt');
    $again = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $check(
        ($again['resumed'] ?? false) === true
        && (string) ($again['attempt']['id'] ?? '') === $attemptId,
        '9 repeated start requests do not create duplicate active attempts'
    );

    $raceService = new TutorialActivityService($service, $activityModel, new DuplicateKeyActivitySubmissionModel(), $reviewModel);
    $raceStudent = $makeUser('student', 'sub_race');
    $studentIds[] = $students->createProfile((string) $raceStudent['_id'], [
        'registerNumber' => 'SR' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    try {
        $raceService->startAttempt($raceStudent, $tutorialId, $moduleId, $publishedIds['short_answer']);
        $check(false, '10 concurrent start handling is safe (expected exception)');
    } catch (Throwable $e) {
        $check(
            $e->getMessage() === 'Could not start the activity. Please try again.'
            && !str_contains($e->getMessage(), 'SQLSTATE')
            && !str_contains($e->getMessage(), '1062'),
            '10 concurrent start handling is safe'
        );
    }

    // 11-13 response structures / invalid / oversized
    $validResponses = [
        'programming_task' => ['source' => "print('hi')"],
        'sql_query' => ['sql' => 'SELECT 1'],
        'numerical' => ['value' => 5, 'unit' => 'ohm'],
        'short_answer' => ['text' => 'Strengths and weaknesses'],
        'case_study' => ['parts' => ['part-1' => 'Risk A', 'part-2' => 'Mitigation B']],
        'analytical_design' => ['text' => 'Use redundant cooling loops.'],
    ];
    foreach ($validResponses as $type => $response) {
        $start = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds[$type]);
        $aid = (string) ($start['attempt']['id'] ?? '');
        if ($type === 'numerical') {
            // already started earlier
            $aid = $attemptId;
        }
        $saved = $activities->saveResponse($studentUser, $tutorialId, $moduleId, $publishedIds[$type], [
            'attemptId' => $aid,
            'response' => $response,
        ]);
        $check(($saved['attempt']['status'] ?? '') === 'IN_PROGRESS', '11 accepts response structure for ' . $type);
    }

    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $publishedIds, $attemptId): void {
        $activities->saveResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
            'attemptId' => $attemptId,
            'response' => ['value' => 'not-a-number'],
        ]);
    }, '12 invalid response structures are rejected');

    $progStart = $activities->startAttempt($otherStudentUser, $tutorialId, $moduleId, $publishedIds['programming_task']);
    $progAttemptId = (string) ($progStart['attempt']['id'] ?? '');
    $throws(static function () use ($activities, $otherStudentUser, $tutorialId, $moduleId, $publishedIds, $progAttemptId): void {
        $activities->saveResponse($otherStudentUser, $tutorialId, $moduleId, $publishedIds['programming_task'], [
            'attemptId' => $progAttemptId,
            'response' => ['source' => str_repeat('x', 20001)],
        ]);
    }, '13 oversized responses are rejected');

    // 14-16 save ownership / submitted lock
    $savedOk = $activities->saveResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $attemptId,
        'response' => ['value' => 5, 'unit' => 'ohm'],
    ]);
    $check(($savedOk['attempt']['payload']['value'] ?? null) == 5, '14 student can save a response');

    $throws(static function () use ($activities, $otherStudentUser, $tutorialId, $moduleId, $publishedIds, $attemptId): void {
        $activities->saveResponse($otherStudentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
            'attemptId' => $attemptId,
            'response' => ['value' => 1],
        ]);
    }, '15 student cannot save another student attempt');

    $submitted = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $attemptId,
        'response' => ['value' => 5, 'unit' => 'ohm', 'score' => 100, 'matched' => false, 'expectedValue' => 999],
    ]);
    $check(($submitted['attempt']['status'] ?? '') === 'SUBMITTED', '17 student can submit a valid attempt');
    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $publishedIds, $attemptId): void {
        $activities->saveResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
            'attemptId' => $attemptId,
            'response' => ['value' => 9],
        ]);
    }, '16 student cannot modify a submitted attempt');

    // 18 idempotent submit
    $againSubmit = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $attemptId,
        'response' => ['value' => 5],
    ]);
    $check(($againSubmit['idempotent'] ?? false) === true, '18 repeated submission is idempotent');

    // 19 client scores ignored
    $check(
        ($submitted['attempt']['autoResult']['matched'] ?? false) === true
        && !isset($submitted['attempt']['payload']['score'])
        && !isset($submitted['attempt']['payload']['expectedValue']),
        '19 client-supplied scores and correctness fields are ignored'
    );

    // 20-22 numerical auto compare
    $exactStart = $activities->startAttempt($otherStudentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $exactId = (string) ($exactStart['attempt']['id'] ?? '');
    $exact = $activities->submitResponse($otherStudentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $exactId,
        'response' => ['value' => 5],
    ]);
    $check(($exact['attempt']['autoResult']['matched'] ?? false) === true, '20 numerical auto-comparison works for exact values');

    $tolStart = $activities->startAttempt($outsiderUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    // outsider can access all-visibility course
    $tolId = (string) ($tolStart['attempt']['id'] ?? '');
    $within = $activities->submitResponse($outsiderUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $tolId,
        'response' => ['value' => 5.1],
    ]);
    $check(($within['attempt']['autoResult']['matched'] ?? false) === true, '21a numerical tolerance upper boundary matched');

    $tol2Student = $makeUser('student', 'sub_tol2');
    $studentIds[] = $students->createProfile((string) $tol2Student['_id'], [
        'registerNumber' => 'ST' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $tol2Start = $activities->startAttempt($tol2Student, $tutorialId, $moduleId, $publishedIds['numerical']);
    $tol2Id = (string) ($tol2Start['attempt']['id'] ?? '');
    $outside = $activities->submitResponse($tol2Student, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'attemptId' => $tol2Id,
        'response' => ['value' => 5.11],
    ]);
    $check(($outside['attempt']['autoResult']['matched'] ?? false) === false, '21b numerical tolerance outside boundary unmatched');

    $badNumStart = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $badNumId = (string) ($badNumStart['attempt']['id'] ?? '');
    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $publishedIds, $badNumId): void {
        $activities->submitResponse($studentUser, $tutorialId, $moduleId, $publishedIds['numerical'], [
            'attemptId' => $badNumId,
            'response' => ['value' => 'abc'],
        ]);
    }, '22 invalid numeric values are rejected');

    // 23 other types do not get fabricated automatic scores
    $progSubmit = $activities->submitResponse($otherStudentUser, $tutorialId, $moduleId, $publishedIds['programming_task'], [
        'attemptId' => $progAttemptId,
        'response' => ['source' => "print('ok')", 'matched' => true, 'score' => 100],
    ]);
    $check(
        ($progSubmit['attempt']['autoResult']['mode'] ?? '') === 'tutor_review'
        && !isset($progSubmit['attempt']['autoResult']['matched']),
        '23 other activity types do not receive fabricated automatic scores'
    );

    // 24 answer keys protected across student endpoints
    $assertNoSecrets($listed, '24a list endpoint protects answer keys');
    $assertNoSecrets($view, '24b get endpoint protects answer keys');
    $attempts = $activities->listAttemptsForStudent($studentUser, $tutorialId, $moduleId, $publishedIds['numerical']);
    $assertNoSecrets($attempts, '24c attempts endpoint protects answer keys');
    $assertNoSecrets($submitted, '24d submit endpoint protects answer keys');

    // 25 historical snapshots unchanged after staff edits
    $snapTitle = (string) ($rawAttempt['activitySnapshot']['title'] ?? '');
    $activities->update($staff, $tutorialId, $moduleId, $publishedIds['numerical'], [
        'title' => 'Numerical edited ' . $suffix,
        'answerKey' => ['expectedValue' => 50],
        'config' => ['unit' => 'ohm', 'tolerance' => 0.1],
    ]);
    $fresh = $submissionModel->findById($attemptId);
    $check(
        is_array($fresh)
        && (string) ($fresh['activitySnapshot']['title'] ?? '') === $snapTitle
        && (float) (($fresh['activitySnapshot']['answerKey']['expectedValue'] ?? -1)) === 5.0,
        '25 historical snapshots remain unchanged after staff edits'
    );

    // 26 archived cannot start new attempts
    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $archiveId): void {
        $activities->startAttempt($studentUser, $tutorialId, $moduleId, $archiveId);
    }, '26 archived activities cannot start new attempts');

    // 27 previous submissions available for tutor review
    $review = $reviewModel->findBySubmission((string) ($progSubmit['attempt']['id'] ?? ''));
    $check(
        is_array($review)
        && ($review['status'] ?? '') === 'pending'
        && $submissionModel->findById((string) ($progSubmit['attempt']['id'] ?? '')) !== null,
        '27 previous submissions remain available for later tutor review'
    );

    // evaluationMode none cannot start
    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $noneId): void {
        $activities->startAttempt($studentUser, $tutorialId, $moduleId, $noneId);
    }, 'evaluationMode none rejects attempt creation');

    // staff cannot use student endpoints
    $throws(static function () use ($activities, $staffAsClient, $tutorialId, $moduleId): void {
        $activities->listForStudent($staffAsClient, $tutorialId, $moduleId);
    }, 'staff cannot use student list endpoint');

    // self-check returns rubric without modelAnswer
    $scStart = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds['short_answer']);
    $scId = (string) ($scStart['attempt']['id'] ?? '');
    $sc = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $publishedIds['short_answer'], [
        'attemptId' => $scId,
        'response' => ['text' => 'Strengths and weaknesses matter'],
    ]);
    $check(
        ($sc['attempt']['autoResult']['mode'] ?? '') === 'self_check'
        && str_contains((string) ($sc['attempt']['autoResult']['rubric'] ?? ''), 'Strengths')
        && ($sc['attempt']['autoResult']['keywordsMatched'] ?? 0) >= 2,
        'self-check returns rubric and keyword match counts'
    );
    $assertNoSecrets($sc, 'self-check response omits model answers');

    // case study incomplete rejected
    $csStart = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $publishedIds['case_study']);
    $csId = (string) ($csStart['attempt']['id'] ?? '');
    $throws(static function () use ($activities, $studentUser, $tutorialId, $moduleId, $publishedIds, $csId): void {
        $activities->submitResponse($studentUser, $tutorialId, $moduleId, $publishedIds['case_study'], [
            'attemptId' => $csId,
            'response' => ['parts' => ['part-1' => 'only one']],
        ]);
    }, 'case study incomplete parts rejected');

} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  fatal → ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    foreach ($moduleIds as $mid) {
        foreach ($activityModel->listByModule($mid, true) as $row) {
            $aid = (string) ($row['_id'] ?? '');
            try {
                foreach ($submissionModel->listByActivity($aid) as $submission) {
                    $sid = (string) ($submission['_id'] ?? '');
                    $review = $reviewModel->findBySubmission($sid);
                    if (is_array($review)) {
                        $reviewModel->delete((string) ($review['_id'] ?? ''));
                    }
                    $submissionModel->delete($sid);
                }
                $activityModel->delete($aid);
            } catch (Throwable) {
            }
        }
        try {
            (new TutorialModuleModel())->delete($mid);
        } catch (Throwable) {
        }
    }
    foreach ($tutorialIds as $tid) {
        try {
            (new TutorialModel())->delete($tid);
        } catch (Throwable) {
        }
    }
    foreach ($studentIds as $sid) {
        try {
            $students->delete($sid);
        } catch (Throwable) {
        }
    }
    foreach ($userIds as $uid) {
        try {
            $users->delete($uid);
        } catch (Throwable) {
        }
    }
}

echo PHP_EOL . "Passed: {$passed}; Failed: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
