<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class AlumniModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::ALUMNI;
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
            'userId'     => Security::toObjectId($userId),
            'company'    => $data['company'] ?? '',
            'role'       => $data['role'] ?? '',
            'experience' => (int) ($data['experience'] ?? 0),
            'skills'     => $data['skills'] ?? [],
        ];
        return $this->insert($doc);
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
