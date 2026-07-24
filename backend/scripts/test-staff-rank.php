<?php

declare(strict_types=1);

/**
 * Smoke tests for StaffRank eligibility (rank < 6 → view placement admin data).
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

expect('empty designation → 5', StaffRank::fromDesignation(''), 5);
expect('Faculty → 5', StaffRank::fromDesignation('Faculty'), 5);
expect('Staff → 5', StaffRank::fromDesignation('Staff'), 5);
expect('Assistant Professor → 5', StaffRank::fromDesignation('Assistant Professor Level 11'), 5);
expect('Associate Professor → 4', StaffRank::fromDesignation('Associate Professor'), 4);
expect('Professor → 3', StaffRank::fromDesignation('Professor'), 3);
expect('HOD,Associate Professor → 2', StaffRank::fromDesignation('HOD,Associate Professor'), 2);
expect('Lecturer → 6', StaffRank::fromDesignation('Lecturer'), 6);
expect('view empty', StaffRank::canViewPlacementAdminData(StaffRank::fromDesignation('')), true);
expect('view Assistant Professor', StaffRank::canViewPlacementAdminData(5), true);
expect('no view Lecturer', StaffRank::canViewPlacementAdminData(6), false);
expect('ignore pay level 11', StaffRank::resolve(['staff_level' => 11, 'rank' => 11], 'Assistant Professor'), 5);
expect('aes rank 3 wins', StaffRank::resolve(['staff_rank' => 3], ''), 3);
expect('aes rank 4', StaffRank::resolve(['staff_rank' => 4], 'Faculty'), 4);
expect('ignore Level-like rank 12', StaffRank::pickNumeric(['rank' => 12]), null);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
