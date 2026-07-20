<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class AlumniJobPostModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::ALUMNI_JOB_POSTS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPost(string $ownerUserId, array $data, string $sourceType = 'alumni'): string
    {
        $source = strtolower(trim($sourceType)) === 'staff' ? 'staff' : 'alumni';
        $departmentId = Security::toObjectId(trim((string) ($data['departmentId'] ?? '')));
        $doc = [
            'ownerUserId'  => Security::toObjectId($ownerUserId),
            'sourceType'   => $source,
            'title'        => trim((string) ($data['title'] ?? '')),
            'company'      => trim((string) ($data['company'] ?? '')),
            'jobType'      => trim((string) ($data['type'] ?? $data['jobType'] ?? 'Full-time')),
            'package'      => trim((string) ($data['package'] ?? '')),
            'location'     => trim((string) ($data['location'] ?? '')),
            'description'  => trim((string) ($data['description'] ?? '')),
            'imageUrl'     => trim((string) ($data['imageUrl'] ?? '')),
            'posterUrl'    => trim((string) ($data['posterUrl'] ?? '')),
            'posterType'   => trim((string) ($data['posterType'] ?? '')),
            'status'       => self::normalizeStatus($data['status'] ?? 'pending'),
            'audience'     => self::normalizeAudience($data['audience'] ?? 'both'),
            'eligibility'  => is_array($data['eligibility'] ?? null) ? $data['eligibility'] : [],
            'departmentId' => $departmentId,
            'driveId'      => null,
            'approvedBy'   => null,
            'approvedAt'   => null,
            'rejectedReason' => '',
            'views'        => 0,
        ];
        if ($source === 'alumni') {
            $doc['alumniUserId'] = Security::toObjectId($ownerUserId);
        } else {
            $doc['staffUserId'] = Security::toObjectId($ownerUserId);
            $doc['alumniUserId'] = null;
        }
        return $this->insert($doc);
    }

    public static function normalizeStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));
        return in_array($value, ['pending', 'open', 'reviewing', 'closed', 'rejected'], true)
            ? $value
            : 'pending';
    }

    private static function normalizeAudience(mixed $audience): string
    {
        $value = strtolower(trim((string) $audience));
        return in_array($value, ['student', 'alumni', 'both'], true) ? $value : 'both';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOwner(string $ownerUserId): array
    {
        $id = Security::toObjectId($ownerUserId);
        if ($id === null) {
            return [];
        }
        $byOwner = $this->findAll(['ownerUserId' => $id], 300);
        $byAlumni = $this->findAll(['alumniUserId' => $id], 300);
        $merged = [];
        foreach (array_merge($byOwner, $byAlumni) as $row) {
            $key = (string) ($row['_id'] ?? '');
            if ($key === '' || isset($merged[$key])) {
                continue;
            }
            $merged[$key] = $row;
        }
        return array_values($merged);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAlumni(string $alumniUserId): array
    {
        return $this->findByOwner($alumniUserId);
    }

    public function countActiveByAlumni(string $alumniUserId): int
    {
        $id = Security::toObjectId($alumniUserId);
        if ($id === null) {
            return 0;
        }
        $rows = $this->findByOwner($alumniUserId);
        return count(array_filter(
            $rows,
            static fn (array $row): bool => in_array(
                strtolower((string) ($row['status'] ?? '')),
                ['pending', 'open', 'reviewing'],
                true
            )
        ));
    }

    public function sumViewsByAlumni(string $alumniUserId): int
    {
        $id = Security::toObjectId($alumniUserId);
        if ($id === null) {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.views')) AS UNSIGNED)), 0)
             FROM `{$this->table}`
             WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.alumniUserId')) = ?
                OR JSON_UNQUOTE(JSON_EXTRACT(payload, '$.ownerUserId')) = ?"
        );
        $stmt->execute([$id, $id]);
        return (int) $stmt->fetchColumn();
    }
}
