<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$refs = [
    'placements.amaljyothi.ac.in',
    'www.aesajce.in',
    'aesajce.in',
    'login.aesajce.in',
    '',
];

foreach ($refs as $ref) {
    $post = ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey];
    if ($ref !== '') {
        $post['refurl'] = $ref;
    }
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
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
    echo "body refurl={$ref}: HTTP {$code} len=" . strlen($raw);
    if ($raw !== '') echo ' ' . substr($raw, 0, 200);
    echo "\n";
}

// URL method with refurl variants
foreach ($refs as $ref) {
    $post = ['admno' => $admno, 'authkey' => $authKey];
    if ($ref !== '') {
        $post['refurl'] = $ref;
    }
    $ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "url refurl={$ref}: HTTP {$code} len=" . strlen($raw);
    if ($raw !== '') echo ' ' . substr($raw, 0, 200);
    echo "\n";
}
