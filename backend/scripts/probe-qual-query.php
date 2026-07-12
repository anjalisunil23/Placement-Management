<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = $argv[1] ?? '16777';
$ref = 'placements.amaljyothi.ac.in';

function req(string $label, string $url, ?string $body = null, string $method = 'POST'): void
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$label}: HTTP {$code} len=" . strlen($raw);
    if ($raw !== '') echo ' ' . substr($raw, 0, 250);
    echo "\n";
}

$q = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => $ref,
]);

req('GET all query', 'https://api.aesajce.in/?' . $q, null, 'GET');
req('POST empty body all query', 'https://api.aesajce.in/?' . $q, '', 'POST');
req('POST no body', 'https://api.aesajce.in/?' . $q, null, 'POST');

$q2 = http_build_query([
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => $ref,
], '', '&', PHP_QUERY_RFC3986);
req('GET RFC3986', 'https://api.aesajce.in/?' . $q2, null, 'GET');

// Info comparison with URL method
$iq = http_build_query(['method' => 'getStudInfo4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref]);
req('info GET query', 'https://api.aesajce.in/?' . $iq, null, 'GET');
req('info POST query empty', 'https://api.aesajce.in/?' . $iq, '', 'POST');

// index.php paths
req('index.php GET qual', 'https://api.aesajce.in/index.php?' . $q, null, 'GET');
req('public_api GET qual', 'https://api.aesajce.in/public_api.php?' . $q, null, 'GET');
