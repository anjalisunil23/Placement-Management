<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$refHost = 'placements.amaljyothi.ac.in';
$methods = [];
foreach (['Placement', 'Placements', 'Placed', 'HigherEducation', 'HigherEdu', 'Employer', 'NBA'] as $s) {
    $methods[] = 'get' . $s . '4Placement';
    $methods[] = 'getStud' . $s . '4Placement';
    $methods[] = 'get' . $s . 'List4Placement';
}
$methods[] = 'getStudInfo4Placement';
$base = [
    'authkey' => $authKey,
    'refurl' => $refHost,
    'stud_deptcode' => '30',
    'stud_course' => 'MCA',
    'stud_branch' => 'Regular',
    'stud_class' => 'MCA2024-2026',
];
foreach ($methods as $method) {
    $ch = curl_init('https://login.aesajce.in/api/public_api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => $method], $base)),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Referer: https://' . $refHost . '/'],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (strlen($raw) < 20 || str_contains($raw, 'Invalid Method')) {
        continue;
    }
    echo "=== login.aesajce.in {$method} ===\n" . substr($raw, 0, 800) . "\n\n";
}
