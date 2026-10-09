<?php

declare(strict_types=1);

/**
 * Verify admin/campus placement filter dropdowns and registry scoping.
 *
 * Usage:
 *   php backend/scripts/test-admin-placement-filters.php
 *   php backend/scripts/test-admin-placement-filters.php --department-id=<id> --program=MCA
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Models\DepartmentModel;
use PMS\Services\PlacementFilterService;
use PMS\Services\StaffPlacementRegistryService;

$opts = getopt('', ['department-id:', 'program:', 'batch:']);

$adminCtx = [
    'profile'      => [],
    'departmentId' => '',
    'department'   => null,
    'isAdmin'      => true,
    'campusWide'   => true,
];

$registry = new StaffPlacementRegistryService();
$departments = $registry->departmentFilterOptions();
$deptCount = count($departments);

echo "=== Admin placement filters test ===\n";
echo "Academic departments in dropdown: {$deptCount}\n";
if ($deptCount > 0) {
    echo '  sample: ' . implode(', ', array_slice(array_map(
        static fn (array $d): string => ($d['name'] ?? '') . ' [' . ($d['id'] ?? '') . ']',
        $departments
    ), 0, 5)) . "\n";
}

$fail = false;
if ($deptCount < 2) {
    fwrite(STDERR, "FAIL: expected at least 2 academic departments (got {$deptCount}).\n");
    $fail = true;
}

$filterCtx = $registry->placementFilterContext($adminCtx, ['departmentId' => '', 'studRole' => 'all']);
$programs = (new PlacementFilterService())->fetchProgramOptions($filterCtx);
echo 'Campus-wide programmes: ' . count($programs) . "\n";
if ($programs !== []) {
    echo '  sample: ' . implode(', ', array_slice($programs, 0, 8)) . "\n";
}

$allList = $registry->list($adminCtx, [
    'departmentId' => '',
    'studRole'     => 'all',
    'omitFilterOptions' => '1',
]);
$allRows = is_array($allList['rows'] ?? null) ? count($allList['rows']) : 0;
echo "Campus-wide registry rows: {$allRows}\n";

$deptId = trim((string) ($opts['department-id'] ?? ''));
if ($deptId === '' && $departments !== []) {
    $deptId = (string) ($departments[0]['id'] ?? '');
}
if ($deptId !== '') {
    $deptFilterCtx = $registry->placementFilterContext($adminCtx, [
        'departmentId' => $deptId,
        'studRole'     => 'all',
    ]);
    $filterSvc = new PlacementFilterService();
    $deptPrograms = $filterSvc->fetchProgramOptions($deptFilterCtx);
    $deptBatches = $filterSvc->fetchBatchOptions($deptFilterCtx, '', '', false);
    echo 'Department programmes (branch dropdown): ' . count($deptPrograms) . "\n";
    if ($deptPrograms !== []) {
        echo '  sample: ' . implode(', ', array_slice($deptPrograms, 0, 8)) . "\n";
    }
    echo 'Department batches (no branch selected): ' . count($deptBatches) . "\n";
    if ($deptBatches !== []) {
        echo '  sample: ' . implode(', ', array_slice($deptBatches, 0, 6)) . "\n";
    }
    if ($deptPrograms === []) {
        fwrite(STDERR, "FAIL: no programmes for selected department.\n");
        $fail = true;
    }

    $program = trim((string) ($opts['program'] ?? ''));
    $batch = trim((string) ($opts['batch'] ?? ''));
    $scoped = $registry->list($adminCtx, [
        'departmentId' => $deptId,
        'program'      => $program,
        'batch'        => $batch,
        'studRole'     => 'all',
        'omitFilterOptions' => '1',
    ]);
    $scopedRows = is_array($scoped['rows'] ?? null) ? count($scoped['rows']) : 0;
    $dept = (new DepartmentModel())->findById($deptId);
    $label = trim((string) ($dept['name'] ?? $deptId));
    echo "Scoped list ({$label}): {$scopedRows} row(s)\n";
    if ($allRows > 0 && $scopedRows > $allRows) {
        fwrite(STDERR, "FAIL: scoped row count exceeds campus-wide count.\n");
        $fail = true;
    }
    if ($allRows > 5 && $scopedRows === $allRows) {
        fwrite(STDERR, "WARN: department filter did not reduce row count (check student_details dept mapping).\n");
    }
}

if ($fail) {
    exit(1);
}

echo "OK\n";
exit(0);
