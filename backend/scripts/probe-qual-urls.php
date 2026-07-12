<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$urls = [
    'https://api.aesajce.in/',
    'https://api.aesajce.in/index.php',
    'https://api.aesajce.in/public_api.php',
    'https://api.aesajce.in/api/public_api.php',
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => 'getStudQual4Placement',
            'admno' => '16777',
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$url} => HTTP {$code} " . substr($raw, 0, 200) . "\n";
}
