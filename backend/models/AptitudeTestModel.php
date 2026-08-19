<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Aptitude mock tests — metadata + MCQ questions.
 */
class AptitudeTestModel extends BaseModel
{
    private static bool $tableReady = false;

    public const CATEGORIES = [
        'Quantitative Aptitude',
        'Logical Reasoning',
        'Verbal Ability',
        'Data Interpretation',
        'Numerical Ability',
        'General Aptitude',
    ];

    public const DIFFICULTIES = ['Easy', 'Medium', 'Hard'];

    public const STATUSES = ['published', 'unpublished'];

    public const CONTEST_TYPES = ['none', 'weekly', 'monthly'];

    public static function normalizeContestType(string $value): string
    {
        $raw = strtolower(trim($value));
        return in_array($raw, ['weekly', 'monthly'], true) ? $raw : 'none';
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function isContestOpen(array $test, ?\DateTimeInterface $now = null): bool
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'none') {
            return true;
        }
        $now = $now instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($now)
            : new \DateTimeImmutable('now');
        if ($type === 'weekly') {
            $want = (int) ($test['contestWeekday'] ?? 0);
            if ($want < 1 || $want > 7) {
                return false;
            }

            return (int) $now->format('N') === $want;
        }
        $want = (int) ($test['contestMonthDay'] ?? 0);
        if ($want < 1 || $want > 28) {
            return false;
        }

