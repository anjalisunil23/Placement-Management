<?php

declare(strict_types=1);

/**
 * Staff draft privacy, published campus visibility, and duplicate-topic awareness.
 * Usage: php backend/scripts/test-tutorial-staff-visibility.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\UserModel;
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
$throws = static function (callable $fn, int $code, string $label) use ($check): void {
    try {
        $fn();
        $check(false, $label . ' (expected exception ' . $code . ')');
    } catch (RuntimeException $e) {
        $check($e->getCode() === $code, $label);
    } catch (Throwable $e) {
        $check(false, $label . ' (' . $e->getMessage() . ')');
    }
};

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$service = new TutorialService();
$userIds = [];
$studentIds = [];
$departmentIds = [];
$tutorialIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-vis.test',
        'password' => 'tutorial-vis-pass',
        'role' => $role,
    ]);
    $userIds[] = $id;
    $user = $users->findById($id);
    if (!is_array($user)) {
        throw new RuntimeException('User was not stored.');
    }

    return $user;
};

try {
    $staffA = $makeUser('staff', 'vis_staff_a');
    $staffB = $makeUser('staff', 'vis_staff_b');
    $studentUser = $makeUser('student', 'vis_student');

    $categoryId = '';
    foreach ($service->listCategories($staffA) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) $category['id'];
        }
    }
    $check($categoryId !== '', 'category available');

    $deptId = $departments->createDepartment([
        'name' => 'Vis CSE ' . $suffix,
        'code' => 'VCSE' . strtoupper($suffix),
    ]);
    $departmentIds[] = $deptId;
    $studentIds[] = $students->createProfile($studentUser['_id'], [
        'registerNumber' => 'VIS' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'CSE2025-27-S3',
    ]);

    // Test 1: Staff A creates a draft and can see it.
    $draft = $service->createTutorial($staffA, [
        'title' => 'Advanced Python Programming ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Advanced Python Programming',
        'description' => 'Private draft for ownership tests.',
        'visibility' => 'all',
    ]);
    $tutorialIds[] = $draft['id'];
    $aList = $service->listManaged($staffA);
    $aIds = array_column($aList, 'id');
    $check(in_array($draft['id'], $aIds, true), 'Test 1: Staff A can see their own draft');

    // Test 2: Staff B cannot see Staff A's draft in the listing.
    $bList = $service->listManaged($staffB);
    $bIds = array_column($bList, 'id');
    $check(!in_array($draft['id'], $bIds, true), 'Test 2: Staff B cannot see Staff A draft in listing');

    // Test 3: Staff B cannot retrieve Staff A's draft by ID.
    $throws(
        static fn () => $service->showManaged($staffB, $draft['id']),
        404,
        'Test 3: Staff B cannot retrieve Staff A draft by id'
    );

    // Test 4: Staff B cannot edit Staff A's draft.
    $throws(
        static fn () => $service->updateTutorial($staffB, $draft['id'], [
            'title' => 'Taken over',
            'categoryId' => $categoryId,
            'topic' => 'Advanced Python Programming',
            'description' => 'Nope',
            'visibility' => 'all',
        ]),
        404,
        'Test 4: Staff B cannot edit Staff A draft'
    );

    // Test 5: Staff B cannot publish Staff A's draft.
    $throws(
        static fn () => $service->publish($staffB, $draft['id']),
        404,
        'Test 5: Staff B cannot publish Staff A draft'
    );

    // Publish a second course owned by Staff A for campus visibility tests.
    $published = $service->createTutorial($staffA, [
        'title' => 'Introduction to Python Programming ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Python Programming',
        'description' => 'Published campus course.',
        'visibility' => 'all',
    ]);
    $tutorialIds[] = $published['id'];
    $service->createModule($staffA, $published['id'], [
        'title' => 'Start',
        'content' => '<p>Intro</p>',
    ]);
    $service->publish($staffA, $published['id']);

    // Test 6: Staff B can see Staff A's published tutorial.
    $bListAfter = $service->listManaged($staffB);
    $bIdsAfter = array_column($bListAfter, 'id');
    $check(in_array($published['id'], $bIdsAfter, true), 'Test 6: Staff B can see Staff A published tutorial');
    $check(!in_array($draft['id'], $bIdsAfter, true), 'published list still hides private draft');

    // Test 7: Staff B can open Staff A's published tutorial.
    $opened = $service->showManaged($staffB, $published['id']);
    $check(
        ($opened['id'] ?? '') === $published['id']
        && ($opened['canEdit'] ?? true) === false
        && ($opened['createdByName'] ?? '') !== '',
        'Test 7: Staff B can open published tutorial as view-only'
    );

    // Test 8: Staff B cannot modify Staff A's published tutorial.
    $throws(
        static fn () => $service->updateTutorial($staffB, $published['id'], [
            'title' => 'Hijacked',
            'categoryId' => $categoryId,
            'topic' => 'Python Programming',
            'description' => 'Nope',
            'visibility' => 'all',
        ]),
        403,
        'Test 8: Staff B cannot modify Staff A published tutorial'
    );
    $throws(
        static fn () => $service->createModule($staffB, $published['id'], [
            'title' => 'Intruder',
            'content' => '<p>x</p>',
        ]),
        403,
        'Test 8b: Staff B cannot add modules to Staff A published tutorial'
    );

    // Test 8c: Placement officers also cannot manage another staff member's published course.
    $officer = $makeUser('placement_officer', 'vis_officer');
    $officerOpen = $service->showManaged($officer, $published['id']);
    $check(
        ($officerOpen['id'] ?? '') === $published['id']
        && ($officerOpen['canEdit'] ?? true) === false,
        'Test 8c: Placement officer opens another staff published course as view-only'
    );
    $ownerModules = $service->showManaged($staffA, $published['id']);
    $firstModuleId = (string) (($ownerModules['modules'][0]['id'] ?? ''));
    $check($firstModuleId !== '', 'owner published course has a module for officer denial checks');
    $throws(
        static fn () => $service->updateModule($officer, $published['id'], $firstModuleId, [
            'title' => 'Officer overwrite',
        ]),
        403,
        'Test 8d: Placement officer cannot edit another staff module'
    );
    $throws(
        static fn () => $service->unpublish($officer, $published['id']),
        403,
        'Test 8e: Placement officer cannot unpublish another staff course'
    );

    // Test 9: Duplicate published topic produces a warning.
    $similarForB = $service->findSimilarTutorials($staffB, 'Python Programming');
    $titles = array_map(static fn (array $row): string => (string) ($row['title'] ?? ''), $similarForB['matches'] ?? []);
    $check(
        ($similarForB['hasMatches'] ?? false) === true
        && count(array_filter($titles, static fn (string $title): bool => str_contains($title, 'Introduction to Python Programming'))) > 0,
        'Test 9: duplicate published topic produces a warning'
    );

    // Test 10: Duplicate private draft belonging to another staff member does NOT leak.
    $leak = $service->findSimilarTutorials($staffB, 'Advanced Python Programming');
    $leakIds = array_column($leak['matches'] ?? [], 'id');
    $leakTitles = array_map('strtolower', array_column($leak['matches'] ?? [], 'title'));
    $check(
        !in_array($draft['id'], $leakIds, true)
        && count(array_filter($leakTitles, static fn (string $title): bool => str_contains($title, 'advanced python'))) === 0,
        'Test 10: other staff private draft is not leaked in duplicate warning'
    );

    // Test 11: The creator's own existing draft can be identified appropriately.
    $similarForA = $service->findSimilarTutorials($staffA, 'Advanced Python Programming');
    $ownIds = array_column($similarForA['matches'] ?? [], 'id');
    $ownMatch = null;
    foreach ($similarForA['matches'] ?? [] as $row) {
        if (($row['id'] ?? '') === $draft['id']) {
            $ownMatch = $row;
        }
    }
    $check(
        in_array($draft['id'], $ownIds, true)
        && is_array($ownMatch)
        && ($ownMatch['isOwner'] ?? false) === true
        && ($ownMatch['status'] ?? '') === 'draft',
        'Test 11: creator can see own draft in duplicate warning'
    );

    // Test 12: Student visibility continues to obey targeting rules.
    $studentIdsPublished = array_column($service->listForStudent($studentUser), 'id');
    $check(
        in_array($published['id'], $studentIdsPublished, true)
        && !in_array($draft['id'], $studentIdsPublished, true),
        'Test 12: students see published courses and not drafts'
    );

    $check(
        TutorialService::normalizeTopicKey('  Intro!! to   Python ') === 'intro to python',
        'topic normalization collapses case punctuation and spaces'
    );
} catch (Throwable $e) {
    $check(false, 'suite error: ' . $e->getMessage());
}

foreach ($tutorialIds as $id) {
    try {
        $modules = new TutorialModuleModel();
        foreach ($modules->listByTutorial($id) as $module) {
            $modules->delete((string) ($module['_id'] ?? ''));
        }
        (new TutorialModel())->delete($id);
    } catch (Throwable) {
        // best effort
    }
}
foreach ($studentIds as $id) {
    try {
        $students->delete($id);
    } catch (Throwable) {
    }
}
foreach ($departmentIds as $id) {
    try {
        $departments->delete($id);
    } catch (Throwable) {
    }
}
foreach ($userIds as $id) {
    try {
        $users->delete($id);
    } catch (Throwable) {
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
