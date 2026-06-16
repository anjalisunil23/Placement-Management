<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class JobModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::JOBS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createJob(array $data): string
    {
        $doc = [
            'companyId'   => Security::toObjectId($data['companyId']),
            'driveId'     => isset($data['driveId']) ? Security::toObjectId($data['driveId']) : null,
            'title'       => $data['title'],
            'description' => $data['description'] ?? '',
            'jdFile'      => $data['jdFile'] ?? null,
            'eligibility' => $data['eligibility'] ?? [],
            'package'     => $data['package'] ?? '',
            'location'    => $data['location'] ?? '',
        ];
        return $this->insert($doc);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByCompany(string $companyId): array
    {
        $oid = Security::toObjectId($companyId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['companyId' => $oid]);
    }
}
