<?php

declare(strict_types=1);

/**
 * Smoke test: AES institute API (api.aesajce.in).
 * Usage: php backend/scripts/smoke-aes-api.php [admission_no]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$register = $argv[1] ?? '';

$api = new AesApiService();

echo "=== getDepartments ===\n";
$deptResult = $api->getDepartments();
$depts = $api->listDepartments();
echo 'http: ' . ($deptResult['status'] ?? 0) . ', parsed count: ' . count($depts) . "\n";
foreach (array_slice($depts, 0, 5) as $row) {
    echo ($row['code'] ?? '') . ' — ' . ($row['name'] ?? '') . "\n";
}

$synced = $api->syncDepartmentsToLocal();
echo "synced new departments: {$synced}\n";

if ($register !== '') {
    echo "\n=== POST getStudInfo4Placement ({$register}) ===\n";
    $request = $api->buildStudentRequestParams(['admno' => $register, 'un' => $register], $register);
    $info = $api->postStudInfo4Placement(['admno' => $register], $register);
    echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    echo "\n=== POST getDepartments ===\n";
    $deptPost = $api->getDepartments();
    echo 'http: ' . ($deptPost['status'] ?? 0) . ', rows: ' . count($api->loadDepartmentsFromApi()) . "\n";

    $resolved = $api->resolveStudentDepartment($request, $register);
    echo "\nResolved department (getStudInfo4Placement + getDepartments):\n";
    echo json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "\nTip: pass admission number to test getStudInfo4Placement\n";
}
