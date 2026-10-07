<?php

declare(strict_types=1);

/**
 * Regression: shared AES → student_details idempotent upsert.
 *
 * Usage: php backend/scripts/test-student-details-sync.php
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
use PMS\Services\StudentDetailsSyncService;

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    if ($ok) {
        echo "PASS: {$label}\n";
        return;
    }
    echo "FAIL: {$label}\n";
    $fail++;
};

echo "=== student_details sync regression ===\n\n";

$model = new StudentDetailsModel();
$assert($model->isAvailable(), 'student_details table available');

$sample = [
    'admno' => 'TESTSD001',
    'stud_admno' => 'TESTSD001',
    'registerno' => 'TESTSD001',
    'stud_name' => 'Sync Test Student',
    'stud_class' => 'INMCA-S1',
    'stud_course' => 'INMCA',
    'stud_branch' => 'MCA',
    'stud_deptcode' => '1001',
    'stud_ajce_mails' => 'sync.test@amaljyothi.ac.in',
    'stud_role' => 'Student',
];

$sync = new StudentDetailsSyncService();
$r1 = $sync->syncFromAesRecords([$sample], ['syncSource' => 'test', 'studRole' => 'student']);
$assert(($r1['inserted'] ?? 0) >= 1 || ($r1['updated'] ?? 0) >= 1, 'first sync inserts or updates');

$r2 = $sync->syncFromAesRecords([$sample], ['syncSource' => 'test', 'studRole' => 'student']);
$assert(($r2['unchanged'] ?? 0) >= 1, 'second sync unchanged (idempotent)');

$found = $model->findByAesAdmno('TESTSD001');
$assert(is_array($found), 'row retrievable by aes_admno');
$assert(
    (string) ($found['studentName'] ?? '') === 'Sync Test Student',
    'student name stored in student_details'
);

$sample['stud_name'] = 'Sync Test Student Updated';
$r3 = $sync->syncFromAesRecords([$sample], ['syncSource' => 'test', 'studRole' => 'student']);
$assert(($r3['updated'] ?? 0) >= 1, 'name change triggers update');

$found2 = $model->findByAesAdmno('TESTSD001');
$assert(
    (string) ($found2['studentName'] ?? '') === 'Sync Test Student Updated',
    'updated name persisted'
);

$dir = $model->listDirectoryRecords('', true, 'student', 50);
$assert(count($dir) >= 1, 'listDirectoryRecords returns rows');

echo $fail === 0 ? "\nAll tests passed.\n" : "\n{$fail} test(s) failed.\n";
exit($fail === 0 ? 0 : 1);
