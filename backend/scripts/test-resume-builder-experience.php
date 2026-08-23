<?php

declare(strict_types=1);

/**
 * Resume Builder experience isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-experience.php
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
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_experience.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_experience'), 'schema.sql adds resume_experience');
$assert(str_contains($isolatedSql, 'organization_name VARCHAR(150)'), 'standalone SQL organization_name');
$assert(str_contains($isolatedSql, 'currently_working TINYINT(1)'), 'standalone SQL currently_working');
$assert(str_contains($collections, "RESUME_EXPERIENCE = 'resume_experience'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/experience'), 'experience list/add routes');
$assert(str_contains($index, '/student/resume-builder/experience/{id}/delete'), 'experience delete route');
$assert(str_contains($controller, 'function addExperience'), 'addExperience action exists');
$assert(str_contains($controller, 'function updateExperience'), 'updateExperience action exists');
$assert(str_contains($controller, 'function deleteExperience'), 'deleteExperience action exists');
$assert(!str_contains($controller, 'updateProfile'), 'does not update student profile');
$assert(str_contains($js, 'EXP_ORG_MIN = 3'), 'UI org min 3');
$assert(str_contains($js, 'EXP_DESC_MIN = 50'), 'UI description min 50');
$assert(str_contains($js, 'EXP_COMPLETE_MIN = 1'), 'complete at 1 experience');
$assert(str_contains($js, 'Experience Added:'), 'experience count label');
$assert(str_contains($js, 'No experience added yet'), 'empty state message');
$assert(str_contains($js, 'Include internships, industrial training, research work'), 'helper text');
$assert(str_contains($js, "api('/student/resume-builder/experience'"), 'AJAX list/add');
$assert(str_contains($js, 'rb-exp-timeline'), 'timeline layout class');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeExperienceModel;

$validBase = [
    'organization_name' => 'Tech Corp',
    'position_title' => 'Software Intern',
    'experience_type' => 'Internship',
    'location' => 'Chennai',
    'description' => str_repeat('A', 50),
    'start_date' => '2025-01-01',
    'end_date' => '2025-06-01',
    'currently_working' => false,
];

$assert(ResumeExperienceModel::validate(array_merge($validBase, ['organization_name' => 'AB']))['ok'] === false, 'org 2 chars invalid');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['organization_name' => 'ABC']))['ok'] === true, 'org 3 chars valid');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['position_title' => 'AB']))['ok'] === false, 'position 2 chars invalid');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['description' => str_repeat('A', 49)]))['ok'] === false, 'description 49 invalid');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['description' => str_repeat('A', 50)]))['ok'] === true, 'description 50 valid');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['experience_type' => 'Unknown']))['ok'] === false, 'invalid type rejected');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['start_date' => '']))['ok'] === false, 'start date required');
$assert(ResumeExperienceModel::validate(array_merge($validBase, ['end_date' => '2024-01-01']))['ok'] === false, 'end before start rejected');
$assert(ResumeExperienceModel::validate(array_merge($validBase, [
    'currently_working' => true,
    'end_date' => '2025-06-01',
]))['ok'] === false, 'end date with currently working rejected');
$assert(ResumeExperienceModel::validate(array_merge($validBase, [
    'currently_working' => true,
    'end_date' => '',
]))['ok'] === true, 'currently working without end date accepted');
$assert(ResumeExperienceModel::validate($validBase)['ok'] === true, 'valid experience accepted');
$assert(ResumeExperienceModel::COMPLETE_MIN_COUNT === 1, 'completion requires 1 experience');
$assert(ResumeExperienceModel::RECOMMENDED_COUNT === 2, 'recommended 2 experiences');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
