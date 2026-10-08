<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\CodingStarterTemplates;
use PMS\Utils\Security;

class CodingProblemBankModel extends BaseModel
{
    private static bool $tableReady = false;

    /** @var list<string> */
    private const TITLE_STOP = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'into', 'your', 'you', 'are', 'was', 'were', 'problem', 'question', 'questions'];

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
        return CodingStarterTemplates::enrichItem([
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
            'topic' => trim((string) ($q['topic'] ?? '')),
        ]);
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

    public static function compareKey(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function extraTitleWordsAreFiller(string $longer, string $shorter): bool
    {
        $extra = trim(str_replace($shorter, ' ', $longer));
        if ($extra === '') {
            return true;
        }
        $filler = ['problem', 'question', 'questions', 'using', 'with', 'and', 'the', 'for', 'from', 'into', 'version'];
        foreach (array_filter(explode(' ', $extra)) as $word) {
            if (strlen($word) > 2 && !in_array($word, $filler, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function titleTokens(string $text): array
    {
        return array_values(array_filter(
            explode(' ', self::compareKey($text)),
            static fn (string $word): bool => strlen($word) > 2 && !in_array($word, self::TITLE_STOP, true)
        ));
    }

    public static function titlesAreSimilar(string $left, string $right): bool
    {
        $a = self::compareKey($left);
        $b = self::compareKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $coreA = implode(' ', self::titleTokens($left));
        $coreB = implode(' ', self::titleTokens($right));
        if ($coreA !== '' && $coreA === $coreB) {
            return true;
        }
        $shorter = strlen($a) <= strlen($b) ? $a : $b;
        $longer = strlen($a) <= strlen($b) ? $b : $a;
        if (strlen($shorter) >= 12 && str_contains($longer, $shorter) && self::extraTitleWordsAreFiller($longer, $shorter)) {
            return true;
        }
        $tokensA = self::titleTokens($left);
        $tokensB = self::titleTokens($right);
        if (count($tokensA) < 2 || count($tokensB) < 2) {
            return false;
        }
        $inter = count(array_intersect($tokensA, $tokensB));
        $union = count(array_unique(array_merge($tokensA, $tokensB)));

        return $union > 0 && ($inter / $union) >= 0.75;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function sameTopicScope(array $a, array $b): bool
    {
        $left = CodingTestModel::normalizeCategory((string) ($a['category'] ?? ''));
        $right = CodingTestModel::normalizeCategory((string) ($b['category'] ?? ''));
        if ($left !== $right) {
            return false;
        }
        $topicA = self::compareKey((string) ($a['topic'] ?? ''));
        $topicB = self::compareKey((string) ($b['topic'] ?? ''));
        if ($topicA === '' || $topicB === '') {
            return true;
        }

        return $topicA === $topicB;
    }

    /**
     * Same title anywhere in the bank, or the same question wording inside one topic.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function problemsAreSimilar(array $a, array $b): bool
    {
        if (self::titlesAreSimilar((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''))) {
            return true;
        }
        if (!self::sameTopicScope($a, $b)) {
            return false;
        }
        $left = self::compareKey((string) ($a['description'] ?? ''));
        $right = self::compareKey((string) ($b['description'] ?? ''));

        return strlen($left) >= 80 && strlen($right) >= 80 && substr($left, 0, 80) === substr($right, 0, 80);
    }

    /**
     * @param array<string, mixed> $problem
     */
    public static function inSimilarTopic(array $problem, string $category, string $topic = ''): bool
    {
        $cat = CodingTestModel::normalizeCategory((string) ($problem['category'] ?? ''));
        if ($cat !== CodingTestModel::normalizeCategory($category)) {
            return false;
        }
        $wanted = self::compareKey($topic);
        if ($wanted === '') {
            return true;
        }
        $have = self::compareKey((string) ($problem['topic'] ?? ''));

        return $have === '' || $have === $wanted;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>|null
     */
    public function findFirstSimilar(array $candidate, ?string $exceptId = null): ?array
    {
        $exceptId = trim((string) $exceptId);
        foreach ($this->listProblems(null, null, 5000) as $row) {
            if ($exceptId !== '' && (string) ($row['id'] ?? '') === $exceptId) {
                continue;
            }
            if (self::problemsAreSimilar($candidate, $row)) {
                return $row;
            }
        }

        return null;
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
    /**
     * Student-safe problem view (hides hidden test case I/O).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publicView(array $row, ?string $id = null): array
    {
        $norm = self::normalize($row);
        $pid = trim((string) ($id ?? $row['id'] ?? $row['_id'] ?? ''));
        $testCases = [];
        foreach ((array) ($norm['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            if (!empty($tc['sample'])) {
                $testCases[] = $tc;
                continue;
            }
            $testCases[] = [
                'id' => (string) ($tc['id'] ?? ''),
                'sample' => false,
                'label' => (string) ($tc['label'] ?? 'Hidden Test Case'),
            ];
        }

        $sampleHasInput = false;
        foreach ($testCases as $tc) {
            if (!empty($tc['sample']) && trim((string) ($tc['input'] ?? '')) !== '') {
                $sampleHasInput = true;
                break;
            }
        }
        if (!$sampleHasInput) {
            foreach ((array) ($norm['examples'] ?? []) as $ex) {
                if (!is_array($ex) || trim((string) ($ex['input'] ?? '')) === '') {
                    continue;
                }
                array_unshift($testCases, [
                    'id' => 'example-sample',
                    'sample' => true,
                    'label' => 'Sample Test Case',
                    'input' => (string) $ex['input'],
                    'expected' => (string) ($ex['output'] ?? ''),
                ]);
                break;
            }
        }

        return [
            'id' => $pid,
            'bankId' => $pid,
            'title' => $norm['title'],
            'description' => $norm['description'],
            'inputFormat' => $norm['inputFormat'],
            'outputFormat' => $norm['outputFormat'],
            'constraints' => $norm['constraints'],
            'examples' => $norm['examples'],
            'starterCode' => $norm['starterCode'],
            'testCases' => $testCases,
            'marks' => $norm['marks'],
            'difficulty' => $norm['difficulty'],
            'category' => $norm['category'],
        ];
    }

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
