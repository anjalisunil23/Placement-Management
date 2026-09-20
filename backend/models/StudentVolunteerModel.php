<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Department placement representative assignments (students nominated by placement officers).
 */
class StudentVolunteerModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::STUDENT_VOLUNTEERS;
    }

    public function findActiveByStudentId(string $studentId): ?array
    {
        $id = Security::toObjectId($studentId);
        if ($id === null) {
            return null;
        }
        return $this->findOne([
            'studentId' => $id,
            'status'    => 'active',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(array $filter = [], int $limit = 500): array
    {
        return $this->findAll(array_merge(['status' => 'active'], $filter), $limit);
    }
}
