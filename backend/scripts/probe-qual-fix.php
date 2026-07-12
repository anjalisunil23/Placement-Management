<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = $argv[1] ?? '16777';
$register = $argv[2] ?? 'AJC25MCA-2012';

function tryReq(string $label, string $url, array $body = [], string $verb = 'POST'): void
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, */*',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
            'X-Requested-With: XMLHttpRequest',
        ],
    ];
    if ($verb === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $preview = trim($raw) === '' ? '(empty)' : substr($raw, 0, 500);
    echo "{$label}\n  HTTP {$code} len=" . strlen($raw) . "\n  {$preview}\n\n";
}

$base = 'https://api.aesajce.in/';

// Working reference
tryReq('REF getStudInfo4Placement body', $base, [
    'method' => 'getStudInfo4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);

// Qual variations
$cases = [
    'qual body admno' => [$base, ['method' => 'getStudQual4Placement', 'admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual body stud_admno' => [$base, ['method' => 'getStudQual4Placement', 'stud_admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual body register' => [$base, ['method' => 'getStudQual4Placement', 'admno' => $register, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual url admno' => [$base . '?method=getStudQual4Placement', ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual url stud_admno' => [$base . '?method=getStudQual4Placement', ['stud_admno' => $admno, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual url admno+register' => [$base . '?method=getStudQual4Placement', ['admno' => $admno, 'registerno' => $register, 'authkey' => $authKey, 'refurl' => 'placements.amaljyothi.ac.in']],
    'qual url no refurl' => [$base . '?method=getStudQual4Placement', ['admno' => $admno, 'authkey' => $authKey]],
    'qual url www refurl' => [$base . '?method=getStudQual4Placement', ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'www.aesajce.in']],
    'qual url aesajce refurl' => [$base . '?method=getStudQual4Placement', ['admno' => $admno, 'authkey' => $authKey, 'refurl' => 'aesajce.in']],
    'qual GET url' => [$base . '?method=getStudQual4Placement&admno=' . rawurlencode($admno) . '&authkey=' . rawurlencode($authKey) . '&refurl=placements.amaljyothi.ac.in', [], 'GET'],
];

foreach ($cases as $label => [$url, $body, $verb]) {
    tryReq($label, $url, $body, $verb ?? 'POST');
}

// Alternate method names with same params as info
$altMethods = [
    'getStudQual4Placement', 'getStudQualification4Placement', 'getStudEducation4Placement',
    'getStudEdu4Placement', 'getStudMarks4Placement', 'getStudentQual4Placement',
];
echo "=== Alternate method names ===\n";
foreach ($altMethods as $method) {
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => $method,
            'admno' => $admno,
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
        ]),
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || str_contains($raw, 'Invalid Method') || trim($raw) === '') {
        echo "{$method}: HTTP {$code} " . (trim($raw) === '' ? '(empty)' : substr($raw, 0, 120)) . "\n";
    } else {
        echo "{$method}: HTTP {$code} HIT " . substr($raw, 0, 300) . "\n";
    }
}
