<?php

declare(strict_types=1);

/**
 * Tutorial progress and attempt checks. Temporary rows are removed.
 * Usage: php backend/scripts/test-tutorial-progress.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentExerciseAttemptModel;
use PMS\Models\StudentModel;
use PMS\Models\StudentTutorialModuleProgressModel;
use PMS\Models\StudentTutorialProgressModel;
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
$throws = static function (callable $fn) use ($check): void {
    try {
        $fn();
        $check(false, 'expected an error');
    } catch (Throwable $e) {
        $check($e->getMessage() !== '', $e->getMessage());
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
        'email' => $name . '.' . $suffix . '@tutorial-progress.test',
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
    $cseId = $departments->createDepartment(['name' => 'Progress CSE ' . $suffix, 'code' => 'PCSE' . strtoupper($suffix)]);
    $mcaId = $departments->createDepartment(['name' => 'Progress MCA ' . $suffix, 'code' => 'PMCA' . strtoupper($suffix)]);
    $departmentIds = [$cseId, $mcaId];
    $staff = $makeUser('staff', 'staff');
    $owner = $makeUser('student', 'owner');
    $other = $makeUser('student', 'other');
    $wrongYear = $makeUser('student', 'later');
    $studentIds[] = $students->createProfile($owner['_id'], [
        'registerNumber' => 'PRG' . $suffix . '1',
        'departmentId' => $cseId,
        'classBatch' => 'CSE2025-27-S3',
    ]);
    $studentIds[] = $students->createProfile($other['_id'], [
        'registerNumber' => 'PRG' . $suffix . '2',
        'departmentId' => $cseId,
        'classBatch' => 'CSE2025-27-S1',
    ]);
    $studentIds[] = $students->createProfile($wrongYear['_id'], [
        'registerNumber' => 'PRG' . $suffix . '3',
        'departmentId' => $cseId,
        'classBatch' => 'CSE2027-29-S1',
    ]);

    $categoryId = '';
    foreach ($service->listCategories($owner) as $category) {
        if (($category['slug'] ?? '') === 'tools') {
            $categoryId = (string) $category['id'];
        }
    }
    $draft = $service->createTutorial($staff, [
        'title' => 'Draft progress ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'Git',
        'description' => 'Not published.',
        'visibility' => 'all',
    ]);
    $tutorialIds[] = $draft['id'];
    $throws(fn () => $service->startProgress($owner, $draft['id']));

    $tutorial = $service->createTutorial($staff, [
        'title' => 'Progress tutorial ' . $suffix,
        'categoryId' => $categoryId,
        'topic' => 'C',
        'description' => 'Progress checks.',
        'visibility' => 'scoped',
        'departmentIds' => [$cseId],
        'passingYears' => ['2027'],
    ]);
    $tutorialIds[] = $tutorial['id'];
    $blocked = $service->publishChecklist($staff, $tutorial['id']);
    $check($blocked['canPublish'] === false, 'a course without modules cannot be published');
    $moduleA = $service->createModule($staff, $tutorial['id'], ['title' => 'Basics', 'content' => '<p>Basics</p>']);
    $moduleB = $service->createModule($staff, $tutorial['id'], ['title' => 'Next', 'content' => '<p>Next</p>']);
    $exercise = $service->createExercise($staff, $tutorial['id'], $moduleA['id'], [
        'title' => 'Print',
        'instructions' => 'Print a line.',
        'language' => 'c',
        'boilerplate' => "int main(){return 0;}\n",
    ]);
    $service->publish($staff, $tutorial['id']);
    $found = $service->listForStudent($owner, ['search' => $suffix, 'category' => $categoryId]);
    $check(in_array($tutorial['id'], array_column($found, 'id'), true), 'search and category stay inside the authorized published list');
    $hidden = $service->listForStudent($wrongYear, ['search' => $suffix]);
    $check($hidden === [], 'search does not reveal a course outside the student year');

    $throws(fn () => $service->startProgress($wrongYear, $tutorial['id']));
    $throws(fn () => $service->progressForStudent($staff, $tutorial['id']));

    $empty = $service->progressForStudent($owner, $tutorial['id']);
    $check($empty['status'] === 'NOT_STARTED' && $empty['progressPercent'] === 0, 'progress starts empty');

    $started = $service->startProgress($owner, $tutorial['id']);
    $check($started['status'] === 'IN_PROGRESS' && $started['completed'] === false, 'starting a tutorial does not complete it');
    $again = $service->startProgress($owner, $tutorial['id']);
    $check($again['status'] === 'IN_PROGRESS', 'starting again reuses the same progress');

    $service->moduleForStudent($owner, $tutorial['id'], $moduleA['id']);
    $afterVisit = $service->progressForStudent($owner, $tutorial['id']);
    $check($afterVisit['lastVisitedModuleId'] === $moduleA['id'], 'opening a module records the last visit');

    $otherView = $service->progressForStudent($other, $tutorial['id']);
    $check($otherView['status'] === 'NOT_STARTED' && $otherView['lastVisitedModuleId'] === null, 'another student does not see this progress');

    $mid = $service->markModuleComplete($owner, $tutorial['id'], $moduleA['id']);
    $service->markModuleComplete($owner, $tutorial['id'], $moduleA['id']);
    $check($mid['completedModules'] === 1 && $mid['progressPercent'] === 50, 'module completion is calculated on the server');
    $rows = (new StudentTutorialModuleProgressModel())->listForTutorial(
        (string) $students->findByUserId($owner['_id'])['_id'],
        $tutorial['id']
    );
    $check(count($rows) === 1, 'marking a module complete does not duplicate the row');
    $throws(fn () => $service->completeTutorial($owner, $tutorial['id']));

    $service->markModuleComplete($owner, $tutorial['id'], $moduleB['id']);
    $done = $service->completeTutorial($owner, $tutorial['id']);
    $doneAgain = $service->completeTutorial($owner, $tutorial['id']);
    $check($done['completed'] === true && $doneAgain['status'] === 'COMPLETED' && $doneAgain['progressPercent'] === 100, 'tutorial completion persists and can be repeated');

    $saved = $service->saveAttempt($owner, $exercise['id'], ['sourceCode' => "int main(){return 0;}\n", 'language' => 'c']);
    $savedAgain = $service->saveAttempt($owner, $exercise['id'], ['sourceCode' => "int main(){return 1;}\n", 'language' => 'C']);
    $check($saved['status'] === 'ATTEMPTED' && !array_key_exists('testsPassed', $saved), 'an attempt is stored without a grade');
    $history = $service->listAttempts($owner, $exercise['id']);
    $check(count($history) === 2 && $history[0]['sourceCode'] !== '', 'repeated attempts are kept for the same student');
    $check($service->listAttempts($other, $exercise['id']) === [], 'another student cannot read these attempts');
    $throws(fn () => $service->saveAttempt($owner, $exercise['id'], ['sourceCode' => '   ', 'language' => 'c']));
    $throws(fn () => $service->saveAttempt($owner, $exercise['id'], ['sourceCode' => str_repeat('a', 70000), 'language' => 'c']));
    $throws(fn () => $service->saveAttempt($owner, $exercise['id'], ['sourceCode' => 'print(1)', 'language' => 'python']));
} catch (Throwable $e) {
    $check(false, 'unexpected: ' . $e->getMessage());
} finally {
    $progress = new StudentTutorialProgressModel();
    $moduleProgress = new StudentTutorialModuleProgressModel();
    $attemptModel = new StudentExerciseAttemptModel();
    foreach ($studentIds as $id) {
        foreach ($progress->findAll(['studentId' => $id], 50) as $row) {
            $progress->delete((string) ($row['_id'] ?? ''));
        }
        foreach ($moduleProgress->findAll(['studentId' => $id], 50) as $row) {
            $moduleProgress->delete((string) ($row['_id'] ?? ''));
        }
        foreach ($attemptModel->findAll(['studentId' => $id], 50) as $row) {
            $attemptModel->delete((string) ($row['_id'] ?? ''));
        }
        $students->delete($id);
    }
    foreach ($tutorialIds as $id) {
        try {
            $service->deleteTutorial($staff ?? ['role' => 'staff', '_id' => ''], $id);
        } catch (Throwable) {
        }
    }
    foreach ($userIds as $id) {
        $users->delete($id);
    }
    foreach ($departmentIds as $id) {
        $departments->delete($id);
    }
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
