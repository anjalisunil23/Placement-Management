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

use PMS\Models\StudentPlacementModel;
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

// Legacy phpMyAdmin flat columns (empty JSON payload).
$legacySqlRow = [
    'id'        => '1',
    'payload'   => '{}',
    'student'   => 'Adithya Anil',
    'studentId' => '5458',
    'cno'       => '',
    'email'     => 'anil.adithya99@gmail.com',
    'year'      => '2020-2021',
    'courseId'  => '1001',
    'branchId'  => '35',
    'employer'  => 'Infosys Accenture Innovature',
    'empcno'    => '',
    'empadr'    => '',
    'payscale'  => '3.6 LPA',
];
$legacyDoc = StudentPlacementModel::mergeLegacyFlatRowIntoDoc($legacySqlRow, []);
$legacyRoster = StudentPlacementModel::rosterRowFromDocument(array_merge($legacyDoc, ['_id' => '5458']));
$legacyGrid = $extract->invoke($svc, $legacyRoster, false, true);
$legacyEntry = $legacyGrid[0] ?? [];
$legacyChecks = [
    'studentName' => 'Adithya Anil',
    'email'       => 'anil.adithya99@gmail.com',
    'company'     => 'Infosys Accenture Innovature',
    'package'     => '3.6 LPA',
    'classBatch'  => '2020-2021',
];
echo "\n=== Legacy flat student_placements row ===\n\n";
foreach ($legacyChecks as $col => $want) {
    $got = trim((string) ($legacyEntry[$col] ?? $legacyEntry['employer'] ?? ''));
    if ($col === 'company' && $got === '') {
        $got = trim((string) ($legacyEntry['employer'] ?? ''));
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

// Full production column set (phpMyAdmin) → grid must match DB values.
$fullLegacySql = [
    'id'            => '1',
    'payload'       => '{}',
    'studentId'     => '5458',
    'student'       => 'Adithya Anil',
    'cno'           => '9744225110',
    'email'         => 'anil.adithya99@gmail.com',
    'year'          => '2020-2021',
    'courseId'      => '1001',
    'branchId'      => '35',
    'employer'      => 'Infosys, Accenture, Innovature',
    'empcno'        => '0471 398 2222',
    'empadr'        => 'Talent Acquisition Infosys Limited Technopark',
    'payscale'      => '3.6 LPA',
    'status'        => 'Placed',
    'createdBy'     => 'admin',
    'crteatedDate'  => '2021-01-01',
    'updatedBy'     => 'admin',
    'updatedDate'   => '2021-01-02',
    's3file'        => 's3://offers/adithya.pdf',
    'filename'      => 'adithya.pdf',
    'fordvv'        => '1',
    'type'          => 'Placement',
    'includedvv'    => '1',
];
$fullDoc = StudentPlacementModel::mergeLegacyFlatRowIntoDoc($fullLegacySql, []);
$fullRoster = StudentPlacementModel::rosterRowFromDocument(array_merge($fullDoc, ['_id' => '5458']));
// Simulate empty AES profile overlay — must not wipe SQL values.
$fullRoster = StudentPlacementModel::mergePreserveFilled($fullRoster, [
    'phone' => '',
    'email' => null,
    'company' => '',
    'package' => null,
    'placement' => [
        'company' => '',
        'package' => null,
        'address' => '',
        'employerContact' => '',
        'placementStatus' => null,
        'fordvv' => '',
        'includedvv' => '',
        'recordType' => '',
    ],
]);
$fullRoster = $hydrate->invoke($svc, $fullRoster);
$fullGrid = $extract->invoke($svc, $fullRoster, false, true);
$fullEntry = $fullGrid[0] ?? [];

$dbToGrid = [
    'studentName'      => ['db' => 'student', 'want' => 'Adithya Anil'],
    'phone'            => ['db' => 'cno', 'want' => '9744225110'],
    'email'            => ['db' => 'email', 'want' => 'anil.adithya99@gmail.com'],
    'classBatch'       => ['db' => 'year', 'want' => '2020-2021'],
    'courseId'         => ['db' => 'courseId', 'want' => '1001'],
    'branchId'         => ['db' => 'branchId', 'want' => '35'],
    'company'          => ['db' => 'employer', 'want' => 'Infosys, Accenture, Innovature'],
    'employerContact'  => ['db' => 'empcno', 'want' => '0471 398 2222'],
    'address'          => ['db' => 'empadr', 'want' => 'Talent Acquisition Infosys Limited Technopark'],
    'package'          => ['db' => 'payscale', 'want' => '3.6 LPA'],
    'placementStatus'  => ['db' => 'status', 'want' => 'Placed'],
    'recordType'       => ['db' => 'type', 'want' => 'Placement'],
    'fordvv'           => ['db' => 'fordvv', 'want' => '1'],
    'includedvv'       => ['db' => 'includedvv', 'want' => '1'],
];

echo "\n=== All production SQL columns → grid (after empty AES merge) ===\n\n";
foreach ($dbToGrid as $gridCol => $meta) {
    $got = trim((string) ($fullEntry[$gridCol] ?? ''));
    if ($gridCol === 'company' && $got === '') {
        $got = trim((string) ($fullEntry['employer'] ?? ''));
    }
    if ($gridCol === 'recordType' && $got === '') {
        $got = trim((string) ($fullEntry['type'] ?? ''));
    }
    $want = $meta['want'];
    $ok = strcasecmp($got, $want) === 0 || ($want !== '' && str_contains($got, $want));
    echo ($ok ? 'PASS' : 'FAIL') . "  {$meta['db']} → {$gridCol}: "
        . ($got !== '' ? $got : '(empty)') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "       expected: {$want}\n";
    }
}

// Doc fields preserved on roster (not always grid columns).
$assertDoc = static function (string $label, string $got, string $want) use (&$pass, &$fail): void {
    $ok = $got === $want;
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}: " . ($got !== '' ? $got : '(empty)') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
};
echo "\n=== Extra DB columns on document ===\n\n";
$assertDoc('s3file → offerLetter', trim((string) ($fullDoc['placement']['offerLetter'] ?? '')), 's3://offers/adithya.pdf');
$assertDoc('filename', trim((string) ($fullDoc['filename'] ?? '')), 'adithya.pdf');
$assertDoc('createdBy', trim((string) ($fullDoc['createdBy'] ?? '')), 'admin');
$assertDoc('crteatedDate → createdDate', trim((string) ($fullDoc['createdDate'] ?? '')), '2021-01-01');

