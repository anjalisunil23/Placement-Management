<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Department-wise placement officer profiles.
 */
class PlacementOfficerModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::PLACEMENT_OFFICERS;
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

    public function findByDepartment(string $departmentId): ?array
    {
        $oid = Security::toObjectId($departmentId);
        if ($oid === null) {
            return null;
        }
        $doc = $this->collection->findOne(['departmentId' => $oid]);
        return $doc ? (array) $doc : null;
    }

    public function deleteByUserId(string $userId): bool
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return false;
        }
        $result = $this->collection->deleteOne(['userId' => $oid]);
        return $result->getDeletedCount() > 0;
    }

    /**
     * @return string[] Department IDs that already have an assigned officer
     */
    public function assignedDepartmentIds(): array
    {
        $ids = [];
        foreach ($this->findAll([], 500) as $row) {
            if (!empty($row['departmentId'])) {
                $ids[] = (string) $row['departmentId'];
            }
        }
        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(): array
    {
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $rows = [];

        foreach ($this->findAll([], 200) as $profile) {
            $userId = (string) ($profile['userId'] ?? '');
            $deptId = (string) ($profile['departmentId'] ?? '');
            $user = $userId ? $userModel->findById($userId) : null;
            $dept = $deptId ? $deptModel->findById($deptId) : null;

            $rows[] = [
                'profileId'    => (string) $profile['_id'],
                'userId'       => $userId,
                'departmentId' => $deptId,
                'designation'  => $profile['designation'] ?? '',
                'officerName'  => $user['name'] ?? '',
                'officerEmail' => $user['email'] ?? '',
                'department'   => $dept ? [
                    'name' => $dept['name'] ?? '',
                    'code' => $dept['code'] ?? '',
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProfile(string $userId, array $data): string
    {
        $deptOid = Security::toObjectId($data['departmentId'] ?? '');
        if ($deptOid === null) {
            throw new \InvalidArgumentException('Valid departmentId is required.');
        }

        if ($this->findByDepartment((string) $deptOid)) {
            throw new \RuntimeException('This department already has a placement officer. Each department can have only one.');
        }

        if ($this->findByUserId($userId)) {
            throw new \RuntimeException('This user is already assigned as a placement officer.');
        }

        return $this->insert([
            'userId'       => Security::toObjectId($userId),
            'departmentId' => $deptOid,
            'designation'  => $data['designation'] ?? 'Department Placement Officer',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithDepartments(int $limit = 100): array
    {
        return $this->findAll([], $limit);
    }
}
