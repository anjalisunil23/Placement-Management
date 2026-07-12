<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = $argv[1] ?? '16777';

function hit(string $label, string $url, array $body, array $extraHeaders = []): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ], $extraHeaders),
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "{$label}: HTTP {$code} len=" . strlen($raw);
    if ($raw !== '') {
        echo ' ' . substr($raw, 0, 200);
    }
    echo "\n";
}

$base = 'https://api.aesajce.in/';
$ref = 'placements.amaljyothi.ac.in';

// Method in both query and body
hit('both method+admno', $base . '?method=getStudQual4Placement', [
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => $ref,
]);

// URL method only, try param names from info response
foreach (['admno', 'stud_admno', 'studno', 'student_admno'] as $key) {
    hit("url {$key}", $base . '?method=getStudQual4Placement', [
        $key => $admno,
        'authkey' => $authKey,
        'refurl' => $ref,
    ]);
}

// Body method minimal - no refurl
hit('body no refurl', $base, [
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
]);

// Body method with only admno+authkey (exact user spec)
hit('body admno only+auth', $base, [
    'method' => 'getStudQual4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => $ref,
]);

// Try registerno from info
hit('url registerno', $base . '?method=getStudQual4Placement', [
    'admno' => 'AJC25MCA-2012',
    'authkey' => $authKey,
    'refurl' => $ref,
]);

// Scan admno range for any non-empty qual response
echo "\n=== Scan nearby admnos (url method) ===\n";
for ($i = (int) $admno - 5; $i <= (int) $admno + 5; $i++) {
    $ch = curl_init($base . '?method=getStudQual4Placement');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => http_build_query(['admno' => (string) $i, 'authkey' => $authKey, 'refurl' => $ref]),
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (trim($raw) !== '') {
        echo "admno {$i}: " . substr($raw, 0, 150) . "\n";
    }
}

// Compare: does getStudInfo with edu fields exist on another endpoint?
hit('getStudEdu body', $base, ['method' => 'getStudEdu', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref]);
hit('getStudEducation body', $base, ['method' => 'getStudEducation', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => $ref]);
