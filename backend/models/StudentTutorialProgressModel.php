<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

class StudentTutorialProgressModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_TUTORIAL_PROGRESS;
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
            'CREATE TABLE IF NOT EXISTS `student_tutorial_progress` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              pair_key VARCHAR(64)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_student_tutorial_progress (pair_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $studentId, string $tutorialId): string
    {
        return $studentId . ':' . $tutorialId;
    }

    public function findFor(string $studentId, string $tutorialId): ?array
    {
        return $this->findOne(['pairKey' => self::pairKey($studentId, $tutorialId)]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveFor(string $studentId, string $tutorialId, array $data): array
    {
        $existing = $this->findFor($studentId, $tutorialId);
        $payload = array_merge($data, [
            'studentId' => $studentId,
            'tutorialId' => $tutorialId,
            'pairKey' => self::pairKey($studentId, $tutorialId),
        ]);
        if ($existing === null) {
            if (!isset($payload['startedAt'])) {
                $payload['startedAt'] = DocumentHelper::now();
            }
            $id = $this->insert($payload);

            return $this->findById($id) ?? $payload;
        }
        $this->update((string) $existing['_id'], $payload);

        return $this->findById((string) $existing['_id']) ?? $payload;
    }
}
