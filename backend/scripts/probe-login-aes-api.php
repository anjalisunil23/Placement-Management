<?php

declare(strict_types=1);

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$refHost = 'placements.amaljyothi.ac.in';

function postLoginAes(string $method, array $params, string $authKey, string $refHost): void
{
    $fields = array_merge([
        'method' => $method,
        'authkey' => $authKey,
        'refurl' => $refHost,
        'admno' => $params['admno'] ?? '',
    ], $params);

    $ch = curl_init('https://login.aesajce.in/api/public_api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: https://' . $refHost . '/public-stats.html',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "login.aesajce.in {$method} HTTP={$code}\n";
    echo substr($raw, 0, 4000) . "\n---\n";
}

foreach (['getStudQual4Placement', 'getStudInfo4Placement', 'getStudentQualification', 'getStudQual'] as $method) {
    postLoginAes($method, ['admno' => $admno], $authKey, $refHost);
}
