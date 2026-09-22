<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingCompanyProblemSetModel;
use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;

/**
 * AI coding problem generation via OpenAI.
 */
final class CodingAiProblemService
{
    private const MAX_BATCH_COUNT = 10;
    private const MAX_TOTAL_COUNT = 30;

    private OpenAIService $openai;

    public function __construct(?OpenAIService $openai = null)
    {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(): array
    {
        return $this->openai->checkStatus();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateFromRequest(array $body): array
    {
        @set_time_limit(600);

        $category = $this->normalizeCategory(trim((string) ($body['category'] ?? '')));
        $topic = trim((string) ($body['topic'] ?? ''));
        $instructions = (string) ($body['instructions'] ?? '');
        if ($category === '') {
            throw new \InvalidArgumentException('Category is required.');
        }
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }

        $batches = $this->normalizeGenerationBatches($body);
        if ($batches === []) {
            throw new \InvalidArgumentException('Add at least one generation row with a problem count.');
        }

        $merged = [];
        foreach ($batches as $batch) {
            $result = $this->generate(
                $category,
                $topic,
                (string) ($batch['difficulty'] ?? 'Medium'),
                (int) ($batch['count'] ?? 0),
                $instructions
            );
            foreach ($result['problems'] ?? [] as $problem) {
                if (is_array($problem)) {
                    $merged[] = $problem;
                }
            }
        }

        if ($merged === []) {
            throw new \RuntimeException('No valid coding problems in the AI response. Try again.');
        }

        $preview = [];
        foreach ($merged as $i => $q) {
            $preview[] = array_merge($q, [
                'tempId' => 'ai-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'selected' => true,
            ]);
        }

        $requested = array_sum(array_map(static fn (array $b): int => (int) ($b['count'] ?? 0), $batches));

        return [
            'problems' => $preview,
            'questions' => $preview,
            'requested' => $requested,
            'received' => count($preview),
            'partial' => count($preview) < $requested,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $category, string $topic, string $difficulty, int $count, string $instructions = ''): array
    {
        $category = $this->normalizeCategory(trim($category));
        $difficulty = $this->normalizeDifficulty($difficulty);
        $topic = trim($topic);
        $count = max(1, min(self::MAX_BATCH_COUNT, $count));
        if ($category === '') {
            throw new \InvalidArgumentException('Category is required.');
        }
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }

        $system = <<<'SYSTEM'
You generate stdin/stdout coding problems for a university placement portal (HackerRank / CodeChef style).
Students write a standalone Python program that reads from stdin and prints to stdout.
NEVER generate LeetCode-style problems: no class stubs, no method signatures to fill in, no multithreading puzzles, no "modify the given code", no problem numbers like "1115.".
Return ONLY valid JSON with no markdown or commentary.
SYSTEM;
        $user = $this->buildPrompt($category, $topic, $difficulty, $count, $instructions);

        try {
            $raw = $this->openai->generateJson($system, $user);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS coding AI] generate failed: ' . $e->getMessage());
            throw new \RuntimeException('AI generation is temporarily unavailable. Please try again.');
        }

        $problems = $this->extractProblems($raw);

        $validated = [];
        foreach ($problems as $i => $p) {
            $mapped = $this->mapAiProblem(is_array($p) ? $p : [], $category, $difficulty);
            if ($mapped !== null) {
                $validated[] = $mapped;
            }
        }
        if ($validated === []) {
            throw new \RuntimeException(
                'No valid stdin/stdout problems were generated. Avoid LeetCode-style class/threading templates and ensure 3 test cases per problem. Try again.'
            );
        }

        $preview = [];
        foreach ($validated as $i => $q) {
            $preview[] = array_merge($q, [
                'tempId' => 'ai-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'selected' => true,
            ]);
        }

        return [
            'problems' => $preview,
            'questions' => $preview,
            'requested' => $count,
            'received' => count($preview),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $problems
     * @return array<string, mixed>
     */
    public function saveApproved(array $problems): array
    {
        $bank = new CodingProblemBankModel();
        $added = 0;
        $skipped = [];
        foreach (array_values($problems) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            if (array_key_exists('selected', $q) && empty($q['selected'])) {
                continue;
            }
            $source = trim((string) ($q['source'] ?? 'AI'));
            if ($source === 'AI_JD') {
                throw new \InvalidArgumentException(
                    'JD-based problems must be saved to the Company Block, not the general question bank.'
                );
            }
            $mapped = $this->mapAiProblem($q, (string) ($q['category'] ?? 'Programming'), (string) ($q['difficulty'] ?? 'Medium'));
            if ($mapped === null || trim((string) ($mapped['title'] ?? '')) === '') {
                $skipped[] = 'Problem ' . ($i + 1) . ' was incomplete.';
                continue;
            }
            $bank->saveProblem($mapped, null);
            $added++;
        }
        if ($added === 0) {
            throw new \RuntimeException('No problems were saved. Select at least one complete problem.');
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    private function normalizeCategory(string $value): string
    {
        return CodingTestModel::normalizeCategory($value);
    }

    private function normalizeDifficulty(string $value): string
    {
        $raw = ucfirst(strtolower(trim($value)));
        return in_array($raw, CodingTestModel::DIFFICULTIES, true) ? $raw : 'Medium';
    }

    private function buildPrompt(string $category, string $topic, string $difficulty, int $count, string $instructions): string
    {
        $extra = trim($instructions);
        $extraLine = $extra !== '' ? "Additional instructions: {$extra}\n" : '';
        return <<<PROMPT
Generate campus-placement coding problems for college students.
Category: {$category}
Topic: {$topic}
Difficulty: {$difficulty}
Count: {$count}
{$extraLine}

FORMAT (mandatory for every problem):
- Standalone stdin/stdout program — the student writes one Python script from scratch.
- Clear sections: description, inputFormat, outputFormat, constraints, one worked example.
- Exactly 3 test cases per problem: 1 sample (shown to student) + 2 hidden.
- Title is a short descriptive name only — NO LeetCode numbers, NO "Implement class X".

FORBIDDEN (never generate):
- LeetCode / interview templates with provided class or method stubs
- Multithreading, mutex, semaphore, or "two threads call foo()/bar()" puzzles
- "Modify the given program/code" or filling in a pre-written class
- Premium/company tags, problem IDs like "1115.", or copy-pasted LeetCode wording

GOOD example shape:
Title: "Reverse Words in a Sentence"
Description: Given a sentence, print the words in reverse order.
inputFormat: One line containing the sentence S.
outputFormat: Words of S reversed, space-separated, on one line.
constraints: 1 <= number of words <= 1000
exampleInput: "hello world"
exampleOutput: "world hello"
sampleInput + sampleExpected: same as the example
hiddenInput1/hiddenExpected1 and hiddenInput2/hiddenExpected2: two more valid cases

Each problem MUST teach or practice "{$topic}" within "{$category}" using stdin/stdout logic only.

Return ONLY valid JSON:
{
  "problems": [
    {
      "title": "short descriptive title",
      "description": "full problem statement in plain English",
      "inputFormat": "how stdin is structured",
      "outputFormat": "exactly what to print",
      "constraints": "numeric limits",
      "exampleInput": "sample stdin",
      "exampleOutput": "sample stdout",
      "sampleInput": "same as exampleInput",
      "sampleExpected": "same as exampleOutput",
      "hiddenInput1": "hidden stdin",
      "hiddenExpected1": "hidden stdout",
      "hiddenInput2": "hidden stdin",
      "hiddenExpected2": "hidden stdout",
      "pythonStarter": "# Write your solution\\n",
      "marks": 2,
      "difficulty": "{$difficulty}",
      "category": "{$category}"
    }
  ]
}
Rules:
- Exactly {$count} problems.
- Every problem must be about "{$topic}".
- Every problem must have ALL 3 test cases filled with correct expected outputs.
- Problems must be solvable by a single Python script using input() and print().
- Do not wrap JSON in markdown.
PROMPT;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function extractProblems(array $raw): array
    {
        foreach (['problems', 'questions'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                return array_values(array_filter($raw[$key], 'is_array'));
            }
        }
        if (isset($raw['title'])) {
            return [$raw];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapAiProblem(array $q, string $fallbackCategory, string $fallbackDifficulty): ?array
    {
        $title = $this->sanitizeProblemTitle(trim((string) ($q['title'] ?? '')));
        $description = trim((string) ($q['description'] ?? $q['prompt'] ?? ''));
        if ($title === '' || $description === '') {
            return null;
        }
        if ($this->isLeetcodeStyleProblem($title, $description, $q)) {
            return null;
        }

        $examples = is_array($q['examples'] ?? null) ? array_values($q['examples']) : [];
        $firstExample = is_array($examples[0] ?? null) ? $examples[0] : [];
        $cases = is_array($q['testCases'] ?? null) ? array_values($q['testCases']) : [];
        $sampleIn = (string) ($q['sampleInput'] ?? $q['exampleInput'] ?? $firstExample['input'] ?? ($cases[0]['input'] ?? ''));
        $sampleOut = (string) ($q['sampleExpected'] ?? $q['exampleOutput'] ?? $firstExample['output'] ?? ($cases[0]['expected'] ?? ''));
        $h1In = (string) ($q['hiddenInput1'] ?? ($cases[1]['input'] ?? ''));
        $h1Out = (string) ($q['hiddenExpected1'] ?? ($cases[1]['expected'] ?? ''));
        $h2In = (string) ($q['hiddenInput2'] ?? ($cases[2]['input'] ?? ''));
        $h2Out = (string) ($q['hiddenExpected2'] ?? ($cases[2]['expected'] ?? ''));

        $testCases = [
            ['id' => 's1', 'label' => 'Sample Test Case', 'input' => $sampleIn, 'expected' => $sampleOut, 'sample' => true],
            ['id' => 'h1', 'input' => $h1In, 'expected' => $h1Out, 'sample' => false],
            ['id' => 'h2', 'input' => $h2In, 'expected' => $h2Out, 'sample' => false],
        ];
        if (!$this->hasCompleteTestCases($testCases)) {
            return null;
        }

        $starter = is_array($q['starterCode'] ?? null) ? $q['starterCode'] : [];
        $python = (string) ($q['pythonStarter'] ?? $starter['Python'] ?? "# Write your solution\n");
        return [
            'title' => $title,
            'description' => $description,
            'inputFormat' => (string) ($q['inputFormat'] ?? ''),
            'outputFormat' => (string) ($q['outputFormat'] ?? ''),
            'constraints' => (string) ($q['constraints'] ?? ''),
            'examples' => [['input' => $sampleIn, 'output' => $sampleOut]],
            'starterCode' => ['Python' => $python],
            'testCases' => $testCases,
            'marks' => max(1, (float) ($q['marks'] ?? 2)),
            'difficulty' => $this->normalizeDifficulty((string) ($q['difficulty'] ?? $fallbackDifficulty)),
            'category' => $this->normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{difficulty:string,count:int}>
     */
    private function normalizeGenerationBatches(array $body): array
    {
        $rawBatches = $body['batches'] ?? null;
        if (!is_array($rawBatches) || $rawBatches === []) {
            $difficulty = $this->normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium'));
            $count = max(1, min(self::MAX_BATCH_COUNT, (int) ($body['count'] ?? 5)));

            return [[
                'difficulty' => $difficulty,
                'count' => $count,
            ]];
        }

        $batches = [];
        $totalCount = 0;
        foreach ($rawBatches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            $difficulty = $this->normalizeDifficulty((string) ($batch['difficulty'] ?? 'Medium'));
            $count = max(0, min(self::MAX_BATCH_COUNT, (int) ($batch['count'] ?? 0)));
            if ($count <= 0) {
                continue;
            }
            $totalCount += $count;
            if ($totalCount > self::MAX_TOTAL_COUNT) {
                throw new \InvalidArgumentException('Total problems cannot exceed ' . self::MAX_TOTAL_COUNT . '.');
            }
            $batches[] = [
                'difficulty' => $difficulty,
                'count' => $count,
            ];
        }

        return $batches;
    }

    private function sanitizeProblemTitle(string $title): string
    {
        $title = preg_replace('/^\d+\.\s*/', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param array<string, mixed> $q
     */
    private function isLeetcodeStyleProblem(string $title, string $description, array $q): bool
    {
        $blob = strtolower($title . "\n" . $description . "\n" . json_encode($q, JSON_UNESCAPED_UNICODE));
        $patterns = [
            '/\bmodify the given (program|code|class)\b/',
            '/\bsame instance\b/',
            '/\btwo different threads\b/',
            '/\bthread [a-z] will call\b/',
            '/\bpublic void \w+\(/',
            '/\bclass \w+\s*\{/',
            '/\bimplement (the )?(following )?(class|interface)\b/',
            '/\bpremium lock\b/',
            '/\bleetcode\b/',
            '/\bfoobar\b.*\bthread\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $blob) === 1) {
                return true;
            }
        }

        return preg_match('/^\d+\.\s/', $title) === 1;
    }

    /**
     * @param list<array<string, mixed>> $testCases
     */
    private function hasCompleteTestCases(array $testCases): bool
    {
        if (count($testCases) !== 3) {
            return false;
        }
        foreach ($testCases as $case) {
            if (trim((string) ($case['input'] ?? '')) === '' || trim((string) ($case['expected'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateFromJdForUser(array $body): array
    {
        @set_time_limit(600);

        $extractor = new JdTextExtractionService($this->openai);
        $jobDescription = $extractor->sanitizeText(
            (string) ($body['jobDescription'] ?? $body['jobDescriptionText'] ?? '')
        );
        if (mb_strlen($jobDescription) < 40) {
            throw new \InvalidArgumentException(
                'Job description text is required. Paste the JD or upload a PDF/image to extract text.'
            );
        }

        $instructions = trim((string) ($body['instructions'] ?? ''));
        $jdInstructions = $instructions !== ''
            ? $instructions
            : 'Generate stdin/stdout coding problems aligned with skills and technologies mentioned in the job description.';

        $batches = $this->normalizeGenerationBatches($body);
        if ($batches === []) {
            throw new \InvalidArgumentException('Add at least one generation row with a problem count.');
        }

        $merged = [];
        foreach ($batches as $batch) {
            $result = $this->generateFromJd(
                $jobDescription,
                (string) ($batch['difficulty'] ?? 'Medium'),
                (int) ($batch['count'] ?? 0),
                $jdInstructions
            );
            foreach ($result['problems'] ?? [] as $problem) {
                if (is_array($problem)) {
                    $merged[] = $problem;
                }
            }
        }

        if ($merged === []) {
            throw new \RuntimeException('No valid coding problems in the AI response. Try again.');
        }

        $preview = [];
        foreach ($merged as $i => $q) {
            $preview[] = array_merge($q, [
                'tempId' => 'ai-jd-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'selected' => true,
                'source' => 'AI_JD',
            ]);
        }

        $requested = array_sum(array_map(static fn (array $b): int => (int) ($b['count'] ?? 0), $batches));

        return [
            'problems' => $preview,
            'questions' => $preview,
            'requested' => $requested,
            'received' => count($preview),
            'partial' => count($preview) < $requested,
            'generationMode' => 'jd',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateFromJd(string $jobDescription, string $difficulty, int $count, string $instructions = ''): array
    {
        $difficulty = $this->normalizeDifficulty($difficulty);
        $count = max(1, min(self::MAX_BATCH_COUNT, $count));
        $excerpt = mb_strlen($jobDescription) > 12000
            ? mb_substr($jobDescription, 0, 12000) . '…'
            : $jobDescription;

        $system = <<<'SYSTEM'
You generate stdin/stdout coding problems for a university placement portal based on job descriptions.
Students write standalone Python programs that read from stdin and print to stdout.
NEVER generate LeetCode-style class/threading templates.
Return ONLY valid JSON with no markdown or commentary.
SYSTEM;

        $extra = trim($instructions);
        $extraLine = $extra !== '' ? "Additional instructions: {$extra}\n" : '';
        $user = <<<PROMPT
Generate {$count} campus-placement coding problem(s) at {$difficulty} difficulty based on this job description.

{$extraLine}
Job description:
{$excerpt}

Each problem must use stdin/stdout format with description, inputFormat, outputFormat, constraints, one example, exactly 3 test cases, and pythonStarter.
Problems should reflect skills, tools, or concepts implied by the JD (e.g. SQL, APIs, data structures, logic).

Return ONLY valid JSON:
{"problems":[{"title":"...","description":"...","inputFormat":"...","outputFormat":"...","constraints":"...","exampleInput":"...","exampleOutput":"...","sampleInput":"...","sampleExpected":"...","hiddenInput1":"...","hiddenExpected1":"...","hiddenInput2":"...","hiddenExpected2":"...","pythonStarter":"# Write your solution\\n","marks":2,"difficulty":"{$difficulty}","category":"Algorithms"}]}
PROMPT;

        try {
            $raw = $this->openai->generateJson($system, $user);
        } catch (\Throwable $e) {
            error_log('[PMS coding AI] JD generate failed: ' . $e->getMessage());
            throw new \RuntimeException('AI generation is temporarily unavailable. Please try again.');
        }

        $problems = $this->extractProblems($raw);
        $validated = [];
        foreach ($problems as $p) {
            $mapped = $this->mapAiProblem(is_array($p) ? $p : [], 'Algorithms', $difficulty);
            if ($mapped !== null) {
                $mapped['source'] = 'AI_JD';
                $validated[] = $mapped;
            }
        }
        if ($validated === []) {
            throw new \RuntimeException('No valid stdin/stdout problems were generated from the job description.');
        }

        $preview = [];
        foreach (array_slice($validated, 0, $count) as $i => $q) {
            $preview[] = array_merge($q, [
                'tempId' => 'ai-jd-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'selected' => true,
                'source' => 'AI_JD',
            ]);
        }

        return [
            'problems' => $preview,
            'questions' => $preview,
            'requested' => $count,
            'received' => count($preview),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>> $problems
     * @return array<string, mixed>
     */
    public function saveCompanyBlockSetForUser(
        array $admin,
        array $problems,
        string $setTitle,
        string $companyId,
        ?string $companyName = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null
    ): array {
        AptitudeAccessService::requireCodingManager($admin);
        $setTitle = trim($setTitle);
        if ($setTitle === '') {
            throw new \InvalidArgumentException('Set title is required.');
        }
        $companyId = trim($companyId);
        if ($companyId === '') {
            throw new \InvalidArgumentException('Company is required.');
        }
        if ($problems === []) {
            throw new \InvalidArgumentException('No problems selected to save.');
        }

        $toSave = [];
        foreach (array_values($problems) as $q) {
            if (!is_array($q)) {
                continue;
            }
            if (array_key_exists('selected', $q) && empty($q['selected'])) {
                continue;
            }
            $mapped = $this->mapAiProblem($q, (string) ($q['category'] ?? 'Algorithms'), (string) ($q['difficulty'] ?? 'Medium'));
            if ($mapped === null) {
                continue;
            }
            $mapped['source'] = 'AI_JD';
            $toSave[] = $mapped;
        }

        if ($toSave === []) {
            throw new \RuntimeException('No problems could be saved. Check validation errors.');
        }

        return (new CodingCompanyProblemSetModel())->createSet(
            $setTitle,
            $toSave,
            (string) ($admin['_id'] ?? $admin['id'] ?? ''),
            $companyId,
            $companyName,
            $jdFilename,
            $jdFile,
            $jdFileUrl,
            $jdMimeType
        );
    }
}
