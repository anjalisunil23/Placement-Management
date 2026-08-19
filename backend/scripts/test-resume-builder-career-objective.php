<?php

declare(strict_types=1);

/**
 * Resume Builder career-objective isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-career-objective.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$label}\n";
    $failed++;
};

$schema = (string) file_get_contents($root . '/backend/database/schema.sql');
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_career_objectives.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$model = (string) file_get_contents($root . '/backend/models/ResumeCareerObjectiveModel.php');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_career_objectives'), 'schema.sql adds isolated table');
$assert(str_contains($isolatedSql, 'objective_text VARCHAR(500)'), 'standalone SQL defines objective_text VARCHAR(500)');
$assert(str_contains($isolatedSql, 'student_id CHAR(24)'), 'standalone SQL defines student_id');
$assert(str_contains($collections, "RESUME_CAREER_OBJECTIVES = 'resume_career_objectives'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/career-objective'), 'API routes added');
$assert(str_contains($index, 'ResumeBuilderController'), 'ResumeBuilderController is wired');
$assert(!str_contains($controller, 'updateProfile'), 'controller does not call profile update');
$assert(str_contains($model, 'MIN_LENGTH = 50'), 'min length 50');
$assert(str_contains($model, 'MAX_LENGTH = 500'), 'max length 500');
$assert(str_contains($js, 'OBJECTIVE_MIN = 50'), 'UI min length 50');
$assert(str_contains($js, 'OBJECTIVE_MAX = 500'), 'UI max length 500');
$assert(str_contains($js, 'data-rb-objective-save'), 'Save control exists');
$assert(str_contains($js, 'data-rb-objective-edit'), 'Edit control exists');
$assert(str_contains($js, 'MCA student passionate about software development'), 'helper example 1');
$assert(str_contains($js, 'Seeking opportunities to apply programming and analytical skills'), 'helper example 2');
$assert(str_contains($js, "api('/student/resume-builder/career-objective'"), 'UI uses isolated AJAX endpoint');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeCareerObjectiveModel;

$short = ResumeCareerObjectiveModel::validateText(str_repeat('a', 49));
$assert($short['ok'] === false, '49 characters is invalid');

$ok = ResumeCareerObjectiveModel::validateText(str_repeat('a', 50));
$assert($ok['ok'] === true, '50 characters is valid');

$long = ResumeCareerObjectiveModel::validateText(str_repeat('a', 501));
$assert($long['ok'] === false, '501 characters is invalid');

$max = ResumeCareerObjectiveModel::validateText(str_repeat('a', 500));
$assert($max['ok'] === true, '500 characters is valid');

$padded = ResumeCareerObjectiveModel::validateText('  ' . str_repeat('b', 50) . '  ');
$assert($padded['ok'] === true, 'trimmed length is used for validation');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
