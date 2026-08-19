<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Reusable aptitude MCQ question bank (admin-managed).
 */
class AptitudeQuestionBankModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::APTITUDE_QUESTION_BANK;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{added:int,items:array<int,array<string,mixed>>}
     */
    public function bulkInsert(array $rows, string $fallbackCategory = 'General Aptitude', ?string $createdBy = null): array
    {
        $added = 0;
        $items = [];
        foreach (array_values($rows) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, $fallbackCategory, $i);
            if ($norm === null) {
                continue;
            }
            $id = $this->insert([
                'type' => 'mcq',
                'prompt' => $norm['prompt'],
                'options' => $norm['options'],
                'correctIndex' => $norm['correctIndex'],
                'marks' => $norm['marks'],
                'explanation' => $norm['explanation'],
                'category' => $norm['category'],
                'difficulty' => AptitudeTestModel::normalizeDifficulty((string) ($norm['difficulty'] ?? $q['difficulty'] ?? 'Medium')),
                'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
            ]);
            $doc = $this->findById($id);
            if ($doc) {
                $items[] = AptitudeTestModel::publicView([
                    '_id' => $id,
                    'title' => '',
                    'questions' => [$norm],
                    'category' => $norm['category'],
                    'status' => 'published',
                ], true)['questions'][0] ?? array_merge($norm, ['id' => $id]);
                // Prefer bank id
                $items[count($items) - 1]['id'] = $id;
                $items[count($items) - 1]['bankId'] = $id;
            }
            $added++;
        }
        return ['added' => $added, 'items' => $items];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQuestions(?string $category = null, ?string $difficulty = null, int $limit = 500): array
    {
        $filter = [];
        if ($category !== null && trim($category) !== '') {
            $filter['category'] = AptitudeTestModel::normalizeCategory($category);
        }
        if ($difficulty !== null && trim($difficulty) !== '') {
            $filter['difficulty'] = AptitudeTestModel::normalizeDifficulty($difficulty);
        }
        $rows = $this->findAll($filter, $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'bankId' => (string) ($row['_id'] ?? ''),
                'type' => 'mcq',
                'prompt' => (string) ($row['prompt'] ?? ''),
                'options' => array_values((array) ($row['options'] ?? [])),
                'correctIndex' => (int) ($row['correctIndex'] ?? 0),
                'marks' => (float) ($row['marks'] ?? 1),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'category' => (string) ($row['category'] ?? 'General Aptitude'),
                'difficulty' => (string) ($row['difficulty'] ?? 'Medium'),
            ];
        }
        return $out;
    }

    /**
     * @return array{Easy:int,Medium:int,Hard:int,total:int}
     */
    public function countByDifficulty(?string $category = null): array
    {
        $counts = ['Easy' => 0, 'Medium' => 0, 'Hard' => 0, 'total' => 0];
        foreach ($this->listQuestions($category, null, 5000) as $question) {
            $level = AptitudeTestModel::normalizeDifficulty((string) ($question['difficulty'] ?? 'Medium'));
            if (isset($counts[$level])) {
                $counts[$level]++;
            }
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * Pick random MCQs from the bank using one or more category + difficulty rules.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    public function pickRandomByRules(array $rules): array
    {
        $picked = [];
        $usedIds = [];

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $category = trim((string) ($rule['category'] ?? ''));
            $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium'));
            $count = max(0, (int) ($rule['count'] ?? 0));
            if ($count === 0) {
                continue;
            }

            $pool = $this->listQuestions(
                $category !== '' ? $category : null,
                $difficulty,
                5000
            );
            $pool = array_values(array_filter(
                $pool,
                static fn (array $q): bool => !in_array((string) ($q['id'] ?? ''), $usedIds, true)
            ));

            if (count($pool) < $count) {
                $label = $category !== '' ? $category : 'question bank';
                throw new \InvalidArgumentException(
                    sprintf(
                        'Not enough %s questions in %s (need %d, found %d).',
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
                $norm = AptitudeTestModel::normalizeMcq(
                    $q,
                    (string) ($q['category'] ?? $category ?: 'General Aptitude')
                );
                if ($norm === null) {
                    continue;
                }
                $norm['bankId'] = $id;
                $picked[] = $norm;
            }
        }

        return $picked;
    }

    /**
     * @param string[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function questionsByIds(array $ids): array
    {
        $map = $this->findByIds($ids);
        $out = [];
        foreach ($ids as $id) {
            $row = $map[$id] ?? null;
            if (!$row) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($row, (string) ($row['category'] ?? 'General Aptitude'));
            if ($norm !== null) {
                $norm['bankId'] = (string) ($row['_id'] ?? $id);
                $out[] = $norm;
            }
        }
        return $out;
    }
}
