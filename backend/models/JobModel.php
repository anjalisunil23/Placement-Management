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
            'jobType'     => $data['jobType'] ?? $data['type'] ?? 'Full-time',
            'status'      => $data['status'] ?? 'open',
            'openings'    => (int) ($data['openings'] ?? 0),
        ];
        return $this->insert($doc);
    }

    public function updateJob(string $id, array $data): bool
    {
        $allowed = ['title', 'description', 'package', 'location', 'eligibility', 'status', 'jobType', 'openings'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) {
            return false;
        }
        return $this->update($id, $update);
    }

    public function countApplicantsByJob(string $companyId): array
    {
        $jobs = $this->findByCompany($companyId);
        $appModel = new ApplicationModel();
        $counts = [];
        foreach ($jobs as $job) {
            $jobId = (string) $job['_id'];
            $counts[$jobId] = $appModel->count(['jobId' => $job['_id']]);
        }
        return $counts;
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
