<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Recruitment results (selected/rejected) recorded by admin/PO.
 */
class RecruitmentResultModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::RECRUITMENT_RESULTS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filter = [], int $limit = 300): array
    {
        return $this->findAll($filter, $limit);
    }

    /**
     * Upsert by (registerNumber, company) as a natural key.
     *
     * @param array<string, mixed> $data
     */
    public function upsertByRegisterCompany(array $data): string
    {
        $register = strtoupper(trim((string) ($data['registerNumber'] ?? '')));
        $company = trim((string) ($data['company'] ?? ''));
        $role = trim((string) ($data['role'] ?? ''));
        if ($register === '' || $company === '' || $role === '') {
            throw new \InvalidArgumentException('registerNumber, company and role are required.');
        }

        $set = [
            'studentName'    => trim((string) ($data['studentName'] ?? '')),
            'registerNumber' => $register,
            'company'        => $company,
            'role'           => $role,
            'package'        => trim((string) ($data['package'] ?? '')),
            'status'         => ($data['status'] ?? 'selected') === 'rejected' ? 'rejected' : 'selected',
            'joiningDate'    => trim((string) ($data['joiningDate'] ?? '')),
            'classBatch'     => trim((string) ($data['classBatch'] ?? '')),
        ];

        if (!empty($data['departmentId'])) {
            $set['departmentId'] = Security::toObjectId((string) $data['departmentId']);
        }

        $existing = $this->collection->findOne(['registerNumber' => $register, 'company' => $company]);
        if ($existing) {
            $id = (string) ($existing['_id'] ?? '');
            $this->update($id, $set);
            return $id;
        }

        return $this->insert($set);
    }
}

