<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Server-recorded answers for lesson MCQs. The client does not submit a score.
 */
class TutorialLessonQuestionAttemptModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::TUTORIAL_LESSON_QUESTION_ATTEMPTS;
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
            'CREATE TABLE IF NOT EXISTS `tutorial_lesson_question_attempts` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              student_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.studentId\'))) STORED,
              question_id VARCHAR(32)
                GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`payload`, \'$.questionId\'))) STORED,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              KEY idx_tutorial_lesson_question_attempts (student_id, question_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function record(string $studentId, string $questionId, string $tutorialId, int $selectedIndex, bool $correct): array
    {
        if (!Security::isValidId($studentId) || !Security::isValidId($questionId) || !Security::isValidId($tutorialId)) {
            throw new \InvalidArgumentException('Lesson answer references are required.');
        }
        $payload = [
            'studentId' => $studentId,
            'questionId' => $questionId,
            'tutorialId' => $tutorialId,
            'selectedIndex' => $selectedIndex,
            'correct' => $correct,
            'submittedAt' => DocumentHelper::now(),
        ];
        $id = $this->insert($payload);

        return $this->findById($id) ?? array_merge($payload, ['_id' => $id]);
    }

    /**
     * @return array<string, true>
     */
    public function correctQuestionIds(string $studentId, string $tutorialId): array
    {
        if (!Security::isValidId($studentId) || !Security::isValidId($tutorialId)) {
            return [];
        }
        $ids = [];
        foreach ($this->findAll(['studentId' => $studentId, 'tutorialId' => $tutorialId], 2000) as $row) {
            if (($row['correct'] ?? false) === true) {
                $ids[(string) ($row['questionId'] ?? '')] = true;
            }
        }

        return $ids;
    }

    public function deleteForQuestion(string $questionId): void
    {
        if (!Security::isValidId($questionId)) {
            return;
        }
        foreach ($this->findAll(['questionId' => $questionId], 500) as $row) {
            $this->delete((string) ($row['_id'] ?? ''));
        }
    }

    public function countForQuestion(string $studentId, string $questionId): int
    {
        if (!Security::isValidId($studentId) || !Security::isValidId($questionId)) {
            return 0;
        }

        return count($this->findAll(['studentId' => $studentId, 'questionId' => $questionId], 100));
    }
}
