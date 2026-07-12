<?php

declare(strict_types=1);

$admno = '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$headers = ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/', 'Content-Type: application/x-www-form-urlencoded'];

foreach ([
    'no authkey' => ['method' => 'getStudQual4Placement', 'admno' => $admno],
    'no refurl' => ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey],
    'admno only url' => null,
] as $label => $post) {
    if ($post === null) {
        $url = 'https://api.aesajce.in/?method=getStudQual4Placement&admno=' . $admno;
        $post = [];
    } else {
        $url = 'https://api.aesajce.in/';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$label}: HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 100) . "\n";
}

// JSON body
$json = json_encode(['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']);
$ch = curl_init('https://api.aesajce.in/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
        'Content-Type: application/json',
    ],
]);
$raw = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "JSON body: HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 100) . "\n";
