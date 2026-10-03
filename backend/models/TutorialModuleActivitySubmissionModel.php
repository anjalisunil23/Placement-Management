<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Student submissions for Tutorial practical activities (foundation; workflows in later phases).
 */
class TutorialModuleActivitySubmissionModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ACTIVITY_SUBMISSIONS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_activity_submissions` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              activity_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.activityId\'))) STORED,
              student_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
              pair_key VARCHAR(96)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_module_activity_submission (pair_key),
              KEY idx_tutorial_module_activity_submissions_activity (activity_id, student_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $studentId, string $activityId, int $attemptNumber): string
    {
        return $studentId . ':' . $activityId . ':' . $attemptNumber;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSubmission(array $data): array
    {
        $studentId = trim((string) ($data['studentId'] ?? ''));
        $activityId = trim((string) ($data['activityId'] ?? ''));
        $tutorialId = trim((string) ($data['tutorialId'] ?? ''));
        $moduleId = trim((string) ($data['moduleId'] ?? ''));
        $attemptNumber = (int) ($data['attemptNumber'] ?? 0);
        if (!Security::isValidId($studentId) || !Security::isValidId($activityId) || !Security::isValidId($tutorialId) || !Security::isValidId($moduleId)) {
            throw new \InvalidArgumentException('Submission references are required.');
        }
        if ($attemptNumber < 1) {
            throw new \InvalidArgumentException('Attempt number is required.');
        }
        $status = strtoupper(trim((string) ($data['status'] ?? 'IN_PROGRESS')));
        if (!in_array($status, ['IN_PROGRESS', 'SUBMITTED', 'RETURNED'], true)) {
            throw new \InvalidArgumentException('Invalid submission status.');
        }
        $payload = [
            'activityId' => $activityId,
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'studentId' => $studentId,
            'attemptNumber' => $attemptNumber,
            'pairKey' => self::pairKey($studentId, $activityId, $attemptNumber),
            'status' => $status,
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
            'autoResult' => is_array($data['autoResult'] ?? null) ? $data['autoResult'] : null,
            'activitySnapshot' => is_array($data['activitySnapshot'] ?? null) ? $data['activitySnapshot'] : [],
            'submittedAt' => $data['submittedAt'] ?? ($status === 'SUBMITTED' ? DocumentHelper::now() : null),
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByActivity(string $activityId): array
    {
        if (!Security::isValidId($activityId)) {
            return [];
        }

        return $this->findAll(['activityId' => $activityId], 500, 0, ['createdAt' => -1]);
    }

    public function countByActivity(string $activityId): int
    {
        if (!Security::isValidId($activityId)) {
            return 0;
        }

        return $this->count(['activityId' => $activityId]);
    }
}
