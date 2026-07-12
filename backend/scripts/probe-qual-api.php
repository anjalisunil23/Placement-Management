<?php

declare(strict_types=1);

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

$cases = [
    'body method' => [
        'url' => 'https://api.aesajce.in/',
        'body' => ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
    'url method' => [
        'url' => 'https://api.aesajce.in/?method=getStudQual4Placement',
        'body' => ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
];

foreach ($cases as $label => $case) {
    $ch = curl_init($case['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($case['body']),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "=== {$label} HTTP {$code} len=" . strlen((string) $raw) . " ===\n";
    echo (string) $raw . "\n\n";
}
