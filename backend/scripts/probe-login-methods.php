<?php

declare(strict_types=1);

$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$refHost = 'placements.amaljyothi.ac.in';
$admno = '16777';

$methods = [
    'getStudentDetails', 'getStudentProfile', 'getPersonalDetails', 'getContactDetails',
    'getStudentEducation', 'getEducationDetails', 'getStudEducation', 'getQualificationDetails',
    'getStudentQualification', 'getAcademicDetails', 'getStudentAcademic',
];

foreach ($methods as $method) {
    $fields = [
        'method' => $method,
        'authkey' => $authKey,
        'refurl' => $refHost,
        'admno' => $admno,
        'username' => $admno,
        'un' => $admno,
    ];
    $ch = curl_init('https://login.aesajce.in/api/public_api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: https://' . $refHost . '/public-stats.html',
        ],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (!str_contains($raw, 'Invalid Method')) {
        echo "=== {$method} ===\n" . substr($raw, 0, 1500) . "\n---\n";
    }
}
