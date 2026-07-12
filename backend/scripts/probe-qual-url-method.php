<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

function qualUrl(string $admno, string $authKey): array
{
    $ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'admno' => $admno,
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

    return ['code' => $code, 'raw' => $raw];
}

foreach (range(16770, 16790) as $n) {
    $r = qualUrl((string) $n, $authKey);
    if (trim($r['raw']) !== '') {
        echo "{$n}: " . substr($r['raw'], 0, 500) . "\n";
    }
}
echo "done scan\n";

// Also test body method crash vs url method for 16777
$ch = curl_init('https://api.aesajce.in/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'method' => 'getStudQual4Placement',
        'admno' => '16777',
        'authkey' => $authKey,
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
    ],
]);
$raw = (string) curl_exec($ch);
echo 'body method HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' len=' . strlen($raw) . "\n";
