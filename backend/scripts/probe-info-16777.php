<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$api = new AesApiService();
foreach (['16777', 'AJC25MCA-2012'] as $adm) {
    echo "=== getStudInfo4Placement {$adm} ===\n";
    $r = $api->getStudInfo4Placement(['admno' => $adm]);
    echo 'status=' . ($r['status'] ?? 0) . ' success=' . json_encode($r['success'] ?? null) . "\n";
    echo json_encode($r['data'] ?? $r['raw'] ?? $r['error'] ?? '', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    echo "=== fetchStudentQualificationProfile {$adm} ===\n";
    $qual = $api->fetchStudentQualificationProfile(['admno' => $adm]);
    echo json_encode($qual, JSON_PRETTY_PRINT) . "\n\n";

    echo "=== fetchStudentPlacementProfile {$adm} ===\n";
    $placement = $api->fetchStudentPlacementProfile(['admno' => $adm]);
    $keys = array_intersect_key($placement, array_flip(['cgpa', 'marks10th', 'marks12th', 'stud_admno', 'edu', 'qualifications']));
    echo json_encode($keys, JSON_PRETTY_PRINT) . "\n---\n";
}
