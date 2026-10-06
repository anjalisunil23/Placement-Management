<?php

declare(strict_types=1);

/**
 * Tutorial practical activity tutor-review tests (Phase 4F).
 * Usage: php backend/scripts/test-tutorial-activity-reviews.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Middleware\AuthMiddleware;
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
use PMS\Utils\Security;

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
        && !str_contains($json, 'privateNotes')
        && !str_contains($json, 'answerKey')
        && !str_contains($json, 'expectedValue')
        && !str_contains($json, 'modelAnswer'),
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

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-act-rev.test',
        'password' => 'tutorial-act-rev-test-pass',
        'role' => $role,
        'isHod' => false,
        'designation' => $role === 'staff' ? 'Assistant Professor' : '',
    ]);
    $userIds[] = $id;
    $user = $users->findById($id);
    if (!is_array($user)) {
        throw new RuntimeException('User missing.');
    }
    $user['isHod'] = false;
    $user['designation'] = $role === 'staff' ? 'Assistant Professor' : '';

    return $user;
};

try {
    try {
        Security::startSession();
        unset($_SESSION['ph_is_hod'], $_SESSION['aes_profile'], $_SESSION['aesProfile']);
    } catch (Throwable) {
        // CLI session may be unavailable.
    }

    $staff = $makeUser('staff', 'actrev_owner_' . $suffix);
    $otherStaff = $makeUser('staff', 'actrev_peer_' . $suffix);
    $check(AuthMiddleware::resolvedRole($staff) === 'staff', 'owner staff role stays staff');
    $check(AuthMiddleware::resolvedRole($otherStaff) === 'staff', 'other staff role stays staff');
    $studentUser = $makeUser('student', 'actrev_student_' . $suffix);
    $otherStudent = $makeUser('student', 'actrev_peerstu_' . $suffix);

    $deptId = $departments->createDepartment([
        'name' => 'Review Dept ' . $suffix,
        'code' => 'RV' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $studentId = $students->createProfile((string) $studentUser['_id'], [
        'registerNumber' => 'RV' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $otherStudentId = $students->createProfile((string) $otherStudent['_id'], [
        'registerNumber' => 'RO' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $studentIds = [$studentId, $otherStudentId];

    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'category ready');

    $course = $service->createTutorial($staff, [
        'title' => 'Review Course ' . $suffix,
        'description' => 'Tutor review course.',
        'topic' => 'Review',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialId = (string) ($course['id'] ?? '');
    $tutorialIds[] = $tutorialId;
    $module = $service->createModule($staff, $tutorialId, [
        'title' => 'Module 1',
        'subtitle' => '',
        'content' => '<p>Lesson</p>',
    ]);
    $moduleId = (string) ($module['id'] ?? '');
    $moduleIds[] = $moduleId;

    $prog = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Code review task',
        'instructions' => 'Write a loop.',
        'activityType' => 'programming_task',
        'evaluationMode' => 'tutor_review',
        'config' => ['language' => 'python', 'boilerplate' => 'pass'],
        'answerKey' => ['modelAnswer' => 'secret-model'],
    ]);
    $progId = (string) ($prog['id'] ?? '');

    $num = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Auto number',
        'instructions' => 'Enter 5',
        'activityType' => 'numerical',
        'evaluationMode' => 'auto_compare',
        'config' => ['unit' => 'ohm', 'tolerance' => 0.1],
        'answerKey' => ['expectedValue' => 5],
    ]);
    $numId = (string) ($num['id'] ?? '');

    $self = $activities->create($staff, $tutorialId, $moduleId, [
        'title' => 'Self check',
        'instructions' => 'Define SWOT',
        'activityType' => 'short_answer',
        'evaluationMode' => 'self_check',
        'config' => ['selfCheckRubric' => 'Mentions SWOT'],
        'answerKey' => ['keywords' => ['strengths'], 'modelAnswer' => 'secret'],
    ]);
    $selfId = (string) ($self['id'] ?? '');

    $service->publish($staff, $tutorialId);
    foreach ([$progId, $numId, $selfId] as $aid) {
        $activities->publish($staff, $tutorialId, $moduleId, $aid);
    }

    $start = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $progId);
    $attemptId = (string) ($start['attempt']['id'] ?? '');
    $submitted = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $progId, [
        'attemptId' => $attemptId,
        'response' => ['source' => "print('ok')"],
    ]);
    $check(($submitted['attempt']['status'] ?? '') === 'SUBMITTED', 'programming submission ready');

    $numStart = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $numId);
    $numAttemptId = (string) ($numStart['attempt']['id'] ?? '');
    $numSubmitted = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $numId, [
        'attemptId' => $numAttemptId,
        'response' => ['value' => 5],
    ]);
    $autoMatched = ($numSubmitted['attempt']['autoResult']['matched'] ?? false) === true;

    $selfStart = $activities->startAttempt($studentUser, $tutorialId, $moduleId, $selfId);
    $selfAttemptId = (string) ($selfStart['attempt']['id'] ?? '');
    $selfSubmitted = $activities->submitResponse($studentUser, $tutorialId, $moduleId, $selfId, [
        'attemptId' => $selfAttemptId,
        'response' => ['text' => 'strengths matter'],
    ]);
    $selfKw = (int) ($selfSubmitted['attempt']['autoResult']['keywordsMatched'] ?? 0);

    // 1 authorized staff list
    $listed = $activities->listSubmissionsManaged($staff, $tutorialId, ['status' => 'all']);
    $check(($listed['count'] ?? 0) >= 1, '1 authorized staff can list submissions for managed tutorials');

    // 2 unauthorized staff
    $throws(static function () use ($activities, $otherStaff, $tutorialId): void {
        $activities->listSubmissionsManaged($otherStaff, $tutorialId, []);
    }, '2 unauthorized staff cannot view another staff tutorial submissions');

    // 3 students cannot access review APIs
    $throws(static function () use ($activities, $studentUser, $tutorialId): void {
        $activities->listSubmissionsManaged($studentUser, $tutorialId, []);
    }, '3 students cannot access review APIs');

    // 4 view submitted + snapshot
    $detail = $activities->getSubmissionManaged($staff, $tutorialId, $attemptId);
    $check(
        ($detail['payload']['source'] ?? '') === "print('ok')"
        && (($detail['activitySnapshot']['title'] ?? '') === 'Code review task')
        && isset($detail['activitySnapshot']['answerKey']),
        '4 staff can view submitted response and immutable activity snapshot'
    );

    // 5 cannot review in-progress
    $inProg = $activities->startAttempt($otherStudent, $tutorialId, $moduleId, $progId);
    $inProgId = (string) ($inProg['attempt']['id'] ?? '');
    $throws(static function () use ($activities, $staff, $tutorialId, $inProgId): void {
        $activities->getSubmissionManaged($staff, $tutorialId, $inProgId);
    }, '5 staff cannot review an in-progress attempt');

    // 6 reviewer identity from auth
    $draft = $activities->saveReviewManaged($staff, $tutorialId, $attemptId, [
        'score' => 7,
        'maxScore' => 10,
        'feedback' => 'Solid effort',
        'privateNotes' => 'Needs style cleanup',
        'passed' => true,
        'reviewerUserId' => (string) ($otherStaff['_id'] ?? ''),
    ]);
    $check(
        ($draft['review']['status'] ?? '') === 'pending'
        && (string) ($draft['review']['reviewerUserId'] ?? '') === (string) ($staff['_id'] ?? ''),
        '6 reviewer identity is derived from authentication'
    );

    // 7 draft saved
    $check(($draft['review']['feedback'] ?? '') === 'Solid effort', '7 valid draft review can be saved');

    // reopen draft preserved
    $reopen = $activities->getSubmissionManaged($staff, $tutorialId, $attemptId);
    $check(($reopen['review']['privateNotes'] ?? '') === 'Needs style cleanup', 'draft private notes preserved');

    // 8 finalize
    $final = $activities->finalizeReviewManaged($staff, $tutorialId, $attemptId, [
        'score' => 8,
        'maxScore' => 10,
        'feedback' => 'Good work overall',
        'privateNotes' => 'Internal only',
        'passed' => true,
    ]);
    $check(($final['review']['status'] ?? '') === 'reviewed' && (float) ($final['review']['score'] ?? 0) === 8.0, '8 valid review can be finalized');

    // 9 invalid scores
    $throws(static function () use ($activities, $staff, $tutorialId, $attemptId): void {
        $activities->saveReviewManaged($staff, $tutorialId, $attemptId, [
            'score' => 12,
            'maxScore' => 10,
            'feedback' => 'bad',
        ]);
    }, '9 invalid scores are rejected');

    // 10 duplicate review records cannot be created
    $before = $reviewModel->findBySubmission($attemptId);
    $activities->finalizeReviewManaged($staff, $tutorialId, $attemptId, [
        'score' => 8,
        'maxScore' => 10,
        'feedback' => 'Good work overall',
        'privateNotes' => 'Internal only',
        'passed' => true,
    ]);
    $after = $reviewModel->findBySubmission($attemptId);
    $check(
        is_array($before) && is_array($after)
        && (string) ($before['_id'] ?? '') === (string) ($after['_id'] ?? ''),
        '10 duplicate review records cannot be created'
    );

    // 11 repeated finalization safe
    $again = $activities->finalizeReviewManaged($staff, $tutorialId, $attemptId, [
        'score' => 8,
        'maxScore' => 10,
        'feedback' => 'Good work overall',
        'privateNotes' => 'Internal only',
        'passed' => true,
    ]);
    $check(($again['review']['status'] ?? '') === 'reviewed', '11 repeated finalization is handled safely');

    // 11b editing a finalized review stays reviewed (does not demote to pending)
    $edited = $activities->saveReviewManaged($staff, $tutorialId, $attemptId, [
        'score' => 9,
        'maxScore' => 10,
        'feedback' => 'Updated feedback after finalize',
        'privateNotes' => 'Edited notes',
        'passed' => true,
    ]);
    $check(
        ($edited['review']['status'] ?? '') === 'reviewed'
        && (float) ($edited['review']['score'] ?? 0) === 9.0
        && ($edited['review']['feedback'] ?? '') === 'Updated feedback after finalize',
        '11b editing a finalized review stays in reviewed status'
    );
    $reviewedOnly = $activities->listSubmissionsManaged($staff, $tutorialId, ['status' => 'reviewed', 'limit' => 50]);
    $pendingOnly = $activities->listSubmissionsManaged($staff, $tutorialId, ['status' => 'pending', 'limit' => 50]);
    $inReviewed = false;
    foreach (($reviewedOnly['submissions'] ?? []) as $row) {
        if ((string) ($row['id'] ?? '') === $attemptId) {
            $inReviewed = true;
            break;
        }
    }
    $inPending = false;
    foreach (($pendingOnly['submissions'] ?? []) as $row) {
        if ((string) ($row['id'] ?? '') === $attemptId) {
            $inPending = true;
            break;
        }
    }
    $check($inReviewed && !$inPending, '11c reviewed queue excludes pending and includes edited review');

    // 12 activity edit does not alter historical review context
    $snapTitle = (string) ($detail['activitySnapshot']['title'] ?? '');
    $activities->update($staff, $tutorialId, $moduleId, $progId, [
        'title' => 'Code review task EDITED ' . $suffix,
    ]);
    $hist = $activities->getSubmissionManaged($staff, $tutorialId, $attemptId);
    $check(($hist['activitySnapshot']['title'] ?? '') === $snapTitle, '12 editing activity does not alter historical review context');

    // 13-15 student visibility
    $studentView = $activities->listAttemptsForStudent($studentUser, $tutorialId, $moduleId, $progId);
    $studentAttempt = null;
    foreach ($studentView['attempts'] as $row) {
        if ((string) ($row['id'] ?? '') === $attemptId) {
            $studentAttempt = $row;
        }
    }
    $assertNoSecrets($studentAttempt, '13 private notes are not exposed to students');
    $check(
        is_array($studentAttempt)
        && (($studentAttempt['review']['status'] ?? '') === 'reviewed')
        && (($studentAttempt['review']['feedback'] ?? '') === 'Updated feedback after finalize')
        && (($studentAttempt['review']['score'] ?? null) == 9)
        && (($studentAttempt['autoResult']['status'] ?? '') === 'reviewed'),
        '14 student-visible feedback is returned after reviewed state'
    );

    $throws(static function () use ($activities, $otherStudent, $tutorialId, $moduleId, $progId, $attemptId): void {
        // other student listing their own attempts should not include this attempt
        $rows = $activities->listAttemptsForStudent($otherStudent, $tutorialId, $moduleId, $progId);
        foreach ($rows['attempts'] as $row) {
            if ((string) ($row['id'] ?? '') === $attemptId) {
                throw new RuntimeException('Leaked foreign attempt');
            }
        }
        // direct save against foreign attempt
        $activities->saveResponse($otherStudent, $tutorialId, $moduleId, $progId, [
            'attemptId' => $attemptId,
            'response' => ['source' => 'hack'],
        ]);
    }, '15 students cannot access another student review/attempt');

    // 16-17 auto results unchanged / not overwritten
    $throws(static function () use ($activities, $staff, $tutorialId, $numAttemptId): void {
        $activities->saveReviewManaged($staff, $tutorialId, $numAttemptId, [
            'score' => 1,
            'maxScore' => 10,
            'feedback' => 'nope',
        ]);
    }, '16 auto_compare cannot be overwritten by tutor review');
    $numAgain = $activities->getSubmissionManaged($staff, $tutorialId, $numAttemptId);
    $check(
        $autoMatched
        && (($numAgain['autoResult']['matched'] ?? false) === true),
        '16b auto_compare results remain unchanged'
    );

    $throws(static function () use ($activities, $staff, $tutorialId, $selfAttemptId): void {
        $activities->finalizeReviewManaged($staff, $tutorialId, $selfAttemptId, [
            'score' => 1,
            'maxScore' => 10,
            'feedback' => 'nope',
        ]);
    }, '17 self_check cannot be overwritten by tutor review');
    $selfAgain = $activities->listAttemptsForStudent($studentUser, $tutorialId, $moduleId, $selfId);
    $selfRow = $selfAgain['attempts'][0] ?? [];
    $check(
        $selfKw >= 1
        && ((int) ($selfRow['autoResult']['keywordsMatched'] ?? 0) === $selfKw),
        '17b self_check results remain unchanged'
    );

    // 18 source remains text
    $check(
        ($detail['payload']['source'] ?? '') === "print('ok')"
        && !isset($detail['payload']['stdout']),
        '18 programming source remains unexecuted text'
    );

    // pending filter
    $pendingOnly = $activities->listSubmissionsManaged($staff, $tutorialId, ['status' => 'pending']);
    foreach ($pendingOnly['submissions'] as $row) {
        $check(($row['review']['status'] ?? '') !== 'reviewed', 'pending filter excludes reviewed ' . ($row['id'] ?? ''));
    }

    // student pending before finalize on a fresh attempt
    $freshStart = $activities->startAttempt($otherStudent, $tutorialId, $moduleId, $progId);
    // first was in-progress - submit it
    $freshId = (string) ($freshStart['attempt']['id'] ?? '');
    if (($freshStart['resumed'] ?? false) === true || $freshId === $inProgId) {
        $freshId = $inProgId;
    }
    $freshSub = $activities->submitResponse($otherStudent, $tutorialId, $moduleId, $progId, [
        'attemptId' => $freshId,
        'response' => ['source' => 'x=1'],
    ]);
    $check(
        (($freshSub['attempt']['review']['status'] ?? '') === 'pending')
        && !isset($freshSub['attempt']['review']['feedback']),
        'pending student review omits feedback fields'
    );

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
