<?php

declare(strict_types=1);

namespace PMS\Models;

use MongoDB\BSON\ObjectId;
use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Student profile model.
 */
class StudentModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::STUDENTS;
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

    public function findByRegisterNumber(string $registerNumber): ?array
    {
        $doc = $this->collection->findOne(['registerNumber' => strtoupper(trim($registerNumber))]);
        return $doc ? (array) $doc : null;
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
     * @param array<string, mixed> $data
     */
    public function createProfile(string $userId, array $data): string
    {
        $userOid = Security::toObjectId($userId);
        $deptOid = Security::toObjectId($data['departmentId']);

        $doc = [
            'userId'         => $userOid,
            'registerNumber' => strtoupper(trim($data['registerNumber'])),
            'departmentId'   => $deptOid,
            'classBatch'     => $data['classBatch'] ?? '',
            'personal'       => $data['personal'] ?? [],
            'academic'       => array_merge([
                'ugMarks'   => 0.0,
                'mcaMarks'  => 0.0,
                'cgpa'      => 0.0,
                'backlogs'  => 0,
                'semesters' => [],
            ], $data['academic'] ?? []),
            'certifications'   => [],
            'resume'           => null,
            'policyAccepted'   => false,
            'signedReport'     => null,
            'placementChances' => ['used' => 0, 'remaining' => 3],
            'placed'           => false,
            'placementHistory' => [],
        ];
        return $this->insert($doc);
    }

    /**
     * @param array<string, mixed> $filter CGPA, department, skills, backlogs
     * @return array<int, array<string, mixed>>
     */
    public function filterStudents(array $filter, int $limit = 100): array
    {
        $query = [];

        if (isset($filter['minCgpa'])) {
            $query['academic.cgpa'] = ['$gte' => (float) $filter['minCgpa']];
        }
        if (isset($filter['maxBacklogs'])) {
            $query['academic.backlogs'] = ['$lte' => (int) $filter['maxBacklogs']];
        }
        if (!empty($filter['departmentId'])) {
            $oid = Security::toObjectId($filter['departmentId']);
            if ($oid) {
                $query['departmentId'] = $oid;
            }
        }
        if (!empty($filter['skills'])) {
            $skills = is_array($filter['skills']) ? $filter['skills'] : explode(',', (string) $filter['skills']);
            $skillConditions = [];
            foreach ($skills as $skill) {
                $skill = trim($skill);
                if ($skill === '') {
                    continue;
                }
                $regex = new \MongoDB\BSON\Regex(preg_quote($skill), 'i');
                $skillConditions[] = [
                    '$or' => [
                        ['academic.skills' => $regex],
                        ['certifications.name' => $regex],
                    ],
                ];
            }
            if (!empty($skillConditions)) {
                $query['$and'] = array_merge($query['$and'] ?? [], $skillConditions);
            }
        }

        return $this->findAll($query, $limit);
    }
}
