<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

class StudentTutorialModuleProgressModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_TUTORIAL_MODULE_PROGRESS;
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
            'CREATE TABLE IF NOT EXISTS `student_tutorial_module_progress` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              pair_key VARCHAR(64)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_student_tutorial_module (pair_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $studentId, string $moduleId): string
    {
        return $studentId . ':' . $moduleId;
    }

    public function findFor(string $studentId, string $moduleId): ?array
    {
        return $this->findOne(['pairKey' => self::pairKey($studentId, $moduleId)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForTutorial(string $studentId, string $tutorialId): array
    {
        return $this->findAll(['studentId' => $studentId, 'tutorialId' => $tutorialId], 500);
    }

    /**
     * @return array<string, mixed>
     */
    public function markComplete(string $studentId, string $tutorialId, string $moduleId): array
    {
        $existing = $this->findFor($studentId, $moduleId);
        if ($existing !== null) {
            return $existing;
        }
        $payload = [
            'studentId' => $studentId,
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'pairKey' => self::pairKey($studentId, $moduleId),
            'completedAt' => DocumentHelper::now(),
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? $payload;
    }

    public function clearComplete(string $studentId, string $moduleId): bool
    {
        $existing = $this->findFor($studentId, $moduleId);
        if ($existing === null) {
            return false;
        }

        return $this->delete((string) ($existing['_id'] ?? ''));
    }
}
