<?php

declare(strict_types=1);

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

$bodies = [
    'admno only' => ['method' => 'getStudInfo4Placement', 'admno' => $admno, 'authkey' => $authKey],
    'with refurl' => ['method' => 'getStudInfo4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    'qual url method' => null,
];

foreach (['admno only', 'with refurl'] as $label) {
    $postData = http_build_query($bodies[$label]);
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    echo "=== {$label} ===\n";
    echo 'keys: ' . implode(', ', array_keys($data['data'] ?? [])) . "\n";
    if (isset($data['data']['edu'])) {
        echo 'edu: ' . json_encode($data['data']['edu']) . "\n";
    }
    echo "\n";
}

$ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
]);
$qualRaw = (string) curl_exec($ch);
curl_close($ch);
echo "=== getStudQual4Placement URL ===\n";
echo 'len=' . strlen($qualRaw) . ' body=' . substr($qualRaw, 0, 500) . "\n";
