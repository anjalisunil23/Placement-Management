<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';
$headers = ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'];

$cases = [
    'auth in q, admno body' => [
        'https://api.aesajce.in/?method=getStudQual4Placement&authkey=' . rawurlencode($authKey) . '&refurl=placements.amaljyothi.ac.in',
        ['admno' => $admno],
    ],
    'admno in q, auth body' => [
        'https://api.aesajce.in/?method=getStudQual4Placement&admno=' . $admno,
        ['authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in'],
    ],
    'only admno body + q method auth ref' => [
        'https://api.aesajce.in/?method=getStudQual4Placement&authkey=' . rawurlencode($authKey) . '&refurl=placements.amaljyothi.ac.in&admno=' . $admno,
        [],
    ],
    'stud_admno in q' => [
        'https://api.aesajce.in/?method=getStudQual4Placement&stud_admno=' . $admno . '&authkey=' . rawurlencode($authKey) . '&refurl=placements.amaljyothi.ac.in',
        [],
    ],
];

foreach ($cases as $label => [$url, $post]) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$label}: HTTP {$code} len=" . strlen($raw) . ' ' . substr($raw, 0, 150) . "\n";
}
