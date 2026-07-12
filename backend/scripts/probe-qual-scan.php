<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$found = 0;

for ($n = 10000; $n <= 25000; $n += 50) {
    $ch = curl_init('https://api.aesajce.in/?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['admno' => (string) $n, 'authkey' => $authKey]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = trim((string) curl_exec($ch));
    curl_close($ch);
    if ($raw !== '') {
        echo "{$n}: " . substr($raw, 0, 400) . "\n";
        $found++;
        if ($found >= 5) {
            break;
        }
    }
}
echo $found ? "done\n" : "no non-empty qual responses in sample\n";
