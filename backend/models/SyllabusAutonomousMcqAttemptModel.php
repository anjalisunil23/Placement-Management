<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

/**
 * Latest autonomous MCQ attempt per student and published test.
 */
class SyllabusAutonomousMcqAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::SYLLABUS_AUTONOMOUS_MCQ_ATTEMPTS;
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
            'CREATE TABLE IF NOT EXISTS `syllabus_autonomous_mcq_attempts` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              student_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
              test_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.testId\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_syllabus_mcq_attempt_student (student_id, test_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $graded
     * @return array<string, mixed>
     */
    public function saveLatest(string $studentId, string $testId, array $graded): array
    {
        $score = (int) ($graded['score'] ?? 0);
        $total = (int) ($graded['total'] ?? 0);
        $percentage = $total > 0 ? (int) round(($score / $total) * 100) : 0;
        $payload = [
            'studentId' => $studentId,
            'testId' => $testId,
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'title' => (string) ($graded['title'] ?? ''),
            'courseCode' => (string) ($graded['courseCode'] ?? ''),
            'submittedAt' => DocumentHelper::now(),
        ];
        $existing = $this->findOne(['studentId' => $studentId, 'testId' => $testId]);
        if (is_array($existing) && (string) ($existing['_id'] ?? '') !== '') {
            $this->update((string) $existing['_id'], $payload);
            $row = $this->findById((string) $existing['_id']);
        } else {
            $id = $this->insert($payload);
            $row = $this->findById($id);
        }

        return $this->listSummary(is_array($row) ? $row : $payload);
    }

    /**
     * @return array<string, array<string, mixed>> keyed by testId
     */
    public function latestMapForStudent(string $studentId): array
    {
        if ($studentId === '') {
            return [];
        }
        $rows = $this->findAll(['studentId' => $studentId], 500, 0, ['submittedAt' => -1]);
        $map = [];
        foreach ($rows as $row) {
            $testId = (string) ($row['testId'] ?? '');
            if ($testId === '' || isset($map[$testId])) {
                continue;
            }
            $map[$testId] = $this->listSummary($row);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function listSummary(array $row): array
    {
        return [
            'score' => (int) ($row['score'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
            'percentage' => (int) ($row['percentage'] ?? 0),
            'submittedAt' => (string) ($row['submittedAt'] ?? ''),
        ];
    }
}
