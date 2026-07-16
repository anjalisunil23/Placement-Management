<?php

declare(strict_types=1);

/**
 * Offline unit check: local-merged AES rows must expose employer via extractRegistryRows.
 * Run: php backend/scripts/test-registry-extract-id.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\StaffPlacementRegistryService;

$svc = new StaffPlacementRegistryService();
$ref = new ReflectionClass($svc);
$extract = $ref->getMethod('extractRegistryRows');
$extract->setAccessible(true);

$row = [
    '_id' => 'abc123localid',
    // intentionally no `id` — MariaDB docs look like this before the mapAes fix
    'registerNumber' => 'AJC22MCA001',
    'admno' => 'AJC22MCA001',
    'displayName' => 'Test Student',
    'classBatch' => 'MCAINT2022-27-S9',
    'stud_course' => 'INMCA',
    'placed' => true,
    'placement' => [
        'company' => 'Acme Corp',
        'role' => 'Developer',
        'package' => '6 LPA',
        'address' => 'Kochi',
        'employerContact' => '9999999999',
        'recordType' => 'Placement',
    ],
];

$entries = $extract->invoke($svc, $row, false);
$ok = count($entries) === 1
    && ($entries[0]['employer'] ?? '') === 'Acme Corp'
    && ($entries[0]['studentId'] ?? '') === 'abc123localid'
    && ($entries[0]['package'] ?? '') === '6 LPA'
    && ($entries[0]['employerContact'] ?? '') === '9999999999';

echo $ok ? "PASS extractRegistryRows uses _id and surfaces employer\n" : "FAIL\n";
if (!$ok) {
    echo json_encode($entries, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$blank = [
    'id' => 'AJC22MCA002',
    'registerNumber' => 'AJC22MCA002',
    'displayName' => 'Blank Student',
    'classBatch' => 'MCAINT2022-27-S9',
    'stud_course' => 'INMCA',
    'placed' => false,
];
$blankEntries = $extract->invoke($svc, $blank, false);
$okBlank = count($blankEntries) === 1
    && ($blankEntries[0]['employer'] ?? 'x') === ''
    && ($blankEntries[0]['source'] ?? '') === 'class_roster';
echo $okBlank ? "PASS blank roster still emitted\n" : "FAIL blank\n";
exit($okBlank ? 0 : 1);
