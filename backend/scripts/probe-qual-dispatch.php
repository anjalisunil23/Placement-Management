<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$headers = ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/', 'Content-Type: application/x-www-form-urlencoded'];

foreach (['method', 'action', 'api', 'cmd', 'fn', 'call'] as $key) {
    $post = [$key => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'];
    $ch = curl_init('https://api.aesajce.in/');
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
    if ($code !== 200 || !str_contains($raw, 'Invalid Method')) {
        echo "{$key}: HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 100) . "\n";
    }
}
