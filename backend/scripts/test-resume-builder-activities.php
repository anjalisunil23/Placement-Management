<?php

declare(strict_types=1);

/**
 * Resume Builder activities isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-activities.php
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
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_activities.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_activities'), 'schema.sql adds resume_activities');
$assert(str_contains($isolatedSql, 'title VARCHAR(150)'), 'standalone SQL title');
$assert(str_contains($isolatedSql, 'activity_type VARCHAR(32)'), 'standalone SQL activity_type');
$assert(str_contains($collections, "RESUME_ACTIVITIES = 'resume_activities'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/activities'), 'activities list/add routes');
$assert(str_contains($index, '/student/resume-builder/activities/{id}/delete'), 'activities delete route');
$assert(str_contains($controller, 'function addActivity'), 'addActivity action exists');
$assert(str_contains($controller, 'function updateActivity'), 'updateActivity action exists');
$assert(str_contains($controller, 'function deleteActivity'), 'deleteActivity action exists');
$assert(!str_contains($controller, 'updateProfile'), 'does not update student profile');
$assert(str_contains($js, 'ACT_TITLE_MIN = 3'), 'UI title min 3');
$assert(str_contains($js, 'ACT_DESC_MIN = 20'), 'UI description min 20');
$assert(str_contains($js, 'ACT_COMPLETE_MIN = 1'), 'complete at 1 activity');
$assert(str_contains($js, 'Activities Added:'), 'activity count label');
$assert(str_contains($js, 'No achievements or activities added yet'), 'empty state message');
$assert(str_contains($js, 'Include leadership roles, volunteer work'), 'helper text');
$assert(str_contains($js, "api('/student/resume-builder/activities'"), 'AJAX list/add');
$assert(str_contains($js, 'Achievements, Leadership & Activities'), 'section title');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeActivityModel;

$validBase = [
    'title' => 'Student Council President',
    'activity_type' => 'Leadership',
    'organization' => 'College Student Union',
    'description' => str_repeat('A', 20),
    'activity_date' => '2025-03-01',
];

$assert(ResumeActivityModel::validate(array_merge($validBase, ['title' => 'AB']))['ok'] === false, 'title 2 chars invalid');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['title' => 'ABC']))['ok'] === true, 'title 3 chars valid');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['description' => str_repeat('A', 19)]))['ok'] === false, 'description 19 invalid');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['description' => str_repeat('A', 20)]))['ok'] === true, 'description 20 valid');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['activity_type' => 'Unknown']))['ok'] === false, 'invalid type rejected');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['activity_date' => 'invalid']))['ok'] === false, 'invalid date rejected');
$assert(ResumeActivityModel::validate(array_merge($validBase, ['activity_date' => '']))['ok'] === true, 'optional date accepted');
$assert(ResumeActivityModel::validate($validBase)['ok'] === true, 'valid activity accepted');
$assert(ResumeActivityModel::COMPLETE_MIN_COUNT === 1, 'completion requires 1 activity');
$assert(ResumeActivityModel::RECOMMENDED_COUNT === 2, 'recommended 2 activities');
$assert(count(ResumeActivityModel::TYPES) === 11, 'eleven activity types defined');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
