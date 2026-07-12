<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$extras = [
    [],
    ['edu' => '1'],
    ['qual' => '1'],
    ['details' => '1'],
    ['full' => '1'],
    ['include' => 'edu'],
    ['include' => 'qualification'],
    ['type' => 'qual'],
    ['mode' => 'qual'],
];

foreach ($extras as $extra) {
    $post = array_merge(['method' => 'getStudInfo4Placement', 'authkey' => $authKey, 'admno' => $admno], $extra);
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
    curl_close($ch);
    $j = json_decode($raw, true);
    $data = is_array($j['data'] ?? null) ? $j['data'] : [];
    $keys = implode(',', array_keys($data));
    $hasEdu = isset($data['edu']) ? 'edu=yes' : '';
    $hasSslc = isset($data['sslc']) || isset($data['stud_sslc']) ? 'sslc=yes' : '';
    echo json_encode($extra) . " keys={$keys} {$hasEdu} {$hasSslc}\n";
}
