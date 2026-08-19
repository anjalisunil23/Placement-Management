<?php

declare(strict_types=1);

/**
 * Resume Builder skills isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-skills.php
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
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_skills.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_skills'), 'schema.sql adds resume_skills');
$assert(str_contains($isolatedSql, 'skill_name VARCHAR(50)'), 'standalone SQL skill_name VARCHAR(50)');
$assert(str_contains($isolatedSql, 'skill_category VARCHAR(32)'), 'standalone SQL skill_category');
$assert(str_contains($collections, "RESUME_SKILLS = 'resume_skills'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/skills'), 'skills list/add routes');
$assert(str_contains($index, '/student/resume-builder/skills/{id}/delete'), 'skills delete route');
$assert(str_contains($controller, 'function addSkill'), 'addSkill action exists');
$assert(str_contains($controller, 'function updateSkill'), 'updateSkill action exists');
$assert(str_contains($controller, 'function deleteSkill'), 'deleteSkill action exists');
$assert(!str_contains($controller, 'updateProfile'), 'does not update student profile');
$assert(str_contains($js, 'SKILL_MIN = 2'), 'UI min length 2');
$assert(str_contains($js, 'SKILL_MAX = 50'), 'UI max length 50');
$assert(str_contains($js, 'SKILL_COMPLETE_MIN = 3'), 'complete at 3 skills');
$assert(str_contains($js, 'Skills Added:'), 'skill count label');
$assert(str_contains($js, 'Include technical, domain, software, communication and professional skills'), 'helper text');
$assert(str_contains($js, "api('/student/resume-builder/skills'"), 'AJAX list/add');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeSkillModel;

$assert(ResumeSkillModel::validate('P', 'Technical')['ok'] === false, '1 character is invalid');
$assert(ResumeSkillModel::validate('Py', 'Technical')['ok'] === true, '2 characters is valid');
$assert(ResumeSkillModel::validate(str_repeat('a', 50), 'Tools')['ok'] === true, '50 characters is valid');
$assert(ResumeSkillModel::validate(str_repeat('a', 51), 'Tools')['ok'] === false, '51 characters is invalid');
$assert(ResumeSkillModel::validate('Python', 'Unknown')['ok'] === false, 'invalid category rejected');
$assert(ResumeSkillModel::validate('Python', 'Technical')['ok'] === true, 'Technical category accepted');
$assert(ResumeSkillModel::normalizeName('  MS   Excel  ') === 'MS Excel', 'name whitespace normalized');
$assert(ResumeSkillModel::COMPLETE_MIN_COUNT === 3, 'completion requires 3 skills');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
