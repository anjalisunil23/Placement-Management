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
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return null;
        }
        return $this->findOne(['userId' => $id]);
    }

    public function findByDepartment(string $departmentId): ?array
    {
        $id = Security::toObjectId($departmentId);
        if ($id === null) {
            return null;
        }
        return $this->findOne(['departmentId' => $id]);
    }

    public function deleteByUserId(string $userId): bool
    {
        return $this->deleteMany(['userId' => Security::toObjectId($userId)]) > 0;
    }

    public function deleteByDepartment(string $departmentId): bool
    {
        return $this->deleteMany(['departmentId' => Security::toObjectId($departmentId)]) > 0;
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
        if ($this->findByUserId($userId)) {
            throw new \RuntimeException('This user is already assigned as a placement officer.');
        }

        $deptId = !empty($data['departmentId']) ? Security::toObjectId($data['departmentId']) : null;
        if ($deptId !== null) {
            if ($this->findByDepartment((string) $deptId)) {
                throw new \RuntimeException('This department already has a placement officer. Each department can have only one.');
            }
        }

        return $this->insert([
            'userId'       => Security::toObjectId($userId),
            'departmentId' => $deptId,
            'designation'  => $data['designation'] ?? '',
        ]);
    }
}
