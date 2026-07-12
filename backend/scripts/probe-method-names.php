<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

for ($i = 0; $i < 256; $i++) {
    // skip - brute force method names is slow
}
$methods = [
    'getStudMarks4Placement', 'getStudMark4Placement', 'getStudAcademic4Placement',
    'getStudEducation4Placement', 'getStudEdu4Placement', 'getStudSSLC4Placement',
    'getStudHSC4Placement', 'getStudSchool4Placement', 'getStudPrev4Placement',
    'getStudUG4Placement', 'getStudDegree4Placement', 'getStudQualification',
    'getStudentMarks4Placement', 'getStudentQual4Placement',
];

foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['method' => $method, 'admno' => $admno, 'authkey' => $authKey]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !str_contains($raw, 'Invalid Method')) {
        echo "{$method} HTTP {$code} " . substr($raw, 0, 200) . "\n";
    }
}
