<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$headers = ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'];
$methods = ['getCourse4Placement', 'getCourses4Placement', 'getBranch4Placement', 'getBranches4Placement', 'getClass4Placement', 'getClasses4Placement', 'getBatch4Placement', 'getBatches4Placement'];
$paramSets = [
    ['stud_deptcode' => '30'],
    ['stud_deptcode' => '30', 'stud_course' => 'MCA'],
    ['stud_deptcode' => '30', 'stud_course' => 'MCA', 'stud_branch' => 'Regular'],
];
foreach ($methods as $method) {
    foreach ($paramSets as $params) {
        $payload = array_merge(['method' => $method, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], $params);
        $ch = curl_init('https://api.aesajce.in/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
        if (strlen(trim($raw)) < 10 || str_contains($raw, 'Invalid Method') || str_contains($raw, 'Unauthorized')) {
            continue;
        }
        echo "=== {$method} " . json_encode($params) . " ===\n" . substr($raw, 0, 1000) . "\n\n";
    }
}
