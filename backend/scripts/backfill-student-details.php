<?php

declare(strict_types=1);

/**
 * Backfill student_details from student_placements and legacy campus JSON snapshot.
 *
 * Usage: php backend/scripts/backfill-student-details.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Models\StudentDetailsModel;
use PMS\Models\StudentPlacementModel;
use PMS\Services\StudentDetailsSyncService;

echo "=== Backfill student_details ===\n\n";

$detailsModel = new StudentDetailsModel();
if (!$detailsModel->isAvailable()) {
    fwrite(STDERR, "student_details table is not available.\n");
    exit(1);
}

$sync = new StudentDetailsSyncService();
$placementModel = new StudentPlacementModel();
$records = [];
$seen = [];

$append = static function (array $record) use (&$records, &$seen): void {
    $key = StudentDetailsModel::resolveAesAdmno($record);
    if ($key === '' || isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $records[] = $record;
};

// 1) student_placements rows (master fields only via payloadFromLegacyPlacementDoc)
if ($placementModel->hasLegacyFlatPlacementColumns()) {
    $rows = $placementModel->listRosterRowsForRegistryScope('', '', '', 10000, false);
} else {
    $rows = $placementModel->findAll([], 10000);
}
foreach ($rows as $doc) {
    $payload = StudentDetailsModel::payloadFromLegacyPlacementDoc($doc);
    if ($payload !== null) {
        $append($payload);
    }
}

// 2) Legacy campus JSON snapshot
foreach ([
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_aes_campus_directory.json',
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_aes_campus_studying_directory.json',
] as $path) {
    if (!is_file($path)) {
        continue;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        continue;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        continue;
    }
    foreach (['studyingRecords', 'records', 'alumniRecords'] as $key) {
        if (!is_array($decoded[$key] ?? null)) {
            continue;
        }
        foreach ($decoded[$key] as $row) {
            if (is_array($row)) {
                $append($row);
            }
        }
    }
}

echo 'Records to upsert: ' . count($records) . "\n";
$stats = $sync->syncFromAesRecords($records, ['syncSource' => 'backfill']);
foreach ($stats as $k => $v) {
    if (is_scalar($v)) {
        echo "  {$k}: {$v}\n";
    }
}

echo "\nstudent_details row count: " . $detailsModel->countByRole('all') . "\n";
echo "Done.\n";
