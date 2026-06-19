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
        $id = Security::toObjectId($studentId);
        if ($id === null) {
            return [];
        }
        return $this->findAll(['studentId' => $id], $limit);
    }

    public function setDefault(string $studentId, string $resumeId): void
    {
        $sId = Security::toObjectId($studentId);
        $rId = Security::toObjectId($resumeId);
        if ($sId === null || $rId === null) {
            return;
        }
        $this->updateMany(['studentId' => $sId], ['isDefault' => false, 'updatedAt' => DocumentHelper::now()]);
        $resume = $this->findById($rId);
        if ($resume && (string) ($resume['studentId'] ?? '') === $sId) {
            $this->update($rId, ['isDefault' => true, 'updatedAt' => DocumentHelper::now()]);
        }
    }
}
