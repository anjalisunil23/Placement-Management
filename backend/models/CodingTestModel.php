<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class CodingTestModel extends BaseModel
{
    private static bool $tableReady = false;

    public const CATEGORIES = ['Programming', 'Python', 'Data Structures', 'Programming Logic', 'Algorithms'];
    public const DIFFICULTIES = ['Easy', 'Medium', 'Hard'];
    public const STATUSES = ['published', 'unpublished'];

    public static function normalizeContestType(string $value): string
    {
        $raw = strtolower(trim($value));
        return in_array($raw, ['weekly', 'monthly'], true) ? $raw : 'none';
    }

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
            return $want >= 1 && $want <= 7 && (int) $now->format('N') === $want;
        }
        $want = (int) ($test['contestMonthDay'] ?? 0);
        return $want >= 1 && $want <= 28 && (int) $now->format('j') === $want;
    }

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
        return Collections::CODING_TESTS;
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
            'CREATE TABLE IF NOT EXISTS `coding_tests` (
              id CHAR(24) NOT NULL PRIMARY KEY,
              payload JSON NOT NULL,
              created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $items = [];
        foreach (array_values((array) ($data['items'] ?? [])) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $title = trim((string) ($q['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = [
                'id' => (string) ($q['id'] ?? ('p-' . ($i + 1))),
                'title' => $title,
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
                'category' => (string) ($q['category'] ?? $data['category'] ?? 'Programming'),
            ];
        }
        $marks = 0.0;
        foreach ($items as $item) {
            $marks += (float) ($item['marks'] ?? 0);
        }
        $status = strtolower(trim((string) ($data['status'] ?? 'unpublished')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'unpublished';
        }
        return [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => (string) ($data['description'] ?? ''),
            'category' => (string) ($data['category'] ?? 'Programming'),
            'difficulty' => (string) ($data['difficulty'] ?? 'Medium'),
            'duration' => max(1, (int) ($data['duration'] ?? $data['durationMinutes'] ?? 20)),
            'durationMinutes' => max(1, (int) ($data['duration'] ?? $data['durationMinutes'] ?? 20)),
            'status' => $status,
            'contestType' => self::normalizeContestType((string) ($data['contestType'] ?? 'none')),
            'contestWeekday' => (int) ($data['contestWeekday'] ?? 1),
            'contestMonthDay' => (int) ($data['contestMonthDay'] ?? 1),
            'instructions' => array_values((array) ($data['instructions'] ?? [])),
            'items' => $items,
            'questions' => count($items),
            'questionCount' => count($items),
            'marks' => $marks,
            'totalMarks' => $marks,
            'departmentId' => (string) ($data['departmentId'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    public static function publicView(array $test, bool $includeHidden = false): array
    {
        $id = (string) ($test['_id'] ?? $test['id'] ?? '');
        $items = [];
        foreach ((array) ($test['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cases = [];
            foreach ((array) ($item['testCases'] ?? []) as $tc) {
                if (!is_array($tc)) {
                    continue;
                }
                $sample = !empty($tc['sample']);
                if ($sample || $includeHidden) {
                    $cases[] = $tc;
                } else {
                    $cases[] = [
                        'id' => $tc['id'] ?? '',
                        'sample' => false,
                        'label' => $tc['label'] ?? 'Hidden Test Case',
                    ];
                }
            }
            $row = $item;
            $row['testCases'] = $cases;
            $items[] = $row;
        }
        $contestType = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        return [
            'id' => $id,
            'title' => (string) ($test['title'] ?? ''),
            'description' => (string) ($test['description'] ?? ''),
            'category' => (string) ($test['category'] ?? 'Programming'),
            'difficulty' => (string) ($test['difficulty'] ?? 'Medium'),
            'duration' => (int) ($test['duration'] ?? $test['durationMinutes'] ?? 20),
            'durationMinutes' => (int) ($test['duration'] ?? $test['durationMinutes'] ?? 20),
            'status' => (string) ($test['status'] ?? 'unpublished'),
            'contestType' => $contestType,
            'contestWeekday' => (int) ($test['contestWeekday'] ?? 1),
            'contestMonthDay' => (int) ($test['contestMonthDay'] ?? 1),
            'contestOpen' => self::isContestOpen($test),
            'contestScheduleLabel' => self::contestScheduleLabel($test),
            'instructions' => array_values((array) ($test['instructions'] ?? [])),
            'questions' => count($items),
            'questionCount' => count($items),
            'marks' => (float) ($test['marks'] ?? $test['totalMarks'] ?? 0),
            'totalMarks' => (float) ($test['totalMarks'] ?? $test['marks'] ?? 0),
            'items' => $includeHidden ? array_values((array) ($test['items'] ?? [])) : $items,
            'departmentId' => (string) ($test['departmentId'] ?? ''),
        ];
    }

    public function saveNew(array $data): string
    {
        $payload = self::normalize($data);
        $payload['updatedAt'] = DocumentHelper::now();
        return $this->insert($payload);
    }

    public function saveExisting(string $id, array $data): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }
        $payload = self::normalize($data);
        $payload['updatedAt'] = DocumentHelper::now();
        return $this->update($id, $payload);
    }
}
