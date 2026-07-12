<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$aes = require dirname(__DIR__) . '/config/aes.php';
$authKey = (string) ($aes['auth_key'] ?? '');
$base = [
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
    'stud_deptcode' => '30',
    'stud_course' => 'MCA',
    'stud_branch' => 'Regular',
    'stud_class' => 'MCA2024-2026',
];
$methods = [];
foreach ([
    'Placement', 'Placements', 'Placed', 'Employer', 'HigherEducation', 'HigherEdu',
    'NBA', 'Proof', 'PlacementList', 'StudPlacement', 'PlacementData',
] as $suffix) {
    $methods[] = 'get' . $suffix . '4Placement';
    $methods[] = 'getStud' . $suffix . '4Placement';
    $methods[] = 'list' . $suffix . '4Placement';
}
$methods = array_unique(array_merge($methods, [
    'getPlacements4Placement', 'getPlacement4Placement', 'getStudInfo4Placement',
    'getPlacementHigherEducation', 'getHigherEducationList',
]));

foreach ($methods as $method) {
    $ch = curl_init('https://api.aesajce.in/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array_merge(['method' => $method], $base)),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Origin: https://www.aesajce.in', 'Referer: https://www.aesajce.in/'],
    ]);
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    if (str_contains($raw, 'Invalid Method') || str_contains($raw, '"data":false')) {
        continue;
    }
    if (strlen(trim($raw)) < 10) {
        continue;
    }
    echo "=== {$method} ===\n" . substr($raw, 0, 600) . "\n\n";
}
