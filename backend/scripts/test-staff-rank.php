<?php

declare(strict_types=1);

/**
 * Smoke tests: AES staff_rank only (never invent from designation).
 * Run: php backend/scripts/test-staff-rank.php
 */

require dirname(__DIR__) . '/services/HodDetection.php';
require dirname(__DIR__) . '/services/StaffRank.php';

use PMS\Services\StaffRank;

$failed = 0;
$passed = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $failed, $passed;
    if ($actual === $expected) {
        echo "OK  {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL {$label} — got " . var_export($actual, true) . " expected " . var_export($expected, true) . "\n";
    $failed++;
}

expect('empty → 99', StaffRank::resolve([]), 99);
expect('no invent from empty', StaffRank::canViewPlacementAdminData(StaffRank::resolve([])), false);
expect('designation ignored', StaffRank::pickAesStaffRank(['designation' => 'Professor']), null);
expect('aes staff_rank 3', StaffRank::resolve(['staff_rank' => 3]), 3);
expect('aes staff_rank 5 view', StaffRank::canViewPlacementAdminData(5), true);
expect('aes staff_rank 6 no view', StaffRank::canViewPlacementAdminData(6), false);
expect('aes staff_rank 1 view', StaffRank::canViewPlacementAdminData(1), true);
expect('ignore pay level 11', StaffRank::pickAesStaffRank(['staff_rank' => 11]), null);
expect('ignore generic rank key', StaffRank::pickAesStaffRank(['rank' => 3]), null);
expect('nested staff_rank', StaffRank::pickAesStaffRank(['staff' => ['staff_rank' => 2]]), 2);
expect('stored staffRank synced', StaffRank::resolve(['staffRank' => 5]), 5);
expect('unknown no view', StaffRank::canViewPlacementAdminData(99), false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
