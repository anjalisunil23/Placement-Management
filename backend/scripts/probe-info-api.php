<?php

declare(strict_types=1);

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

$postData = http_build_query(['method' => 'getStudInfo4Placement', 'admno' => $admno, 'authkey' => $authKey]);
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
$raw = curl_exec($ch);
curl_close($ch);
$data = json_decode((string) $raw, true);
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
echo "\nKeys in data: " . implode(', ', array_keys($data['data'] ?? [])) . "\n";
