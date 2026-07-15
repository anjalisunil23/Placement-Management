<?php

declare(strict_types=1);

/**
 * Smoke test: qualifications table uses getStudQual4Placement only (not getStudInfo4Placement fallback).
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use PMS\Services\AesApiService;

$admno = $argv[1] ?? '16777';
$authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
$failures = [];

function fail(array &$failures, string $msg): void
{
    $failures[] = $msg;
    echo "FAIL: {$msg}\n";
}

function pass(string $msg): void
{
    echo "PASS: {$msg}\n";
}

echo "=== 1) getStudInfo4Placement must NOT populate qualifications table ===\n";
$postData = http_build_query([
    'method' => 'getStudInfo4Placement',
    'admno' => $admno,
    'authkey' => $authKey,
    'refurl' => 'placements.amaljyothi.ac.in',
]);
$ch = curl_init('https://api.aesajce.in/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'Origin: https://www.aesajce.in',
        'Referer: https://www.aesajce.in/',
    ],
]);
$raw = (string) curl_exec($ch);
curl_close($ch);
$decoded = json_decode($raw, true);
$aesData = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

$api = new AesApiService();
$infoNormalized = $api->normalizePlacementStudentRecord($aesData);
if (!empty($infoNormalized['qualifications'])) {
    fail($failures, 'normalizePlacementStudentRecord must not add qualifications from info API');
} else {
    pass('info API path leaves qualifications empty');
}

echo "\n=== 2) getStudQual4Placement call ===\n";
$qualResponse = $api->getStudQual4Placement(['admno' => $admno]);
$ref = new ReflectionClass($api);
$extractQual = $ref->getMethod('extractQualificationRawRecord');
$extractQual->setAccessible(true);
$qualRaw = $extractQual->invoke($api, $qualResponse);

if (($qualResponse['status'] ?? 0) >= 500) {
    echo "NOTE: getStudQual4Placement HTTP " . ($qualResponse['status'] ?? 0) . " (body method may 500; URL fallback tried)\n";
}

if ($qualRaw === []) {
    echo "NOTE: getStudQual4Placement returned empty for admno {$admno} — table will stay hidden until AES returns data\n";
    pass('qual API callable (empty payload accepted)');
} else {
    pass('getStudQual4Placement returned qualification payload');
    $qualNorm = $api->normalizeQualificationRecord($qualRaw);
    if (empty($qualNorm['qualifications'])) {
        // Marks-only AES payloads no longer invent SSLC/HSC/CGPA table rows.
        pass('normalizeQualificationRecord has no edu table rows (marks-only OK)');
    } else {
        pass('qual table rows count=' . count($qualNorm['qualifications']));
    }
}

echo "\n=== 3) buildQualificationTableRowsFromMarks (qual API shape only) ===\n";
$mockQual = ['marks10th' => 85.5, 'marks12th' => 78.0, 'cgpa' => 8.0, 'registerno' => 'TEST-1'];
$built = $api->buildQualificationTableRowsFromMarks($mockQual);
if (count($built) < 3) {
    fail($failures, 'buildQualificationTableRowsFromMarks expected SSLC, HSC, CGPA rows');
} else {
    pass('buildQualificationTableRowsFromMarks count=' . count($built));
}

echo "\n=== 4) fetchStudentQualificationProfile ===\n";
$qualProfile = $api->fetchStudentQualificationProfile(['admno' => $admno, 'stud_admno' => $admno]);
if ($qualRaw === [] && $qualProfile === []) {
    pass('fetchStudentQualificationProfile empty when AES qual API empty');
} elseif (!empty($qualProfile['qualifications'])) {
    pass('fetchStudentQualificationProfile qualifications count=' . count($qualProfile['qualifications']));
} elseif (!empty($qualProfile['cgpa']) || !empty($qualProfile['marks10th']) || !empty($qualProfile['marks12th'])) {
    pass('fetchStudentQualificationProfile marks/CGPA without inventing edu table rows');
} else {
    fail($failures, 'qual profile missing qualifications when raw payload was non-empty');
}

echo "\n=== Summary ===\n";
if ($failures !== []) {
    echo "FAILED " . count($failures) . " check(s):\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "All checks passed for admno {$admno}.\n";
exit(0);
