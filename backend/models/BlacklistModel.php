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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function active(int $limit = 200): array
    {
        return $this->findAll(['removedAt' => null], $limit);
    }
}

