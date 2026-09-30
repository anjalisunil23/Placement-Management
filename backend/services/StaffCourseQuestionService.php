<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Generates multiple-choice questions from the official syllabus PDF loaded with Get.
 */
final class StaffCourseQuestionService
{
    private const MIN_COUNT = 5;
    private const MAX_COUNT = 20;
    private const COOLDOWN_SECONDS = 8;

    private OpenAIService $openai;

    public function __construct(?OpenAIService $openai = null)
    {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $user, array $body): array
    {
        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException('AI question generation is not configured. Contact the administrator.');
        }

        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');
        $course = $this->resolveLoadedSyllabus($body, $deptCode, $deptName, $deptShort);

        $count = (int) ($body['count'] ?? 10);
        if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
            throw new \InvalidArgumentException('Choose between 5 and 20 questions.');
        }

        $difficulty = $this->normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium'));
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? 'staff'));

        $syllabus = $this->syllabusText($course);
        if (mb_strlen($syllabus) < 80) {
            throw new \RuntimeException('Could not read that syllabus. Click Get, then generate questions.');
        }
        $system = 'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.';
        $userPrompt = <<<PROMPT
Course: {$course['code']} {$course['title']}
Difficulty: {$difficulty}
Write exactly {$count} multiple-choice questions.

Official syllabus:
{$syllabus}

Rules:
- Every question must be answerable from the syllabus above.
- Do not invent topics, tools, or outcomes that are not in the syllabus.
- Spread questions across the modules and course outcomes.
- Each question has exactly four distinct options and one correct answer.
- correctIndex is the 0-based index of the correct option.
- explanation states why that option is correct in one or two sentences.
- Do not copy a question verbatim from a sample paper if a more direct syllabus fact can be tested.

