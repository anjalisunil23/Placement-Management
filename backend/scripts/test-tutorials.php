<?php

declare(strict_types=1);

/**
 * Tutorial model foundation checks. Removes the temporary tutorial when finished.
 * Usage: php backend/scripts/test-tutorials.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialExerciseModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialTestCaseModel;

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

$pdo = Database::pdo();
$codingBefore = [];
foreach ([
    'coding_tests',
    'coding_attempts',
    'coding_problem_bank',
    'coding_practice_submissions',
    'coding_company_problem_sets',
    'certifications',
] as $table) {
    $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
    $codingBefore[$table] = $exists ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : null;
}

$categories = new TutorialCategoryModel();
$categories->seedDefaults();
$categories->seedDefaults();
$rows = $categories->listAll();
$slugs = [];
foreach ($rows as $row) {
    $slugs[(string) ($row['slug'] ?? '')] = true;
}
$expected = [
    'programming-languages',
    'tools',
    'technologies',
    'frameworks',
    'databases',
    'computer-science',
    'devops',
    'other',
];
$seedOk = true;
foreach ($expected as $slug) {
    if (!isset($slugs[$slug])) {
        $seedOk = false;
    }
}
$check($seedOk, 'eight default categories exist');
$check($categories->count(['slug' => 'tools']) === 1, 'seed does not duplicate Tools');

$category = $categories->findBySlug('programming-languages');
$check(is_array($category) && ($category['_id'] ?? '') !== '', 'programming languages category is stored');

$tutorials = new TutorialModel();
$modules = new TutorialModuleModel();
$exercises = new TutorialExerciseModel();
$cases = new TutorialTestCaseModel();

$tutorialId = '';
$moduleId = '';
$exerciseId = '';
$publicId = '';
$hiddenId = '';

try {
    $tutorial = $tutorials->create([
        'title' => 'Phase 2A temporary tutorial',
        'categoryId' => (string) ($category['_id'] ?? ''),
        'topic' => 'Git',
        'description' => 'Removed at the end of the model test.',
        'status' => 'draft',
        'visibility' => 'all',
        'departmentIds' => ['not-an-id'],
        'passingYears' => ['2027'],
    ]);
    $tutorialId = (string) ($tutorial['_id'] ?? '');
    $check($tutorialId !== '' && ($tutorial['visibility'] ?? '') === 'all', 'tutorial create');
    $check(($tutorial['departmentIds'] ?? null) === [] && ($tutorial['passingYears'] ?? null) === [], 'all visibility stores empty scope');
    $check(($tutorial['topic'] ?? '') === 'Git', 'topic stays free text');

    $module = $modules->create([
        'tutorialId' => $tutorialId,
        'title' => 'Getting started',
        'sortOrder' => 1,
        'content' => '<p>Intro</p>',
    ]);
    $moduleId = (string) ($module['_id'] ?? '');
    $check($moduleId !== '' && (int) ($module['sortOrder'] ?? 0) === 1, 'module linked to tutorial');

    $exercise = $exercises->create([
        'moduleId' => $moduleId,
        'title' => 'Print a line',
        'instructions' => 'Print hello.',
        'language' => 'C++',
        'boilerplate' => "int main(){return 0;}\n",
        'sortOrder' => 1,
    ]);
    $exerciseId = (string) ($exercise['_id'] ?? '');
    $check($exerciseId !== '' && ($exercise['language'] ?? '') === 'cpp', 'exercise language normalized without a compiler id');

    $publicCase = $cases->create([
        'exerciseId' => $exerciseId,
        'stdin' => '',
        'expectedOutput' => "hello\n",
        'sample' => true,
        'sortOrder' => 1,
    ]);
    $publicId = (string) ($publicCase['_id'] ?? '');
    $hiddenCase = $cases->create([
        'exerciseId' => $exerciseId,
        'stdin' => '2 3',
        'expectedOutput' => '5',
        'sample' => false,
        'sortOrder' => 2,
    ]);
    $hiddenId = (string) ($hiddenCase['_id'] ?? '');
    $listed = $cases->listByExercise($exerciseId);
    $check(count($listed) === 2 && ($listed[0]['sample'] ?? null) === true && ($listed[1]['sample'] ?? null) === false, 'public and hidden cases stay on the exercise');

    $check(count($modules->listByTutorial($tutorialId)) === 1, 'module lookup by tutorial');
    $check(count($exercises->listByModule($moduleId)) === 1, 'exercise lookup by module');
    $check(count($tutorials->listByCategory((string) ($category['_id'] ?? ''))) >= 1, 'tutorial lookup by category');
} catch (Throwable $e) {
    $check(false, 'temporary graph: ' . $e->getMessage());
}

foreach ([
    [$cases, $hiddenId],
    [$cases, $publicId],
    [$exercises, $exerciseId],
    [$modules, $moduleId],
    [$tutorials, $tutorialId],
] as [$model, $id]) {
    if ($id !== '') {
        $model->delete($id);
    }
}

$check($tutorialId === '' || $tutorials->findById($tutorialId) === null, 'temporary tutorial removed');
$check($moduleId === '' || $modules->findById($moduleId) === null, 'temporary module removed');
$check($exerciseId === '' || $exercises->findById($exerciseId) === null, 'temporary exercise removed');
$check($publicId === '' || $cases->findById($publicId) === null, 'temporary public case removed');
$check($hiddenId === '' || $cases->findById($hiddenId) === null, 'temporary hidden case removed');

$tables = [];
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
    $tables[] = (string) $row[0];
}
foreach (['tutorial_categories', 'tutorials', 'tutorial_modules', 'tutorial_exercises', 'tutorial_test_cases'] as $table) {
    $check(in_array($table, $tables, true), $table . ' exists');
}

$codingSame = true;
foreach ($codingBefore as $table => $count) {
    $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
    $after = $exists ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : null;
    if ($after !== $count) {
        $codingSame = false;
    }
}
$check($codingSame, 'coding and certification row counts unchanged');

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
