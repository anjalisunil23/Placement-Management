<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

class StudentExerciseAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::STUDENT_EXERCISE_ATTEMPTS;
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
            'CREATE TABLE IF NOT EXISTS `student_exercise_attempts` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              student_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
              exercise_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.exerciseId\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_student_exercise_attempts (student_id, exercise_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function record(array $data): array
    {
        $data['submittedAt'] = DocumentHelper::now();
        $data['status'] = 'ATTEMPTED';
        $data['testsPassed'] = null;
        $data['testsTotal'] = null;
        $id = $this->insert($data);

        return $this->findById($id) ?? $data;
    }

    /**
     * Store a server-graded submission. Caller-supplied pass flags are ignored.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordGraded(array $data, bool $passed, int $testsPassed, int $testsTotal): array
    {
        unset($data['passed'], $data['testsPassed'], $data['testsTotal'], $data['testsFailed'], $data['results'], $data['status']);
        $data['submittedAt'] = DocumentHelper::now();
        $data['passed'] = $passed;
        $data['status'] = $passed ? 'PASSED' : 'FAILED';
        $data['testsPassed'] = max(0, $testsPassed);
        $data['testsTotal'] = max(0, $testsTotal);
        $data['testsFailed'] = max(0, $data['testsTotal'] - $data['testsPassed']);
        $id = $this->insert($data);

        return $this->findById($id) ?? $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForStudentExercise(string $studentId, string $exerciseId, int $limit = 20): array
    {
        return $this->findAll(
            ['studentId' => $studentId, 'exerciseId' => $exerciseId],
            $limit,
            0,
            ['submittedAt' => -1]
        );
    }
}
