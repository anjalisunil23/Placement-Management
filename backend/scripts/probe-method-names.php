<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$ref = 'placements.amaljyothi.ac.in';
$headers = ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'];

$methods = [];
foreach (['Course', 'Courses', 'Program', 'Programs', 'Branch', 'Branches', 'Class', 'Classes', 'Batch', 'Batches'] as $n) {
    $methods[] = 'get' . $n . '4Placement';
    $methods[] = 'get' . $n . 'List4Placement';
}

$base = ['stud_deptcode' => '30', 'stud_course' => 'MCA', 'stud_branch' => 'Regular'];
foreach ($methods as $method) {
    $payload = array_merge(['method' => $method, 'authkey' => $authKey, 'refurl' => $ref], $base);
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (str_contains($raw, 'Invalid Method') || str_contains($raw, 'Unauthorized')) {
        continue;
    }
    if (strlen(trim($raw)) < 15) {
        continue;
    }
    echo "=== {$method} ===\n" . substr($raw, 0, 1200) . "\n\n";
}

// placements host proxy guesses
$urls = [
    'https://placements.amaljyothi.ac.in/backend/api/aes.php',
    'https://placements.amaljyothi.ac.in/api/aes',
    'https://placements.amaljyothi.ac.in/aes/api',
];
foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => 'getCourses4Placement', 'authkey' => $authKey, 'refurl' => $ref], ['stud_deptcode' => '30'])),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 400 && strlen($raw) > 20) {
        echo "=== URL {$url} HTTP {$code} ===\n" . substr($raw, 0, 800) . "\n\n";
    }
}
