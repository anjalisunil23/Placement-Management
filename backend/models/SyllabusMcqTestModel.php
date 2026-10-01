<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Saved MCQ tests built from a course question card.
 */
class SyllabusMcqTestModel extends BaseModel
{
    private static bool $tableReady = false;

    protected function collectionName(): string
    {
        return Collections::SYLLABUS_MCQ_TESTS;
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
            'CREATE TABLE IF NOT EXISTS `syllabus_mcq_tests` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param list<string> $courseCodes
     * @param list<array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function createTest(string $title, int $durationMinutes, array $questions, array $courseCodes, string $createdBy): array
    {
        $codes = [];
        foreach ($courseCodes as $code) {
            $normalized = SyllabusQuestionBankModel::normalizeCourseCode((string) $code);
            if ($normalized !== '' && !in_array($normalized, $codes, true)) {
                $codes[] = $normalized;
            }
        }
        $titles = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $courseTitle = trim((string) ($question['courseTitle'] ?? ''));
            if ($courseTitle !== '') {
                $titles[$courseTitle] = true;
            }
        }
        $courseCode = $codes[0] ?? '';
        $id = $this->insert([
            'title' => $title,
            'courseCode' => $courseCode,
            'courseCodes' => $codes,
            'courseTitle' => $titles !== [] ? implode(', ', array_keys($titles)) : $courseCode,
            'durationMinutes' => $durationMinutes,
            'questions' => array_values($questions),
            'createdBy' => Security::toObjectId($createdBy) ?: null,
        ]);
        $row = $this->findById($id);

        return $this->publicView(is_array($row) ? $row : ['_id' => $id, 'title' => $title, 'courseCode' => $courseCode, 'courseCodes' => $codes]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCards(int $limit = 200): array
    {
        $out = [];
        foreach ($this->findAll([], $limit, 0, ['createdAt' => -1]) as $row) {
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
        $questions = is_array($row['questions'] ?? null) ? $row['questions'] : [];
        $codes = [];
        foreach ((array) ($row['courseCodes'] ?? []) as $code) {
            $normalized = SyllabusQuestionBankModel::normalizeCourseCode((string) $code);
            if ($normalized !== '' && !in_array($normalized, $codes, true)) {
                $codes[] = $normalized;
            }
        }
        if ($codes === []) {
            $fallback = SyllabusQuestionBankModel::normalizeCourseCode((string) ($row['courseCode'] ?? ''));
            if ($fallback !== '') {
                $codes[] = $fallback;
            }
        }

        return [
            'id' => (string) ($row['_id'] ?? ''),
            'title' => trim((string) ($row['title'] ?? '')),
            'courseCode' => implode(', ', $codes),
            'courseCodes' => $codes,
            'courseTitle' => trim((string) ($row['courseTitle'] ?? '')),
            'durationMinutes' => max(1, (int) ($row['durationMinutes'] ?? 30)),
            'questionCount' => count($questions),
        ];
    }
}
