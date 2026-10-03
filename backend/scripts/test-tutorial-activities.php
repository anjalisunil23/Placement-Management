<?php

declare(strict_types=1);

/**
 * Tutorial practical activities foundation tests.
 * Usage: php backend/scripts/test-tutorial-activities.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleActivityReviewModel;
use PMS\Models\TutorialModuleActivitySubmissionModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialActivityService;
use PMS\Services\TutorialService;

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
$activityIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-activity.test',
        'password' => 'tutorial-activity-test-pass',
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
    // Model / collection initialization
    $check($activityModel instanceof TutorialModuleActivityModel, 'activity model initializes');
    $check($submissionModel instanceof TutorialModuleActivitySubmissionModel, 'submission model initializes');
    $check($reviewModel instanceof TutorialModuleActivityReviewModel, 'review model initializes');

    $staff = $makeUser('staff', 'act_staff');
    $otherStaff = $makeUser('staff', 'act_other');
    $student = $makeUser('student', 'act_student');
    $deptId = $departments->createDepartment([
        'name' => 'Activity Test Dept ' . $suffix,
        'code' => 'ACT' . strtoupper(substr($suffix, 0, 3)),
    ]);
    $studentIds[] = $students->createProfile((string) $student['_id'], [
        'registerNumber' => 'AC' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);

    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'category ready');

    $course = $service->createTutorial($staff, [
        'title' => 'Activity Course ' . $suffix,
        'description' => 'Practical activities foundation course.',
        'topic' => 'Systems',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialId = (string) ($course['id'] ?? '');
    $tutorialIds[] = $tutorialId;
    $module = $service->createModule($staff, $tutorialId, [
        'title' => 'Module 1',
        'subtitle' => 'Practice',
        'content' => '<p>Lesson content for activities.</p>',
    ]);
    $moduleId = (string) ($module['id'] ?? '');
    $moduleIds[] = $moduleId;
    $check($moduleId !== '', 'module created');

    $samples = [
        'programming_task' => [
            'title' => 'Write a loop',
            'instructions' => 'Write a Python loop that prints 1 to 5.',
            'activityType' => 'programming_task',
            'evaluationMode' => 'tutor_review',
            'academicField' => 'computer_applications',
            'config' => ['language' => 'python', 'boilerplate' => 'for i in range(5):\n    pass\n'],
            'answerKey' => ['modelAnswer' => 'for i in range(1, 6): print(i)'],
        ],
        'sql_query' => [
            'title' => 'Select students',
            'instructions' => 'Write a SELECT for active students.',
            'activityType' => 'sql_query',
            'evaluationMode' => 'tutor_review',
            'academicField' => 'computer_applications',
            'config' => ['schemaDescription' => 'students(id, name, active)'],
            'answerKey' => ['modelAnswer' => 'SELECT * FROM students WHERE active = 1'],
        ],
        'numerical' => [
            'title' => 'Compute resistance',
            'instructions' => 'Given V=10 and I=2, find R.',
            'activityType' => 'numerical',
            'evaluationMode' => 'auto_compare',
            'academicField' => 'engineering',
            'config' => ['unit' => 'ohm', 'tolerance' => 0.01],
            'answerKey' => ['expectedValue' => 5],
        ],
        'short_answer' => [
            'title' => 'Define SWOT',
            'instructions' => 'Explain SWOT analysis briefly.',
            'activityType' => 'short_answer',
            'evaluationMode' => 'self_check',
            'academicField' => 'business_administration',
            'config' => ['selfCheckRubric' => 'Mentions Strengths Weaknesses Opportunities Threats'],
            'answerKey' => ['modelAnswer' => 'SWOT covers strengths, weaknesses, opportunities, threats.', 'keywords' => ['strengths', 'weaknesses']],
        ],
        'case_study' => [
            'title' => 'Retail expansion case',
            'instructions' => 'Read the case and answer each part.',
            'activityType' => 'case_study',
            'evaluationMode' => 'tutor_review',
            'academicField' => 'business_administration',
            'config' => [
                'parts' => [
                    ['id' => 'a', 'prompt' => 'Identify the market risk.'],
                    ['id' => 'b', 'prompt' => 'Recommend one mitigation.'],
                ],
            ],
            'answerKey' => ['modelAnswer' => 'Risk is demand uncertainty; mitigate with pilot stores.'],
        ],
        'analytical_design' => [
            'title' => 'Design a cooling plan',
            'instructions' => 'Propose a cooling approach for a small data room.',
            'activityType' => 'analytical_design',
            'evaluationMode' => 'tutor_review',
            'academicField' => 'engineering',
            'config' => ['deliverableHint' => 'Include airflow and redundancy notes.'],
            'answerKey' => ['modelAnswer' => 'Use hot/cold aisle containment with N+1 CRAC.'],
        ],
    ];

    $created = [];
    foreach ($samples as $type => $payload) {
        $row = $activities->create($staff, $tutorialId, $moduleId, $payload);
        $created[$type] = $row;
        $activityIds[] = (string) ($row['id'] ?? '');
        $check(($row['activityType'] ?? '') === $type && ($row['status'] ?? '') === 'draft', 'create type ' . $type);
        $check(isset($row['answerKey']), 'staff view includes answerKey for ' . $type);
    }

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->create($staff, $tutorialId, $moduleId, [
            'title' => 'Bad',
            'instructions' => 'x',
            'activityType' => 'essay_bomb',
            'evaluationMode' => 'tutor_review',
        ]);
    }, 'invalid type rejection');

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->create($staff, $tutorialId, $moduleId, [
            'title' => 'Bad mode',
            'instructions' => 'x',
            'activityType' => 'programming_task',
            'evaluationMode' => 'auto_compare',
        ]);
    }, 'invalid evaluation mode rejection');

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->create($staff, $tutorialId, $moduleId, [
            'title' => 'Bad numeric',
            'instructions' => 'Find x',
            'activityType' => 'numerical',
            'evaluationMode' => 'auto_compare',
            'config' => ['tolerance' => 0.1],
            'answerKey' => [],
        ]);
    }, 'numerical auto_compare requires expectedValue');

    $list = $activities->listManaged($staff, $tutorialId, $moduleId);
    $check(($list['count'] ?? 0) === 6, 'activity listing count');
    $orders = array_map(static fn (array $row): int => (int) ($row['sortOrder'] ?? 0), $list['activities']);
    $check($orders === range(1, 6), 'activity default ordering');

    $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $list['activities']);
    $reversed = array_reverse($ids);
    $reordered = $activities->reorder($staff, $tutorialId, $moduleId, $reversed);
    $newOrders = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $reordered['activities']);
    $check($newOrders === $reversed, 'activity reorder');

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId, $ids): void {
        $activities->reorder($staff, $tutorialId, $moduleId, array_slice($ids, 0, 2));
    }, 'invalid reorder list rejected');

    $firstId = (string) ($created['programming_task']['id'] ?? '');
    $updated = $activities->update($staff, $tutorialId, $moduleId, $firstId, [
        'title' => 'Write a loop (edited)',
        'difficulty' => 'intermediate',
    ]);
    $check(($updated['title'] ?? '') === 'Write a loop (edited)' && ($updated['difficulty'] ?? '') === 'intermediate', 'activity update');

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId, $firstId): void {
        $activities->publish($staff, $tutorialId, $moduleId, $firstId);
    }, 'parent course publication requirement');

    $service->publish($staff, $tutorialId);
    $published = $activities->publish($staff, $tutorialId, $moduleId, $firstId);
    $check(($published['status'] ?? '') === 'published', 'draft/published lifecycle publish');
    $unpublished = $activities->unpublish($staff, $tutorialId, $moduleId, $firstId);
    $check(($unpublished['status'] ?? '') === 'draft', 'draft/published lifecycle unpublish');
    $activities->publish($staff, $tutorialId, $moduleId, $firstId);

    $throws(static function () use ($activities, $otherStaff, $tutorialId, $moduleId): void {
        $activities->listManaged($otherStaff, $tutorialId, $moduleId);
    }, 'ownership enforcement');

    $throws(static function () use ($activities, $student, $tutorialId, $moduleId): void {
        $activities->create($student, $tutorialId, $moduleId, [
            'title' => 'Nope',
            'instructions' => 'Student cannot create',
            'activityType' => 'short_answer',
            'evaluationMode' => 'tutor_review',
        ]);
    }, 'unauthorized student create');

    $safe = $activities->studentSafeView($published);
    $safeJson = json_encode($safe);
    $check(
        is_string($safeJson)
        && !str_contains($safeJson, 'answerKey')
        && !str_contains($safeJson, 'modelAnswer')
        && !str_contains($safeJson, 'expectedValue'),
        'answer-key exclusion from student-safe serializer'
    );

    // Archival without historical deletion
    $seedSubmission = $submissionModel->createSubmission([
        'activityId' => $firstId,
        'tutorialId' => $tutorialId,
        'moduleId' => $moduleId,
        'studentId' => $studentIds[0],
        'attemptNumber' => 1,
        'status' => 'SUBMITTED',
        'payload' => ['sourceCode' => 'print(1)'],
        'activitySnapshot' => ['id' => $firstId, 'title' => 'Write a loop (edited)'],
    ]);
    $seedReview = $reviewModel->createReview([
        'submissionId' => (string) ($seedSubmission['_id'] ?? ''),
        'activityId' => $firstId,
        'reviewerUserId' => (string) ($staff['_id'] ?? ''),
        'score' => 8,
        'maxScore' => 10,
        'passed' => true,
        'feedback' => 'Good effort',
        'privateNotes' => 'Needs cleaner style',
        'status' => 'reviewed',
    ]);
    $archived = $activities->archive($staff, $tutorialId, $moduleId, $firstId);
    $check(($archived['archived'] ?? false) === true && ($archived['status'] ?? '') === 'draft', 'activity archival');
    $listedAfter = $activities->listManaged($staff, $tutorialId, $moduleId);
    $stillListed = false;
    foreach ($listedAfter['activities'] as $row) {
        if ((string) ($row['id'] ?? '') === $firstId) {
            $stillListed = true;
        }
    }
    $check(!$stillListed, 'archived activity hidden from active list');
    $check($submissionModel->findById((string) ($seedSubmission['_id'] ?? '')) !== null, 'submission retained after archive');
    $check($reviewModel->findById((string) ($seedReview['_id'] ?? '')) !== null, 'review retained after archive');
    $private = $reviewModel->findById((string) ($seedReview['_id'] ?? ''));
    $check(($private['privateNotes'] ?? '') === 'Needs cleaner style', 'private notes preserved server-side');

    $throws(static function () use ($activities, $staff, $tutorialId, $moduleId): void {
        $activities->getManaged($staff, $tutorialId, $moduleId, 'ffffffffffffffffffffffff');
    }, 'invalid activity relationship / missing activity');

    $throws(static function () use ($activities, $staff, $tutorialId): void {
        $activities->listManaged($staff, $tutorialId, 'ffffffffffffffffffffffff');
    }, 'invalid module relationship');

    // Existing Tutorial behavior smoke
    $progress = $service->markModuleComplete($student, $tutorialId, $moduleId);
    $check(($progress['completedModules'] ?? 0) >= 1, 'existing Tutorial progress still works');
    $manual = $service->createTutorial($staff, [
        'title' => 'Manual still works ' . $suffix,
        'description' => 'Regression',
        'topic' => 'X',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialIds[] = (string) ($manual['id'] ?? '');
    $check(($manual['status'] ?? '') === 'draft', 'existing Tutorial create still works');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL  fatal → ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    foreach ($activityIds as $aid) {
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
    // Also remove any leftover archived/active activities for these modules
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
    }
    foreach ($tutorialIds as $tid) {
        try {
            $mods = (new TutorialModuleModel())->listByTutorial($tid);
            foreach ($mods as $mod) {
                (new TutorialModuleModel())->delete((string) ($mod['_id'] ?? ''));
            }
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
