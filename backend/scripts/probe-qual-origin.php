<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$origins = [
    'https://www.aesajce.in',
    'https://placements.amaljyothi.ac.in',
    'https://login.aesajce.in',
    'https://b.aesajce.in',
];

foreach ($origins as $origin) {
    $ref = parse_url($origin, PHP_URL_HOST) ?: 'placements.amaljyothi.ac.in';
    foreach (['body', 'url'] as $mode) {
        if ($mode === 'body') {
            $url = 'https://api.aesajce.in/';
            $post = ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref];
        } else {
            $url = 'https://api.aesajce.in/?method=getStudQual4Placement';
            $post = ['admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: ' . $origin,
                'Referer: ' . $origin . '/',
            ],
        ]);
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || trim($raw) !== '') {
            echo "{$mode} origin={$origin} ref={$ref}: HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 120) . "\n";
        }
    }
}

echo "done (only non-empty or non-200 shown)\n";
