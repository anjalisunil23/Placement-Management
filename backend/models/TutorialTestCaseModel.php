<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialTestCaseModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_TEST_CASES;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_test_cases` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              exercise_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.exerciseId\'))) STORED,
              sort_order INT
                GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.sortOrder\')) AS SIGNED)) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_test_cases_exercise_order (exercise_id, sort_order)
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
    public function updateTestCase(string $id, array $data): ?array
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
    public function listByExercise(string $exerciseId): array
    {
        if (!Security::isValidId($exerciseId)) {
            return [];
        }

        return $this->findAll(['exerciseId' => $exerciseId], 500, 0, ['sortOrder' => 1]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $exerciseId = trim((string) ($data['exerciseId'] ?? ''));
        if (!Security::isValidId($exerciseId) || (new TutorialExerciseModel())->findById($exerciseId) === null) {
            throw new \InvalidArgumentException('Test case exerciseId is required.');
        }
        if (!array_key_exists('sample', $data) || !is_bool($data['sample'])) {
            throw new \InvalidArgumentException('Test case sample must be true or false.');
        }

        return [
            'exerciseId' => $exerciseId,
            'stdin' => (string) ($data['stdin'] ?? ''),
            'expectedOutput' => (string) ($data['expectedOutput'] ?? ''),
            'sample' => $data['sample'],
            'sortOrder' => TutorialModuleModel::sortOrder($data['sortOrder'] ?? 1),
        ];
    }
}
