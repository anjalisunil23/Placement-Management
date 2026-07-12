<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$post = http_build_query(['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']);
$url = 'https://api.aesajce.in/?method=getStudQual4Placement';

$combos = [
    ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ['Origin: https://placements.amaljyothi.ac.in', 'Referer: https://placements.amaljyothi.ac.in/'],
    ['Origin: https://www.aesajce.in', 'Referer: https://placements.amaljyothi.ac.in/'],
    ['Origin: https://placements.amaljyothi.ac.in', 'Referer: https://www.aesajce.in/'],
    ['Referer: https://placements.amaljyothi.ac.in/settings.html'],
];

foreach ($combos as $i => $hdrs) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $hdrs),
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo '#' . ($i + 1) . ' ' . implode(' | ', $hdrs) . "\n";
    echo "  HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 120) . "\n\n";
}

// body method with placements Referer + www Origin
$body = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
foreach ($combos as $i => $hdrs) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $hdrs),
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo 'body #' . ($i + 1) . ' HTTP ' . $code . ' len=' . strlen($raw) . ' ' . substr($raw, 0, 120) . "\n";
}
