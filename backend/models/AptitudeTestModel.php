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

    public static function normalizeTestKind(string $value): string
    {
        $raw = strtolower(trim($value));
        return $raw === 'company' ? 'company' : 'regular';
    }

    public static function normalizeQuestionSource(string $value): string
    {
        $raw = strtolower(trim($value));
        if ($raw === 'random_jd') {
            return 'random_jd';
        }
        if ($raw === 'random') {
            return 'random';
        }

        return 'manual';
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function isContest(array $test): bool
    {
        return in_array(self::normalizeContestType((string) ($test['contestType'] ?? 'none')), ['weekly', 'monthly'], true);
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function isCompanyTest(array $test): bool
    {
        if (self::normalizeTestKind((string) ($test['testKind'] ?? '')) === 'company') {
            return true;
        }

        return trim((string) ($test['companyId'] ?? '')) !== '';
    }

    /**
     * Regular tests always expose results. Contests stay hidden until published.
     *
     * @param array<string, mixed> $test
     */
    public static function resultsPublished(array $test): bool
    {
        if (!self::isContest($test)) {
            return true;
        }

        return filter_var($test['resultsPublished'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function isContestOpen(array $test, ?\DateTimeInterface $now = null): bool
    {
        return self::contestStatus($test, $now) === 'ACTIVE';
    }

    public static function normalizeContestTime(string $raw, string $default = '00:00'): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
        }

        return $default;
    }

    /**
     * Start/end timestamps for a contest occurrence on a calendar day.
     *
     * @param array<string, mixed> $test
     * @return array{start:\DateTimeImmutable,end:\DateTimeImmutable}
     */
    public static function contestDayWindow(\DateTimeImmutable $occurrenceDate, array $test): array
    {
        $startTime = self::normalizeContestTime((string) ($test['contestStartTime'] ?? ''), '00:00');
        $endTime = self::normalizeContestTime((string) ($test['contestEndTime'] ?? ''), '23:59');
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));
        $day = $occurrenceDate->setTime(0, 0, 0);
        $start = $day->setTime($sh, $sm, 0);
        $end = $day->setTime($eh, $em, 59);
        if ($end <= $start) {
            $end = $start->modify('+1 hour');
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function contestTimeLabel(array $test): string
    {
        $start = trim((string) ($test['contestStartTime'] ?? ''));
        $end = trim((string) ($test['contestEndTime'] ?? ''));
        if ($start === '' && $end === '') {
            return '';
        }

        return ' · '
            . self::normalizeContestTime($start, '00:00')
            . '–'
            . self::normalizeContestTime($end, '23:59');
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function contestCreatedAt(array $test): ?\DateTimeImmutable
    {
        $raw = $test['createdAt'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function lastWeeklyOccurrenceStart(int $want, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $today = (int) $now->format('N');
        $daysSince = ($today - $want + 7) % 7;

        return $now->setTime(0, 0, 0)->modify("-{$daysSince} days");
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function nextWeeklyOccurrenceStart(int $want, \DateTimeImmutable $now, array $test = []): \DateTimeImmutable
    {
        $today = (int) $now->format('N');
        $daysUntil = ($want - $today + 7) % 7;
        if ($daysUntil === 0) {
            if ($test !== []) {
                $window = self::contestDayWindow($now->setTime(0, 0, 0), $test);
                if ($now > $window['end']) {
                    $daysUntil = 7;
                }
            } else {
                $end = $now->setTime(23, 59, 59);
                if ($now > $end) {
                    $daysUntil = 7;
                }
            }
        }

        return $now->setTime(0, 0, 0)->modify("+{$daysUntil} days");
    }

    private static function lastMonthlyOccurrenceStart(int $want, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $dom = (int) $now->format('j');
        if ($dom < $want) {
            $month--;
            if ($month < 1) {
                $month = 12;
                $year--;
            }
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $want));
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function nextMonthlyOccurrenceStart(int $want, \DateTimeImmutable $now, array $test = []): \DateTimeImmutable
    {
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $dom = (int) $now->format('j');
        if ($dom === $want && $test !== []) {
            $occ = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $want));
            $window = self::contestDayWindow($occ, $test);
            if ($now > $window['end']) {
                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            } else {
                return $occ;
            }
        } elseif ($dom > $want) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $want));
    }

    /**
     * Lifecycle for scheduled contests: UPCOMING → ACTIVE → COMPLETED (per occurrence).
     *
     * @param array<string, mixed> $test
     */
    public static function contestStatus(array $test, ?\DateTimeInterface $now = null): string
    {
        if (!self::isContest($test)) {
            return 'ACTIVE';
        }
        $now = $now instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($now)
            : new \DateTimeImmutable('now');
        $created = self::contestCreatedAt($test);
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'weekly') {
            $want = (int) ($test['contestWeekday'] ?? 0);
            if ($want < 1 || $want > 7) {
                return 'UPCOMING';
            }
            $today = (int) $now->format('N');
            if ($today === $want) {
                $occ = self::lastWeeklyOccurrenceStart($want, $now);
                $window = self::contestDayWindow($occ, $test);
                if ($now < $window['start']) {
                    return 'UPCOMING';
                }

                return $now <= $window['end'] ? 'ACTIVE' : 'COMPLETED';
            }
            $daysSince = ($today - $want + 7) % 7;
            if ($daysSince >= 1 && $daysSince <= 3) {
                $lastOcc = self::lastWeeklyOccurrenceStart($want, $now);
                if ($created !== null && $created <= $lastOcc) {
                    return 'COMPLETED';
                }

                return 'UPCOMING';
            }

            return 'UPCOMING';
        }
        $want = (int) ($test['contestMonthDay'] ?? 0);
        if ($want < 1 || $want > 28) {
            return 'UPCOMING';
        }
        $todayDom = (int) $now->format('j');
        if ($todayDom === $want) {
            $occ = self::lastMonthlyOccurrenceStart($want, $now);
            $window = self::contestDayWindow($occ, $test);
            if ($now < $window['start']) {
                return 'UPCOMING';
            }

            return $now <= $window['end'] ? 'ACTIVE' : 'COMPLETED';
        }
        if ($todayDom > $want) {
            $lastOcc = self::lastMonthlyOccurrenceStart($want, $now);
            if ($created !== null && $created <= $lastOcc) {
                return 'COMPLETED';
            }

            return 'UPCOMING';
        }

        return 'UPCOMING';
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function resultStatus(array $test): string
    {
        if (!self::isContest($test)) {
            return 'PUBLISHED';
        }

        return self::resultsPublished($test) ? 'PUBLISHED' : 'PENDING';
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function resultPublishedAt(array $test): ?string
    {
        $raw = trim((string) ($test['resultPublishedAt'] ?? ''));
        if ($raw === '') {
            return null;
        }

        return $raw;
    }

    /**
     * Start/end timestamps for the current contest occurrence window.
     *
     * @param array<string, mixed> $test
     * @return array{start:?string,end:?string}
     */
    public static function contestWindow(array $test, ?\DateTimeInterface $now = null): array
    {
        if (!self::isContest($test)) {
            return ['start' => null, 'end' => null];
        }
        $now = $now instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($now)
            : new \DateTimeImmutable('now');
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        $status = self::contestStatus($test, $now);
        if ($type === 'weekly') {
            $want = (int) ($test['contestWeekday'] ?? 0);
            if ($want < 1 || $want > 7) {
                return ['start' => null, 'end' => null];
            }
            $occurrence = $status === 'UPCOMING'
                ? self::nextWeeklyOccurrenceStart($want, $now, $test)
                : self::lastWeeklyOccurrenceStart($want, $now);
            $window = self::contestDayWindow($occurrence, $test);

            return [
                'start' => $window['start']->format(DATE_ATOM),
                'end' => $window['end']->format(DATE_ATOM),
            ];
        }
        $want = (int) ($test['contestMonthDay'] ?? 0);
        if ($want < 1 || $want > 28) {
            return ['start' => null, 'end' => null];
        }
        try {
            $occurrence = $status === 'UPCOMING'
                ? self::nextMonthlyOccurrenceStart($want, $now, $test)
                : self::lastMonthlyOccurrenceStart($want, $now);
        } catch (\Throwable) {
            return ['start' => null, 'end' => null];
        }
        $window = self::contestDayWindow($occurrence, $test);

        return [
            'start' => $window['start']->format(DATE_ATOM),
            'end' => $window['end']->format(DATE_ATOM),
        ];
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

            $times = self::contestTimeLabel($test);

            return $day >= 1 && $day <= 7 ? 'Weekly · ' . $days[$day] . $times : 'Weekly contest';
        }
        if ($type === 'monthly') {
            $dom = (int) ($test['contestMonthDay'] ?? 0);
            $times = self::contestTimeLabel($test);

            return $dom >= 1 && $dom <= 28 ? 'Monthly · day ' . $dom . $times : 'Monthly contest';
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
            array_map(static fn ($o) => self::sanitizeOptionText((string) $o), (array) ($q['options'] ?? [])),
            static fn ($o) => $o !== ''
        ));
        if ($prompt === '' || count($options) < 2) {
            return null;
        }
        $correctIndex = self::resolveCorrectIndex($q, $options);
        $explanation = trim((string) ($q['explanation'] ?? $q['solution'] ?? ''));
        if (empty($q['lockCorrectIndex']) && $explanation !== '') {
            $fromExplanation = self::findUniqueOptionInExplanation($options, $explanation);
            if ($fromExplanation !== null && $fromExplanation !== $correctIndex) {
                $currentOpt = strtolower(trim((string) ($options[$correctIndex] ?? '')));
                $currentSupported = $currentOpt !== ''
                    && self::optionAppearsInExplanation($currentOpt, strtolower($explanation));
                if (!$currentSupported) {
                    $correctIndex = $fromExplanation;
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
        $id = trim((string) ($q['id'] ?? $q['bankId'] ?? $q['_id'] ?? ''));
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
            'explanation' => $explanation,
            'category' => self::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'difficulty' => self::normalizeDifficulty((string) ($q['difficulty'] ?? 'Medium')),
        ];
    }

    public static function sanitizeOptionText(string $value): string
    {
        $text = trim($value);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/^[A-Da-d][\).\:\-\s]+/u', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param list<string> $options
     */
    private static function resolveCorrectIndex(array $q, array $options): int
    {
        $count = count($options);
        if ($count === 0) {
            return 0;
        }

        if (array_key_exists('correctIndex', $q) && $q['correctIndex'] !== '' && $q['correctIndex'] !== null) {
            $idx = (int) $q['correctIndex'];
            if ($idx >= 0 && $idx < $count) {
                return $idx;
            }
        }

        if (isset($q['answerIndex']) && $q['answerIndex'] !== '' && $q['answerIndex'] !== null) {
            $idx = (int) $q['answerIndex'];
            if ($idx >= 0 && $idx < $count) {
                return $idx;
            }
        }

        if (isset($q['correct_answer']) || isset($q['correctAnswer'])) {
            $rawCorrect = $q['correct_answer'] ?? $q['correctAnswer'];
            if (is_int($rawCorrect) || (is_string($rawCorrect) && ctype_digit($rawCorrect))) {
                $idx = (int) $rawCorrect;
                if ($idx >= 1 && $idx <= $count) {
                    return $idx - 1;
                }
                if ($idx >= 0 && $idx < $count) {
                    return $idx;
                }
            } elseif (is_string($rawCorrect)) {
                $letter = strtoupper(trim($rawCorrect));
                if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'Z') {
                    $idx = ord($letter) - ord('A');
                    if ($idx >= 0 && $idx < $count) {
                        return $idx;
                    }
                }
                foreach ($options as $i => $opt) {
                    if (strcasecmp($opt, trim($rawCorrect)) === 0) {
                        return (int) $i;
                    }
                }
            }
        }

        return 0;
    }

    public static function parseOptionNumeric(string $option): ?float
    {
        $text = trim($option);
        if ($text === '') {
            return null;
        }
        $stripped = preg_replace('/[^\d.\-]/', '', str_replace(',', '', $text)) ?? '';
        if ($stripped === '' || !is_numeric($stripped)) {
            return null;
        }

        return (float) $stripped;
    }

    public static function extractComputedNumericFromExplanation(string $explanation): ?float
    {
        $explanation = trim($explanation);
        if ($explanation === '') {
            return null;
        }
        if (preg_match_all('/=\s*(?:\$|₹|Rs\.?\s*)?([\d,]+(?:\.\d+)?)/iu', $explanation, $matches) !== false && $matches[1] !== []) {
            $last = (string) end($matches[1]);

            return (float) str_replace(',', '', $last);
        }

        return null;
    }

    /**
     * @param list<string> $options
     */
    public static function formatNumericLikeOptions(float $value, array $options): string
    {
        $usesDollar = false;
        foreach ($options as $opt) {
            if (str_contains((string) $opt, '$')) {
                $usesDollar = true;
                break;
            }
        }
        $rounded = abs($value - round($value)) < 0.001 ? (string) (int) round($value) : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $usesDollar ? '$' . $rounded : $rounded;
    }

    /**
     * Ensure one option matches the value derived from the explanation when possible.
     *
     * @param list<string> $options
     * @return array{options:list<string>,correctIndex:int}
     */
    public static function alignOptionsWithExplanation(array $options, string $explanation, int $hintIndex = 0): array
    {
        $hintIndex = max(0, min(3, $hintIndex));
        $options = array_values(array_slice($options, 0, 4));

        $fromExplanation = self::findUniqueOptionInExplanation($options, $explanation);
        if ($fromExplanation !== null) {
            return ['options' => $options, 'correctIndex' => $fromExplanation];
        }

        $computed = self::extractComputedNumericFromExplanation($explanation);
        if ($computed === null) {
            return ['options' => $options, 'correctIndex' => $hintIndex];
        }

        foreach ($options as $i => $opt) {
            $optNum = self::parseOptionNumeric($opt);
            if ($optNum !== null && abs($optNum - $computed) < 0.01) {
                return ['options' => $options, 'correctIndex' => $i];
            }
        }

        $replaceIndex = $hintIndex;
        $hintNum = self::parseOptionNumeric($options[$replaceIndex] ?? '');
        if ($hintNum !== null && abs($hintNum - $computed) >= 0.01) {
            $options[$replaceIndex] = self::formatNumericLikeOptions($computed, $options);
        } else {
            $bestIdx = $hintIndex;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($options as $i => $opt) {
                $n = self::parseOptionNumeric($opt);
                if ($n === null) {
                    continue;
                }
                $diff = abs($n - $computed);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestIdx = $i;
                }
            }
            $replaceIndex = $bestIdx;
            $options[$replaceIndex] = self::formatNumericLikeOptions($computed, $options);
        }

        return ['options' => $options, 'correctIndex' => $replaceIndex];
    }

    /**
     * Ensure the explanation explicitly references the marked correct option text.
     *
     * @param list<string> $options
     */
    public static function ensureExplanationMentionsCorrectOption(array $options, int $correctIndex, string $explanation): string
    {
        $correctIndex = max(0, min(3, $correctIndex));
        $opt = trim($options[$correctIndex] ?? '');
        $explanation = trim($explanation);
        if ($opt === '' || $explanation === '') {
            return $explanation;
        }
        if (self::optionAppearsInExplanation(strtolower($opt), strtolower($explanation))) {
            return $explanation;
        }

        return rtrim(rtrim($explanation, '.'), ' ') . '. The correct answer is ' . $opt . '.';
    }

    /**
     * @param list<string> $options
     */
    public static function findUniqueOptionInExplanation(array $options, string $explanation): ?int
    {
        $letterIndex = self::parseLetterFromExplanation($explanation);
        if ($letterIndex !== null && isset($options[$letterIndex])) {
            return $letterIndex;
        }

        $explanationNorm = strtolower($explanation);
        $matches = [];
        foreach ($options as $i => $opt) {
            $optNorm = strtolower(trim($opt));
            if ($optNorm === '' || strlen($optNorm) < 2) {
                continue;
            }
            if (!self::optionAppearsInExplanation($optNorm, $explanationNorm)) {
                continue;
            }
            $matches[] = ['index' => $i, 'len' => strlen($optNorm)];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $a, array $b): int => $b['len'] <=> $a['len']);
        $best = $matches[0]['index'];
        $bestLen = $matches[0]['len'];
        $tied = array_filter($matches, static fn (array $m): bool => $m['len'] === $bestLen);
        if (count($tied) > 1) {
            return null;
        }

        return $best;
    }

    private static function parseLetterFromExplanation(string $explanation): ?int
    {
        if (preg_match('/\b(?:option|choice|answer)\s*([A-Da-d])\b/i', $explanation, $m) === 1) {
            return ord(strtoupper($m[1])) - ord('A');
        }
        if (preg_match('/\b([A-Da-d])\s+(?:is|are)\s+(?:correct|the correct|right)\b/i', $explanation, $m) === 1) {
            return ord(strtoupper($m[1])) - ord('A');
        }

        return null;
    }

    private static function optionAppearsInExplanation(string $optNorm, string $explanationNorm): bool
    {
        $numeric = self::parseOptionNumeric($optNorm);
        if ($numeric !== null) {
            $formatted = self::formatNumericLikeOptions($numeric, [$optNorm]);
            $candidates = array_unique(array_filter([
                strtolower(trim($optNorm)),
                strtolower(trim($formatted)),
                strtolower(trim((string) (int) round($numeric))),
                strtolower(trim(number_format($numeric, 2, '.', ''))),
            ]));
            foreach ($candidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (preg_match('/^-?\d+(?:\.\d+)?%?$/', $candidate) === 1) {
                    $pattern = '/(?<!\d)' . preg_quote($candidate, '/') . '(?!\d)/u';
                    if (@preg_match($pattern, $explanationNorm) === 1) {
                        return true;
                    }
                } elseif (str_contains($explanationNorm, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return str_contains($explanationNorm, $optNorm);
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

        $questionSource = self::normalizeQuestionSource((string) ($data['questionSource'] ?? 'manual'));
        $randomRules = [];
        foreach ((array) ($data['randomRules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $marks = (float) ($rule['marks'] ?? 1);
            $randomRules[] = [
                'category' => self::normalizeCategory((string) ($rule['category'] ?? $category)),
                'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                'count' => max(1, (int) ($rule['count'] ?? 1)),
                'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
            ];
        }
        $bankQuestionIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => trim((string) $id), (array) ($data['bankQuestionIds'] ?? [])),
            static fn ($id) => $id !== ''
        )));
        $bankFilterRules = [];
        foreach ((array) ($data['bankFilterRules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $marks = (float) ($rule['marks'] ?? 1);
            $selectedQuestionIds = array_values(array_unique(array_filter(
                array_map(static fn ($id) => trim((string) $id), (array) ($rule['selectedQuestionIds'] ?? [])),
                static fn ($id) => $id !== ''
            )));
            $bankFilterRules[] = [
                'category' => self::normalizeCategory((string) ($rule['category'] ?? $category)),
                'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                'count' => max(1, (int) ($rule['count'] ?? 1)),
                'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
                'selectedQuestionIds' => $selectedQuestionIds,
            ];
        }
        $jdFilterRules = [];
        foreach ((array) ($data['jdFilterRules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $setId = trim((string) ($rule['jdSetId'] ?? ''));
            if ($setId === '') {
                continue;
            }
            $marks = (float) ($rule['marks'] ?? 1);
            $selectedQuestionIds = array_values(array_unique(array_filter(
                array_map(static fn ($id) => trim((string) $id), (array) ($rule['selectedQuestionIds'] ?? [])),
                static fn ($id) => $id !== ''
            )));
            $jdFilterRules[] = [
                'jdSetId' => $setId,
                'jdTitle' => trim((string) ($rule['jdTitle'] ?? '')),
                'count' => max(1, (int) ($rule['count'] ?? 1)),
                'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
                'selectedQuestionIds' => $selectedQuestionIds,
            ];
        }

        $testKind = self::normalizeTestKind((string) ($data['testKind'] ?? 'regular'));
        $companyId = trim((string) ($data['companyId'] ?? ''));
        $companyName = trim((string) ($data['companyName'] ?? ''));

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
            'status' => self::normalizeStatus((string) ($data['status'] ?? 'published')),
            'testKind' => $testKind,
            'contestType' => $testKind === 'company'
                ? 'none'
                : self::normalizeContestType((string) ($data['contestType'] ?? 'none')),
            'questionSource' => $questionSource,
            'randomRules' => $questionSource === 'random' ? $randomRules : [],
            'bankFilterRules' => $questionSource === 'manual' ? $bankFilterRules : [],
            'bankQuestionIds' => $questionSource === 'manual' ? $bankQuestionIds : [],
            'jdFilterRules' => in_array($questionSource, ['manual', 'random_jd'], true) ? $jdFilterRules : [],
            'questionType' => 'mcq',
            'questions' => $questions,
        ];
        if ($testKind === 'company' && $companyId !== '' && Security::isValidId($companyId)) {
            $payload['companyId'] = $companyId;
            $payload['companyName'] = $companyName !== '' ? $companyName : 'Company';
        }
        $contestType = $payload['contestType'];
        $payload['resultsPublished'] = $contestType === 'none'
            ? true
            : filter_var($data['resultsPublished'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (array_key_exists('resultPublishedAt', $data)) {
            $publishedAt = trim((string) ($data['resultPublishedAt'] ?? ''));
            $payload['resultPublishedAt'] = $publishedAt !== '' ? $publishedAt : null;
        }
        if ($contestType === 'weekly') {
            $payload['contestWeekday'] = max(1, min(7, (int) ($data['contestWeekday'] ?? 1)));
            $payload['contestStartTime'] = self::normalizeContestTime((string) ($data['contestStartTime'] ?? ''), '00:00');
            $payload['contestEndTime'] = self::normalizeContestTime((string) ($data['contestEndTime'] ?? ''), '23:59');
        } elseif ($contestType === 'monthly') {
            $payload['contestMonthDay'] = max(1, min(28, (int) ($data['contestMonthDay'] ?? 1)));
            $payload['contestStartTime'] = self::normalizeContestTime((string) ($data['contestStartTime'] ?? ''), '00:00');
            $payload['contestEndTime'] = self::normalizeContestTime((string) ($data['contestEndTime'] ?? ''), '23:59');
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
     * Remove answer fields from a normalized MCQ row (exam / student list).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function stripAnswerFields(array $row): array
    {
        unset(
            $row['correctIndex'],
            $row['correct_answer'],
            $row['correctAnswer'],
            $row['correctAnswerIndex'],
            $row['correct'],
            $row['correctOption'],
            $row['correctOptionLetter'],
            $row['explanation'],
            $row['solution'],
            $row['lockCorrectIndex']
        );

        return $row;
    }

    /**
     * Normalize all MCQs on a test with stable unique ids (fixes legacy duplicate q1 ids).
     *
     * @param array<string, mixed> $test
     * @return array<int, array<string, mixed>>
     */
    public static function normalizedQuestions(array $test): array
    {
        $category = self::normalizeCategory((string) ($test['category'] ?? 'General Aptitude'));
        $usedIds = [];
        $questions = [];
        foreach (array_values((array) ($test['questions'] ?? [])) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            $norm = self::normalizeMcq($q, $category, (int) $i);
            if ($norm === null) {
                continue;
            }
            $bankId = trim((string) ($norm['bankId'] ?? $q['bankId'] ?? ''));
            $qid = trim((string) ($norm['id'] ?? ''));
            if ($qid === '' || isset($usedIds[$qid])) {
                $qid = $bankId !== '' ? $bankId : ('q' . ($i + 1));
            }
            while (isset($usedIds[$qid])) {
                $qid = ($bankId !== '' ? $bankId : ('q' . ($i + 1))) . '_' . count($usedIds);
            }
            $norm['id'] = $qid;
            if ($bankId !== '') {
                $norm['bankId'] = $bankId;
            }
            $usedIds[$qid] = true;
            $questions[] = $norm;
        }

        return $questions;
    }

    /**
     * Public-safe test shape (no correct answers unless requested).
     *
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    public static function publicView(array $test, bool $includeAnswers = false): array
    {
        $normalized = self::normalizedQuestions($test);
        $category = trim((string) ($test['category'] ?? ''));
        if ($category === '' && $normalized !== []) {
            $category = (string) ($normalized[0]['category'] ?? '');
        }
        $category = self::normalizeCategory($category !== '' ? $category : 'General Aptitude');
        $questions = [];
        foreach ($normalized as $norm) {
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
            } else {
                $row = self::stripAnswerFields($row);
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
            'testKind' => self::normalizeTestKind((string) ($test['testKind'] ?? 'regular')),
            'companyId' => trim((string) ($test['companyId'] ?? '')) !== '' ? (string) $test['companyId'] : null,
            'companyName' => trim((string) ($test['companyName'] ?? '')) !== '' ? (string) $test['companyName'] : null,
            'contestType' => self::normalizeContestType((string) ($test['contestType'] ?? 'none')),
            'contestWeekday' => isset($test['contestWeekday']) ? (int) $test['contestWeekday'] : null,
            'contestMonthDay' => isset($test['contestMonthDay']) ? (int) $test['contestMonthDay'] : null,
            'contestStartTime' => trim((string) ($test['contestStartTime'] ?? '')) !== ''
                ? self::normalizeContestTime((string) $test['contestStartTime'], '00:00')
                : null,
            'contestEndTime' => trim((string) ($test['contestEndTime'] ?? '')) !== ''
                ? self::normalizeContestTime((string) $test['contestEndTime'], '23:59')
                : null,
            'contestScheduleLabel' => self::contestScheduleLabel($test),
            'contestOpen' => self::isContestOpen($test),
            'contestStatus' => self::contestStatus($test),
            'contestWindow' => self::contestWindow($test),
            'resultsPublished' => self::resultsPublished($test),
            'resultStatus' => self::resultStatus($test),
            'resultPublishedAt' => self::resultPublishedAt($test),
            'questionSource' => self::normalizeQuestionSource((string) ($test['questionSource'] ?? 'manual')),
            'randomRules' => array_values(array_map(
                static function ($rule): array {
                    if (!is_array($rule)) {
                        return [];
                    }

                    $marks = (float) ($rule['marks'] ?? 1);

                    return [
                        'category' => self::normalizeCategory((string) ($rule['category'] ?? 'General Aptitude')),
                        'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                        'count' => max(1, (int) ($rule['count'] ?? 1)),
                        'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
                    ];
                },
                (array) ($test['randomRules'] ?? [])
            )),
            'bankFilterRules' => array_values(array_map(
                static function ($rule): array {
                    if (!is_array($rule)) {
                        return [];
                    }
                    $marks = (float) ($rule['marks'] ?? 1);

                    return [
                        'category' => self::normalizeCategory((string) ($rule['category'] ?? 'General Aptitude')),
                        'difficulty' => self::normalizeDifficulty((string) ($rule['difficulty'] ?? 'Medium')),
                        'count' => max(1, (int) ($rule['count'] ?? 1)),
                        'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
                        'selectedQuestionIds' => array_values(array_filter(array_map(
                            static fn ($id) => trim((string) $id),
                            (array) ($rule['selectedQuestionIds'] ?? [])
                        ))),
                    ];
                },
                (array) ($test['bankFilterRules'] ?? [])
            )),
            'bankQuestionIds' => array_values(array_filter(array_map(
                static fn ($id) => trim((string) $id),
                (array) ($test['bankQuestionIds'] ?? [])
            ))),
            'jdFilterRules' => array_values(array_map(
                static function ($rule): array {
                    if (!is_array($rule)) {
                        return [];
                    }
                    $marks = (float) ($rule['marks'] ?? 1);

                    return [
                        'jdSetId' => trim((string) ($rule['jdSetId'] ?? '')),
                        'jdTitle' => trim((string) ($rule['jdTitle'] ?? '')),
                        'count' => max(1, (int) ($rule['count'] ?? 1)),
                        'marks' => $marks > 0 ? max(0.5, $marks) : 1.0,
                        'selectedQuestionIds' => array_values(array_filter(array_map(
                            static fn ($id) => trim((string) $id),
                            (array) ($rule['selectedQuestionIds'] ?? [])
                        ))),
                    ];
                },
                (array) ($test['jdFilterRules'] ?? [])
            )),
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
