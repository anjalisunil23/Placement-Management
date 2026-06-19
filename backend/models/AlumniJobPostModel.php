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
    public function createPost(string $alumniUserId, array $data): string
    {
        return $this->insert([
            'alumniUserId' => Security::toObjectId($alumniUserId),
            'title'        => trim((string) ($data['title'] ?? '')),
            'company'      => trim((string) ($data['company'] ?? '')),
            'jobType'      => trim((string) ($data['type'] ?? $data['jobType'] ?? 'Full-time')),
            'package'      => trim((string) ($data['package'] ?? '')),
            'location'     => trim((string) ($data['location'] ?? '')),
            'description'  => trim((string) ($data['description'] ?? '')),
            'status'       => self::normalizeStatus($data['status'] ?? 'open'),
            'views'        => 0,
        ]);
    }

    public static function normalizeStatus(mixed $status): string
    {
        $value = strtolower(trim((string) $status));
        return in_array($value, ['open', 'reviewing', 'closed'], true) ? $value : 'open';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAlumni(string $alumniUserId): array
    {
        $id = Security::toObjectId($alumniUserId);
        if ($id === null) {
            return [];
        }
        return $this->findAll(['alumniUserId' => $id]);
    }

    public function countActiveByAlumni(string $alumniUserId): int
    {
        $id = Security::toObjectId($alumniUserId);
        if ($id === null) {
            return 0;
        }
        return $this->count(['alumniUserId' => $id, 'status' => ['$in' => ['open', 'reviewing']]]);
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
             WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.alumniUserId')) = ?"
        );
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }
}
