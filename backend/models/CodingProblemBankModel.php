<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class CodingProblemBankModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::CODING_PROBLEM_BANK;
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
            'CREATE TABLE IF NOT EXISTS `coding_problem_bank` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public static function normalize(array $q): array
    {
        return [
            'title' => trim((string) ($q['title'] ?? '')),
            'description' => (string) ($q['description'] ?? ''),
            'inputFormat' => (string) ($q['inputFormat'] ?? ''),
            'outputFormat' => (string) ($q['outputFormat'] ?? ''),
            'constraints' => (string) ($q['constraints'] ?? ''),
            'examples' => array_values((array) ($q['examples'] ?? [])),
            'starterCode' => is_array($q['starterCode'] ?? null) ? $q['starterCode'] : [],
            'testCases' => array_values((array) ($q['testCases'] ?? [])),
            'keywords' => is_array($q['keywords'] ?? null) ? $q['keywords'] : [],
            'marks' => (float) ($q['marks'] ?? 2),
            'difficulty' => (string) ($q['difficulty'] ?? 'Medium'),
            'category' => (string) ($q['category'] ?? 'Programming'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProblems(?string $category = null, ?string $difficulty = null, int $limit = 500): array
    {
        $filter = [];
        if ($category !== null && trim($category) !== '') {
            $filter['category'] = trim($category);
        }
        if ($difficulty !== null && trim($difficulty) !== '') {
            $filter['difficulty'] = trim($difficulty);
        }
        $rows = $this->findAll($filter, $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = array_merge(self::normalize($row), [
                'id' => (string) ($row['_id'] ?? ''),
            ]);
        }
        return $out;
    }

    public function saveProblem(array $data, ?string $id = null): string
    {
        $payload = self::normalize($data);
        if ($id && Security::isValidId($id) && $this->findById($id)) {
            $this->update($id, $payload);
            return $id;
        }
        return $this->insert($payload);
    }
}
