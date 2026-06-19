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
     * @return array<int, array<string, mixed>>
     */
    public function findByDepartmentId(string $departmentId, int $limit = 200): array
    {
        $oid = Security::toObjectId($departmentId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['departmentId' => $oid], $limit);
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

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(string $userId, array $data): bool
    {
        $profile = $this->findByUserId($userId);
        if (!$profile) {
            return false;
        }

        $update = [];
        if (isset($data['departmentId'])) {
            $update['departmentId'] = Security::toObjectId($data['departmentId']);
        }
        if (isset($data['designation'])) {
            $update['designation'] = $data['designation'];
        }
        if ($update === []) {
            return true;
        }

        return $this->update((string) $profile['_id'], $update);
    }

    public function deleteByUserId(string $userId): bool
    {
        $profile = $this->findByUserId($userId);
        if (!$profile) {
            return false;
        }
        return $this->delete((string) $profile['_id']);
    }
}
