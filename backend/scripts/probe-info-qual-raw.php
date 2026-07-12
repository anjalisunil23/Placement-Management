<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = $argv[1] ?? '16777';

function aesPost(string $url, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'raw' => $raw, 'json' => json_decode($raw, true)];
}

echo "=== getStudInfo4Placement body method ===\n";
$info = aesPost('https://api.aesajce.in/', [
    'method' => 'getStudInfo4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
echo "HTTP {$info['code']}\n";
echo substr($info['raw'], 0, 5000) . "\n\n";

echo "=== getStudQual4Placement URL method + refurl ===\n";
$qual = aesPost('https://api.aesajce.in/?method=getStudQual4Placement', [
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
echo "HTTP {$qual['code']} len=" . strlen($qual['raw']) . "\n";
echo substr($qual['raw'], 0, 5000) . "\n\n";

echo "=== getStudQual4Placement body method ===\n";
$qual2 = aesPost('https://api.aesajce.in/', [
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
echo "HTTP {$qual2['code']} len=" . strlen($qual2['raw']) . "\n";
echo substr($qual2['raw'], 0, 5000) . "\n\n";

// Scan info response for mark-like keys
if (is_array($info['json'])) {
    $flat = json_encode($info['json']);
    foreach (['sslc', 'hsc', 'marks', 'edu', 'qual', '10', '12', 'percent'] as $needle) {
        if (stripos($flat, $needle) !== false) {
            echo "info contains: {$needle}\n";
        }
    }
}
