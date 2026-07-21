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
        $departmentId = trim((string) ($data['departmentId'] ?? ''));
        $departmentIds = $data['departmentIds'] ?? [];
        if (!is_array($departmentIds)) {
            $departmentIds = [];
        }
        $doc = [
            'companyId'   => Security::toObjectId($data['companyId']),
            'ownerUserId' => isset($data['ownerUserId']) ? Security::toObjectId($data['ownerUserId']) : null,
            'sourceType'  => 'company',
            'companyName' => trim((string) ($data['companyName'] ?? '')),
            'departmentId'=> $departmentId !== '' ? Security::toObjectId($departmentId) : null,
            'departmentIds'=> array_values(array_filter(array_map(
                static fn (mixed $value): ?object => Security::toObjectId((string) $value),
                $departmentIds
            ))),
            'driveId'     => isset($data['driveId']) ? Security::toObjectId($data['driveId']) : null,
            'title'       => $data['title'],
            'description' => $data['description'] ?? '',
            'jdFile'      => $data['jdFile'] ?? null,
            'imageUrl'    => trim((string) ($data['imageUrl'] ?? '')),
            'posterUrl'   => trim((string) ($data['posterUrl'] ?? '')),
            'posterType'  => trim((string) ($data['posterType'] ?? '')),
            'eligibility' => $data['eligibility'] ?? [],
            'package'     => $data['package'] ?? '',
            'location'    => $data['location'] ?? '',
            'jobType'     => $data['jobType'] ?? $data['type'] ?? 'Full-time',
            'status'      => $data['status'] ?? 'open',
            'audience'    => self::normalizeAudience($data['audience'] ?? 'both'),
            'openings'    => (int) ($data['openings'] ?? 0),
        ];
        return $this->insert($doc);
    }

    public function updateJob(string $id, array $data): bool
    {
        $allowed = ['title', 'description', 'package', 'location', 'eligibility', 'status', 'jobType', 'openings', 'audience', 'departmentId', 'departmentIds'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) {
            return false;
        }
        return $this->update($id, $update);
    }

    private static function normalizeAudience(mixed $audience): string
    {
        $value = strtolower(trim((string) $audience));
        return in_array($value, ['student', 'alumni', 'both'], true) ? $value : 'both';
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
