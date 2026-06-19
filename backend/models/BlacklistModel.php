<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Student blacklist records.
 */
class BlacklistModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::BLACKLIST;
    }

    public function isBlacklisted(string $studentId): bool
    {
        $id = Security::toObjectId($studentId);
        if ($id === null) {
            return false;
        }
        return $this->findOne([
            'studentId' => $id,
            'removedAt' => null,
        ]) !== null;
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
        $id = Security::toObjectId($studentId);
        if ($id === null) {
            return false;
        }
        return $this->updateMany(
            ['studentId' => $id, 'removedAt' => null],
            ['removedAt' => DocumentHelper::now()]
        ) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function active(int $limit = 200): array
    {
        return $this->findAll(['removedAt' => null], $limit);
    }
}