Return this JSON shape:
{"questions":[{"module":"Module 1","question":"...","options":["...","...","...","..."],"correctIndex":0,"explanation":"..."}]}
PROMPT;

        $raw = $this->openai->generateJson($system, $userPrompt);
        $questions = $this->normalizeQuestions($raw['questions'] ?? $raw, $count);
        if ($questions === []) {
            throw new \RuntimeException('AI did not return any usable questions. Please try again.');
        }

        $sessionId = bin2hex(random_bytes(16));
        $_SESSION['staff_course_practice'] = [
            'id' => $sessionId,
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'courseCode' => (string) $course['code'],
            'courseTitle' => (string) $course['title'],
            'difficulty' => $difficulty,
            'questions' => $questions,
            'createdAt' => time(),
        ];

        return [
            'sessionId' => $sessionId,
            'courseCode' => (string) $course['code'],
            'courseTitle' => (string) $course['title'],
            'difficulty' => $difficulty,
            'requested' => $count,
            'questions' => $this->questionsForPractice($questions),
        ];
    }

    /**
     * Grade a practice session. Answers are 0-based option indexes; -1 means unanswered.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submit(array $user, array $body): array
    {
        $session = $_SESSION['staff_course_practice'] ?? null;
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if (!is_array($session) || $sessionId === '' || (string) ($session['id'] ?? '') !== $sessionId) {
            throw new \InvalidArgumentException('This practice session has ended. Generate a new set of questions.');
        }
        if ((string) ($session['userId'] ?? '') !== $userId) {
            throw new \InvalidArgumentException('This practice session has ended. Generate a new set of questions.');
        }
        if ((time() - (int) ($session['createdAt'] ?? 0)) > 7200) {
            unset($_SESSION['staff_course_practice']);
            throw new \InvalidArgumentException('This practice session has expired. Generate a new set of questions.');
        }

        $answers = is_array($body['answers'] ?? null) ? array_values($body['answers']) : [];
        $questions = is_array($session['questions'] ?? null) ? $session['questions'] : [];
        $results = [];
        $score = 0;
        foreach ($questions as $index => $question) {
            if (!is_array($question)) {
                continue;
            }
            $selected = array_key_exists($index, $answers) ? (int) $answers[$index] : -1;
            $correctIndex = (int) ($question['correctIndex'] ?? -1);
            $correct = $selected === $correctIndex;
            if ($correct) {
                $score++;
            }
            $results[] = [
                'module' => (string) ($question['module'] ?? ''),
                'question' => (string) ($question['question'] ?? ''),
                'options' => array_values((array) ($question['options'] ?? [])),
                'selectedIndex' => $selected,
                'correctIndex' => $correctIndex,
                'correct' => $correct,
                'explanation' => (string) ($question['explanation'] ?? ''),
            ];
        }
        unset($_SESSION['staff_course_practice']);

        return [
            'courseCode' => (string) ($session['courseCode'] ?? ''),
            'courseTitle' => (string) ($session['courseTitle'] ?? ''),
            'difficulty' => (string) ($session['difficulty'] ?? ''),
            'score' => $score,
            'total' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    private function questionsForPractice(array $questions): array
    {
        $out = [];
        foreach ($questions as $question) {
            $out[] = [
                'module' => (string) ($question['module'] ?? ''),
                'question' => (string) ($question['question'] ?? ''),
                'options' => array_values((array) ($question['options'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function resolveLoadedSyllabus(array $body, string $deptCode, string $deptName, string $deptShort): array
    {
        $code = strtoupper(trim((string) ($body['courseCode'] ?? $body['code'] ?? '')));
        $semsubId = trim((string) ($body['semsubId'] ?? $body['id'] ?? ''));
        $title = trim((string) ($body['courseTitle'] ?? $body['title'] ?? ''));
        if ($code === '' || $semsubId === '') {
            throw new \InvalidArgumentException('Click Get to load the syllabus first.');
        }
        if (!CourseSyllabusCatalog::subjectVisibleToStaff($code, $deptCode, $deptName, $deptShort)) {
            throw new \InvalidArgumentException('That course is outside your department.');
        }

        $encid = AesSyllabusCipher::encrypt($semsubId);
        if ($encid === '') {
            throw new \RuntimeException('Could not encode that course id.');
        }
        try {
            $pdf = AesSyllabusCipher::fetchPdf($encid);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($e->getMessage(), 502);
        }
        $text = AesSyllabusCipher::extractText($pdf);

        return [
            'code' => $code,
            'title' => $title !== '' ? $title : $code,
            'semsubId' => $semsubId,
            'department' => CourseSyllabusCatalog::subjectDepartment($code),
            'scheme' => 'AES',
            'syllabusText' => $text,
        ];
    }

    /**
     * @param array<string, mixed> $course
     */
    private function syllabusText(array $course): string
    {
        $fromPdf = trim((string) ($course['syllabusText'] ?? ''));
        if ($fromPdf !== '') {
            return $fromPdf;
        }
        $lines = [];
        foreach ((array) ($course['modules'] ?? []) as $module) {
            if (!is_array($module)) {
                continue;
            }
            $lines[] = trim((string) ($module['name'] ?? 'Module')) . ': ' . trim((string) ($module['topics'] ?? ''));
        }

        return implode("\n", $lines);
    }

    private function normalizeDifficulty(string $value): string
    {
        $value = ucfirst(strtolower(trim($value)));
        if (!in_array($value, ['Easy', 'Medium', 'Hard'], true)) {
            throw new \InvalidArgumentException('Difficulty must be Easy, Medium, or Hard.');
        }

        return $value;
    }

    private function assertCooldown(string $userId): void
    {
        $key = 'staff_course_q_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $userId);
        $now = time();
        $last = (int) ($_SESSION[$key] ?? 0);
        if ($last > 0 && ($now - $last) < self::COOLDOWN_SECONDS) {
            throw new \RuntimeException('Please wait a few seconds before generating again.', 429);
        }
        $_SESSION[$key] = $now;
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeQuestions(mixed $raw, int $limit): array
    {
        if (!is_array($raw)) {
            return [];
        }
        if (isset($raw['question']) || isset($raw['options'])) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['prompt'] ?? ''));
            $options = [];
            foreach ((array) ($item['options'] ?? []) as $option) {
                $text = trim((string) $option);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
            $options = array_values(array_unique($options));
            if ($question === '' || count($options) !== 4) {
                continue;
            }
            $index = (int) ($item['correctIndex'] ?? $item['answerIndex'] ?? -1);
            if ($index < 0 || $index > 3) {
                continue;
            }
            $out[] = [
                'module' => trim((string) ($item['module'] ?? '')),
                'question' => $question,
                'options' => $options,
                'correctIndex' => $index,
                'explanation' => trim((string) ($item['explanation'] ?? '')),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
