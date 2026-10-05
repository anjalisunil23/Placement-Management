<?php

declare(strict_types=1);

/**
 * Tutorials practice execution and lesson-completion checks.
 * The runner is mocked. No student code is executed in PHP.
 * Usage: php backend/scripts/test-tutorial-execution.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\TutorialLessonQuestionModel;
use PMS\Models\UserModel;
use PMS\Services\TutorialsCodeExecutionService;
use PMS\Services\TutorialsPistonClient;
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

final class ScriptedTutorialsPiston extends TutorialsPistonClient
{
    public function __construct()
    {
        parent::__construct('http://isolated-tutorials.test');
    }

    public function execute(string $language, string $source, string $stdin, int $timeLimitMs): array
    {
        if (str_contains($source, 'SYNTAX')) {
            return [
                'ok' => false,
                'status' => 'Compilation Error',
                'stdout' => '',
                'stderr' => 'SyntaxError: invalid syntax',
                'timedOut' => false,
                'durationMs' => 8,
            ];
        }
        if (str_contains($source, 'SLEEP')) {
            return [
                'ok' => false,
                'status' => 'Time Limit Exceeded',
                'stdout' => '',
                'stderr' => 'Execution exceeded the time limit.',
                'timedOut' => true,
                'durationMs' => $timeLimitMs,
            ];
        }
        if (str_contains($source, 'MULTI')) {
            return [
                'ok' => true,
                'status' => 'OK',
                'stdout' => "line1\nline2\n",
                'stderr' => '',
                'timedOut' => false,
                'durationMs' => 15,
            ];
        }

        return [
            'ok' => true,
            'status' => 'OK',
            'stdout' => $stdin,
            'stderr' => '',
            'timedOut' => false,
            'durationMs' => 4,
        ];
    }
}

$suffix = bin2hex(random_bytes(3));
$users = new UserModel();
$students = new StudentModel();
$departments = new DepartmentModel();
$service = new TutorialService();
$service->useCodeExecution(new TutorialsCodeExecutionService(new ScriptedTutorialsPiston()));
$userIds = [];
$studentIds = [];
$tutorialIds = [];

$makeUser = static function (string $role, string $name) use ($users, $suffix, &$userIds): array {
    $id = $users->createUser([
        'name' => $name,
        'email' => $name . '.' . $suffix . '@tutorial-exec.test',
        'password' => 'tutorial-exec-pass',
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
    $unconfigured = new TutorialsCodeExecutionService(new TutorialsPistonClient(''));
    $throws(static function () use ($unconfigured): void {
        $unconfigured->run('python', "print('hello')\n", '', 2000);
    }, 'unconfigured runner does not invent output');

    $staff = $makeUser('staff', 'exec_staff');
    $student = $makeUser('student', 'exec_student');
    $deptId = $departments->createDepartment([
        'name' => 'Exec Dept ' . $suffix,
        'code' => 'EX' . strtoupper(substr($suffix, 0, 4)),
    ]);
    $studentIds[] = $students->createProfile((string) $student['_id'], [
        'registerNumber' => 'EX' . $suffix,
        'departmentId' => $deptId,
        'classBatch' => 'MCA2025-27-S3',
    ]);
    $categoryId = '';
    foreach ($service->listCategories($staff) as $category) {
        if (($category['slug'] ?? '') === 'programming-languages') {
            $categoryId = (string) ($category['id'] ?? '');
        }
    }
    $check($categoryId !== '', 'category exists');
    $course = $service->createTutorial($staff, [
        'title' => 'Exec course ' . $suffix,
        'topic' => 'Python',
        'description' => 'Execution checks.',
        'categoryId' => $categoryId,
        'visibility' => 'all',
    ]);
    $tutorialIds[] = (string) $course['id'];
    $moduleA = $service->createModule($staff, (string) $course['id'], [
        'title' => 'Variables',
        'content' => json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'vars', 'type' => 'heading', 'level' => 2, 'text' => 'Python Variables'],
                ['type' => 'paragraph', 'text' => 'Names store values.'],
                ['id' => 'types', 'type' => 'heading', 'level' => 2, 'text' => 'Data Types'],
                ['type' => 'paragraph', 'text' => 'Integers and strings.'],
            ],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $moduleB = $service->createModule($staff, (string) $course['id'], [
        'title' => 'Loops',
        'content' => json_encode([
            'version' => 1,
            'blocks' => [
                ['id' => 'loops', 'type' => 'heading', 'level' => 2, 'text' => 'Loops'],
                ['type' => 'paragraph', 'text' => 'Repeat work.'],
            ],
        ], JSON_UNESCAPED_SLASHES),
    ]);
    $exercise = $service->createExercise($staff, (string) $course['id'], (string) $moduleA['id'], [
        'title' => 'Print two lines',
        'instructions' => 'Print two lines.',
        'language' => 'python',
        'boilerplate' => "print('line1')\n",
        'lessonBlockId' => 'vars',
    ]);
    $service->createTestCase($staff, (string) $exercise['id'], [
        'stdin' => '',
        'expectedOutput' => "line1\nline2\n",
        'sample' => true,
        'sortOrder' => 1,
    ]);
    $service->createTestCase($staff, (string) $exercise['id'], [
        'stdin' => "secret-in\n",
        'expectedOutput' => "SECRET_OUT\n",
        'sample' => false,
        'sortOrder' => 2,
    ]);
    $plain = $service->createExercise($staff, (string) $course['id'], (string) $moduleB['id'], [
        'title' => 'Unused',
        'instructions' => 'Not linked.',
        'language' => 'python',
        'boilerplate' => '',
        'lessonBlockId' => '',
    ]);
    $service->publish($staff, (string) $course['id']);

    $detail = $service->showForStudent($student, (string) $course['id']);
    $check(
        count($detail['modules'] ?? []) === 2
        && count($detail['modules'][0]['lessonOutline'] ?? []) === 2
        && ($detail['modules'][0]['lessonOutline'][0]['id'] ?? '') === 'vars'
        && ($detail['modules'][1]['lessonOutline'][0]['id'] ?? '') === 'loops',
        'course outline keeps lessons under their modules'
    );
    $visited = $service->moduleForStudent($student, (string) $course['id'], (string) $moduleA['id']);
    $afterVisit = $service->progressForStudent($student, (string) $course['id']);
    $check(
        ($afterVisit['workspace']['lessons'][(string) $moduleA['id'] . ':vars'] ?? true) === false
        && ($afterVisit['workspace']['completedLessons'] ?? 1) === 0
        && count($visited['lessons'] ?? []) === 2
        && ($visited['exercises'] ?? null) === [],
        'opening a lesson does not complete it and does not show programming exercises'
    );

    $ran = $service->runExercise($student, (string) $exercise['id'], [
        'sourceCode' => "MULTI\n",
        'language' => 'python',
        'testsPassed' => 9,
    ]);
    $check(
        ($ran['stdout'] ?? '') === "line1\nline2\n"
        && ($ran['ok'] ?? false) === true
        && ($ran['durationMs'] ?? 0) === 15
        && !array_key_exists('testsPassed', $ran),
        'run returns actual stdout and ignores a client score'
    );
    $syntax = $service->runExercise($student, (string) $exercise['id'], [
        'sourceCode' => "SYNTAX\n",
        'language' => 'python',
    ]);
    $check(($syntax['ok'] ?? true) === false && str_contains((string) ($syntax['stderr'] ?? ''), 'SyntaxError'), 'syntax errors are returned as stderr');
    $slow = $service->runExercise($student, (string) $exercise['id'], [
        'sourceCode' => "SLEEP\n",
        'language' => 'python',
    ]);
    $check(($slow['timedOut'] ?? false) === true && ($slow['status'] ?? '') === 'Time Limit Exceeded', 'time limit is reported');

    $graded = $service->submitExercise($student, (string) $exercise['id'], [
        'sourceCode' => "MULTI\n",
        'language' => 'python',
        'passed' => true,
        'testsPassed' => 2,
        'testsTotal' => 2,
    ]);
    $encoded = json_encode($graded);
    $hidden = null;
    foreach ((array) ($graded['results'] ?? []) as $row) {
        if (($row['sample'] ?? true) === false) {
            $hidden = $row;
        }
    }
    $check(
        ($graded['testsTotal'] ?? 0) === 2
        && ($graded['testsPassed'] ?? 0) === 1
        && ($graded['testsFailed'] ?? 0) === 1
        && ($graded['passed'] ?? true) === false
        && is_array($hidden)
        && !array_key_exists('stdin', $hidden)
        && !array_key_exists('expectedOutput', $hidden)
        && !array_key_exists('stdout', $hidden)
        && is_string($encoded)
        && !str_contains($encoded, 'SECRET_OUT')
        && !str_contains($encoded, 'secret-in'),
        'submit grades on the server and hides hidden tests'
    );
    $history = $service->listAttempts($student, (string) $exercise['id']);
    $check(count($history) === 1 && ($history[0]['status'] ?? '') === 'FAILED', 'submission history is stored');
    (new TutorialLessonQuestionModel())->createQuestion([
        'tutorialId' => (string) $course['id'],
        'moduleId' => (string) $moduleA['id'],
        'lessonBlockId' => 'vars',
        'question' => 'Which name can store a value in Python?',
        'options' => ['total', '2total', 'total-sum', 'class'],
        'correctIndex' => 0,
        'explanation' => 'A variable name starts with a letter and is not a reserved word.',
        'difficulty' => 'beginner',
        'sortOrder' => 1,
    ]);
    $throws(static function () use ($service, $student, $course, $moduleA): void {
        $service->markLessonReviewed($student, (string) $course['id'], (string) $moduleA['id'], 'vars');
    }, 'a lesson with practice questions cannot be marked complete by hand');
    $reviewed = $service->markLessonReviewed($student, (string) $course['id'], (string) $moduleA['id'], 'types');
    $check(
        ($reviewed['workspace']['lessons'][(string) $moduleA['id'] . ':types'] ?? false) === true
        && ($reviewed['workspace']['lessons'][(string) $moduleA['id'] . ':vars'] ?? true) === false
        && ($reviewed['progressPercent'] ?? 100) !== 100,
        'only the reviewed lesson is complete and the course percentage stays on the server'
    );
    $check(($plain['id'] ?? '') !== '', 'an unlinked exercise can still be created');
} catch (Throwable $e) {
    $check(false, 'unexpected: ' . $e->getMessage());
} finally {
    foreach ($tutorialIds as $id) {
        if ($id !== '') {
            try {
                $service->deleteTutorial($staff ?? ['role' => 'staff'], $id);
            } catch (Throwable) {
                // best-effort
            }
        }
    }
    foreach ($studentIds as $id) {
        if ($id !== '') {
            $students->delete($id);
        }
    }
    foreach ($userIds as $id) {
        if ($id !== '') {
            $users->delete($id);
        }
    }
}

echo PHP_EOL . "{$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
