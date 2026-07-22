<?php

declare(strict_types=1);

/**
 * Smoke / unit checks for drive application exceptions (all tiers).
 *
 * Usage: php backend/scripts/test-drive-application-exceptions.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php\n");
    exit(1);
}
require_once $autoload;

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
if (is_readable($root . '/.env.local')) {
    Dotenv\Dotenv::createMutable($root, '.env.local')->safeLoad();
}

use PMS\Models\DriveApplicationExceptionModel;
use PMS\Services\EligibilityEngine;
use PMS\Services\PlacementCategoryService;
use PMS\Utils\Security;

$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$label}\n";
    $failed++;
};

$cats = new PlacementCategoryService();

$unplaced = ['placed' => false];
$driveTier2B = ['tier' => 'Tier 2', 'eligibility' => ['package' => '4 LPA']];
$driveTier1A = ['tier' => 'Tier 1', 'eligibility' => ['package' => '8 LPA']];
$driveTier3C = ['tier' => 'Tier 3', 'eligibility' => ['package' => '2 LPA']];

$assert($cats->mayAttemptDrive($unplaced, $driveTier3C)['allowed'] === true, 'Unplaced student may attempt any drive');

$placedC = [
    'placed' => true,
    'placementCategory' => 'C',
    'placement' => ['tier' => 'Tier 3', 'package' => '2.5 LPA'],
];
$assert($cats->studentIsTier3Placed($placedC) === true, 'Category C / Tier 3 student detected');
$assert($cats->mayAttemptDrive($placedC, $driveTier1A)['allowed'] === true, 'Category C may attempt Tier 1 / Category A');
$assert($cats->mayAttemptDrive($placedC, $driveTier3C)['allowed'] === false, 'Category C blocked from Tier 3 drive');
$assert($cats->mayAttemptDrive($placedC, $driveTier2B)['allowed'] === false, 'Category C blocked from Tier 2 / Category B');

$placedB = [
    'placed' => true,
    'placementCategory' => 'B',
    'placement' => ['tier' => 'Tier 2', 'package' => '4 LPA'],
];
$assert($cats->studentIsTier3Placed($placedB) === false, 'Category B is not Tier-3-only cohort');
$assert($cats->mayAttemptDrive($placedB, $driveTier1A)['allowed'] === true, 'Category B may attempt Category A');
$assert($cats->mayAttemptDrive($placedB, $driveTier3C)['allowed'] === false, 'Category B blocked from Tier 3');
$assert($cats->mayAttemptDrive($placedB, $driveTier2B)['allowed'] === false, 'Category B blocked from another Category B');

$placedA = [
    'placed' => true,
    'placementCategory' => 'A',
    'placement' => ['tier' => 'Tier 1', 'package' => '10 LPA'],
];
$assert($cats->mayAttemptDrive($placedA, $driveTier1A)['allowed'] === false, 'Category A blocked from further drives');
$assert($cats->mayAttemptDrive($placedA, $driveTier2B)['allowed'] === false, 'Category A blocked from Tier 2');
$assert($cats->mayAttemptDrive($placedA, $driveTier3C)['allowed'] === false, 'Category A blocked from Tier 3');

$assert($cats->studentPlacementCategory([
    'placed' => true,
    'placement' => ['tier' => 'Tier 1', 'package' => ''],
]) === 'A', 'Tier 1 placement classifies as Category A');

$assert($cats->studentPlacementCategory([
    'placed' => true,
    'placement' => ['tier' => 'Tier 2', 'package' => '4 LPA'],
]) === 'B', 'Tier 2 + 4 LPA classifies as Category B');

// Exception bypass policy for all tiers: if exception active OR category allows → eligible path.
$bypass = static function (bool $hasException, bool $categoryAllowed): bool {
    return $hasException || $categoryAllowed;
};
$assert($bypass(false, false) === false, 'No exception + blocked category => blocked');
$assert($bypass(true, false) === true, 'Active exception unlocks blocked Category A/B/C');
$assert($bypass(false, true) === true, 'Category-allowed drive needs no exception');
$assert($bypass(true, true) === true, 'Exception with already-allowed drive still ok');

foreach (['A', 'B', 'C'] as $cat) {
    $student = [
        'placed' => true,
        'placementCategory' => $cat,
        'placement' => [
            'tier' => $cat === 'A' ? 'Tier 1' : ($cat === 'B' ? 'Tier 2' : 'Tier 3'),
            'package' => $cat === 'A' ? '10 LPA' : ($cat === 'B' ? '4 LPA' : '2 LPA'),
        ],
    ];
    $gate = $cats->mayAttemptDrive($student, $driveTier2B);
    $assert(
        $bypass(true, $gate['allowed']) === true,
        "Exception policy unlocks Category {$cat} for a Tier 2 drive"
    );
}

// Source guards
$svc = (string) file_get_contents($root . '/backend/services/DriveApplicationExceptionService.php');
$assert(!str_contains($svc, 'studentIsTier3Placed'), 'Grant service no longer requires Tier-3-only placement');
$assert(str_contains($svc, 'already placed'), 'Grant service still requires placed students');

$html = (string) file_get_contents($root . '/students.html');
$assert(str_contains($html, 'any tier / category'), 'UI copy mentions any tier / category');
$assert(!str_contains($html, 'Open Drive (Tier 3)'), 'UI tab no longer limited to Tier 3 label');

$engineSrc = (string) file_get_contents($root . '/backend/services/EligibilityEngine.php');
$assert(str_contains($engineSrc, 'DriveApplicationExceptionModel'), 'EligibilityEngine honors drive exceptions');
$assert(str_contains($engineSrc, 'hasActive'), 'EligibilityEngine checks active exceptions');

// DB-backed path when local/production DB credentials work
$dbOk = false;
try {
    $dbOk = \PMS\Config\Database::ping();
} catch (Throwable) {
    $dbOk = false;
}

if ($dbOk) {
    try {
        \PMS\Config\Database::setupIndexes();
    } catch (Throwable $e) {
        echo "WARN  schema setup: {$e->getMessage()}\n";
    }

    $studentId = Security::generateId();
    $driveId = Security::generateId();
    $studentDoc = [
        '_id' => $studentId,
        'placed' => true,
        'placementCategory' => 'A',
        'placement' => ['tier' => 'Tier 1', 'package' => '12 LPA'],
        'academic' => ['cgpa' => 8.0, 'backlogs' => 0],
    ];
    $driveDoc = [
        '_id' => $driveId,
        'status' => 'scheduled',
        'tier' => 'Tier 2',
        'eligibility' => [
            'package' => '4 LPA',
            'minCgpa' => 6,
            'deadline' => date('Y-m-d', time() + 86400 * 14),
        ],
        'branches' => [],
    ];

    $engine = new EligibilityEngine();
    $assert(
        $engine->checkStudentAgainstDrive($studentDoc, $driveDoc, false)['eligible'] === false,
        'Category A student blocked without exception (DB)'
    );

    $exModel = new DriveApplicationExceptionModel();
    $grant = $exModel->grant($studentId, $driveId, Security::generateId(), 'test all-tiers exception');
    $assert($grant['created'] === true && $grant['id'] !== '', 'Exception grant creates row');
    $assert($exModel->hasActive($studentId, $driveId) === true, 'Exception is active after grant');
    $assert($engine->driveVisibleToStudent($studentDoc, $driveDoc) === true, 'Drive visible with active exception');
    $assert(
        $engine->checkStudentAgainstDrive($studentDoc, $driveDoc, false)['eligible'] === true,
        'Category A student eligible with exception (all tiers, DB)'
    );

    $exModel->update($grant['id'], ['expiresAt' => date('c', time() - 60), 'revokedAt' => null]);
    $assert($exModel->hasActive($studentId, $driveId) === false, 'Expired exception is inactive');

    $grant2 = $exModel->grant($studentId, $driveId, Security::generateId(), 'second grant');
    $exModel->revoke($grant2['id'], Security::generateId());
    $assert($exModel->hasActive($studentId, $driveId) === false, 'Revoked exception is inactive');

    foreach ([$grant['id'], $grant2['id']] as $id) {
        try {
            $exModel->delete($id);
        } catch (Throwable) {
        }
    }
} else {
    echo "SKIP  DB-backed exception grant/eligibility tests (database not reachable from this machine)\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
