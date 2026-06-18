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
            'staffId'     => (string) ($profile['_id'] ?? ''),
            'department'  => (string) ($department['code'] ?? ''),
            'departmentId'=> (string) ($profile['departmentId'] ?? ''),
            'designation' => (string) ($profile['designation'] ?? ''),
        ];
    }
}
