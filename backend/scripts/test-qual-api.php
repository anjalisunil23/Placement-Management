<?php

declare(strict_types=1);

/**
 * Smoke test: AES getStudQual4Placement (10th, 12th, CGPA).
 * Usage: php backend/scripts/test-qual-api.php [admission_no]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$register = $argv[1] ?? '22MCA047';
$api = new AesApiService();

echo "=== getStudQual4Placement raw ({$register}) ===\n";
$raw = $api->postStudQual4Placement(['admno' => $register], $register);
echo json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n=== fetchStudentQualificationProfile ({$register}) ===\n";
$qual = $api->fetchStudentQualificationProfile(['admno' => $register]);
echo json_encode($qual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$ok = ($qual['cgpa'] ?? null) !== null
    || ($qual['marks10th'] ?? null) !== null
    || ($qual['marks12th'] ?? null) !== null
    || !empty($qual['qualifications']);

echo "\n" . ($ok ? "Qualification data present.\n" : "No qualification fields parsed (check admno / AES response).\n");
exit($ok ? 0 : 1);
