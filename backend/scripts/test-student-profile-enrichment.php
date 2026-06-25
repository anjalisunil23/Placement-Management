<?php

declare(strict_types=1);

/**
 * Verify AES profile enrichment overrides stale MariaDB personal JSON.
 * Usage: php backend/scripts/test-student-profile-enrichment.php [admno]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesLoginService;
use PMS\Services\OfficerDataService;

$register = strtoupper(trim($argv[1] ?? '17145'));
$service = new OfficerDataService();
$reflection = new ReflectionClass($service);

$apply = $reflection->getMethod('applyAesPlacementFieldsToRow');
$apply->setAccessible(true);

$placementMethod = $reflection->getMethod('placementProfileForRegister');
$placementMethod->setAccessible(true);
$placement = $placementMethod->invoke($service, $register);
$expected = (new AesLoginService())->mapAesDetailsToUserFields($placement);

if ($placement === []) {
    echo "FAIL: no AES placement profile for {$register}\n";
    exit(1);
}

// Simulate MariaDB student payload with another student's stale personal data.
$student = [
    '_id' => 'test-student-id',
    'registerNumber' => $register,
    'classBatch' => 'WRONG-BATCH',
    'personal' => [
        'phone' => '919895652005',
        'personalEmail' => 'anjalisunil200@gmail.com',
    ],
    'academic' => [
        'cgpa' => 0,
        'marks10th' => 0,
        'marks12th' => 0,
        'backlogs' => 99,
    ],
];

$row = $apply->invoke($service, [], $student);

$checks = [
    'collegeEmail'   => strtolower((string) ($row['collegeEmail'] ?? '')),
    'personalEmail'  => strtolower((string) ($row['personalEmail'] ?? '')),
    'phone'          => preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')),
    'classBatch'     => (string) ($row['classBatch'] ?? ''),
    'departmentName' => (string) ($row['departmentName'] ?? ''),
];

$expect = [
    'collegeEmail'   => strtolower((string) ($expected['collegeEmail'] ?? $expected['email'] ?? '')),
    'personalEmail'  => strtolower((string) ($expected['personalEmail'] ?? '')),
    'phone'          => preg_replace('/\D+/', '', (string) ($expected['phone'] ?? '')),
    'classBatch'     => (string) ($expected['classBatch'] ?? ''),
    'departmentName' => (string) ($expected['departmentName'] ?? $expected['branch'] ?? ''),
];

echo "=== Student profile enrichment ({$register}) ===\n";

$ok = true;
foreach ($checks as $field => $value) {
    $pass = $value !== '' && $value === $expect[$field];
    if ($field === 'collegeEmail' || $field === 'personalEmail') {
        $pass = $value !== '' && str_contains($value, '@') && ($expect[$field] === '' || $value === $expect[$field]);
    }
    echo ($pass ? 'PASS' : 'FAIL') . " {$field}: {$value}";
    if (!$pass && $expect[$field] !== '') {
        echo " (expected {$expect[$field]})";
        $ok = false;
    } elseif (!$pass) {
        $ok = false;
    }
    echo "\n";
}

if ($register === '17145' && ($checks['personalEmail'] === 'anjalisunil200@gmail.com' || $checks['phone'] === '919895652005')) {
    echo "FAIL stale MariaDB personal data was not replaced by AES\n";
    $ok = false;
}

exit($ok ? 0 : 1);
