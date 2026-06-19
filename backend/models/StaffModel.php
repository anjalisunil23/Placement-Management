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
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return null;
        }
        return $this->findOne(['userId' => $id]);
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
            'phone'        => $data['phone'] ?? '',
        ];
        return $this->insert($doc);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateProfile(string $id, array $data): bool
    {
        $allowed = ['departmentId', 'designation', 'phone'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (isset($update['departmentId'])) {
            $update['departmentId'] = Security::toObjectId((string) $update['departmentId']);
        }
        if ($update === []) {
            return false;
        }
        return $this->update($id, $update);
    }

    public function updateProfileByUserId(string $userId, array $data): bool
    {
        $profile = $this->findByUserId($userId);
        if (!$profile) {
            return false;
        }
        return $this->updateProfile((string) $profile['_id'], $data);
    }

    public function deleteByUserId(string $userId): bool
    {
        $profile = $this->findByUserId($userId);
        if (!$profile) {
            return false;
        }
        return $this->delete((string) $profile['_id']);
    }

    /**
     * @param array<string, mixed>|null $profile
     * @param array<string, mixed>|null $department
     * @return array<string, mixed>
     */
    public static function profileToUserFields(?array $profile, ?array $department = null): array
    {
        if ($profile === null) {
            return [];
        }
        return [
            'staffId'      => (string) ($profile['_id'] ?? ''),
            'department'   => (string) ($department['code'] ?? ''),
            'departmentId' => (string) ($profile['departmentId'] ?? ''),
            'designation'  => (string) ($profile['designation'] ?? ''),
        ];
    }
}
