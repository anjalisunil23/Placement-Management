<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * JD-based aptitude question sets — stored separately from the general question bank.
 */
class AptitudeJdQuestionSetModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::APTITUDE_JD_QUESTION_SETS;
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
            'CREATE TABLE IF NOT EXISTS `aptitude_jd_question_sets` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(int $limit = 200): array
    {
        $rows = $this->findAll([], $limit, 0, ['createdAt' => -1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->summaryView($row);
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function createSet(
        string $jdTitle,
        array $questions,
        ?string $createdBy = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null
    ): array {
        $jdTitle = trim($jdTitle);
        if ($jdTitle === '') {
            throw new \InvalidArgumentException('JD title is required.');
        }

        $normalized = [];
        foreach (array_values($questions) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = AptitudeTestModel::normalizeMcq($q, 'General Aptitude', $i);
            if ($norm === null) {
                continue;
            }
            $topic = trim((string) ($q['topic'] ?? ''));
            if ($topic !== '') {
                $norm['topic'] = $topic;
            }
            $norm['id'] = 'jdq-' . ($i + 1) . '-' . bin2hex(random_bytes(4));
            $norm['source'] = 'AI_JD';
            $normalized[] = $norm;
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('No valid questions to save.');
        }

        $doc = [
            'jdTitle' => $jdTitle,
            'questionCount' => count($normalized),
            'questions' => $normalized,
            'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
        ];
        $filename = trim((string) ($jdFilename ?? ''));
        if ($filename !== '') {
            $doc['jdFilename'] = $filename;
        }
        $fileUri = trim((string) ($jdFile ?? ''));
        if ($fileUri !== '') {
            $doc['jdFile'] = $fileUri;
        }
        $fileUrl = trim((string) ($jdFileUrl ?? ''));
        if ($fileUrl !== '') {
            $doc['jdFileUrl'] = $fileUrl;
        }
        $mime = trim((string) ($jdMimeType ?? ''));
        if ($mime !== '') {
            $doc['jdMimeType'] = $mime;
        }

        $id = $this->insert($doc);
        $saved = $this->findById($id);

        return $saved !== null ? $this->detailView($saved) : ['id' => $id, 'jdTitle' => $jdTitle];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    public function resolveByRules(array $rules): array
    {
        $picked = [];
        $usedKeys = [];

        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $setId = trim((string) ($rule['jdSetId'] ?? ''));
            if ($setId === '' || !Security::isValidId($setId)) {
                throw new \InvalidArgumentException('Invalid JD set selected.');
            }
            $count = max(0, (int) ($rule['count'] ?? 0));
            $marks = max(0.25, (float) ($rule['marks'] ?? 1));
            $selectedIds = array_values(array_filter(array_map(
                static fn ($id): string => trim((string) $id),
                (array) ($rule['selectedQuestionIds'] ?? [])
            )));
            if ($count <= 0) {
                continue;
            }
            if (count($selectedIds) !== $count) {
                $title = trim((string) ($rule['jdTitle'] ?? 'JD set'));
                throw new \InvalidArgumentException(
                    'Select exactly ' . $count . ' question(s) for ' . ($title !== '' ? $title : 'the JD set') . '.'
                );
            }

            $set = $this->findById($setId);
            if ($set === null) {
                throw new \InvalidArgumentException('JD question set not found.');
            }
            $jdTitle = trim((string) ($set['jdTitle'] ?? 'Job Description'));
            $index = $this->questionIndex($set);

            foreach ($selectedIds as $qid) {
                $key = $setId . ':' . $qid;
                if (isset($usedKeys[$key])) {
                    continue;
                }
                $q = $index[$qid] ?? null;
                if ($q === null) {
                    throw new \InvalidArgumentException('One or more selected JD questions were not found.');
                }
                $usedKeys[$key] = true;
                $picked[] = array_merge($q, [
                    'marks' => $marks,
                    'jdSetId' => $setId,
                    'jdTitle' => $jdTitle,
                    'source' => 'AI_JD',
                ]);
            }
        }

        return $picked;
    }

    public function deleteSet(string $id): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }

        return $this->delete($id);
    }

    /**
     * @param array<string, mixed> $set
     * @return array<string, array<string, mixed>>
     */
    private function questionIndex(array $set): array
    {
        $index = [];
        foreach (array_values((array) ($set['questions'] ?? [])) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $id = trim((string) ($q['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $index[$id] = $q;
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summaryView(array $row): array
    {
        $id = (string) ($row['_id'] ?? '');

        return $this->withDocumentUrl([
            'id' => $id,
            'jdTitle' => (string) ($row['jdTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'questionCount' => (int) ($row['questionCount'] ?? count((array) ($row['questions'] ?? []))),
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function detailView(array $row): array
    {
        $questions = [];
        foreach (array_values((array) ($row['questions'] ?? [])) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $questions[] = [
                'id' => (string) ($q['id'] ?? ''),
                'prompt' => (string) ($q['prompt'] ?? ''),
                'options' => array_values((array) ($q['options'] ?? [])),
                'correctIndex' => (int) ($q['correctIndex'] ?? 0),
                'explanation' => (string) ($q['explanation'] ?? ''),
                'topic' => (string) ($q['topic'] ?? ''),
                'difficulty' => (string) ($q['difficulty'] ?? 'Medium'),
                'category' => (string) ($q['category'] ?? 'General Aptitude'),
                'marks' => (float) ($q['marks'] ?? 1),
                'source' => 'AI_JD',
            ];
        }

        $id = (string) ($row['_id'] ?? '');

        return $this->withDocumentUrl([
            'id' => $id,
            'jdTitle' => (string) ($row['jdTitle'] ?? ''),
            'jdFilename' => (string) ($row['jdFilename'] ?? ''),
            'jdFileUrl' => (string) ($row['jdFileUrl'] ?? ''),
            'jdMimeType' => (string) ($row['jdMimeType'] ?? ''),
            'hasDocument' => trim((string) ($row['jdFile'] ?? '')) !== '' || trim((string) ($row['jdFileUrl'] ?? '')) !== '',
            'questionCount' => count($questions),
            'questions' => $questions,
            'createdAt' => (string) ($row['createdAt'] ?? ''),
        ], $row);
    }

    /**
     * @param array<string, mixed> $view
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withDocumentUrl(array $view, array $row): array
    {
        $fileUri = trim((string) ($row['jdFile'] ?? ''));
        $id = (string) ($view['id'] ?? $row['_id'] ?? '');
        if ($fileUri !== '' && $id !== '') {
            $view['jdFileUrl'] = '/backend/api/aptitude/jd-sets/' . rawurlencode($id) . '/document';
            $view['hasDocument'] = true;
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function publicDetail(array $row): array
    {
        return $this->detailView($row);
    }
}
