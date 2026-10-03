<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class TutorialModuleQuestionModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_MODULE_QUESTIONS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_module_questions` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              assessment_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.assessmentId\'))) STORED,
              sort_order INT
                GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.sortOrder\')) AS SIGNED)) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_module_questions_assessment (assessment_id, sort_order)
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
    public function updateQuestion(string $id, array $data): ?array
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return null;
        }
        $payload = $this->validate(array_merge($existing, $data, [
            'assessmentId' => (string) ($existing['assessmentId'] ?? ''),
        ]));
        $this->update($id, $payload);

        return $this->findById($id);
    }

    /**
     * Active (non-archived) questions for an assessment, ordered for presentation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByAssessment(string $assessmentId, bool $includeArchived = false): array
    {
        if (!Security::isValidId($assessmentId)) {
            return [];
        }
        $rows = $this->findAll(['assessmentId' => $assessmentId], 500, 0, ['sortOrder' => 1]);
        if (!$includeArchived) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => ($row['archived'] ?? false) !== true
            ));
        }
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['sortOrder'] ?? 0)) <=> ((int) ($b['sortOrder'] ?? 0)));

        return $rows;
    }

    /**
     * Soft-archive active questions so historical attempt answer IDs remain resolvable.
     * Does not delete rows.
     */
    public function archiveActiveByAssessment(string $assessmentId): void
    {
        foreach ($this->listByAssessment($assessmentId, false) as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id === '' || !Security::isValidId($id)) {
                continue;
            }
            $payload = $row;
            unset($payload['_id'], $payload['createdAt'], $payload['updatedAt']);
            $payload['archived'] = true;
            $this->update($id, $payload);
        }
    }

    /**
     * Permanently remove all questions for an assessment (tests/admin cleanup only).
     */
    public function hardDeleteByAssessment(string $assessmentId): void
    {
        foreach ($this->listByAssessment($assessmentId, true) as $row) {
            $this->delete((string) ($row['_id'] ?? ''));
        }
    }

    /**
     * @deprecated Prefer archiveActiveByAssessment for production edits.
     */
    public function deleteByAssessment(string $assessmentId): void
    {
        $this->archiveActiveByAssessment($assessmentId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $assessmentId = trim((string) ($data['assessmentId'] ?? ''));
        if (!Security::isValidId($assessmentId)) {
            throw new \InvalidArgumentException('Question assessmentId is required.');
        }
        $question = trim(self::sanitizeText((string) ($data['question'] ?? '')));
        if ($question === '') {
            throw new \InvalidArgumentException('Question text is required.');
        }
        $options = [];
        foreach (array_values((array) ($data['options'] ?? [])) as $option) {
            $text = trim(self::sanitizeText((string) $option));
            if ($text !== '') {
                $options[] = mb_substr($text, 0, 500);
            }
        }
        if (count($options) !== 4) {
            throw new \InvalidArgumentException('Each question needs exactly four non-empty options.');
        }
        $correctIndex = (int) ($data['correctIndex'] ?? $data['correctAnswer'] ?? -1);
        if ($correctIndex < 0 || $correctIndex > 3) {
            throw new \InvalidArgumentException('Correct answer must be an option index from 0 to 3.');
        }
        $explanation = trim(self::sanitizeText((string) ($data['explanation'] ?? '')));
        if ($explanation === '') {
            throw new \InvalidArgumentException('Explanation is required.');
        }
        $difficulty = strtolower(trim((string) ($data['difficulty'] ?? 'beginner')));
        if (!in_array($difficulty, ['beginner', 'intermediate', 'advanced'], true)) {
            throw new \InvalidArgumentException('Difficulty must be beginner, intermediate, or advanced.');
        }
        $marks = (int) ($data['marks'] ?? 1);
        if ($marks < 1 || $marks > 20) {
            throw new \InvalidArgumentException('Marks must be between 1 and 20.');
        }
        $sortOrder = (int) ($data['sortOrder'] ?? 1);
        if ($sortOrder < 1) {
            $sortOrder = 1;
        }

        return [
            'assessmentId' => $assessmentId,
            'question' => mb_substr($question, 0, 2000),
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => mb_substr($explanation, 0, 4000),
            'difficulty' => $difficulty,
            'marks' => $marks,
            'sortOrder' => $sortOrder,
            'archived' => ($data['archived'] ?? false) === true,
        ];
    }

    private static function sanitizeText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
        $value = preg_replace('/<\/?(script|iframe|object|embed)[^>]*>/iu', '', $value) ?? $value;

        return $value;
    }
}
