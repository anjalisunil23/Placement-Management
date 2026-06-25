<?php

declare(strict_types=1);

/**
 * Verify AES placement API returns name, emails, and photo for a student.
 * Usage: php backend/scripts/test-profile-api-fields.php [admno]
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$register = strtoupper(trim($argv[1] ?? '17228'));
$api = new AesApiService();

$result = $api->postStudInfo4Placement(['admno' => $register], $register);
$status = (int) ($result['status'] ?? 0);
$placement = $api->fetchStudentPlacementProfile(['admno' => $register]);

echo "=== AES getStudInfo4Placement ({$register}) ===\n";
echo "HTTP status: {$status}\n";

if ($placement === []) {
    echo "FAIL: fetchStudentPlacementProfile returned empty\n";
    echo "Raw wrapper:\n";
    echo json_encode($result['data'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$record = $placement;
$checks = [
    'stud_name'      => trim((string) ($record['stud_name'] ?? $record['name'] ?? '')),
    'collegeEmail'   => trim((string) ($record['collegeEmail'] ?? '')),
    'personalEmail'  => trim((string) ($record['personalEmail'] ?? '')),
    'photoUrl'       => trim((string) ($record['photoUrl'] ?? $record['stud_photo'] ?? '')),
    'phone'          => trim((string) ($record['phone'] ?? $record['stud_mobiles'] ?? '')),
    'branch'         => trim((string) ($record['branch'] ?? $record['stud_cource_short'] ?? $record['stud_course'] ?? '')),
    'cgpa'           => (string) ($record['cgpa'] ?? ''),
    'marks10th'      => (string) ($record['marks10th'] ?? ''),
    'marks12th'      => (string) ($record['marks12th'] ?? $record['ugMarks'] ?? ''),
];

$ok = true;
foreach ($checks as $field => $value) {
    $pass = $value !== '';
    if ($field === 'photoUrl') {
        $pass = $pass && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    if ($field === 'collegeEmail' || $field === 'personalEmail') {
        $pass = $pass && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    if ($field === 'phone') {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $pass = strlen($digits) >= 10;
    }
    if ($field === 'marks10th' || $field === 'marks12th') {
        $pass = true;
    }
    $label = ($field === 'marks10th' || $field === 'marks12th') && $value === '' ? 'INFO' : ($pass ? 'PASS' : 'FAIL');
    echo "{$label} {$field}: " . ($value !== '' ? $value : '(empty)') . "\n";
    if (!$pass && $field !== 'marks10th' && $field !== 'marks12th') {
        $ok = false;
    }
}

echo "\nNormalized record (selected fields):\n";
echo json_encode([
    'stud_name' => $checks['stud_name'],
    'collegeEmail' => $checks['collegeEmail'],
    'personalEmail' => $checks['personalEmail'],
    'photoUrl' => $checks['photoUrl'],
    'phone' => $checks['phone'],
    'branch' => $checks['branch'],
    'cgpa' => $checks['cgpa'],
    'marks10th' => $checks['marks10th'],
    'marks12th' => $checks['marks12th'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit($ok ? 0 : 1);
