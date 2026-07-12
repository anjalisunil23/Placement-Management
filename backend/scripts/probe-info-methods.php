<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$admno = '16777';

$methods = [
    'getStudInfo', 'getStudentInfo', 'getStudDetails', 'getStudentDetails',
    'getStudProfile', 'getStudentProfile', 'getStudEducation', 'getStudEdu',
    'getStudAcademic', 'getStudentAcademic', 'getStudMarks', 'getStudentMarks',
    'getStudQual', 'getStudentQual', 'getStudQualification',
];

foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
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
    if ($code === 200 && !str_contains($raw, 'Invalid Method') && trim($raw) !== '') {
        echo "{$method} HTTP {$code}\n" . substr($raw, 0, 400) . "\n---\n";
    } elseif ($code === 500) {
        echo "{$method} HTTP 500\n";
    }
}
