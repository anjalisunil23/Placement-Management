<?php

declare(strict_types=1);

/**
 * Resume Builder certifications isolation and validation checks.
 *
 * Usage: php backend/scripts/test-resume-builder-certifications.php
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
$isolatedSql = (string) file_get_contents($root . '/backend/database/resume_certifications.sql');
$collections = (string) file_get_contents($root . '/backend/schemas/Collections.php');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$js = (string) file_get_contents($root . '/js/resume-builder.js');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');

$assert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS resume_certifications'), 'schema.sql adds resume_certifications');
$assert(str_contains($isolatedSql, 'certification_name VARCHAR(200)'), 'standalone SQL certification_name');
$assert(str_contains($isolatedSql, 'issuing_organization VARCHAR(150)'), 'standalone SQL issuing_organization');
$assert(str_contains($collections, "RESUME_CERTIFICATIONS = 'resume_certifications'"), 'Collections constant added');
$assert(str_contains($index, '/student/resume-builder/certifications'), 'certifications list/add routes');
$assert(str_contains($index, '/student/resume-builder/certifications/{id}/delete'), 'certifications delete route');
$assert(str_contains($controller, 'function addCertification'), 'addCertification action exists');
$assert(str_contains($controller, 'function updateCertification'), 'updateCertification action exists');
$assert(str_contains($controller, 'function deleteCertification'), 'deleteCertification action exists');
$assert(!str_contains($controller, 'updateProfile'), 'does not update student profile');
$assert(str_contains($js, 'CERT_NAME_MIN = 3'), 'UI name min 3');
$assert(str_contains($js, 'CERT_ORG_MIN = 2'), 'UI org min 2');
$assert(str_contains($js, 'CERT_COMPLETE_MIN = 1'), 'complete at 1 certification');
$assert(str_contains($js, 'Certifications Added:'), 'certification count label');
$assert(str_contains($js, 'No certifications added yet'), 'empty state message');
$assert(str_contains($js, 'Include courses, certifications, workshops'), 'helper text');
$assert(str_contains($js, "api('/student/resume-builder/certifications'"), 'AJAX list/add');
$assert(str_contains($js, 'View Credential'), 'view credential button');
$assert(str_contains($js, 'rb-cert-list'), 'certification card layout');

require $root . '/vendor/autoload.php';
use PMS\Models\ResumeCertificationModel;

$validBase = [
    'certification_name' => 'AWS Cloud Practitioner',
    'issuing_organization' => 'Amazon Web Services',
    'issue_date' => '2025-01-01',
    'expiry_date' => '2028-01-01',
    'credential_id' => 'ABC-123',
    'credential_url' => 'https://example.com/credential',
    'description' => 'Cloud fundamentals certification',
];

$assert(ResumeCertificationModel::validate(array_merge($validBase, ['certification_name' => 'AB']))['ok'] === false, 'name 2 chars invalid');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['certification_name' => 'ABC']))['ok'] === true, 'name 3 chars valid');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['issuing_organization' => 'A']))['ok'] === false, 'org 1 char invalid');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['issuing_organization' => 'AB']))['ok'] === true, 'org 2 chars valid');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['issue_date' => '']))['ok'] === false, 'issue date required');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['expiry_date' => '2024-01-01']))['ok'] === false, 'expiry before issue rejected');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['credential_url' => 'not-a-url']))['ok'] === false, 'invalid url rejected');
$assert(ResumeCertificationModel::validate(array_merge($validBase, ['expiry_date' => '']))['ok'] === true, 'optional expiry accepted');
$assert(ResumeCertificationModel::validate($validBase)['ok'] === true, 'valid certification accepted');
$assert(ResumeCertificationModel::COMPLETE_MIN_COUNT === 1, 'completion requires 1 certification');
$assert(ResumeCertificationModel::RECOMMENDED_COUNT === 2, 'recommended 2 certifications');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
