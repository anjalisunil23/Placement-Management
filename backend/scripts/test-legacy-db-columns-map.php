<?php

declare(strict_types=1);

/**
 * Minimal column-map verification (avoids full service bootstrap / PHP 8.1+ files).
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

// Load only what this check needs.
require_once $root . '/backend/models/BaseModel.php';
require_once $root . '/backend/models/StudentPlacementModel.php';

use PMS\Models\StudentPlacementModel;

$pass = 0;
$fail = 0;
$check = static function (string $label, mixed $got, mixed $want) use (&$pass, &$fail): void {
    $g = is_scalar($got) || $got === null ? trim((string) $got) : '';
    $w = is_scalar($want) || $want === null ? trim((string) $want) : '';
    $ok = strcasecmp($g, $w) === 0 || ($w !== '' && str_contains($g, $w));
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}: " . ($g !== '' ? $g : '(empty)') . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "       expected: {$w}\n";
    }
};

echo "=== Production student_placements column → document map ===\n\n";

$row = [
    'id' => '1',
    'payload' => '{}',
    'studentId' => '5458',
    'student' => 'Adithya Anil',
    'cno' => '9744225110',
    'email' => 'anil.adithya99@gmail.com',
    'year' => '2020-2021',
    'courseId' => '1001',
    'branchId' => '35',
    'employer' => 'Infosys, Accenture, Innovature',
    'empcno' => '0471 398 2222',
    'empadr' => 'Talent Acquisition Infosys Limited Technopark',
    'payscale' => '3.6 LPA',
    'status' => 'Placed',
    'createdBy' => 'admin',
    'crteatedDate' => '2021-01-01',
    'updatedBy' => 'admin',
    'updatedDate' => '2021-01-02',
    's3file' => 's3://offers/adithya.pdf',
    'filename' => 'adithya.pdf',
    'fordvv' => '1',
    'type' => 'Placement',
    'includedvv' => '1',
];

$doc = StudentPlacementModel::mergeLegacyFlatRowIntoDoc($row, []);
$roster = StudentPlacementModel::rosterRowFromDocument(array_merge($doc, ['_id' => '5458']));

$map = [
    'student → studentName' => [$roster['studentName'] ?? '', 'Adithya Anil'],
    'cno → phone' => [$roster['phone'] ?? '', '9744225110'],
    'email → email' => [$roster['email'] ?? '', 'anil.adithya99@gmail.com'],
    'year → classBatch' => [$roster['classBatch'] ?? '', '2020-2021'],
    'courseId' => [$roster['courseId'] ?? '', '1001'],
    'branchId' => [$roster['branchId'] ?? '', '35'],
    'employer → company' => [$roster['company'] ?? '', 'Infosys, Accenture, Innovature'],
    'empcno → employerContact' => [$roster['employerContact'] ?? ($roster['placement']['employerContact'] ?? ''), '0471 398 2222'],
    'empadr → address' => [$roster['address'] ?? ($roster['placement']['address'] ?? ''), 'Talent Acquisition Infosys Limited Technopark'],
    'payscale → package' => [$roster['package'] ?? ($roster['placement']['package'] ?? ''), '3.6 LPA'],
    'status → placementStatus' => [$roster['placementStatus'] ?? ($roster['placement']['placementStatus'] ?? ''), 'Placed'],
    'type → recordType' => [$roster['recordType'] ?? ($roster['placement']['recordType'] ?? ''), 'Placement'],
    'fordvv' => [$roster['fordvv'] ?? ($roster['placement']['fordvv'] ?? ''), '1'],
    'includedvv' => [$roster['includedvv'] ?? ($roster['placement']['includedvv'] ?? ''), '1'],
    's3file → offerLetter' => [$doc['placement']['offerLetter'] ?? '', 's3://offers/adithya.pdf'],
    'filename' => [$doc['filename'] ?? '', 'adithya.pdf'],
    'crteatedDate → createdDate' => [$doc['createdDate'] ?? '', '2021-01-01'],
];

foreach ($map as $label => [$got, $want]) {
    $check($label, $got, $want);
}

echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
