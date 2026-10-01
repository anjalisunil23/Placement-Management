<?php

declare(strict_types=1);

/**
 * Tutorial service checks. Temporary users, departments, and tutorials are removed.
 * Usage: php backend/scripts/test-tutorial-service.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Middleware\AuthMiddleware;
use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialExerciseModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialTestCaseModel;
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
$throws = static function (callable $fn, int $code) use ($check): void {
    try {
        $fn();
        $check(false, 'expected exception ' . $code);
    } catch (RuntimeException $e) {
        $check($e->getCode() === $code, $e->getMessage());
    } catch (Throwable $e) {
        $check(false, $e->getMessage());
    }
};

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$service = new TutorialService();
$userIds = [];
$studentIds = [];
$departmentIds = [];
$tutorialIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial.test',
        'password' => 'tutorial-test-pass',
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
    $check(TutorialService::passoutYear('MCA2025-27-S3') === '2027', 'passout year uses the end of the batch range');
    $safe = TutorialService::sanitizeHtml('<p>ok</p><script>alert(1)</script><img src="https://cdn.example/a.png" onerror="alert(1)"><a href="javascript:alert(1)">x</a><iframe src="https://evil.example"></iframe>');
    $check(
        !str_contains($safe, 'script')
        && !str_contains($safe, 'onerror')
        && !str_contains($safe, 'javascript:')
        && !str_contains($safe, 'iframe')
        && str_contains($safe, 'cdn.example'),
        'module html drops script tags, event handlers, and unsafe links'
    );

    $cseId = $departments->createDepartment(['name' => 'Tutorial CSE ' . $suffix, 'code' => 'TCSE' . strtoupper($suffix)]);
    $mcaId = $departments->createDepartment(['name' => 'Tutorial MCA ' . $suffix, 'code' => 'TMCA' . strtoupper($suffix)]);
    $eceId = $departments->createDepartment(['name' => 'Tutorial ECE ' . $suffix, 'code' => 'TECE' . strtoupper($suffix)]);
    $departmentIds = [$cseId, $mcaId, $eceId];

    $admin = $makeUser('admin', 'admin');
    $staffA = $makeUser('staff', 'staffa');
    $staffB = $makeUser('staff', 'staffb');
    $studentUser = $makeUser('student', 'student');
    $check(AuthMiddleware::resolvedRole($staffA) === 'staff', 'plain staff stays staff');

    $profiles = [
        'cse2027' => [$cseId, 'CSE2025-27-S3'],
        'mca2027' => [$mcaId, 'MCA2025-27-S3'],
        'mca2029' => [$mcaId, 'MCA2027-29-S1'],
        'ece2027' => [$eceId, 'ECE2025-27-S1'],
    ];
    $studentUsers = [];
    $n = 1;
    foreach ($profiles as $key => [$deptId, $batch]) {
        $account = $makeUser('student', $key);
        $studentIds[] = $students->createProfile($account['_id'], [
            'registerNumber' => 'TUT' . $suffix . $n,
            'departmentId' => $deptId,
            'classBatch' => $batch,
        ]);
        $studentUsers[$key] = $account;
        $n++;
    }

    $throws(fn () => $service->createTutorial($studentUser, [
        'title' => 'Student tutorial',
        'categoryId' => 'x',
        'visibility' => 'all',
    ]), 403);

    $categoryId = '';
    foreach ($service->listCategories($studentUsers['cse2027']) as $category) {
        if (($category['slug'] ?? '') === 'tools') {
            $categoryId = (string) $category['id'];
        }
    }
    $check($categoryId !== '', 'student can read active categories');

    $byAdmin = $service->createTutorial($admin, [
        'title' => 'Admin tutorial ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Git',
        'description' => 'Admin authored.',
        'visibility' => 'all',
        'status' => 'published',
        'createdBy' => $staffA['_id'],
    ]);
    $tutorialIds[] = $byAdmin['id'];
    $check($byAdmin['status'] === 'draft' && $byAdmin['createdBy'] === $admin['_id'], 'admin create stays draft and ignores client createdBy');

    $byStaff = $service->createTutorial($staffA, [
        'title' => 'Staff tutorial ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Docker',
        'description' => 'Staff authored.',
        'visibility' => 'all',
    ]);
    $tutorialIds[] = $byStaff['id'];
    $check($byStaff['createdBy'] === $staffA['_id'], 'staff can create a tutorial');

    $throws(fn () => $service->updateTutorial($staffB, $byStaff['id'], [
        'title' => 'Taken over',
        'categoryId' => $categoryId,
        'topic' => 'Docker',
        'description' => 'Staff authored.',
        'visibility' => 'all',
    ]), 403);

    $adminEdit = $service->updateTutorial($admin, $byStaff['id'], [
        'title' => 'Staff tutorial edited ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Docker',
        'description' => 'Edited by admin.',
        'visibility' => 'all',
    ]);
    $check($adminEdit['title'] === 'Staff tutorial edited ' . $suffix && $adminEdit['createdBy'] === $staffA['_id'], 'admin can edit another user tutorial without taking ownership');

    $visibleBeforePublish = array_column($service->listForStudent($studentUsers['cse2027']), 'id');
    $check(!in_array($byStaff['id'], $visibleBeforePublish, true) && !in_array($byAdmin['id'], $visibleBeforePublish, true), 'draft tutorials are hidden from students');
    $throws(fn () => $service->showForStudent($studentUsers['cse2027'], $byStaff['id']), 404);

    try {
        $service->publish($staffA, $byStaff['id']);
        $check(false, 'publish without a module should fail');
    } catch (InvalidArgumentException $e) {
        $check(str_contains($e->getMessage(), 'module'), 'publish without a module is rejected');
    }
    $publishStub = $service->createModule($staffA, $byStaff['id'], ['title' => 'Publish stub', 'content' => '<p>Stub</p>']);
    $service->publish($staffA, $byStaff['id']);
    $service->unpublish($staffA, $byStaff['id']);
    $check($service->showManaged($staffA, $byStaff['id'])['status'] === 'unpublished', 'unpublish sets unpublished');
    $throws(fn () => $service->showForStudent($studentUsers['cse2027'], $byStaff['id']), 404);

    $service->publish($staffA, $byStaff['id']);
    $visibleIds = array_column($service->listForStudent($studentUsers['mca2027']), 'id');
    $check(in_array($byStaff['id'], $visibleIds, true), 'all-visibility published tutorial is visible');

    $scoped = $service->createTutorial($staffA, [
        'title' => 'Scoped tutorial ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'React',
        'description' => 'Scoped to CSE and MCA, 2027 and 2028.',
        'visibility' => 'scoped',
        'departmentIds' => [$cseId, $mcaId],
        'passingYears' => ['2027', '2028'],
    ]);
    $tutorialIds[] = $scoped['id'];
    $service->createModule($staffA, $scoped['id'], ['title' => 'Scoped stub', 'content' => '<p>Stub</p>']);
    $service->publish($staffA, $scoped['id']);
    $sees = static function (array $user) use ($service, $scoped): bool {
        $ids = array_column($service->listForStudent($user), 'id');

        return in_array($scoped['id'], $ids, true);
    };
    $check($sees($studentUsers['cse2027']) && $sees($studentUsers['mca2027']), 'scoped tutorial matches department and year together');
    $check(!$sees($studentUsers['ece2027']) && !$sees($studentUsers['mca2029']), 'scoped tutorial hides the wrong department and the wrong year');

    $spoofed = $studentUsers['ece2027'];
    $spoofed['departmentId'] = $cseId;
    $spoofed['passingYear'] = '2027';
    $spoofed['studentId'] = $studentUsers['cse2027']['_id'];
    $check(!$sees($spoofed), 'student request fields cannot change department, year, or student id');

    $deptOnly = $service->createTutorial($staffA, [
        'title' => 'CSE only ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'C',
        'description' => 'Department filter.',
        'visibility' => 'scoped',
        'departmentIds' => [$cseId],
        'passingYears' => [],
    ]);
    $tutorialIds[] = $deptOnly['id'];
    $service->createModule($staffA, $deptOnly['id'], ['title' => 'Department stub', 'content' => '<p>Stub</p>']);
    $service->publish($staffA, $deptOnly['id']);
    $deptIds = array_column($service->listForStudent($studentUsers['mca2027']), 'id');
    $cseIds = array_column($service->listForStudent($studentUsers['cse2027']), 'id');
    $check(in_array($deptOnly['id'], $cseIds, true) && !in_array($deptOnly['id'], $deptIds, true), 'department scope hides other departments');

    $yearOnly = $service->createTutorial($staffA, [
        'title' => '2027 only ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Python',
        'description' => 'Year filter.',
        'visibility' => 'scoped',
        'departmentIds' => [],
        'passingYears' => ['2027'],
    ]);
    $tutorialIds[] = $yearOnly['id'];
    $service->createModule($staffA, $yearOnly['id'], ['title' => 'Year stub', 'content' => '<p>Stub</p>']);
    $service->publish($staffA, $yearOnly['id']);
    $yearIds2029 = array_column($service->listForStudent($studentUsers['mca2029']), 'id');
    $yearIds2027 = array_column($service->listForStudent($studentUsers['ece2027']), 'id');
    $check(in_array($yearOnly['id'], $yearIds2027, true) && !in_array($yearOnly['id'], $yearIds2029, true), 'year scope uses the passout year from classBatch');

    $moduleA = $service->createModule($staffA, $byStaff['id'], ['title' => 'One', 'content' => '<p>One</p><script>no</script>']);
    $moduleB = $service->createModule($staffA, $byStaff['id'], ['title' => 'Two', 'content' => '<p>Two</p>']);
    $other = $service->createModule($staffA, $scoped['id'], ['title' => 'Other tutorial']);
    $throws(fn () => $service->moduleForStudent($studentUsers['cse2027'], $byStaff['id'], $other['id']), 404);
    $mine = $service->moduleForStudent($studentUsers['cse2027'], $byStaff['id'], $moduleA['id']);
    $check(($mine['title'] ?? '') === 'One' && !str_contains((string) ($mine['content'] ?? ''), 'script'), 'student can open a module of that published tutorial');

    $throws(fn () => $service->updateModule($staffB, $byStaff['id'], $moduleA['id'], ['title' => 'Nope']), 403);
    $lessonDoc = [
        'version' => 1,
        'blocks' => [
            ['id' => 'p1', 'type' => 'paragraph', 'text' => 'Intro'],
            ['id' => 'c1', 'type' => 'code', 'language' => 'python', 'source' => 'print(1)', 'exampleOutput' => '1'],
            ['id' => 'c2', 'type' => 'code', 'language' => 'javascript:alert(1)', 'source' => 'console.log(1)', 'exampleOutput' => ''],
        ],
    ];
    $updatedLesson = $service->updateModule($staffA, $byStaff['id'], $moduleA['id'], [
        'title' => 'One renamed',
        'content' => json_encode($lessonDoc),
    ]);
    $storedLesson = json_decode((string) ($updatedLesson['content'] ?? ''), true);
    $check(
        is_array($storedLesson)
        && (int) ($storedLesson['version'] ?? 0) === 1
        && ($storedLesson['blocks'][1]['language'] ?? '') === 'python'
        && ($storedLesson['blocks'][1]['exampleOutput'] ?? '') === '1'
        && ($storedLesson['blocks'][2]['language'] ?? '') === 'auto',
        'lesson JSON keeps a per-block language and rejects unknown languages'
    );
    $studentLesson = $service->moduleForStudent($studentUsers['cse2027'], $byStaff['id'], $moduleA['id']);
    $check(
        str_contains((string) ($studentLesson['content'] ?? ''), 'print(1)')
        && str_contains((string) ($studentLesson['content'] ?? ''), '<pre')
        && !str_contains((string) ($studentLesson['content'] ?? ''), '"version"'),
        'students receive rendered lesson HTML, not editor JSON'
    );
    $htmlLegacy = $service->updateModule($staffA, $byStaff['id'], $moduleA['id'], [
        'content' => '<h2>Old</h2><p>Still loads</p><script>alert(1)</script>',
    ]);
    $check(
        str_contains((string) ($htmlLegacy['content'] ?? ''), 'Still loads')
        && !str_contains((string) ($htmlLegacy['content'] ?? ''), 'script'),
        'legacy HTML modules still save through the sanitizer'
    );
    $ordered = $service->reorderModules($staffA, $byStaff['id'], [$moduleB['id'], $moduleA['id'], $publishStub['id']]);
    $check(($ordered[0]['id'] ?? '') === $moduleB['id'] && ($ordered[0]['sortOrder'] ?? 0) === 1, 'owner can reorder modules');

    $exercise = $service->createExercise($staffA, $byStaff['id'], $moduleA['id'], [
        'title' => 'Print',
        'instructions' => '<p>Print</p>',
        'language' => 'Python',
        'boilerplate' => "print('hi')\n",
    ]);
    $publicCase = $service->createTestCase($staffA, $exercise['id'], [
        'stdin' => '',
        'expectedOutput' => "hi\n",
        'sample' => true,
    ]);
    $hiddenCase = $service->createTestCase($staffA, $exercise['id'], [
        'stdin' => 'secret-input',
        'expectedOutput' => 'secret-output',
        'sample' => false,
    ]);
    $studentExercise = $service->exerciseForStudent($studentUsers['cse2027'], $exercise['id']);
    $encoded = json_encode($studentExercise);
    $check(
        count($studentExercise['testCases'] ?? []) === 1
        && ($studentExercise['testCases'][0]['sample'] ?? false) === true
        && is_string($encoded)
        && !str_contains($encoded, 'secret-input')
        && !str_contains($encoded, 'secret-output'),
        'student exercise returns public cases only'
    );
    $draftModule = $service->createModule($admin, $byAdmin['id'], ['title' => 'Draft module']);
    $draftExercise = $service->createExercise($admin, $byAdmin['id'], $draftModule['id'], [
        'title' => 'Hidden exercise',
        'instructions' => 'Hidden until published.',
        'language' => 'c',
    ]);
    $throws(fn () => $service->exerciseForStudent($studentUsers['cse2027'], $draftExercise['id']), 404);

    $throws(fn () => $service->deleteExercise($staffB, $byStaff['id'], $moduleA['id'], $exercise['id']), 403);
    $service->updateTestCase($staffA, $exercise['id'], $publicCase['id'], ['expectedOutput' => "hello\n", 'sample' => true]);
    $service->deleteTestCase($admin, $exercise['id'], $hiddenCase['id']);
    $service->deleteExercise($admin, $byStaff['id'], $moduleA['id'], $exercise['id']);
    $service->deleteModule($staffA, $byStaff['id'], $moduleB['id']);

    $used = $service->createCategory($staffA, ['name' => 'Phase category ' . $suffix, 'description' => 'Temporary.']);
    $linked = $service->createTutorial($staffA, [
        'title' => 'Uses category ' . $suffix,
        'categoryId' => $used['id'],
        'topic' => 'Notes',
        'description' => 'Keep the category.',
        'visibility' => 'all',
    ]);
    $tutorialIds[] = $linked['id'];
    $blocked = $service->removeCategory($staffA, $used['id']);
    $check($blocked['deactivated'] === true && $blocked['deleted'] === false, 'a category used by a tutorial is deactivated, not deleted');
} catch (Throwable $e) {
    $check(false, 'unexpected: ' . $e->getMessage());
} finally {
    $tutorialModel = new TutorialModel();
    $moduleModel = new TutorialModuleModel();
    $exerciseModel = new TutorialExerciseModel();
    $caseModel = new TutorialTestCaseModel();
    foreach ($tutorialIds as $id) {
        foreach ($moduleModel->listByTutorial($id) as $module) {
            foreach ($exerciseModel->listByModule((string) ($module['_id'] ?? '')) as $exercise) {
                foreach ($caseModel->listByExercise((string) ($exercise['_id'] ?? '')) as $case) {
                    $caseModel->delete((string) ($case['_id'] ?? ''));
                }
                $exerciseModel->delete((string) ($exercise['_id'] ?? ''));
            }
            $moduleModel->delete((string) ($module['_id'] ?? ''));
        }
        $tutorialModel->delete($id);
    }
    foreach ($studentIds as $id) {
        $students->delete($id);
    }
    foreach ($userIds as $id) {
        $users->delete($id);
    }
    foreach ($departmentIds as $id) {
        $departments->delete($id);
    }
    $categoryModel = new TutorialCategoryModel();
    $temporary = $categoryModel->findBySlug('phase-category-' . $suffix);
    if (is_array($temporary)) {
        $categoryModel->delete((string) $temporary['_id']);
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
