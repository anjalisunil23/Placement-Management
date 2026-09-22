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
            'category' => CodingTestModel::normalizeCategory((string) ($q['category'] ?? 'Algorithms')),
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

    /**
     * @param string[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function problemsByIds(array $ids): array
    {
        $out = [];
        foreach (array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $ids
        )))) as $id) {
            if (!Security::isValidId($id)) {
                continue;
            }
            $row = $this->findById($id);
            if (!$row) {
                continue;
            }
            $out[] = self::toTestItem($row, $id);
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param string[] $excludeIds
     * @return array<int, array<string, mixed>>
     */
    public function pickRandomByRules(array $rules, array $excludeIds = []): array
    {
        $picked = [];
        $usedIds = array_values(array_unique(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            $excludeIds
        ))));

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $category = trim((string) ($rule['category'] ?? ''));
            $difficulty = CodingTestModel::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium'));
            $count = max(0, (int) ($rule['count'] ?? 0));
            if ($count === 0) {
                continue;
            }

            $pool = $this->listProblems(
                $category !== '' ? $category : null,
                $difficulty,
                5000
            );
            $pool = array_values(array_filter(
                $pool,
                static fn (array $q): bool => !in_array((string) ($q['id'] ?? ''), $usedIds, true)
            ));

            if (count($pool) < $count) {
                $label = $category !== '' ? $category : 'problem bank';
                throw new \InvalidArgumentException(
                    sprintf(
                        'Not enough %s problems in %s (need %d, found %d).',
                        $difficulty,
                        $label,
                        $count,
                        count($pool)
                    )
                );
            }

            shuffle($pool);
            foreach (array_slice($pool, 0, $count) as $q) {
                $id = (string) ($q['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $usedIds[] = $id;
                $item = self::toTestItem($q, $id);
                $item['marks'] = max(1, (float) ($rule['marks'] ?? $item['marks'] ?? 2));
                $picked[] = $item;
            }
        }

        return $picked;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @param string[] $preferredIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveByRulesWithPreferred(array $rules, array $preferredIds = []): array
    {
        $preferred = $this->problemsByIds($preferredIds);
        $usedIds = [];
        $picked = [];

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            if ($count === 0) {
                continue;
            }

            $selectedIds = array_values(array_unique(array_filter(
                array_map(static fn ($id) => trim((string) $id), (array) ($rule['selectedQuestionIds'] ?? [])),
                static fn ($id) => $id !== ''
            )));
            if ($selectedIds !== []) {
                if (count($selectedIds) !== $count) {
                    $label = (string) ($rule['category'] ?? 'topic');
                    $diff = (string) ($rule['difficulty'] ?? 'Medium');
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Select exactly %d problem(s) for %s — %s (%d selected).',
                            $count,
                            $label,
                            $diff,
                            count($selectedIds)
                        )
                    );
                }
                foreach ($this->problemsByIds($selectedIds) as $item) {
                    $bankId = (string) ($item['bankId'] ?? '');
                    if ($bankId === '' || in_array($bankId, $usedIds, true)) {
                        continue;
                    }
                    $usedIds[] = $bankId;
                    $item['marks'] = max(1, (float) ($rule['marks'] ?? $item['marks'] ?? 2));
                    $picked[] = $item;
                }
                continue;
            }

            $category = trim((string) ($rule['category'] ?? ''));
            $difficulty = CodingTestModel::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium'));
            $fromPreferred = array_values(array_filter(
                $preferred,
                static function (array $q) use ($category, $difficulty, $usedIds): bool {
                    $id = (string) ($q['bankId'] ?? '');
                    if ($id === '' || in_array($id, $usedIds, true)) {
                        return false;
                    }
                    $catOk = $category === '' || (string) ($q['category'] ?? '') === $category;
                    $diffOk = (string) ($q['difficulty'] ?? 'Medium') === $difficulty;

                    return $catOk && $diffOk;
                }
            ));
            $need = $count;
            foreach (array_slice($fromPreferred, 0, $need) as $item) {
                $bankId = (string) ($item['bankId'] ?? '');
                if ($bankId === '') {
                    continue;
                }
                $usedIds[] = $bankId;
                $item['marks'] = max(1, (float) ($rule['marks'] ?? $item['marks'] ?? 2));
                $picked[] = $item;
                $need--;
            }
            if ($need > 0) {
                $extra = $this->pickRandomByRules([[
                    'category' => $category,
                    'difficulty' => $difficulty,
                    'count' => $need,
                    'marks' => $rule['marks'] ?? 2,
                ]], $usedIds);
                foreach ($extra as $item) {
                    $bankId = (string) ($item['bankId'] ?? '');
                    if ($bankId !== '') {
                        $usedIds[] = $bankId;
                    }
                    $picked[] = $item;
                }
            }
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public static function toTestItem(array $q, ?string $bankId = null): array
    {
        $norm = self::normalize($q);
        $id = trim((string) ($bankId ?? $q['id'] ?? $q['_id'] ?? ''));
        $slug = $id !== '' ? substr($id, -8) : bin2hex(random_bytes(4));

        return array_merge($norm, [
            'id' => 'bp-' . $slug,
            'bankId' => $id !== '' ? $id : null,
        ]);
    }
}
