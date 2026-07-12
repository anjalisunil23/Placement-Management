<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$cookie = tempnam(sys_get_temp_dir(), 'aes_');

function aesCall(string $method, array $params, string $authKey, string $cookie): array
{
    $post = array_merge(['method' => $method, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], $params);
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
            'X-Requested-With: XMLHttpRequest',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'raw' => $raw];
}

echo "1) getStudInfo4Placement\n";
$r1 = aesCall('getStudInfo4Placement', ['admno' => $admno], $authKey, $cookie);
echo "HTTP {$r1['code']} " . substr($r1['raw'], 0, 120) . "\n\n";

echo "2) getStudQual4Placement (with session cookie)\n";
$r2 = aesCall('getStudQual4Placement', ['admno' => $admno], $authKey, $cookie);
echo "HTTP {$r2['code']} " . substr($r2['raw'], 0, 800) . "\n";

@unlink($cookie);
