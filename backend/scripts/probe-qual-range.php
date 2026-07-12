<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

function qual(string $admno, string $authKey): array
{
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => 'getStudQual4Placement',
            'admno' => $admno,
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
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

    return ['code' => $code, 'raw' => $raw];
}

$found = 0;
foreach (range(16770, 16790) as $n) {
    $r = qual((string) $n, $authKey);
    if ($r['code'] !== 500 || trim($r['raw']) !== '') {
        echo "{$n} HTTP {$r['code']} " . substr($r['raw'], 0, 400) . "\n";
        $found++;
        if ($found >= 5) {
            break;
        }
    }
}
if ($found === 0) {
    echo "All 16770-16790 returned HTTP 500 empty\n";
}
