<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

final class ResumeModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RESUMES;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStudent(string $studentId, int $limit = 50): array
    {
        $oid = Security::toObjectId($studentId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['studentId' => $oid], $limit);
    }

    public function setDefault(string $studentId, string $resumeId): void
    {
        $sOid = Security::toObjectId($studentId);
        $rOid = Security::toObjectId($resumeId);
        if ($sOid === null || $rOid === null) {
            return;
        }
        $this->collection->updateMany(['studentId' => $sOid], ['$set' => ['isDefault' => false, 'updatedAt' => DocumentHelper::now()]]);
        $this->collection->updateOne(['_id' => $rOid, 'studentId' => $sOid], ['$set' => ['isDefault' => true, 'updatedAt' => DocumentHelper::now()]]);
    }
}

