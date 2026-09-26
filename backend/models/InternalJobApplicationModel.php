<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

class InternalJobApplicationModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::INTERNAL_JOB_APPLICATIONS;
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
            'CREATE TABLE IF NOT EXISTS `internal_job_applications` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public function findByPostAndStudent(string $postId, string $studentUserId): ?array
    {
        $postId = trim($postId);
        $studentUserId = trim($studentUserId);
        if ($postId === '' || $studentUserId === '') {
            return null;
        }

        return $this->findOne([
            'postId' => $postId,
            'studentUserId' => $studentUserId,
            'status' => 'applied',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStudent(string $studentUserId): array
    {
        $studentUserId = trim($studentUserId);
        if ($studentUserId === '') {
            return [];
        }

        return $this->findAll(['studentUserId' => $studentUserId, 'status' => 'applied'], 500);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByPost(string $postId): array
    {
        $postId = trim($postId);
        if ($postId === '') {
            return [];
        }

        return $this->findAll(['postId' => $postId, 'status' => 'applied'], 500, 0, ['createdAt' => -1]);
    }

    public function countForPost(string $postId): int
    {
        $postId = trim($postId);
        if ($postId === '') {
            return 0;
        }

        return $this->count(['postId' => $postId, 'status' => 'applied']);
    }
}
