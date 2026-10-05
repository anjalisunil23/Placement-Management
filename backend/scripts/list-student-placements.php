<?php

declare(strict_types=1);

/**
 * Print roster rows from student_placements (same shape as staff grid source).
 *
 * Usage:
 *   php backend/scripts/list-student-placements.php
 *   php backend/scripts/list-student-placements.php --department-id=... --program=MCA --batch="MCA-2023-25" --limit=50
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';

use PMS\Models\StudentPlacementModel;

$opts = getopt('', ['department-id:', 'program:', 'batch:', 'limit::']);
$departmentId = trim((string) ($opts['department-id'] ?? ''));
$program = trim((string) ($opts['program'] ?? ''));
$batch = trim((string) ($opts['batch'] ?? ''));
$limit = max(1, min(5000, (int) ($opts['limit'] ?? 100)));

$model = new StudentPlacementModel();
if ($departmentId !== '') {
    $rows = $model->listRosterRowsForRegistryScope($departmentId, $program, $batch, $limit, true);
} else {
    $rows = $model->listAllRosterRows($limit);
}

echo 'student_placements rows: ' . count($rows) . PHP_EOL;
foreach ($rows as $row) {
    $placement = is_array($row['placement'] ?? null) ? $row['placement'] : [];
    echo json_encode([
        'studentId'      => $row['studentId'] ?? '',
        'studentName'    => $row['studentName'] ?? '',
        'registerNumber' => $row['registerNumber'] ?? '',
        'classBatch'     => $row['classBatch'] ?? '',
        'programme'      => $row['programme'] ?? '',
        'phone'          => $row['phone'] ?? '',
        'email'          => $row['email'] ?? '',
        'company'        => $placement['company'] ?? '',
        'role'           => $placement['role'] ?? '',
        'source'         => $row['source'] ?? '',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

exit($rows === [] ? 2 : 0);
