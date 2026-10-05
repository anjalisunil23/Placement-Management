<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Lesson-specific MCQs. Separate from module assessments and programming exercises.
 */
class TutorialLessonQuestionModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_LESSON_QUESTIONS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_lesson_questions` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              module_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.moduleId\'))) STORED,
              lesson_block_id VARCHAR(64)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.lessonBlockId\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_lesson_questions_module (module_id, lesson_block_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createQuestion(array $data): array
    {
        $payload = $this->validate($data);
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByModule(string $moduleId): array
    {
        if (!Security::isValidId($moduleId)) {
            return [];
        }

        return $this->findAll(['moduleId' => $moduleId], 200, 0, ['sortOrder' => 1]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        foreach (['tutorialId', 'moduleId'] as $field) {
            if (!Security::isValidId((string) ($data[$field] ?? ''))) {
                throw new \InvalidArgumentException('Lesson question ' . $field . ' is required.');
            }
        }
        $lessonBlockId = trim((string) ($data['lessonBlockId'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonBlockId) !== 1) {
            throw new \InvalidArgumentException('Lesson question must belong to a lesson.');
        }
        $question = trim((string) ($data['question'] ?? ''));
        $options = array_values((array) ($data['options'] ?? []));
        if ($question === '' || count($options) !== 4) {
            throw new \InvalidArgumentException('A lesson MCQ needs a question and four options.');
        }
        $correct = (int) ($data['correctIndex'] ?? -1);
        if ($correct < 0 || $correct > 3) {
            throw new \InvalidArgumentException('Lesson MCQ correct answer is invalid.');
        }

        return [
            'tutorialId' => (string) $data['tutorialId'],
            'moduleId' => (string) $data['moduleId'],
            'lessonBlockId' => $lessonBlockId,
            'question' => mb_substr($question, 0, 2000),
            'options' => array_map(static fn ($option): string => mb_substr(trim((string) $option), 0, 500), $options),
            'correctIndex' => $correct,
            'explanation' => mb_substr(trim((string) ($data['explanation'] ?? '')), 0, 4000),
            'difficulty' => (string) ($data['difficulty'] ?? 'beginner'),
            'sortOrder' => max(1, (int) ($data['sortOrder'] ?? 1)),
        ];
    }
}
