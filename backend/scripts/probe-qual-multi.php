<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

function qualUrl(string $admno, string $authKey): string
{
    $ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => http_build_query([
            'admno' => $admno,
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
        ]),
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);

    return $raw;
}

foreach (['16777', '22MCA047', '16779', '15000', '12000', 'AJC25MCA-2012'] as $a) {
    $r = qualUrl($a, $authKey);
    echo $a . ' len=' . strlen($r) . ' ' . substr($r, 0, 300) . PHP_EOL;
}

// admno in query string
$ch = curl_init('https://api.aesajce.in/?' . http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => '16777',
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
$r = (string) curl_exec($ch);
curl_close($ch);
echo 'GET all-in-query len=' . strlen($r) . ' ' . substr($r, 0, 300) . PHP_EOL;
