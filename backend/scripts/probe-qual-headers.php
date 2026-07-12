<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$post = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => '16777',
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);

$ch = curl_init('https://api.aesajce.in/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
        'X-Requested-With: XMLHttpRequest',
    ],
]);
$response = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

echo "HTTP {$code}\n";
echo "HEADERS:\n" . substr($response, 0, $headerSize) . "\n";
echo "BODY (" . strlen(substr($response, $headerSize)) . " bytes):\n";
echo substr($response, $headerSize) . "\n";
