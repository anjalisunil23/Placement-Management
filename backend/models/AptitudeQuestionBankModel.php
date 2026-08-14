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
                'difficulty' => AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? 'Medium')),
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
    public function listQuestions(?string $category = null, int $limit = 500): array
    {
        $filter = [];
        if ($category !== null && trim($category) !== '') {
            $filter['category'] = AptitudeTestModel::normalizeCategory($category);
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
                $out[] = $norm;
            }
        }
        return $out;
    }
}
