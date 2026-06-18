<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Generated report metadata stored in MongoDB.
 */
class ReportModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::REPORTS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): string
    {
        return $this->insert([
            'type'          => $data['type'] ?? 'unknown',
            'title'         => $data['title'] ?? '',
            'filename'      => $data['filename'] ?? '',
            'path'          => $data['path'] ?? '',
            'format'        => $data['format'] ?? 'pdf',
            'generatedBy'   => isset($data['generatedBy']) ? Security::toObjectId((string) $data['generatedBy']) : null,
            'departmentId'  => isset($data['departmentId']) ? Security::toObjectId((string) $data['departmentId']) : null,
            'filters'       => $data['filters'] ?? [],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(?string $departmentId = null, int $limit = 50): array
    {
        $filter = [];
        if ($departmentId !== null && $departmentId !== '') {
            $oid = Security::toObjectId($departmentId);
            if ($oid) {
                $filter['departmentId'] = $oid;
            }
        }

        $cursor = $this->collection->find(
            $filter,
            ['sort' => ['createdAt' => -1], 'limit' => $limit]
        );

        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = DocumentHelper::serialize((array) $doc) ?? [];
        }
        return $rows;
    }
}
