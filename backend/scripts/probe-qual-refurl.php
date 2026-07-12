<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

foreach (['placements.amaljyothi.ac.in', 'www.aesajce.in', 'aesajce.in'] as $ref) {
    $ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => http_build_query(['admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref]),
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    echo "refurl={$ref} len=" . strlen($raw) . ' ' . substr($raw, 0, 200) . PHP_EOL;
}
