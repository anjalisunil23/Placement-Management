<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$body = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);

$headerSets = [
    'probe-info style' => [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
    ],
    'AesApiService style' => [
        'Accept: application/json, */*;q=0.1',
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
        'X-Requested-With: XMLHttpRequest',
    ],
    'minimal' => [],
];

foreach ($headerSets as $label => $headers) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$label}: HTTP {$code} len=" . strlen($raw);
    if ($raw !== '') echo ' ' . substr($raw, 0, 150);
    echo "\n";
}

// URL method: params in query string, POST with probe-info headers
$q = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
$ch = curl_init('https://api.aesajce.in/?' . $q);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
    ],
]);
$raw = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "URL method POST all-in-query: HTTP {$code} len=" . strlen($raw) . " {$raw}\n";

// Wrong authkey comparison
foreach (['getStudInfo4Placement', 'getStudQual4Placement'] as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['method' => $method, 'admno' => $admno, 'authkey' => 'bad']),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "bad auth {$method}: HTTP {$code} " . substr($raw, 0, 100) . "\n";
}
