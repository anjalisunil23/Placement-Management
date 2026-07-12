<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$suffixes = [
    'Qual4Placement', 'QualForPlacement', 'Qualification4Placement', 'Qualifications4Placement',
    'Qual4Place', 'QualPlacement', 'QualDetails4Placement', 'QualInfo4Placement',
    'PrevQual4Placement', 'SchoolQual4Placement', 'AcademicQual4Placement',
    'MarksQual4Placement', 'EduQual4Placement', 'getStudQual4Placements',
    'StudQual4Placement', 'studQual4Placement',
];

foreach ($suffixes as $suffix) {
    $method = str_starts_with($suffix, 'get') || str_starts_with($suffix, 'stud') ? $suffix : 'getStud' . $suffix;
    if (!str_starts_with($method, 'get')) {
        $method = 'get' . ucfirst($method);
    }
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'method' => $method,
            'admno' => $admno,
            'authkey' => $authKey,
            'refurl' => 'placements.amaljyothi.ac.in',
        ]),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Origin: https://www.aesajce.in',
            'Referer: https://www.aesajce.in/',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!str_contains($raw, 'Invalid Method')) {
        echo "{$method}: HTTP {$code} len=" . strlen($raw);
        if ($raw !== '') echo ' ' . substr($raw, 0, 120);
        echo "\n";
    }
}
