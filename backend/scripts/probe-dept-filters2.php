<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$refHost = 'placements.amaljyothi.ac.in';
$methods = [];
foreach (['Department', 'Dept', 'Program', 'Course', 'Branch', 'Class', 'Batch', 'Section'] as $s) {
    foreach (['get', 'list', 'getStud'] as $p) {
        $methods[] = $p . $s . '4Placement';
    }
}
$base = ['authkey' => $authKey, 'refurl' => $refHost, 'stud_deptcode' => '30', 'deptCode' => '30', 'deptshort' => 'MCA'];
foreach (array_unique($methods) as $method) {
    foreach (['https://api.aesajce.in/', 'https://login.aesajce.in/api/public_api.php'] as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => $method], $base)),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Referer: https://' . $refHost . '/'],
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        if (strlen(trim($raw)) < 20 || str_contains($raw, 'Invalid Method')) {
            continue;
        }
        if (str_contains($raw, '"data":false') && !str_contains($raw, 'Regular')) {
            continue;
        }
        echo "=== {$url} {$method} ===\n" . substr($raw, 0, 600) . "\n\n";
    }
}
