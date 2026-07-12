<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

function headersOf(string $url, array $post, bool $queryMethod): array
{
    if ($queryMethod) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'method=getStudQual4Placement';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $resp = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($resp, 0, $hsize);
    $body = substr($resp, $hsize);

    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

foreach ([
    'info body' => ['https://api.aesajce.in/', ['method' => 'getStudInfo4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], false],
    'qual body' => ['https://api.aesajce.in/', ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], false],
    'qual url' => ['https://api.aesajce.in/', ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'], true],
    'qual url q all' => ['https://api.aesajce.in/?' . http_build_query(['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']), [], true],
] as $label => [$url, $post, $qm]) {
    $r = headersOf($url, $post, $qm && !str_contains($url, 'method='));
    echo "=== {$label} HTTP {$r['code']} bodyLen=" . strlen($r['body']) . " ===\n";
    foreach (explode("\n", $r['headers']) as $line) {
        if (preg_match('/^(HTTP|Content-|Transfer-|Server|X-)/i', trim($line))) {
            echo trim($line) . "\n";
        }
    }
    if (trim($r['body']) !== '') {
        echo "BODY: " . substr($r['body'], 0, 200) . "\n";
    }
    echo "\n";
}
