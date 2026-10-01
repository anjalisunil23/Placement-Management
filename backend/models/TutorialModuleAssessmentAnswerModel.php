<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialModuleAssessmentAnswerModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ASSESSMENT_ANSWERS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_assessment_answers` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              attempt_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.attemptId\'))) STORED,
              question_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.questionId\'))) STORED,
              pair_key VARCHAR(72)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_module_assessment_answer (pair_key),
              KEY idx_tutorial_module_assessment_answers_attempt (attempt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $attemptId, string $questionId): string
    {
        return $attemptId . ':' . $questionId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAnswer(array $data): array
    {
        $attemptId = trim((string) ($data['attemptId'] ?? ''));
        $questionId = trim((string) ($data['questionId'] ?? ''));
        if (!Security::isValidId($attemptId) || !Security::isValidId($questionId)) {
            throw new \InvalidArgumentException('Answer attemptId and questionId are required.');
        }
        $selectedIndex = (int) ($data['selectedIndex'] ?? -1);
        if ($selectedIndex < 0 || $selectedIndex > 3) {
            throw new \InvalidArgumentException('Selected option must be between 0 and 3.');
        }
        $payload = [
            'attemptId' => $attemptId,
            'questionId' => $questionId,
            'pairKey' => self::pairKey($attemptId, $questionId),
            'selectedIndex' => $selectedIndex,
            'isCorrect' => ($data['isCorrect'] ?? false) === true,
            'marksAwarded' => max(0, (int) ($data['marksAwarded'] ?? 0)),
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByAttempt(string $attemptId): array
    {
        if (!Security::isValidId($attemptId)) {
            return [];
        }

        return $this->findAll(['attemptId' => $attemptId], 200, 0, ['createdAt' => 1]);
    }
}
