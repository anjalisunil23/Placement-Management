<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$admno = $argv[1] ?? '16777';
$api = new AesApiService();

echo "=== getStudQual4Placement via AesApiService (admno {$admno}) ===\n";
$response = $api->getStudQual4Placement(['admno' => $admno]);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$qual = $api->fetchStudentQualificationProfile(['admno' => $admno, 'stud_admno' => $admno]);
echo "=== fetchStudentQualificationProfile ===\n";
echo json_encode($qual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
