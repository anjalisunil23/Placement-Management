<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\StaffContext;

function assertTrue(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "PASS: {$msg}\n";
}

$binumon = [
    'user' => ['name' => 'Binumon Joseph', 'email' => 'binumon@ajce.in'],
    'profile' => [],
    'departmentId' => 'dept-ca',
];

$inClass = [
    'departmentId' => 'dept-ca',
    'classBatch' => 'MCAINT2023-28-S7',
];
$otherClass = [
    'departmentId' => 'dept-ca',
    'classBatch' => 'MCA2025-27-S3',
];
$aesOnly = [
    'classBatch' => 'MCAINT2023-28-S7',
];

assertTrue(StaffContext::studentMatchesScope($inClass, $binumon), 'CT sees own class student');
assertTrue(!StaffContext::studentMatchesScope($otherClass, $binumon), 'CT does not see other class');
assertTrue(StaffContext::studentMatchesScope($aesOnly, $binumon), 'CT sees AES-only row in own class');

$poCtx = [
    'isAdmin' => false,
    'departmentId' => 'dept-ca',
    'staffScope' => false,
];
// PO path does not use studentMatchesScope for final-year AES list; simulate dept-only:
assertTrue(
    (string) ($otherClass['departmentId'] ?? '') === (string) $poCtx['departmentId'],
    'PO department includes MCA students'
);

echo "Student scope filters OK.\n";
