<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Student submissions for Tutorial practical activities.
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
            'startedAt' => $data['startedAt'] ?? DocumentHelper::now(),
            'submittedAt' => $data['submittedAt'] ?? ($status === 'SUBMITTED' ? DocumentHelper::now() : null),
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateSubmission(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $status = strtoupper(trim((string) ($data['status'] ?? ($existing['status'] ?? 'IN_PROGRESS'))));
        if (!in_array($status, ['IN_PROGRESS', 'SUBMITTED', 'RETURNED'], true)) {
            throw new \InvalidArgumentException('Invalid submission status.');
        }
        $payload = [
            'activityId' => (string) ($existing['activityId'] ?? ''),
            'tutorialId' => (string) ($existing['tutorialId'] ?? ''),
            'moduleId' => (string) ($existing['moduleId'] ?? ''),
            'studentId' => (string) ($existing['studentId'] ?? ''),
            'attemptNumber' => (int) ($existing['attemptNumber'] ?? 1),
            'pairKey' => (string) ($existing['pairKey'] ?? self::pairKey(
                (string) ($existing['studentId'] ?? ''),
                (string) ($existing['activityId'] ?? ''),
                (int) ($existing['attemptNumber'] ?? 1)
            )),
            'status' => $status,
            'payload' => array_key_exists('payload', $data) && is_array($data['payload'])
                ? $data['payload']
                : (is_array($existing['payload'] ?? null) ? $existing['payload'] : []),
            'autoResult' => array_key_exists('autoResult', $data)
                ? (is_array($data['autoResult']) ? $data['autoResult'] : null)
                : (is_array($existing['autoResult'] ?? null) ? $existing['autoResult'] : null),
            'activitySnapshot' => is_array($existing['activitySnapshot'] ?? null) ? $existing['activitySnapshot'] : [],
            'startedAt' => $existing['startedAt'] ?? DocumentHelper::now(),
            'submittedAt' => array_key_exists('submittedAt', $data)
                ? $data['submittedAt']
                : ($existing['submittedAt'] ?? ($status === 'SUBMITTED' ? DocumentHelper::now() : null)),
        ];
        $this->update($id, $payload);

        return $this->findById($id);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForStudent(string $studentId, string $activityId): array
    {
        if (!Security::isValidId($studentId) || !Security::isValidId($activityId)) {
            return [];
        }
        $rows = $this->findAll(['studentId' => $studentId, 'activityId' => $activityId], 50, 0, ['attemptNumber' => -1]);
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['attemptNumber'] ?? 0)) <=> ((int) ($a['attemptNumber'] ?? 0)));

        return $rows;
    }

    public function countByActivity(string $activityId): int
    {
        if (!Security::isValidId($activityId)) {
            return 0;
        }

        return $this->count(['activityId' => $activityId]);
    }

    public function countForStudent(string $studentId, string $activityId): int
    {
        return count($this->listForStudent($studentId, $activityId));
    }

    public function nextAttemptNumber(string $studentId, string $activityId): int
    {
        $max = 0;
        foreach ($this->listForStudent($studentId, $activityId) as $row) {
            $max = max($max, (int) ($row['attemptNumber'] ?? 0));
        }

        return $max + 1;
    }

    public function findInProgress(string $studentId, string $activityId): ?array
    {
        foreach ($this->listForStudent($studentId, $activityId) as $row) {
            if ((string) ($row['status'] ?? '') === 'IN_PROGRESS') {
                return $row;
            }
        }

        return null;
    }
}
