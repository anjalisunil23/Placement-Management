<?php

declare(strict_types=1);

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

$attempts = [
    'info-style body' => [
        'url' => 'https://api.aesajce.in/',
        'body' => ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
    'info-style no refurl' => [
        'url' => 'https://api.aesajce.in/',
        'body' => ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey],
    ],
    'url method' => [
        'url' => 'https://api.aesajce.in/?method=getStudQual4Placement',
        'body' => ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
    'url method stud_admno' => [
        'url' => 'https://api.aesajce.in/?method=getStudQual4Placement',
        'body' => ['stud_admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
];

foreach ($attempts as $label => $cfg) {
    $ch = curl_init($cfg['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($cfg['body']),
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
    echo "=== {$label} HTTP {$code} len=" . strlen($raw) . " ===\n";
    echo substr($raw, 0, 2000) . "\n\n";
}
