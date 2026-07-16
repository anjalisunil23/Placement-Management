<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\ClassInchargeRegistry;
use PMS\Services\StaffContext;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "PASS: {$msg}\n";
}

$meera = [
    'user' => ['name' => 'Meera Rose Mathew', 'email' => 'meera@amaljyothi.ac.in'],
    'profile' => [],
    'departmentId' => 'dept1',
];
$rony = [
    'user' => ['name' => 'Rony Tom', 'email' => 'rony@amaljyothi.ac.in'],
    'profile' => [],
    'departmentId' => 'dept1',
];
$other = [
    'user' => ['name' => 'Random Faculty', 'email' => 'random@amaljyothi.ac.in'],
    'profile' => [],
    'departmentId' => 'dept1',
];

assertTrue(ClassInchargeRegistry::staffIsInchargeOfBatch($meera, 'MCAINT2022-27-S9'), 'Meera is CT for INMCA 2022-27');
assertTrue(ClassInchargeRegistry::staffIsInchargeOfBatch($rony, 'MCAINT2022-27-S9'), 'Rony is CoCT for INMCA 2022-27');
assertTrue(!ClassInchargeRegistry::staffIsInchargeOfBatch($other, 'MCAINT2022-27-S9'), 'Other staff cannot edit INMCA 2022-27');
assertTrue(StaffContext::canEditClassBatch($meera, 'MCAINT2022-27-S8'), 'Semester suffix still matches cohort');
assertTrue(!StaffContext::canEditClassBatch($other, 'MCA2025-27-S3'), 'Other cannot edit MCA 2025-27');
assertTrue(StaffContext::canEditClassBatch(
    ['user' => ['name' => 'Nimmy Francis'], 'profile' => [], 'departmentId' => 'x'],
    'MCA2025-27-S3'
), 'Nimmy is CT for MCA 2025-27');

$binumon = [
    'user' => ['name' => 'Binumon Joseph', 'email' => 'binumon@amaljyothi.ac.in'],
    'profile' => [
        // Stale department-wide dump (the live bug).
        'assignedClassBatches' => ['MCA2025-27-S3', 'MCAINT2023-28-S7', 'MCA2026-28-S1'],
    ],
    'departmentId' => 'dept1',
];
assertTrue(StaffContext::canEditClassBatch($binumon, 'MCAINT2023-28-S7'), 'Binumon can edit his INMCA class');
assertTrue(!StaffContext::canEditClassBatch($binumon, 'MCA2025-27-S3'), 'Binumon cannot edit MCA even with stale profile batches');
$assigned = StaffContext::assignedClassBatches($binumon);
assertTrue(in_array('MCAINT2023-28', $assigned, true) || in_array('MCAINT2023-28-S7', $assigned, true), 'assigned lists Binumon cohort');
assertTrue(!in_array('MCA2025-27-S3', $assigned, true) && !in_array('MCA2025-27', $assigned, true), 'assigned excludes foreign MCA batch');

echo "All class-incharge checks passed.\n";
