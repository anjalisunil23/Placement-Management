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
        $company = null;
        if (is_array($drive) && !empty($drive['companyId'])) {
            $company = (new \PMS\Models\CompanyModel())->findById((string) $drive['companyId']);
            if ($tier === 'Tier 2' && !empty($company['tier'])) {
                $tier = $company['tier'];
            }
        }
        $rule = (new RuleModel())->getActiveRule();
        $tierRules = $rule['tierRules'] ?? [];
        $cost = (int) ($tierRules[$tier]['chances'] ?? 1);

        $chances = $student['placementChances'] ?? ['used' => 0, 'remaining' => 3];
        $used = (int) ($chances['used'] ?? 0) + $cost;
        $total = (int) ($rule['placementChances'] ?? 3);

        $package = (string) ($placementRecord['package'] ?? '');
        if ($package === '' && is_array($drive)) {
            $elig = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
            $package = (string) ($elig['package'] ?? $drive['package'] ?? '');
        }
        $categories = new PlacementCategoryService();
        $placementCategory = $categories->classify($package, $tier);

        $history = $student['placementHistory'] ?? [];
        $history[] = array_merge($placementRecord, [
            'tier'               => $tier,
            'package'            => $package,
            'placementCategory'  => $placementCategory,
            'cost'               => $cost,
            'date'               => DocumentHelper::now(),
        ]);

        $studentModel->update($studentId, [
            'placed'            => true,
            'placementCategory' => $placementCategory,
            'placementChances'  => [
                'used'      => $used,
                'remaining' => max(0, $total - $used),
            ],
            'placementHistory'  => $history,
        ]);
    }
}
