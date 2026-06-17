<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DriveModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Utils\DocumentHelper;

/**
 * Placement chance accounting tied to tier rules.
 */
final class PlacementChanceService
{
    public function syncChancesFromRule(string $studentId): void
    {
        $rule = (new RuleModel())->getActiveRule();
        if (!$rule) {
            return;
        }
        $studentModel = new StudentModel();
        $student = $studentModel->findById($studentId);
        if (!$student) {
            return;
        }
        $chances = $student['placementChances'] ?? [];
        $total = (int) ($rule['placementChances'] ?? 3);
        $used = (int) ($chances['used'] ?? 0);
        $studentModel->update($studentId, [
            'placementChances' => [
                'used'      => $used,
                'remaining' => max(0, $total - $used),
            ],
        ]);
    }

    /**
     * Consume chances when a student is selected for a drive tier.
     */
    public function consumeOnSelection(string $studentId, string $driveId, array $placementRecord): void
    {
        $studentModel = new StudentModel();
        $student = $studentModel->findById($studentId);
        if (!$student) {
            return;
        }

        $drive = (new DriveModel())->findById($driveId);
        $tier = $drive['tier'] ?? 'Tier 2';
        $rule = (new RuleModel())->getActiveRule();
        $tierRules = $rule['tierRules'] ?? [];
        $cost = (int) ($tierRules[$tier]['chances'] ?? 1);

        $chances = $student['placementChances'] ?? ['used' => 0, 'remaining' => 3];
        $used = (int) ($chances['used'] ?? 0) + $cost;
        $total = (int) ($rule['placementChances'] ?? 3);

        $history = $student['placementHistory'] ?? [];
        $history[] = array_merge($placementRecord, [
            'tier'  => $tier,
            'cost'  => $cost,
            'date'  => DocumentHelper::now(),
        ]);

        $studentModel->update($studentId, [
            'placed'            => true,
            'placementChances'  => [
                'used'      => $used,
                'remaining' => max(0, $total - $used),
            ],
            'placementHistory'  => $history,
        ]);
    }
}
