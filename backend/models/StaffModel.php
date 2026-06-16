<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class StaffModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::STAFF;
    }

    public function findByUserId(string $userId): ?array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return null;
        }
        $doc = $this->collection->findOne(['userId' => $oid]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProfile(string $userId, array $data): string
    {
        $doc = [
            'userId'       => Security::toObjectId($userId),
            'departmentId' => isset($data['departmentId']) ? Security::toObjectId($data['departmentId']) : null,
            'designation'  => $data['designation'] ?? 'Staff',
        ];
        return $this->insert($doc);
    }
}
