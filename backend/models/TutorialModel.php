<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIALS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `tutorials` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              category_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.categoryId\'))) STORED,
              created_by VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.createdBy\'))) STORED,
              status VARCHAR(16)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.status\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorials_category (category_id),
              KEY idx_tutorials_created_by (created_by),
              KEY idx_tutorials_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $payload = $this->validate($data);
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateTutorial(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByCategory(string $categoryId): array
    {
        if (!Security::isValidId($categoryId)) {
            return [];
        }

        return $this->findAll(['categoryId' => $categoryId], 500, 0, ['createdAt' => -1]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByCreator(string $createdBy): array
    {
        $createdBy = trim($createdBy);
        if ($createdBy === '') {
            return [];
        }

        return $this->findAll(['createdBy' => $createdBy], 500, 0, ['createdAt' => -1]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'published', 'unpublished'], true)) {
            return [];
        }

        return $this->findAll(['status' => $status], 500, 0, ['createdAt' => -1]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Tutorial title is required.');
        }
        $categoryId = trim((string) ($data['categoryId'] ?? ''));
        if (!Security::isValidId($categoryId) || (new TutorialCategoryModel())->findById($categoryId) === null) {
            throw new \InvalidArgumentException('Tutorial categoryId is required.');
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'draft')));
        if (!in_array($status, ['draft', 'published', 'unpublished'], true)) {
            throw new \InvalidArgumentException('Tutorial status must be draft, published, or unpublished.');
        }
        $visibility = strtolower(trim((string) ($data['visibility'] ?? 'all')));
        if (!in_array($visibility, ['all', 'scoped'], true)) {
            throw new \InvalidArgumentException('Tutorial visibility must be all or scoped.');
        }
        $createdBy = trim((string) ($data['createdBy'] ?? ''));
        if ($createdBy !== '' && !Security::isValidId($createdBy)) {
            throw new \InvalidArgumentException('Tutorial createdBy must be a user id.');
        }

        $departmentIds = [];
        $passingYears = [];
        if ($visibility === 'scoped') {
            foreach ((array) ($data['departmentIds'] ?? []) as $departmentId) {
                $departmentId = trim((string) $departmentId);
                if ($departmentId === '') {
                    continue;
                }
                if (!Security::isValidId($departmentId)) {
                    throw new \InvalidArgumentException('Tutorial departmentIds must be department ids.');
                }
                $departmentIds[$departmentId] = $departmentId;
            }
            foreach ((array) ($data['passingYears'] ?? []) as $year) {
                $year = trim((string) $year);
                if ($year === '') {
                    continue;
                }
                if (preg_match('/^(19|20)\d{2}$/', $year) !== 1) {
                    throw new \InvalidArgumentException('Tutorial passing years must be four-digit years.');
                }
                $passingYears[$year] = $year;
            }
        }

        return [
            'title' => $title,
            'categoryId' => $categoryId,
            'topic' => trim((string) ($data['topic'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'thumbnail' => trim((string) ($data['thumbnail'] ?? '')),
            'status' => $status,
            'visibility' => $visibility,
            'departmentIds' => array_values($departmentIds),
            'passingYears' => array_values($passingYears),
            'createdBy' => $createdBy,
        ];
    }
}
