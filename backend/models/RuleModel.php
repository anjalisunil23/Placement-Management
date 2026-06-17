<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class RuleModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RULES;
    }

    public function getActiveRule(): ?array
    {
        $doc = $this->collection->findOne(['active' => true], ['sort' => ['createdAt' => -1]]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRule(array $data): string
    {
        // Deactivate other rules if this one is active
        if (!empty($data['active'])) {
            $this->collection->updateMany(['active' => true], ['$set' => ['active' => false]]);
        }
        return $this->insert([
            'name'                => $data['name'],
            'minCgpa'             => (float) ($data['minCgpa'] ?? 0),
            'maxBacklogs'         => (int) ($data['maxBacklogs'] ?? 0),
            'placementChances'    => (int) ($data['placementChances'] ?? 3),
            'eligibilityCriteria' => $data['eligibilityCriteria'] ?? '',
            'tierRules'           => $data['tierRules'] ?? [
                'Tier 1' => ['chances' => 1],
                'Tier 2' => ['chances' => 1],
                'Tier 3' => ['chances' => 1],
            ],
            'active' => (bool) ($data['active'] ?? true),
        ]);
    }
}

class BlacklistModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::BLACKLIST;
    }

    public function isBlacklisted(string $studentId): bool
    {
        $oid = Security::toObjectId($studentId);
        if ($oid === null) {
            return false;
        }
        $doc = $this->collection->findOne([
            'studentId' => $oid,
            'removedAt' => null,
        ]);
        return $doc !== null;
    }

    public function blacklist(string $studentId, string $reason, string $by): string
    {
        return $this->insert([
            'studentId'     => Security::toObjectId($studentId),
            'reason'        => $reason,
            'blacklistedBy' => Security::toObjectId($by),
            'removedAt'     => null,
        ]);
    }

    public function removeBlacklist(string $studentId): bool
    {
        $oid = Security::toObjectId($studentId);
        if ($oid === null) {
            return false;
        }
        $result = $this->collection->updateOne(
            ['studentId' => $oid, 'removedAt' => null],
            ['$set' => ['removedAt' => DocumentHelper::now()]]
        );
        return $result->getModifiedCount() > 0;
    }
}

class RecommendationModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RECOMMENDATIONS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRecommendation(string $staffId, array $data): string
    {
        return $this->insert([
            'staffId'     => Security::toObjectId($staffId),
            'companyName' => $data['companyName'],
            'category'    => $data['category'],
            'reason'      => $data['reason'],
            'contact'     => $data['contact'],
        ]);
    }
}
