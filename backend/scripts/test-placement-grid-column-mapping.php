<?php

declare(strict_types=1);

/**
 * Verify staff placement grid columns map from AES-shaped records (no DB / no live AES required).
 *
 * Usage: php backend/scripts/test-placement-grid-column-mapping.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\AesApiService;
use PMS\Services\StaffPlacementRegistryService;

$aes = new AesApiService();

$sampleAesRow = $aes->applyStudInfoContactAliases([
    'stud_admno'         => '201612345',
    'stud_name'          => 'Sample Student',
    'stud_class'         => 'MCAINT2016-21',
    'stud_course'        => 'INMCA',
    'stud_mobiles'       => '9876543210',
    'stud_ajce_mails'    => 'sample@ajce.ac.in',
    'stud_company'       => 'Acme Corp',
    'job_role'           => 'Software Engineer',
    'package'            => '6 LPA',
    'company_address'    => 'Bangalore',
    'employer_contact'   => 'hr@acme.com',
    'placement_status'   => 'Placed',
]);

$placement = $aes->placementFieldsFromStudInfoDirectoryRecord($sampleAesRow);

$rosterRow = [
    '_id'            => '201612345',
    'id'             => '201612345',
    'studentId'      => '201612345',
    'registerNumber' => '201612345',
    'displayName'    => $sampleAesRow['studentName'] ?? 'Sample Student',
    'studentName'    => $sampleAesRow['studentName'] ?? 'Sample Student',
    'classBatch'     => $sampleAesRow['classBatch'] ?? 'MCAINT2016-21',
    'programme'      => 'INMCA',
    'phone'          => $sampleAesRow['phone'] ?? '',
    'email'          => $sampleAesRow['email'] ?? '',
    'collegeEmail'   => $sampleAesRow['collegeEmail'] ?? '',
    'placement'      => $placement,
    'placed'         => ($placement['company'] ?? '') !== '',
];

$svc = new StaffPlacementRegistryService();
$ref = new ReflectionClass($svc);
$hydrate = $ref->getMethod('hydrateRosterRowFromAesKeys');
$hydrate->setAccessible(true);
$extract = $ref->getMethod('extractRegistryRows');
$extract->setAccessible(true);

$hydrated = $hydrate->invoke($svc, $rosterRow);
$registry = $extract->invoke($svc, $hydrated, false, true);
$grid = $registry[0] ?? [];

$checks = [
    'phone'             => '9876543210',
    'email'             => 'sample@ajce.ac.in',
    'company'           => 'Acme Corp',
    'role'              => 'Software Engineer',
    'package'           => '6 LPA',
    'address'           => 'Bangalore',
    'employerContact'   => 'hr@acme.com',
    'placementStatus'   => 'Placed',
    'classBatch'        => 'MCAINT2016-21',
    'programme'         => 'INMCA',
];

echo "=== Placement grid column mapping test (synthetic AES row) ===\n\n";

$pass = 0;
$fail = 0;
foreach ($checks as $col => $want) {
    $got = trim((string) ($grid[$col] ?? $grid['employer'] ?? ''));
    if ($col === 'company' && $got === '') {
        $got = trim((string) ($grid['employer'] ?? ''));
    }
    $ok = strcasecmp($got, $want) === 0 || str_contains($got, $want);
    echo ($ok ? 'PASS' : 'FAIL') . "  {$col}: " . ($got !== '' ? $got : '(empty)') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "       expected: {$want}\n";
    }
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";

// Slim directory record must retain contact fields (regression).
$slim = (new ReflectionClass(AesApiService::class))->getMethod('slimDirectoryRecord');
$slim->setAccessible(true);
$slimOut = $slim->invoke($aes, [
    'stud_admno'      => '99',
    'stud_mobiles'    => '9000000001',
    'stud_ajce_mails' => 'slim@test.in',
    'stud_company'    => 'Slim Co',
]);
$slimOk = trim((string) ($slimOut['phone'] ?? '')) === '9000000001'
    && str_contains((string) ($slimOut['email'] ?? ''), 'slim@test.in');
echo ($slimOk ? 'PASS' : 'FAIL') . "  slimDirectoryRecord contact + placement passthrough\n";
if (!$slimOk) {
    $fail++;
} else {
    $pass++;
}

exit($fail > 0 ? 1 : 0);
