<?php

declare(strict_types=1);

/**
 * Regression: AES sync/list must not wipe student_placements fields.
 *
 * Usage: php backend/scripts/test-aes-placement-safe-merge.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Models\StudentPlacementModel;
use PMS\Services\StaffPlacementRegistryService;

$pass = 0;
$fail = 0;

$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . "  {$label}\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
};

echo "=== AES / SQL placement safe-merge regression ===\n\n";

// --- Helpers ---
$assert(
    StudentPlacementModel::isBlankMergeValue(null) === true,
    'null is blank'
);
$assert(
    StudentPlacementModel::isBlankMergeValue('') === true,
    'empty string is blank'
);
$assert(
    StudentPlacementModel::isBlankMergeValue(0) === false,
    'integer 0 is NOT blank'
);
$assert(
    StudentPlacementModel::isBlankMergeValue('0') === false,
    'string "0" is NOT blank'
);
$assert(
    StudentPlacementModel::isBlankMergeValue(false) === false,
    'false is NOT blank'
);

// Test 1 — SQL placement + empty AES
$sql = [
    'company' => 'TCS',
    'package' => '600000',
    'phone' => '9876543210',
    'email' => 'student@example.com',
    'placementStatus' => 'Placed',
    'placement' => [
        'company' => 'TCS',
        'package' => '600000',
        'placementStatus' => 'Placed',
    ],
];
$aesEmpty = [
    'company' => '',
    'package' => null,
    'phone' => '',
    'email' => '',
    'placementStatus' => null,
    'placement' => [
        'company' => '',
        'package' => null,
        'placementStatus' => '',
    ],
];
$merged1 = StudentPlacementModel::mergePreserveFilled($sql, $aesEmpty);
$assert(($merged1['company'] ?? '') === 'TCS', 'Test1 company preserved');
$assert(($merged1['package'] ?? '') === '600000', 'Test1 package preserved');
$assert(($merged1['phone'] ?? '') === '9876543210', 'Test1 phone preserved');
$assert(($merged1['email'] ?? '') === 'student@example.com', 'Test1 email preserved');
$assert(($merged1['placement']['company'] ?? '') === 'TCS', 'Test1 nested company preserved');
$assert(($merged1['placementStatus'] ?? '') === 'Placed', 'Test1 status preserved');

// Test 2 — SQL filled + AES non-empty: placement-owned SQL wins (preserve filled)
$sqlContact = [
    'phone' => '9876543210',
    'email' => 'old@example.com',
    'company' => 'TCS',
];
$aesContact = [
    'phone' => '9123456789',
    'email' => 'new@example.com',
    'company' => 'Infosys',
];
$merged2 = StudentPlacementModel::mergePreserveFilled($sqlContact, $aesContact);
$assert(($merged2['phone'] ?? '') === '9876543210', 'Test2 phone keeps SQL (placement-owned)');
$assert(($merged2['email'] ?? '') === 'old@example.com', 'Test2 email keeps SQL');
$assert(($merged2['company'] ?? '') === 'TCS', 'Test2 company keeps SQL');

// Fill-only from AES when SQL blank
$sqlBlankPhone = ['phone' => '', 'email' => 'kept@example.com', 'company' => ''];
$aesFill = ['phone' => '9123456789', 'email' => 'ignored@example.com', 'company' => 'Wipro'];
$merged2b = StudentPlacementModel::mergePreserveFilled($sqlBlankPhone, $aesFill);
$assert(($merged2b['phone'] ?? '') === '9123456789', 'Test2b AES fills blank phone');
$assert(($merged2b['email'] ?? '') === 'kept@example.com', 'Test2b email keeps SQL');
$assert(($merged2b['company'] ?? '') === 'Wipro', 'Test2b AES fills blank company');

// Test 3 — AES-only student shell (mergeCompleteClassRoster)
$svc = new StaffPlacementRegistryService();
$ref = new ReflectionClass($svc);
$mergeRoster = $ref->getMethod('mergeCompleteClassRoster');
$mergeRoster->setAccessible(true);
$extract = $ref->getMethod('extractRegistryRows');
$extract->setAccessible(true);
$studentKey = $ref->getMethod('studentRowKey');
$studentKey->setAccessible(true);
$dedupe = $ref->getMethod('deduplicateStudentRows');
$dedupe->setAccessible(true);

$aesOnly = [[
    'id' => 'AESONLY1',
    'studentId' => 'AESONLY1',
    'registerNumber' => 'REG999',
    'admno' => 'REG999',
    'studentName' => 'Aes Only',
    'displayName' => 'Aes Only',
    'classBatch' => 'MCALE2016-18',
    'placement' => [],
]];
$merged3 = $mergeRoster->invoke($svc, [], $aesOnly);
$assert(count($merged3) === 1, 'Test3 AES-only student appears');
$assert(trim((string) ($merged3[0]['placement']['company'] ?? '')) === '', 'Test3 placement empty');

// Test 4 — SQL-only student preserved when AES has others
$sqlOnly = [[
    'id' => '5458',
    'studentId' => '5458',
    'registerNumber' => '5458',
    'studentName' => 'Adithya Anil',
    'email' => 'anil.adithya99@gmail.com',
    'company' => 'Infosys',
    'employer' => 'Infosys',
    'placement' => ['company' => 'Infosys', 'package' => '3.6 LPA'],
]];
$aesOther = [[
    'id' => 'OTHER1',
    'studentId' => 'OTHER1',
    'registerNumber' => 'OTHER1',
    'studentName' => 'Other Student',
    'placement' => [],
]];
$merged4 = $mergeRoster->invoke($svc, $sqlOnly, $aesOther);
$keys4 = array_map(static fn (array $r): string => (string) $studentKey->invoke($svc, $r), $merged4);
$assert(in_array('5458', $keys4, true), 'Test4 SQL-only student preserved');
$assert(count($merged4) === 2, 'Test4 SQL + AES both present');
$sqlKept = null;
foreach ($merged4 as $row) {
    if ((string) $studentKey->invoke($svc, $row) === '5458') {
        $sqlKept = $row;
    }
}
$assert(is_array($sqlKept) && ($sqlKept['placement']['company'] ?? '') === 'Infosys', 'Test4 SQL company intact');

// Test 5 — Name formatting difference collapses to one
$rows5 = [
    [
        'id' => 'A1',
        'studentId' => 'A1',
        'registerNumber' => 'A1',
        'studentName' => 'Anjali Sunil',
        'company' => '',
        'employer' => '',
    ],
    [
        'id' => 'B2',
        'studentId' => 'B2',
        'registerNumber' => 'B2',
        'studentName' => 'ANJALI  SUNIL',
        'company' => 'TCS',
        'employer' => 'TCS',
    ],
];
$deduped5 = $dedupe->invoke($svc, $rows5);
$assert(count($deduped5) === 1, 'Test5 one student after name normalize');
$assert(trim((string) ($deduped5[0]['company'] ?? $deduped5[0]['employer'] ?? '')) === 'TCS', 'Test5 richer row kept');

// Test 6 — Zero/false values preserved through merge
$base6 = ['fordvv' => '0', 'offerLetterVerified' => false, 'package' => 0];
$incoming6 = ['fordvv' => '', 'offerLetterVerified' => null, 'package' => null, 'role' => 'SDE'];
$merged6 = StudentPlacementModel::mergePreserveFilled($base6, $incoming6);
$assert(($merged6['fordvv'] ?? null) === '0', 'Test6 fordvv "0" preserved');
$assert(($merged6['offerLetterVerified'] ?? null) === false, 'Test6 false preserved');
$assert(($merged6['package'] ?? null) === 0, 'Test6 package 0 preserved');
$assert(($merged6['role'] ?? '') === 'SDE', 'Test6 blank base filled from AES');

// mergeNonEmptyValues: blank incoming must not erase
$mergedNe = StudentPlacementModel::mergeNonEmptyValues(
    ['company' => 'TCS', 'phone' => '9'],
    ['company' => '', 'phone' => null, 'email' => 'a@b.c']
);
$assert(($mergedNe['company'] ?? '') === 'TCS', 'mergeNonEmptyValues keeps company');
$assert(($mergedNe['phone'] ?? '') === '9', 'mergeNonEmptyValues keeps phone');
$assert(($mergedNe['email'] ?? '') === 'a@b.c', 'mergeNonEmptyValues adds email');

// Name normalize
$assert(
    StudentPlacementModel::normalizePersonName('ANJALI  SUNIL') === 'anjali sunil',
    'normalizePersonName collapses spaces/case'
);

// Grid extract still shows company after empty AES hydrate path
$roster = [
    'id' => '5458',
    'studentId' => '5458',
    'registerNumber' => '5458',
    'studentName' => 'Adithya Anil',
    'email' => 'anil.adithya99@gmail.com',
    'phone' => '',
    'company' => 'Infosys Accenture Innovature',
    'employer' => 'Infosys Accenture Innovature',
    'placement' => [
        'company' => 'Infosys Accenture Innovature',
        'package' => '3.6 LPA',
        'placementStatus' => 'Placed',
    ],
    'placed' => true,
];
$wiped = StudentPlacementModel::mergePreserveFilled($roster, [
    'company' => '',
    'email' => '',
    'placement' => ['company' => '', 'package' => null],
]);
$hydrate = $ref->getMethod('hydrateRosterRowFromAesKeys');
$hydrate->setAccessible(true);
$hydrated = $hydrate->invoke($svc, $wiped);
$grid = $extract->invoke($svc, $hydrated, false, true);
$entry = $grid[0] ?? [];
$assert(
    str_contains((string) ($entry['company'] ?? $entry['employer'] ?? ''), 'Infosys'),
    'Grid still shows company after empty AES overlay'
);
$assert(
    str_contains((string) ($entry['package'] ?? ''), '3.6'),
    'Grid still shows package after empty AES overlay'
);

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