        return (int) $now->format('j') === $want;
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function contestScheduleLabel(array $test): string
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'weekly') {
            $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $day = (int) ($test['contestWeekday'] ?? 0);

            return $day >= 1 && $day <= 7 ? 'Weekly · ' . $days[$day] : 'Weekly contest';
        }
        if ($type === 'monthly') {
            $dom = (int) ($test['contestMonthDay'] ?? 0);

            return $dom >= 1 && $dom <= 28 ? 'Monthly · day ' . $dom : 'Monthly contest';
        }

        return '';
    }

    protected function collectionName(): string
    {
        return Collections::APTITUDE_TESTS;
    }

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    /** Create table if production DB was set up before aptitude tests existed. */
    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS `aptitude_tests` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function published(int $limit = 100): array
    {
        return $this->findAll(['status' => 'published'], $limit, 0, ['createdAt' => -1]);
    }

    public static function normalizeCategory(string $value): string
    {
        $raw = trim($value);
        foreach (self::CATEGORIES as $cat) {
            if (strcasecmp($cat, $raw) === 0) {
                return $cat;
            }
        }
        // Legacy short labels → canonical
        $map = [
            'quantitative' => 'Quantitative Aptitude',
            'logical' => 'Logical Reasoning',
            'verbal' => 'Verbal Ability',
            'data interpretation' => 'Data Interpretation',
            'numerical' => 'Numerical Ability',
            'general' => 'General Aptitude',
            'general aptitude' => 'General Aptitude',
        ];
        $key = strtolower($raw);
        return $map[$key] ?? 'General Aptitude';
    }

    public static function normalizeDifficulty(string $value): string
    {
        $raw = trim($value);
        foreach (self::DIFFICULTIES as $d) {
            if (strcasecmp($d, $raw) === 0) {
                return $d;
            }
        }
        return 'Medium';
    }

    public static function normalizeStatus(string $value): string
    {
        $raw = strtolower(trim($value));
        if ($raw === 'draft' || $raw === 'unpublished') {
            return 'unpublished';
        }
        if ($raw === 'published' || $raw === 'live' || $raw === 'active') {
            return 'published';
        }
        return 'unpublished';
    }

    /**
     * Normalize one MCQ question.
     *
     * @param array<string, mixed> $q
     * @param string $fallbackCategory
     * @return array<string, mixed>|null
     */
    public static function normalizeMcq(array $q, string $fallbackCategory, int $index = 0): ?array
    {
        $prompt = trim((string) ($q['prompt'] ?? $q['question'] ?? $q['question_text'] ?? ''));
        $options = array_values(array_filter(
            array_map(static fn ($o) => trim((string) $o), (array) ($q['options'] ?? [])),
            static fn ($o) => $o !== ''
        ));
        if ($prompt === '' || count($options) < 2) {
            return null;
        }
        $correctIndex = (int) ($q['correctIndex'] ?? $q['answerIndex'] ?? 0);
        if (isset($q['correct_answer']) || isset($q['correctAnswer'])) {
            $rawCorrect = $q['correct_answer'] ?? $q['correctAnswer'];
            if (is_int($rawCorrect) || (is_string($rawCorrect) && ctype_digit($rawCorrect))) {
                $correctIndex = (int) $rawCorrect;
            } elseif (is_string($rawCorrect)) {
                $letter = strtoupper(trim($rawCorrect));
                if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'Z') {
                    $correctIndex = ord($letter) - ord('A');
                } else {
                    foreach ($options as $i => $opt) {
                        if (strcasecmp($opt, $rawCorrect) === 0) {
                            $correctIndex = $i;
                            break;
                        }
                    }
                }
            }
        }
        if ($correctIndex < 0 || $correctIndex >= count($options)) {
            $correctIndex = 0;
        }
        $marks = (float) ($q['marks'] ?? 1);
        if ($marks <= 0) {
            $marks = 1.0;
        }
        $negativeMarks = (float) ($q['negative_marks'] ?? $q['negativeMarks'] ?? 0);
        if ($negativeMarks < 0) {
            $negativeMarks = abs($negativeMarks);
        }
        $id = trim((string) ($q['id'] ?? ''));
        if ($id === '') {
            $id = 'q' . ($index + 1);
        }

        return [
            'id' => $id,
            'type' => 'mcq',
            'prompt' => $prompt,
            'question_text' => $prompt,
            'options' => $options,
            'correctIndex' => $correctIndex,
            'correct_answer' => $correctIndex,
            'marks' => $marks,
            'negative_marks' => $negativeMarks,
            'explanation' => trim((string) ($q['explanation'] ?? $q['solution'] ?? '')),
            'category' => self::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'difficulty' => self::normalizeDifficulty((string) ($q['difficulty'] ?? 'Medium')),
        ];
    }

    /**
     * Append MCQ questions to an existing test (bulk / question-bank import).
     *
     * @param array<int, array<string, mixed>> $incoming
     * @return array{added:int,total:int,test:array<string,mixed>}
     */
    public function appendQuestions(string $id, array $incoming, string $fallbackCategory = 'General Aptitude'): array
    {
        $test = $this->findById($id);
        if (!$test) {
            return ['added' => 0, 'total' => 0, 'test' => []];
        }
        $category = self::normalizeCategory((string) ($test['category'] ?? $fallbackCategory));
        $existing = array_values((array) ($test['questions'] ?? []));
        $start = count($existing);
        $added = 0;
        foreach (array_values($incoming) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = self::normalizeMcq($q, $category, $start + $i);
            if ($norm === null) {
                continue;
            }
            // Ensure unique ids within the test
            $baseId = $norm['id'];
            $n = 1;
            $ids = array_map(static fn ($x) => (string) ($x['id'] ?? ''), $existing);
            while (in_array($norm['id'], $ids, true)) {
                $norm['id'] = $baseId . '_' . $n;
                $n++;
            }
            $existing[] = $norm;
            $ids[] = $norm['id'];
            $added++;
        }
        $marks = 0.0;
        foreach ($existing as $q) {
            $marks += (float) ($q['marks'] ?? 1);
        }
        $this->update($id, [
            'questions' => $existing,
            'questionCount' => count($existing),
            'totalMarks' => $marks > 0 ? $marks : (float) count($existing),
            'updatedAt' => DocumentHelper::now(),
        ]);
        $fresh = $this->findById($id) ?: $test;
        return [
            'added' => $added,
            'total' => count($existing),
            'test' => self::publicView($fresh, true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $data): array
    {
        $category = self::normalizeCategory((string) ($data['category'] ?? 'General Aptitude'));
        $questions = [];
        foreach (array_values((array) ($data['questions'] ?? [])) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = self::normalizeMcq($q, $category, (int) $i);
            if ($norm !== null) {
                $questions[] = $norm;
            }
        }

        $questionCount = (int) ($data['questionCount'] ?? $data['numberOfQuestions'] ?? count($questions));
        if ($questionCount < count($questions)) {
            $questionCount = count($questions);
        }
        if ($questions !== [] && (int) ($data['questionCount'] ?? 0) === 0) {
            $questionCount = count($questions);
        }

        $marksFromQuestions = 0.0;
        foreach ($questions as $q) {
            $marksFromQuestions += (float) ($q['marks'] ?? 1);
        }
        $totalMarks = (float) ($data['totalMarks'] ?? 0);
        if ($totalMarks <= 0) {
            $totalMarks = $marksFromQuestions > 0 ? $marksFromQuestions : (float) max(1, $questionCount);
        }

        $negativeMarking = filter_var($data['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = (float) ($data['negativeMarks'] ?? $data['negativeMarkValue'] ?? 0);
        if (!$negativeMarking) {
            $negativeMarks = 0.0;
        }
        if ($negativeMarks < 0) {
            $negativeMarks = abs($negativeMarks);
        }

        $questionSource = strtolower(trim((string) ($data['questionSource'] ?? 'manual'))) === 'random'
            ? 'random'
            : 'manual';
        $randomRules = [];
        foreach ((array) ($data['randomRules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $randomRules[] = [
                'category' => self::normalizeCategory((string) ($rule['category'] ?? $category)),
                'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                'count' => max(1, (int) ($rule['count'] ?? 1)),
            ];
        }
        $bankQuestionIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => trim((string) $id), (array) ($data['bankQuestionIds'] ?? [])),
            static fn ($id) => $id !== ''
        )));

        $payload = [
            'title' => trim((string) ($data['title'] ?? 'Aptitude mock')) ?: 'Aptitude mock',
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => $category,
            'difficulty' => self::normalizeDifficulty((string) ($data['difficulty'] ?? 'Medium')),
            'questionCount' => max(0, $questionCount),
            'durationMinutes' => max(1, (int) ($data['durationMinutes'] ?? $data['duration'] ?? 30)),
            'totalMarks' => $totalMarks,
            'negativeMarking' => $negativeMarking,
            'negativeMarks' => $negativeMarks,
            'instructions' => trim((string) ($data['instructions'] ?? '')),
            'status' => self::normalizeStatus((string) ($data['status'] ?? 'unpublished')),
            'contestType' => self::normalizeContestType((string) ($data['contestType'] ?? 'none')),
            'questionSource' => $questionSource,
            'randomRules' => $questionSource === 'random' ? $randomRules : [],
            'bankQuestionIds' => $questionSource === 'manual' ? $bankQuestionIds : [],
            'questionType' => 'mcq',
            'questions' => $questions,
        ];
        $contestType = $payload['contestType'];
        if ($contestType === 'weekly') {
            $payload['contestWeekday'] = max(1, min(7, (int) ($data['contestWeekday'] ?? 1)));
        } elseif ($contestType === 'monthly') {
            $payload['contestMonthDay'] = max(1, min(28, (int) ($data['contestMonthDay'] ?? 1)));
        }
        $deptOid = Security::toObjectId((string) ($data['departmentId'] ?? ''));
        if ($deptOid !== null) {
            $payload['departmentId'] = $deptOid;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTest(array $data): string
    {
        $payload = self::normalizePayload($data);
        $payload['createdBy'] = Security::toObjectId((string) ($data['createdBy'] ?? '')) ?: null;
        return $this->insert($payload);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTest(string $id, array $data): bool
    {
        $existing = $this->findById($id);
        if (!$existing) {
            return false;
        }
        $merged = array_merge($existing, $data);
        if (!array_key_exists('questions', $data)) {
            $merged['questions'] = $existing['questions'] ?? [];
        }
        $payload = self::normalizePayload($merged);
        $payload['updatedAt'] = DocumentHelper::now();
        return $this->update($id, $payload);
    }

    /**
     * Public-safe test shape (no correct answers unless requested).
     *
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    public static function publicView(array $test, bool $includeAnswers = false): array
    {
        $category = self::normalizeCategory((string) ($test['category'] ?? 'General Aptitude'));
        $questions = [];
        foreach ((array) ($test['questions'] ?? []) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = self::normalizeMcq($q, $category, (int) $i);
            if ($norm === null) {
                continue;
            }
            $row = [
                'id' => $norm['id'],
                'type' => 'mcq',
                'prompt' => $norm['prompt'],
                'options' => $norm['options'],
                'marks' => $norm['marks'],
                'category' => $norm['category'],
            ];
            if ($includeAnswers) {
                $row['correctIndex'] = $norm['correctIndex'];
                $row['explanation'] = $norm['explanation'];
            }
            $questions[] = $row;
        }

        $questionCount = (int) ($test['questionCount'] ?? count($questions));
        if ($questionCount <= 0) {
            $questionCount = count($questions);
        }

        $negativeMarking = filter_var($test['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = (float) ($test['negativeMarks'] ?? 0);

        return [
            'id' => (string) ($test['_id'] ?? ''),
            'title' => (string) ($test['title'] ?? ''),
            'description' => (string) ($test['description'] ?? ''),
            'category' => $category,
            'difficulty' => self::normalizeDifficulty((string) ($test['difficulty'] ?? 'Medium')),
            'questionCount' => $questionCount,
            'durationMinutes' => max(1, (int) ($test['durationMinutes'] ?? 30)),
            'totalMarks' => (float) ($test['totalMarks'] ?? max(1, $questionCount)),
            'negativeMarking' => $negativeMarking,
            'negativeMarks' => $negativeMarking ? $negativeMarks : 0.0,
            'instructions' => (string) ($test['instructions'] ?? ''),
            'status' => self::normalizeStatus((string) ($test['status'] ?? 'unpublished')),
            'contestType' => self::normalizeContestType((string) ($test['contestType'] ?? 'none')),
            'contestWeekday' => isset($test['contestWeekday']) ? (int) $test['contestWeekday'] : null,
            'contestMonthDay' => isset($test['contestMonthDay']) ? (int) $test['contestMonthDay'] : null,
            'contestScheduleLabel' => self::contestScheduleLabel($test),
            'contestOpen' => self::isContestOpen($test),
            'questionSource' => in_array((string) ($test['questionSource'] ?? 'manual'), ['random'], true)
                ? 'random'
                : 'manual',
            'randomRules' => array_values(array_map(
                static function ($rule): array {
                    if (!is_array($rule)) {
                        return [];
                    }

                    return [
                        'category' => self::normalizeCategory((string) ($rule['category'] ?? 'General Aptitude')),
                        'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                        'count' => max(1, (int) ($rule['count'] ?? 1)),
                    ];
                },
                (array) ($test['randomRules'] ?? [])
            )),
            'bankQuestionIds' => array_values(array_filter(array_map(
                static fn ($id) => trim((string) $id),
                (array) ($test['bankQuestionIds'] ?? [])
            ))),
            'questionType' => 'mcq',
            'questions' => $questions,
            'departmentId' => isset($test['departmentId']) ? (string) $test['departmentId'] : null,
            'createdAt' => $test['createdAt'] ?? null,
            'updatedAt' => $test['updatedAt'] ?? null,
            'categories' => self::CATEGORIES,
            'difficulties' => self::DIFFICULTIES,
        ];
    }
}
