<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';

function tryCall(string $label, string $url, array $body, string $method = 'POST'): void
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
            'X-Requested-With: XMLHttpRequest',
        ],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (trim($raw) !== '' || $code >= 400) {
        echo "{$label}\nHTTP {$code} len=" . strlen($raw) . "\n" . substr($raw, 0, 800) . "\n---\n";
    }
}

$bodies = [
    ['admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ['admno' => '16777', 'authkey' => $authKey, 'refurl' => 'www.aesajce.in'],
    ['stud_admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ['registerno' => 'AJC25MCA-2012', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ['admno' => '16777', 'registerno' => 'AJC25MCA-2012', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ['method' => 'getStudQual4Placement', 'admno' => '16777', 'authkey' => $authKey],
];

foreach ($bodies as $i => $body) {
    tryCall("URL-method body#" . ($i + 1), 'https://api.aesajce.in/?method=getStudQual4Placement', $body);
}

tryCall(
    'GET query all',
    'https://api.aesajce.in/?' . http_build_query([
        'method' => 'getStudQual4Placement',
        'admno' => '16777',
        'authkey' => $authKey,
        'refurl' => 'placements.amaljyothi.ac.in',
    ]),
    [],
    'GET'
);

tryCall(
    'www.aesajce.in api',
    'https://www.aesajce.in/api/?method=getStudQual4Placement',
    ['admno' => '16777', 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']
);
