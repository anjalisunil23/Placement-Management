<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class TutorialModuleAssessmentAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_ASSESSMENT_ATTEMPTS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_assessment_attempts` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              student_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
              assessment_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.assessmentId\'))) STORED,
              pair_key VARCHAR(96)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.pairKey\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              UNIQUE KEY uniq_tutorial_module_assessment_attempt (pair_key),
              KEY idx_tutorial_module_assessment_attempts_student (student_id, assessment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function pairKey(string $studentId, string $assessmentId, int $attemptNumber): string
    {
        return $studentId . ':' . $assessmentId . ':' . $attemptNumber;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAttempt(array $data): array
    {
        $studentId = trim((string) ($data['studentId'] ?? ''));
        $assessmentId = trim((string) ($data['assessmentId'] ?? ''));
        $tutorialId = trim((string) ($data['tutorialId'] ?? ''));
        $moduleId = trim((string) ($data['moduleId'] ?? ''));
        $attemptNumber = (int) ($data['attemptNumber'] ?? 0);
        if (!Security::isValidId($studentId) || !Security::isValidId($assessmentId) || !Security::isValidId($tutorialId) || !Security::isValidId($moduleId)) {
            throw new \InvalidArgumentException('Attempt references are required.');
        }
        if ($attemptNumber < 1) {
            throw new \InvalidArgumentException('Attempt number is required.');
        }
        $payload = [
            'studentId' => $studentId,
            'assessmentId' => $assessmentId,
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'attemptNumber' => $attemptNumber,
            'pairKey' => self::pairKey($studentId, $assessmentId, $attemptNumber),
            'score' => 0,
            'totalMarks' => (int) ($data['totalMarks'] ?? 0),
            'percent' => 0,
            'passed' => false,
            'status' => 'IN_PROGRESS',
            'startedAt' => DocumentHelper::now(),
            'submittedAt' => null,
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function finalize(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = array_merge($existing, [
            'score' => (int) ($data['score'] ?? 0),
            'totalMarks' => (int) ($data['totalMarks'] ?? ($existing['totalMarks'] ?? 0)),
            'percent' => (int) ($data['percent'] ?? 0),
            'passed' => ($data['passed'] ?? false) === true,
            'status' => 'SUBMITTED',
            'submittedAt' => DocumentHelper::now(),
            'pairKey' => (string) ($existing['pairKey'] ?? ''),
            'studentId' => (string) ($existing['studentId'] ?? ''),
            'assessmentId' => (string) ($existing['assessmentId'] ?? ''),
            'tutorialId' => (string) ($existing['tutorialId'] ?? ''),
            'moduleId' => (string) ($existing['moduleId'] ?? ''),
            'attemptNumber' => (int) ($existing['attemptNumber'] ?? 1),
            'startedAt' => (string) ($existing['startedAt'] ?? DocumentHelper::now()),
        ]);
        unset($payload['_id'], $payload['createdAt'], $payload['updatedAt']);
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForStudent(string $studentId, string $assessmentId): array
    {
        if (!Security::isValidId($studentId) || !Security::isValidId($assessmentId)) {
            return [];
        }
        $rows = $this->findAll(['studentId' => $studentId, 'assessmentId' => $assessmentId], 50, 0, ['attemptNumber' => -1]);
        usort($rows, static fn (array $a, array $b): int => ((int) ($b['attemptNumber'] ?? 0)) <=> ((int) ($a['attemptNumber'] ?? 0)));

        return $rows;
    }

    public function countSubmitted(string $studentId, string $assessmentId): int
    {
        $count = 0;
        foreach ($this->listForStudent($studentId, $assessmentId) as $row) {
            if ((string) ($row['status'] ?? '') === 'SUBMITTED') {
                $count++;
            }
        }

        return $count;
    }

    public function findInProgress(string $studentId, string $assessmentId): ?array
    {
        foreach ($this->listForStudent($studentId, $assessmentId) as $row) {
            if ((string) ($row['status'] ?? '') === 'IN_PROGRESS') {
                return $row;
            }
        }

        return null;
    }
}
