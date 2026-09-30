<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Syllabus MCQ bank — one area per course code.
 */
class SyllabusQuestionBankModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::SYLLABUS_QUESTION_BANK;
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
            'CREATE TABLE IF NOT EXISTS `syllabus_question_bank` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    public static function normalizeCourseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function normalizePromptKey(string $text): string
    {
        $text = strtolower(trim(strip_tags($text)));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param array<string, mixed> $course
     * @param list<array<string, mixed>> $questions
     * @return array{added:int,skipped:int,courseCode:string,courseTitle:string,questions:list<array<string, mixed>>}
     */
    public function addQuestions(array $course, array $questions, string $difficulty, ?string $createdBy = null): array
    {
        $courseCode = self::normalizeCourseCode((string) ($course['code'] ?? ''));
        $courseTitle = trim((string) ($course['title'] ?? ''));
        if ($courseCode === '') {
            return ['added' => 0, 'skipped' => 0, 'courseCode' => '', 'courseTitle' => '', 'questions' => []];
        }

        $existing = $this->promptIndexForCourse($courseCode);
        $added = 0;
        $skipped = 0;
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $prompt = trim((string) ($question['question'] ?? $question['prompt'] ?? ''));
            $options = [];
            foreach ((array) ($question['options'] ?? []) as $option) {
                $text = trim((string) $option);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
            $options = array_values(array_unique($options));
            $correctIndex = (int) ($question['correctIndex'] ?? -1);
            if ($prompt === '' || count($options) !== 4 || $correctIndex < 0 || $correctIndex > 3) {
                continue;
            }
            $key = self::normalizePromptKey($prompt);
            if ($key !== '' && isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $id = $this->insert([
                'courseCode' => $courseCode,
                'courseTitle' => $courseTitle !== '' ? $courseTitle : $courseCode,
                'semsubId' => trim((string) ($course['semsubId'] ?? '')),
                'department' => trim((string) ($course['department'] ?? '')),
                'module' => trim((string) ($question['module'] ?? '')),
                'question' => $prompt,
                'promptKey' => $key,
                'options' => $options,
                'correctIndex' => $correctIndex,
                'explanation' => trim((string) ($question['explanation'] ?? $question['description'] ?? '')),
                'difficulty' => $this->questionDifficulty($question, $difficulty),
                'createdBy' => Security::toObjectId((string) ($createdBy ?? '')) ?: null,
            ]);
            if ($key !== '') {
                $existing[$key] = true;
            }
            $added++;
            unset($id);
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'courseCode' => $courseCode,
            'courseTitle' => $courseTitle !== '' ? $courseTitle : $courseCode,
            'questions' => $this->listByCourseCode($courseCode),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByCourseCode(string $courseCode, int $limit = 200): array
    {
        $courseCode = self::normalizeCourseCode($courseCode);
        if ($courseCode === '') {
            return [];
        }
        $rows = $this->findAll(['courseCode' => $courseCode], $limit, 0, ['createdAt' => 1]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->publicView($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function publicView(array $row): array
    {
        $options = [];
        foreach ((array) ($row['options'] ?? []) as $option) {
            $text = trim((string) $option);
            if ($text !== '') {
                $options[] = $text;
            }
        }

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'courseCode' => self::normalizeCourseCode((string) ($row['courseCode'] ?? '')),
            'courseTitle' => trim((string) ($row['courseTitle'] ?? '')),
            'module' => trim((string) ($row['module'] ?? '')),
            'question' => trim((string) ($row['question'] ?? '')),
            'options' => $options,
            'correctIndex' => (int) ($row['correctIndex'] ?? -1),
            'explanation' => trim((string) ($row['explanation'] ?? $row['description'] ?? '')),
            'description' => trim((string) ($row['explanation'] ?? $row['description'] ?? '')),
            'difficulty' => trim((string) ($row['difficulty'] ?? '')),
        ];
    }

    /**
     * @return array<string, true>
     */
    private function promptIndexForCourse(string $courseCode): array
    {
        $index = [];
        foreach ($this->findAll(['courseCode' => $courseCode], 1000, 0, ['createdAt' => -1]) as $row) {
            $key = trim((string) ($row['promptKey'] ?? ''));
            if ($key === '') {
                $key = self::normalizePromptKey((string) ($row['question'] ?? ''));
            }
            if ($key !== '') {
                $index[$key] = true;
            }
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $question
     */
    private function questionDifficulty(array $question, string $fallback): string
    {
        $value = ucfirst(strtolower(trim((string) ($question['difficulty'] ?? ''))));
        if (in_array($value, ['Easy', 'Medium', 'Hard'], true)) {
            return $value;
        }

        return $fallback !== '' ? $fallback : 'Medium';
    }
}
