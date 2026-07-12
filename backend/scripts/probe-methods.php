<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$methods = [];
foreach (['Info', 'Qual', 'Edu', 'Education', 'Mark', 'Marks', 'Academic', 'Academics', 'Detail', 'Details'] as $suffix) {
    $methods[] = 'getStud' . $suffix . '4Placement';
}
$methods[] = 'getStudQual4Placement';
$methods[] = 'getStudentQual4Placement';

foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => $method,
            'admno' => $admno,
            'authkey' => $authKey,
        ]),
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
    if ($code !== 200 || str_contains($raw, 'Invalid Method')) {
        if ($code === 500) {
            echo "{$method} => HTTP 500\n";
        }
        continue;
    }
    echo "{$method} => " . substr($raw, 0, 300) . "\n";
}
