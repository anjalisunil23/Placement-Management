<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

class RuleModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RULES;
    }

    public function getActiveRule(): ?array
    {
        return $this->findOne(['active' => true], ['sort' => ['createdAt' => -1]]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRule(array $data): string
    {
        if (!empty($data['active'])) {
            $this->updateMany(['active' => true], ['active' => false]);
        }
        return $this->insert([
            'name'                => $data['name'] ?? 'Placement Rules',
            'minCgpa'             => (float) ($data['minCgpa'] ?? 0),
            'maxBacklogs'         => (int) ($data['maxBacklogs'] ?? $data['maxBacklog'] ?? 0),
            'placementChances'    => (int) ($data['placementChances'] ?? $data['maxPlacementChances'] ?? 3),
            'eligibilityCriteria' => $data['eligibilityCriteria'] ?? $data['placementPolicy'] ?? '',
            'tierRules'           => $data['tierRules'] ?? [
                'Tier 1' => ['chances' => 1],
                'Tier 2' => ['chances' => 1],
                'Tier 3' => ['chances' => 1],
            ],
            'blockPlacedStudents' => (bool) ($data['blockPlacedStudents'] ?? true),
            'allowPlacedForSelectedDrives' => (bool) ($data['allowPlacedForSelectedDrives'] ?? false),
            'policyVersion'       => $data['policyVersion'] ?? 'v1.0',
            'active'              => (bool) ($data['active'] ?? true),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveActiveRule(array $data): array
    {
        $this->updateMany(['active' => true], ['active' => false]);
        $this->createRule(array_merge($data, ['active' => true, 'name' => 'Active Placement Rules']));
        return $this->getActiveRule() ?? [];
    }
}
