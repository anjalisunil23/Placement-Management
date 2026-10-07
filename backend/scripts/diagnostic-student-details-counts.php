<?php

declare(strict_types=1);

/**
 * Compare local student_details counts with the latest AES sync snapshot metadata.
 *
 * Usage: php backend/scripts/diagnostic-student-details-counts.php
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

$model = new StudentDetailsModel();
if (!$model->isAvailable()) {
    fwrite(STDERR, "student_details table is not available.\n");
    exit(1);
}

$diag = $model->directoryCountDiagnostics();
$roles = $model->countGroupedByStudRole();

echo "=== student_details directory diagnostics ===\n\n";
echo 'Total rows:        ' . $diag['total'] . "\n";
echo 'Students:          ' . $diag['students'] . "\n";
echo 'Alumni (total):    ' . $diag['alumni'] . "\n";
if (isset($diag['alumniRoleOnly'])) {
    echo '  stud_role=alumni only: ' . $diag['alumniRoleOnly'] . "\n";
}
if (isset($diag['studyingAlumniOverlap'])) {
    echo '  studying+alumni overlap: ' . $diag['studyingAlumniOverlap'] . "\n";
}
if (isset($diag['aesAlumniDirectory'])) {
    echo '  aesAlumniDirectory flag: ' . $diag['aesAlumniDirectory'] . "\n";
}
echo 'Null/empty role:   ' . $diag['nullRole'] . "\n";
echo 'Unique aes_admno:  ' . $diag['uniqueAesAdmno'] . "\n\n";

echo "stud_role breakdown:\n";
foreach ($roles as $row) {
    $label = $row['studRole'] !== '' ? $row['studRole'] : '(empty)';
    echo "  {$label}: {$row['count']}\n";
}

$snapshotPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_aes_campus_directory.json';
if (is_readable($snapshotPath)) {
    $payload = json_decode((string) file_get_contents($snapshotPath), true);
    if (is_array($payload)) {
        echo "\nLatest AES sync snapshot ({$snapshotPath}):\n";
        echo '  syncedAt:           ' . (string) ($payload['syncedAt'] ?? '') . "\n";
        echo '  fetchedStudents:    ' . (int) ($payload['fetchedStudents'] ?? 0) . "\n";
        echo '  fetchedAlumni:      ' . (int) ($payload['fetchedAlumni'] ?? 0) . "\n";
        echo '  canonical overlap:  ' . (int) ($payload['studentAlumniOverlap'] ?? 0) . "\n";
        $report = is_array($payload['lastSyncReport'] ?? null) ? $payload['lastSyncReport'] : null;
        if ($report !== null) {
            echo "\nLast sync report:\n";
            foreach ([
                'aesStudentsReceived',
                'aesAlumniReceived',
                'aesAlumniExclusive',
                'aesCanonicalOverlap',
                'storedStudents',
                'storedAlumni',
                'inserted',
                'updated',
                'skipped',
                'skippedRetainedAsStudent',
                'markedAesAlumniDirectory',
                'skippedEmptyAdmno',
                'failed',
            ] as $key) {
                if (array_key_exists($key, $report)) {
                    echo "  {$key}: {$report[$key]}\n";
                }
            }
            if (is_array($report['diagnosis'] ?? null)) {
                echo '  diagnosis: ' . (string) ($report['diagnosis']['code'] ?? '') . ' — '
                    . (string) ($report['diagnosis']['summary'] ?? '') . "\n";
            }
        }
    }
}

exit(0);
