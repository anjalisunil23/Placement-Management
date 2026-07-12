<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$base = [
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
    'stud_deptcode' => '30',
];
$methods = [];
foreach (['Course', 'Courses', 'Branch', 'Branches', 'Class', 'Classes', 'Batch', 'Batches', 'DeptCourse', 'DeptBranch', 'DeptClass'] as $s) {
    $methods[] = 'get' . $s . '4Placement';
    $methods[] = 'getStud' . $s . '4Placement';
    $methods[] = 'list' . $s . '4Placement';
}
$methods[] = 'getDepartments';
foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => $method], $base)),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (strlen(trim($raw)) < 15 || str_contains($raw, 'Invalid Method') || str_contains($raw, '"data":false')) {
        continue;
    }
    echo "=== {$method} ===\n" . substr($raw, 0, 800) . "\n\n";
}