// Third screenshot-style row with multi-employer + package.
$row3Sql = [
    'id' => '4', 'payload' => '{}',
    'student' => 'Ajesh', 'studentId' => '5461',
    'cno' => '8547157902', 'email' => 'ajeshmokavoor@gmail.com',
    'year' => '2020-2021', 'courseId' => '1001', 'branchId' => '35',
    'employer' => 'Infosys, Experion Tech, Mahindra', 'empcno' => '0471 398 2222',
    'empadr' => 'Talent Acquisition Infosys Limited Technopark', 'payscale' => '3.6 LPA',
    'status' => 'Joined', 'type' => 'Placement', 'fordvv' => '1', 'includedvv' => '1',
];
$row3Doc = StudentPlacementModel::mergeLegacyFlatRowIntoDoc($row3Sql, []);
$row3Roster = StudentPlacementModel::rosterRowFromDocument(array_merge($row3Doc, ['_id' => '5461']));
$row3Grid = $extract->invoke($svc, $hydrate->invoke($svc, $row3Roster), false, true);
$row3 = $row3Grid[0] ?? [];
$row3Checks = [
    'studentName' => 'Ajesh',
    'phone' => '8547157902',
    'email' => 'ajeshmokavoor@gmail.com',
    'company' => 'Infosys, Experion Tech, Mahindra',
    'employerContact' => '0471 398 2222',
    'address' => 'Talent Acquisition Infosys Limited Technopark',
    'package' => '3.6 LPA',
    'placementStatus' => 'Placed',
];
echo "\n=== Screenshot-style row (Ajesh) ===\n\n";
foreach ($row3Checks as $col => $want) {
    $got = trim((string) ($row3[$col] ?? ($col === 'company' ? ($row3['employer'] ?? '') : '')));
    $ok = strcasecmp($got, $want) === 0 || str_contains($got, $want);
    echo ($ok ? 'PASS' : 'FAIL') . "  {$col}: " . ($got !== '' ? $got : '(empty)') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

echo "\n=== Legacy batch year vs AES class label ===\n\n";
$legacyBatchCases = [
    ['MCALE2016-18', '2020-2021', true],
    ['MCA LE2016-18', '2019-2020', true],
    ['MCALE2016-18', 'MCALE2016-18', true],
    ['MCALE2016-18', 'MCAINT2016-21', false],
];
foreach ($legacyBatchCases as [$want, $row, $expect]) {
    $got = StudentPlacementModel::legacyBatchFilterMatches($want, $row);
    $ok = $got === $expect;
    echo ($ok ? 'PASS' : 'FAIL') . "  legacyBatchFilterMatches({$want}, {$row}) => "
        . ($got ? 'true' : 'false') . " (want " . ($expect ? 'true' : 'false') . ")\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
