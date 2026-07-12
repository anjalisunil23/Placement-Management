<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$combos = [
    ['method' => 'getDepartments', 'params' => []],
    ['method' => 'getDepartments', 'params' => ['stud_deptcode' => '30']],
    ['method' => 'getDepartments', 'params' => ['deptCode' => '30']],
    ['method' => 'getDepartments', 'params' => ['deptshort' => 'MCA']],
];
foreach ($combos as $c) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => $c['method'], 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], $c['params'])),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    echo '=== ' . $c['method'] . ' ' . json_encode($c['params']) . " ===\n";
    echo substr($raw, 0, 400) . "\n\n";
}
