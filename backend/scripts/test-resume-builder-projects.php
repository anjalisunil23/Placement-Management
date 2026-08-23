<?php

declare(strict_types=1);

/**
 * Resume Builder projects isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-projects.php
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
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_projects.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_projects'), 'schema.sql adds resume_projects');
$assert(str_contains($isolatedSql, 'project_title VARCHAR(150)'), 'standalone SQL project_title');
$assert(str_contains($isolatedSql, 'project_description VARCHAR(1000)'), 'standalone SQL project_description');
$assert(str_contains($collections, "RESUME_PROJECTS = 'resume_projects'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/projects'), 'projects list/add routes');
$assert(str_contains($index, '/student/resume-builder/projects/{id}/delete'), 'projects delete route');
$assert(str_contains($controller, 'function addProject'), 'addProject action exists');
$assert(str_contains($controller, 'function updateProject'), 'updateProject action exists');
$assert(str_contains($controller, 'function deleteProject'), 'deleteProject action exists');
$assert(!str_contains($controller, 'updateProfile'), 'does not update student profile');
$assert(str_contains($js, 'PROJECT_TITLE_MIN = 3'), 'UI title min 3');
$assert(str_contains($js, 'PROJECT_DESC_MIN = 50'), 'UI description min 50');
$assert(str_contains($js, 'PROJECT_COMPLETE_MIN = 1'), 'complete at 1 project');
$assert(str_contains($js, 'Projects Added:'), 'project count label');
$assert(str_contains($js, 'Include academic, personal, internship, research, or freelance projects'), 'helper text');
$assert(str_contains($js, "api('/student/resume-builder/projects'"), 'AJAX list/add');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeProjectModel;

$validBase = [
    'project_title' => 'Campus Portal',
    'project_type' => 'Academic',
    'technologies_used' => 'PHP, MySQL',
    'project_description' => str_repeat('A', 50),
    'project_link' => 'https://example.com/project',
    'start_date' => '2025-01-01',
    'end_date' => '2025-06-01',
];

$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_title' => 'AB']))['ok'] === false, 'title 2 chars invalid');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_title' => 'ABC']))['ok'] === true, 'title 3 chars valid');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_description' => str_repeat('A', 49)]))['ok'] === false, 'description 49 invalid');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_description' => str_repeat('A', 50)]))['ok'] === true, 'description 50 valid');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_type' => 'Unknown']))['ok'] === false, 'invalid type rejected');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['project_link' => 'not-a-url']))['ok'] === false, 'invalid link rejected');
$assert(ResumeProjectModel::validate(array_merge($validBase, ['end_date' => '2024-01-01']))['ok'] === false, 'end before start rejected');
$assert(ResumeProjectModel::validate($validBase)['ok'] === true, 'valid project accepted');
$assert(ResumeProjectModel::COMPLETE_MIN_COUNT === 1, 'completion requires 1 project');
$assert(ResumeProjectModel::RECOMMENDED_COUNT === 2, 'recommended 2 projects');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
