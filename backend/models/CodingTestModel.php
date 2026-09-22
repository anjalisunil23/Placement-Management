<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

class CodingTestModel extends BaseModel
{
    private static bool $tableReady = false;

    private const CONTEST_START_DEFAULT = '09:00';
    private const CONTEST_WINDOW_HOURS = 24;

    public const CATEGORIES = ['Algorithms', 'Database', 'Shell', 'Concurrency', 'JavaScript', 'pandas'];

    /** @var array<string, string> */
    private const LEGACY_CATEGORIES = [
        'Programming' => 'Algorithms',
        'Python' => 'Shell',
        'Data Structures' => 'Algorithms',
        'Programming Logic' => 'Algorithms',
    ];

    public static function normalizeCategory(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return 'Algorithms';
        }
        if (isset(self::LEGACY_CATEGORIES[$raw])) {
            return self::LEGACY_CATEGORIES[$raw];
        }
        foreach (self::CATEGORIES as $cat) {
            if (strcasecmp($cat, $raw) === 0) {
                return $cat;
            }
        }

        return 'Algorithms';
    }
    public const DIFFICULTIES = ['Easy', 'Medium', 'Hard'];
    public const STATUSES = ['published', 'unpublished'];
    public const PROBLEM_TEST_SEP = '::';

    public static function normalizeContestType(string $value): string
    {
        $raw = strtolower(trim($value));
        return in_array($raw, ['weekly', 'monthly'], true) ? $raw : 'none';
    }

    public static function normalizeTestKind(string $value): string
    {
        return strtolower(trim($value)) === 'company' ? 'company' : 'regular';
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

    public static function normalizeDifficulty(string $value): string
    {
        $raw = ucfirst(strtolower(trim($value)));

        return in_array($raw, self::DIFFICULTIES, true) ? $raw : 'Medium';
    }

    public static function isCompanyTest(array $test): bool
    {
        if (self::normalizeTestKind((string) ($test['testKind'] ?? '')) === 'company') {
            return true;
        }

        return trim((string) ($test['companyId'] ?? '')) !== '';
    }

    public static function composeProblemTestId(string $parentId, string $problemId): string
    {
        return $parentId . self::PROBLEM_TEST_SEP . $problemId;
    }

    /**
     * @return array{0:string,1:string}
     */
    public static function parseProblemTestId(string $id): array
    {
        $pos = strpos($id, self::PROBLEM_TEST_SEP);
        if ($pos === false) {
            return [$id, ''];
        }

        return [substr($id, 0, $pos), substr($id, $pos + strlen(self::PROBLEM_TEST_SEP))];
    }

    public static function shouldSplitForStudentList(array $test): bool
    {
        $contest = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if (in_array($contest, ['weekly', 'monthly'], true)) {
            return false;
        }

        return count((array) ($test['items'] ?? [])) > 1;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $parent
     */
    public static function singleProblemDuration(array $item, array $parent = []): int
    {
        $diff = strtolower((string) ($item['difficulty'] ?? $parent['difficulty'] ?? 'medium'));
        if ($diff === 'easy') {
            return 15;
        }
        if ($diff === 'hard') {
            return 30;
        }

        return 20;
    }

    public static function normalizeContestStartTime(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return self::CONTEST_START_DEFAULT;
        }
        if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $raw)) {
            return self::CONTEST_START_DEFAULT;
        }

        [$hour, $minute] = array_map('intval', explode(':', $raw, 2));
        return sprintf('%02d:%02d', $hour, $minute);
    }

    public static function contestClock(?\DateTimeInterface $now = null): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        if ($now instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($now)->setTimezone($tz);
        }

        return new \DateTimeImmutable('now', $tz);
    }

    public static function isContest(array $test): bool
    {
        return in_array(self::normalizeContestType((string) ($test['contestType'] ?? 'none')), ['weekly', 'monthly'], true);
    }

    public static function resultsPublished(array $test): bool
    {
        return filter_var($test['resultsPublished'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public static function resultStatus(array $test): string
    {
        if (!self::isContest($test)) {
            return 'PUBLISHED';
        }

        return self::resultsPublished($test) ? 'PUBLISHED' : 'PENDING';
    }

    public static function resultPublishedAt(array $test): ?string
    {
        $raw = trim((string) ($test['resultPublishedAt'] ?? ''));
        return $raw !== '' ? $raw : null;
    }

    /**
     * @param array<string, mixed> $test
     * @return array{start:?string,end:?string}
     */
    public static function contestWindow(array $test, ?\DateTimeInterface $now = null): array
    {
        if (!self::isContest($test)) {
            return ['start' => null, 'end' => null];
        }
        $bounds = self::contestWindowBounds($test, $now);

        return [
            'start' => $bounds['start'] ?? null,
            'end' => $bounds['end'] ?? null,
        ];
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
        $clock = self::contestClock($now);
        if (self::isContestOpen($test, $clock)) {
            return 'ACTIVE';
        }
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        $created = self::contestCreatedAt($test);
        if ($type === 'weekly') {
            $want = (int) ($test['contestWeekday'] ?? 0);
            if ($want < 1 || $want > 7) {
                return 'UPCOMING';
            }
            $today = (int) $clock->format('N');
            if ($today === $want) {
                return 'COMPLETED';
            }
            $daysSince = ($today - $want + 7) % 7;
            if ($daysSince >= 1 && $daysSince <= 3) {
                $lastOcc = self::lastWeeklyOccurrenceStart($want, $clock);
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
        $todayDom = (int) $clock->format('j');
        if ($todayDom === $want) {
            return 'COMPLETED';
        }
        if ($todayDom > $want) {
            $lastOcc = self::lastMonthlyOccurrenceStart($want, $clock);
            if ($created !== null && $created <= $lastOcc) {
                return 'COMPLETED';
            }

            return 'UPCOMING';
        }

        return 'UPCOMING';
    }

    public static function isContestOpen(array $test, ?\DateTimeInterface $now = null): bool
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'none') {
            return true;
        }

        $clock = self::contestClock($now);
        $bounds = self::contestWindowBounds($test, $clock);
        $start = self::contestClockFromValue($bounds['start'] ?? '');
        $end = self::contestClockFromValue($bounds['end'] ?? '');

        return $start < $end && $clock >= $start && $clock < $end;
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function contestCreatedAt(array $test): ?\DateTimeImmutable
    {
        $raw = trim((string) ($test['createdAt'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
    }

    private static function lastWeeklyOccurrenceStart(int $want, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $today = (int) $now->format('N');
        $daysSince = ($today - $want + 7) % 7;
        $d = $now->setTime(0, 0);
        if ($daysSince > 0) {
            $d = $d->modify('-' . $daysSince . ' days');
        }

        return $d;
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

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $want), new \DateTimeZone('Asia/Kolkata'));
    }

    /**
     * @param array<string, mixed> $test
     * @return array{start:?string,end:?string,startTimestamp:?int,endTimestamp:?int,periodKey:string}
     */
    public static function contestWindowBounds(array $test, mixed $when = null): array
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'none') {
            return [
                'start' => null,
                'end' => null,
                'startTimestamp' => null,
                'endTimestamp' => null,
                'periodKey' => '',
            ];
        }

        $start = self::contestOccurrenceStart($test, $when);
        if (!$start instanceof \DateTimeImmutable) {
            return [
                'start' => null,
                'end' => null,
                'startTimestamp' => null,
                'endTimestamp' => null,
                'periodKey' => '',
            ];
        }
        $end = $start->modify('+' . self::CONTEST_WINDOW_HOURS . ' hours');

        return [
            'start' => $start->format(DATE_ATOM),
            'end' => $end->format(DATE_ATOM),
            'startTimestamp' => $start->getTimestamp(),
            'endTimestamp' => $end->getTimestamp(),
            'periodKey' => self::contestPeriodKeyForStart($type, $start),
        ];
    }

    /**
     * @param array<string, mixed> $test
     */
    public static function periodKey(array $test, mixed $when = null): string
    {
        return (string) (self::contestWindowBounds($test, $when)['periodKey'] ?? '');
    }

    public static function contestPeriodKey(string $type, mixed $when = null): string
    {
        $dt = $when instanceof \DateTimeInterface
            ? self::contestClock($when)
            : self::contestClockFromValue($when);
        if ($type === 'monthly') {
            return $dt->format('Y-m');
        }

        return $dt->format('o-\WW');
    }

    public static function previousContestPeriodKey(string $type, ?\DateTimeInterface $now = null): string
    {
        $dt = self::contestClock($now);
        if ($type === 'monthly') {
            return $dt->modify('first day of last month')->format('Y-m');
        }

        return $dt->modify('-7 days')->format('o-\WW');
    }

    public static function contestClockFromValue(mixed $value): \DateTimeImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return self::contestClock();
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return self::contestClock();
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
    }

    public static function contestScheduleLabel(array $test): string
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        $startTime = self::normalizeContestStartTime((string) ($test['contestStartTime'] ?? self::CONTEST_START_DEFAULT));
        if ($type === 'weekly') {
            $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $day = (int) ($test['contestWeekday'] ?? 0);
            return $day >= 1 && $day <= 7 ? 'Weekly · ' . $days[$day] . ', ' . $startTime . ' IST' : 'Weekly contest';
        }
        if ($type === 'monthly') {
            $dom = (int) ($test['contestMonthDay'] ?? 0);
            return $dom >= 1 && $dom <= 28 ? 'Monthly · day ' . $dom . ', ' . $startTime . ' IST' : 'Monthly contest';
        }
        return '';
    }

    /**
     * @param array<string, mixed> $test
     */
    private static function contestOccurrenceStart(array $test, mixed $when = null): ?\DateTimeImmutable
    {
        $type = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        if ($type === 'none') {
            return null;
        }

        $clock = $when instanceof \DateTimeInterface
            ? self::contestClock($when)
            : self::contestClockFromValue($when);
        $startTime = self::normalizeContestStartTime((string) ($test['contestStartTime'] ?? self::CONTEST_START_DEFAULT));
        [$hour, $minute] = array_map('intval', explode(':', $startTime, 2));

        if ($type === 'weekly') {
            $want = max(1, min(7, (int) ($test['contestWeekday'] ?? 1)));
            $currentDow = (int) $clock->format('N');
            $candidate = $clock->setTime($hour, $minute)->modify(sprintf('%+d days', $want - $currentDow));
            if ($candidate > $clock) {
                $candidate = $candidate->modify('-7 days');
            }

            return $candidate;
        }

        $want = max(1, min(28, (int) ($test['contestMonthDay'] ?? 1)));
        $candidate = self::buildContestMonthDate((int) $clock->format('Y'), (int) $clock->format('n'), $want, $hour, $minute);
        if ($candidate > $clock) {
            $previousMonth = $clock->modify('first day of last month');
            $candidate = self::buildContestMonthDate((int) $previousMonth->format('Y'), (int) $previousMonth->format('n'), $want, $hour, $minute);
        }

        return $candidate;
    }

    private static function buildContestMonthDate(int $year, int $month, int $day, int $hour, int $minute): \DateTimeImmutable
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $base = new \DateTimeImmutable(sprintf('%04d-%02d-01 %02d:%02d:00', $year, $month, $hour, $minute), $tz);
        $maxDay = (int) $base->format('t');
        $day = max(1, min($day, $maxDay));

        return $base->setDate($year, $month, $day);
    }

    private static function contestPeriodKeyForStart(string $type, \DateTimeImmutable $start): string
    {
        if ($type === 'monthly') {
            return $start->format('Y-m');
        }

        return $start->format('o-\WW');
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
                'category' => self::normalizeCategory((string) ($q['category'] ?? $data['category'] ?? 'Algorithms')),
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
        $testKind = self::normalizeTestKind((string) ($data['testKind'] ?? 'regular'));
        $companyId = trim((string) ($data['companyId'] ?? ''));
        $companyName = trim((string) ($data['companyName'] ?? ''));
        $contestType = $testKind === 'company'
            ? 'none'
            : self::normalizeContestType((string) ($data['contestType'] ?? 'none'));
        $source = self::normalizeQuestionSource((string) ($data['questionSource'] ?? 'manual'));
        $jdFilterRules = [];
        foreach ((array) ($data['jdFilterRules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $jdFilterRules[] = [
                'jdSetId' => trim((string) ($rule['jdSetId'] ?? '')),
                'jdTitle' => trim((string) ($rule['jdTitle'] ?? $rule['setTitle'] ?? '')),
                'count' => max(0, (int) ($rule['count'] ?? 0)),
                'marks' => max(1, (float) ($rule['marks'] ?? 2)),
                'selectedQuestionIds' => array_values(array_filter(array_map(
                    static fn ($id): string => trim((string) $id),
                    (array) ($rule['selectedQuestionIds'] ?? $rule['selectedProblemIds'] ?? [])
                ))),
            ];
        }
        $payload = [
            'title' => trim((string) ($data['title'] ?? '')),
            'description' => (string) ($data['description'] ?? ''),
            'questionSource' => $source,
            'randomRules' => array_values(array_filter((array) ($data['randomRules'] ?? []), 'is_array')),
            'bankFilterRules' => array_values(array_filter((array) ($data['bankFilterRules'] ?? []), 'is_array')),
            'jdFilterRules' => in_array($source, ['manual', 'random_jd'], true) ? $jdFilterRules : [],
            'bankProblemIds' => array_values(array_filter(array_map(
                static fn ($id) => trim((string) $id),
                (array) ($data['bankProblemIds'] ?? [])
            ))),
            'category' => self::normalizeCategory((string) ($data['category'] ?? 'Algorithms')),
            'difficulty' => (string) ($data['difficulty'] ?? 'Medium'),
            'duration' => max(1, (int) ($data['duration'] ?? $data['durationMinutes'] ?? 20)),
            'durationMinutes' => max(1, (int) ($data['duration'] ?? $data['durationMinutes'] ?? 20)),
            'status' => $status,
            'testKind' => $testKind,
            'contestType' => $contestType,
            'contestWeekday' => (int) ($data['contestWeekday'] ?? 1),
            'contestMonthDay' => (int) ($data['contestMonthDay'] ?? 1),
            'contestStartTime' => self::normalizeContestStartTime((string) ($data['contestStartTime'] ?? self::CONTEST_START_DEFAULT)),
            'instructions' => array_values((array) ($data['instructions'] ?? [])),
            'items' => $items,
            'questions' => count($items),
            'questionCount' => count($items),
            'marks' => $marks,
            'totalMarks' => $marks,
            'departmentId' => (string) ($data['departmentId'] ?? ''),
            'resultsPublished' => $contestType === 'none'
                ? false
                : filter_var($data['resultsPublished'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'resultPublishedAt' => trim((string) ($data['resultPublishedAt'] ?? '')) !== ''
                ? (string) $data['resultPublishedAt']
                : null,
        ];
        if ($testKind === 'company' && $companyId !== '' && Security::isValidId($companyId)) {
            $payload['companyId'] = $companyId;
            $payload['companyName'] = $companyName !== '' ? $companyName : 'Company';
        }

        return $payload;
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
            $row['category'] = self::normalizeCategory((string) ($item['category'] ?? $test['category'] ?? 'Algorithms'));
            $items[] = $row;
        }
        $contestType = self::normalizeContestType((string) ($test['contestType'] ?? 'none'));
        return [
            'id' => $id,
            'title' => (string) ($test['title'] ?? ''),
            'description' => (string) ($test['description'] ?? ''),
            'category' => self::normalizeCategory((string) ($test['category'] ?? 'Algorithms')),
            'difficulty' => (string) ($test['difficulty'] ?? 'Medium'),
            'duration' => (int) ($test['duration'] ?? $test['durationMinutes'] ?? 20),
            'durationMinutes' => (int) ($test['duration'] ?? $test['durationMinutes'] ?? 20),
            'status' => (string) ($test['status'] ?? 'unpublished'),
            'contestType' => $contestType,
            'contestWeekday' => (int) ($test['contestWeekday'] ?? 1),
            'contestMonthDay' => (int) ($test['contestMonthDay'] ?? 1),
            'contestStartTime' => self::normalizeContestStartTime((string) ($test['contestStartTime'] ?? self::CONTEST_START_DEFAULT)),
            'contestOpen' => self::isContestOpen($test),
            'contestScheduleLabel' => self::contestScheduleLabel($test),
            'contestWindowBounds' => self::contestWindowBounds($test),
            'periodKey' => self::periodKey($test),
            'instructions' => array_values((array) ($test['instructions'] ?? [])),
            'questionSource' => self::normalizeQuestionSource((string) ($test['questionSource'] ?? 'manual')),
            'randomRules' => array_values((array) ($test['randomRules'] ?? [])),
            'bankFilterRules' => array_values((array) ($test['bankFilterRules'] ?? [])),
            'jdFilterRules' => array_values((array) ($test['jdFilterRules'] ?? [])),
            'questions' => count($items),
            'questionCount' => (int) ($test['questionCount'] ?? count($items)),
            'marks' => (float) ($test['marks'] ?? $test['totalMarks'] ?? 0),
            'totalMarks' => (float) ($test['totalMarks'] ?? $test['marks'] ?? 0),
            'items' => $includeHidden ? array_values((array) ($test['items'] ?? [])) : $items,
            'departmentId' => (string) ($test['departmentId'] ?? ''),
            'testKind' => self::normalizeTestKind((string) ($test['testKind'] ?? 'regular')),
            'companyId' => trim((string) ($test['companyId'] ?? '')) !== '' ? (string) $test['companyId'] : null,
            'companyName' => trim((string) ($test['companyName'] ?? '')) !== '' ? (string) $test['companyName'] : null,
            'resultsPublished' => self::resultsPublished($test),
            'resultStatus' => self::resultStatus($test),
            'resultPublishedAt' => self::resultPublishedAt($test),
            'contestStatus' => self::contestStatus($test),
            'contestWindow' => self::contestWindow($test),
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
