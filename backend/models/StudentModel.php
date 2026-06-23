<?php

declare(strict_types=1);

namespace PMS\Models;

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
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return null;
        }
        return $this->findOne(['userId' => $id]);
    }

    public function findByRegisterNumber(string $registerNumber): ?array
    {
        return $this->findOne(['registerNumber' => strtoupper(trim($registerNumber))]);
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
        $userId = Security::toObjectId($userId);
        $deptId = !empty($data['departmentId']) ? Security::toObjectId($data['departmentId']) : null;

        $doc = [
            'userId'         => $userId,
            'registerNumber' => strtoupper(trim($data['registerNumber'])),
            'departmentId'   => $deptId,
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
            $id = Security::toObjectId($filter['departmentId']);
            if ($id) {
                $query['departmentId'] = $id;
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
                $skillConditions[] = [
                    '$or' => [
                        ['academic.skills' => ['$regex' => '%' . $skill . '%']],
                        ['certifications.name' => ['$regex' => '%' . $skill . '%']],
                    ],
                ];
            }
            if (!empty($skillConditions)) {
                $query['$and'] = array_merge($query['$and'] ?? [], $skillConditions);
            }
        }

        return $this->findAll($query, $limit);
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

        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];

        return [
            'studentId'      => (string) ($profile['_id'] ?? ''),
            'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
            'departmentId'   => (string) ($profile['departmentId'] ?? ''),
            'department'     => $department
                ? (string) ($department['code'] ?? $department['name'] ?? '')
                : '',
            'departmentName' => $department ? (string) ($department['name'] ?? '') : '',
            'classBatch'     => (string) ($profile['classBatch'] ?? ''),
            'cgpa'           => (float) ($academic['cgpa'] ?? 0),
            'backlogs'       => (int) ($academic['backlogs'] ?? 0),
            'placed'         => (bool) ($profile['placed'] ?? false),
            'phone'          => (string) ($personal['phone'] ?? ''),
            'course'         => (string) ($personal['course'] ?? ''),
            'year'           => (string) ($personal['year'] ?? ''),
            'semester'       => (string) ($personal['semester'] ?? ''),
            'gender'         => (string) ($personal['gender'] ?? ''),
            'bloodGroup'     => (string) ($personal['bloodGroup'] ?? ''),
            'address'        => (string) ($personal['address'] ?? ''),
            'parentName'     => (string) ($personal['parentName'] ?? ''),
            'dob'            => (string) ($personal['dob'] ?? ''),
            'aadhar'         => (string) ($personal['aadhar'] ?? ''),
        ];
    }
}
