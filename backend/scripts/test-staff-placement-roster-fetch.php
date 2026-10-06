<?php

declare(strict_types=1);

/**
 * Smoke test: AES + staff placement registry list for a department/program/batch.
 *
 * Usage:
 *   php backend/scripts/test-staff-placement-roster-fetch.php --department-name="Computer Applications" --program=MCA
 *   php backend/scripts/test-staff-placement-roster-fetch.php --department-name="Computer Applications" --program=MCA --batch=MCA-2023-25
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
use PMS\Models\StudentPlacementModel;
use PMS\Services\AesApiService;
use PMS\Services\PlacementFilterService;
use PMS\Services\StaffPlacementRegistryService;
use PMS\Services\StaffContext;

$opts = getopt('', ['department-id:', 'department-code:', 'department-name:', 'program:', 'batch:']);

$deptModel = new DepartmentModel();
$dept = null;
if (!empty($opts['department-id'])) {
    $dept = $deptModel->findById((string) $opts['department-id']);
} elseif (!empty($opts['department-code'])) {
    $dept = $deptModel->findByCode(strtoupper(trim((string) $opts['department-code'])));
} elseif (!empty($opts['department-name'])) {
    $name = trim((string) $opts['department-name']);
    foreach ($deptModel->findAll([], 500) as $row) {
        if (strcasecmp(trim((string) ($row['name'] ?? '')), $name) === 0) {
            $dept = $row;
            break;
        }
    }
}

if (!is_array($dept) || $dept === []) {
    fwrite(STDERR, "Department not found.\n");
    exit(1);
}

$departmentId = (string) ($dept['_id'] ?? '');
$program = trim((string) ($opts['program'] ?? 'MCA'));
$batch = trim((string) ($opts['batch'] ?? ''));

$staffCtx = [
    'departmentId' => $departmentId,
    'department'   => $dept,
    'profile'      => ['departmentId' => $departmentId],
    'user'         => ['role' => 'staff'],
    'staffScope'   => true,
];

$filters = [
    'departmentId' => $departmentId,
    'program'      => $program,
    'batch'        => $batch,
    'studRole'     => 'all',
];

echo "=== Staff placement roster fetch test ===\n";
echo 'Department: ' . trim((string) ($dept['name'] ?? '')) . " ({$departmentId})\n";
echo "Program: {$program}\n";
echo 'Batch: ' . ($batch !== '' ? $batch : '(all batches)') . "\n\n";

$officerCtx = StaffContext::officerCompatible(array_merge($staffCtx, ['department' => $dept]));
$deptAesId = (new PlacementFilterService())->resolveParentDeptAesId($officerCtx);
echo "AES dept id: " . ($deptAesId !== '' ? $deptAesId : '(missing — check department aesId)') . "\n";

$aes = new AesApiService();
if ($deptAesId !== '') {
    try {
        $batches = $aes->fetchPlacementClassBatches($deptAesId, $program);
        echo 'AES class batches for programme: ' . count($batches) . "\n";
        if ($batches !== []) {
            echo '  sample: ' . implode(', ', array_slice($batches, 0, 5)) . "\n";
        }
        $probeBatch = $batch !== '' ? $batch : ($batches[0] ?? '');
        if ($probeBatch !== '') {
            $classRows = $aes->fetchClassStudInfo4Placement($deptAesId, $program, $probeBatch, true);
            echo "AES getStudInfo4Placement for \"{$probeBatch}\": " . count($classRows) . " record(s)\n";
            if ($classRows !== []) {
                $first = $classRows[0];
                $name = trim((string) ($first['stud_name'] ?? $first['name'] ?? ''));
                $adm = trim((string) ($first['admno'] ?? $first['stud_admno'] ?? ''));
                echo "  first student: {$name} ({$adm})\n";
            }
        }
    } catch (Throwable $e) {
        echo 'AES error: ' . $e->getMessage() . "\n";
    }
} else {
    echo "Skipping AES class API (no dept AES id).\n";
}

echo "\n--- Sync from AES (writes student_placements) ---\n";
@ini_set('memory_limit', trim((string) ($_ENV['AES_DIRECTORY_MEMORY_LIMIT'] ?? '512M')));
@set_time_limit(300);

try {
    $sync = (new StaffPlacementRegistryService())->syncFromAes($staffCtx, $filters);
    echo 'aesRosterFetched: ' . (int) ($sync['aesRosterFetched'] ?? 0) . "\n";
    echo 'studentsSynced: ' . (int) ($sync['studentsSynced'] ?? 0) . "\n";
    $err = trim((string) ($sync['syncError'] ?? ''));
    if ($err !== '') {
        echo "syncError: {$err}\n";
    }
} catch (Throwable $e) {
    echo 'Sync failed: ' . $e->getMessage() . "\n";
}

echo "\n--- Registry list (grid data) ---\n";
try {
    $registry = (new StaffPlacementRegistryService())->list($staffCtx, $filters);
    $total = (int) ($registry['totals']['all'] ?? 0);
    echo "rows returned for filters: {$total}\n";
    if ($total > 0) {
        $row = $registry['rows'][0] ?? [];
        echo '  first: ' . trim((string) ($row['studentName'] ?? '')) . ' | '
            . trim((string) ($row['registerNumber'] ?? '')) . ' | '
            . trim((string) ($row['classBatch'] ?? '')) . "\n";
    }
} catch (Throwable $e) {
    echo 'List failed: ' . $e->getMessage() . "\n";
}

try {
    $tableCount = count((new StudentPlacementModel())->listRosterRowsForRegistryScope(
        $departmentId,
        $program,
        $batch,
        5000,
        true
    ));
    echo "student_placements table rows (scope): {$tableCount}\n";
} catch (Throwable $e) {
    echo 'student_placements query skipped: ' . $e->getMessage() . "\n";
}

echo "\nDone.\n";
