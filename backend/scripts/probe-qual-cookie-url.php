<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$cookie = tempnam(sys_get_temp_dir(), 'aesq_');

function post(string $url, array $body, string $cookie): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
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

// Warm session via info
$post('https://api.aesajce.in/', [
    'method' => 'getStudInfo4Placement',
    'admno' => '16777',
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
], $cookie);

$variants = [
    'url+admno' => ['https://api.aesajce.in/?method=getStudQual4Placement', ['admno' => '16777', 'authkey' => $authKey]],
    'url+admno+ref' => ['https://api.aesajce.in/?method=getStudQual4Placement', ['admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'url+stud_admno' => ['https://api.aesajce.in/?method=getStudQual4Placement', ['stud_admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'body method' => ['https://api.aesajce.in/', ['method' => 'getStudQual4Placement', 'admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
];

foreach ($variants as $label => [$url, $body]) {
    $r = post($url, $body, $cookie);
    echo "{$label}: HTTP {$r['code']} len=" . strlen($r['raw']) . "\n";
    if (trim($r['raw']) !== '') {
        echo substr($r['raw'], 0, 1000) . "\n";
    }
    echo "---\n";
}

@unlink($cookie);
