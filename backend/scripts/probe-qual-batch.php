<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admnos = array_slice($argv, 1);
if ($admnos === []) {
    $admnos = ['16777', '22MCA047', '10001', '15001'];
}

function aesPost(string $method, array $params, string $authKey): array
{
    $post = array_merge(['method' => $method, 'authkey' => $authKey], $params);
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
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

    return ['code' => $code, 'raw' => $raw, 'json' => json_decode($raw, true)];
}

foreach ($admnos as $admno) {
    echo "===== admno {$admno} =====\n";
    $qual = aesPost('getStudQual4Placement', ['admno' => $admno], $authKey);
    echo "qual HTTP {$qual['code']} body=" . substr($qual['raw'], 0, 1200) . "\n";
    $info = aesPost('getStudInfo4Placement', ['admno' => $admno], $authKey);
    $data = is_array($info['json']['data'] ?? null) ? $info['json']['data'] : [];
    echo "info HTTP {$info['code']} name=" . ($data['stud_name'] ?? '—') . ' edu=' . (isset($data['edu']) ? 'yes' : 'no') . "\n";
    foreach (['sslc', 'hsc', 'marks10th', 'marks12th', 'edu', 'qualifications'] as $k) {
        if (array_key_exists($k, $data)) {
            echo "  info.{$k}=" . json_encode($data[$k]) . "\n";
        }
    }
    echo "\n";
}
