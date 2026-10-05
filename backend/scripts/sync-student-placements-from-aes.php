<?php

declare(strict_types=1);

/**
 * Bulk AES → student_placements import (same logic as staff "Sync from AES").
 *
 * Usage:
 *   php backend/scripts/sync-student-placements-from-aes.php --department-id=<mongoId>
 *   php backend/scripts/sync-student-placements-from-aes.php --department-code=MCA
 *   php backend/scripts/sync-student-placements-from-aes.php --department-name="Computer Applications"
 *   php backend/scripts/sync-student-placements-from-aes.php --program=INMCA --batch=INMCA-S1
 *
 * Options:
 *   --program=CODE     Branch / programme (optional)
 *   --batch=LABEL      Class batch e.g. INMCA-S1 (optional)
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
use PMS\Services\StaffPlacementRegistryService;

$opts = getopt('', [
    'department-id:',
    'department-code:',
    'department-name:',
    'program:',
    'batch:',
]);

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
    fwrite(STDERR, "Department not found. Use --department-id, --department-code, or --department-name.\n");
    exit(1);
}

$departmentId = (string) ($dept['_id'] ?? '');
$filters = [
    'departmentId' => $departmentId,
    'program'      => trim((string) ($opts['program'] ?? '')),
    'batch'        => trim((string) ($opts['batch'] ?? '')),
    'studRole'     => 'all',
];

$staffCtx = [
    'departmentId' => $departmentId,
    'department'   => $dept,
    'profile'      => ['departmentId' => $departmentId],
    'user'         => ['role' => 'staff'],
];

@ini_set('memory_limit', trim((string) ($_ENV['AES_DIRECTORY_MEMORY_LIMIT'] ?? '512M')));
@set_time_limit(max(300, (int) ($_ENV['AES_REGISTRY_SYNC_TIME_LIMIT'] ?? 600)));

echo 'Department: ' . trim((string) ($dept['name'] ?? $dept['code'] ?? $departmentId)) . PHP_EOL;
echo 'Program: ' . ($filters['program'] !== '' ? $filters['program'] : '(all branches)') . PHP_EOL;
echo 'Batch: ' . ($filters['batch'] !== '' ? $filters['batch'] : '(all batches)') . PHP_EOL;
echo 'Calling AES and writing student_placements…' . PHP_EOL;

$svc = new StaffPlacementRegistryService();
$result = $svc->syncFromAes($staffCtx, $filters);

$aesFetched = (int) ($result['aesRosterFetched'] ?? 0);
$saved = (int) ($result['studentsSynced'] ?? 0);
$studying = (int) ($result['studyingSynced'] ?? 0);
$alumni = (int) ($result['alumniSynced'] ?? 0);

echo "AES roster fetched: {$aesFetched}" . PHP_EOL;
echo "Rows upserted: {$saved} (studying {$studying}, alumni {$alumni})" . PHP_EOL;
echo 'AES profile backfill updated: ' . (int) ($result['backfillUpdated'] ?? 0)
    . ' (' . (int) ($result['profilesFetched'] ?? 0) . " profile lookups)\n";

$model = new StudentPlacementModel();
$tableRows = $model->listRosterRowsForRegistryScope(
    $departmentId,
    $filters['program'],
    $filters['batch'],
    StudentPlacementModel::REGISTRY_TABLE_LIST_MAX,
    true
);
echo 'student_placements rows for scope: ' . count($tableRows) . PHP_EOL;

exit($aesFetched > 0 || count($tableRows) > 0 ? 0 : 2);
