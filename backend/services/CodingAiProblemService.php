<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\CodingProblemBankModel;
use PMS\Models\CodingTestModel;

/**
 * AI coding problem generation via Ollama.
 */
final class CodingAiProblemService
{
    private const MAX_BATCH_COUNT = 10;
    private const MAX_TOTAL_COUNT = 30;

    private OllamaService $ollama;

    public function __construct(?OllamaService $ollama = null)
    {
        $this->ollama = $ollama ?? new OllamaService();
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateFromRequest(array $body): array
    {
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

        $prompt = $this->buildPrompt($category, $topic, $difficulty, $count, $instructions);
        $raw = $this->ollama->generateJson($prompt);
        $problems = $this->extractProblems($raw);

        $validated = [];
        foreach ($problems as $i => $p) {
            $mapped = $this->mapAiProblem(is_array($p) ? $p : [], $category, $difficulty);
            if ($mapped !== null) {
                $validated[] = $mapped;
            }
        }
        if ($validated === []) {
            throw new \RuntimeException('No valid coding problems in the AI response. Try again.');
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
You generate campus-placement coding problems for college students.
Category: {$category}
Topic: {$topic}
Difficulty: {$difficulty}
Count: {$count}
{$extraLine}
Each problem MUST focus specifically on "{$topic}" within the "{$category}" category.
Do NOT generate generic programming questions unrelated to "{$topic}".
Every problem must require the student to apply "{$topic}" concepts, patterns, or techniques to solve it.
Return ONLY valid JSON:
{
  "problems": [
    {
      "title": "short title",
      "description": "problem statement",
      "inputFormat": "how input is given",
      "outputFormat": "how output should be printed",
      "constraints": "constraints",
      "exampleInput": "sample stdin",
      "exampleOutput": "sample stdout",
      "sampleInput": "same as example input",
      "sampleExpected": "same as example output",
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
- Every problem must be about "{$topic}" — not generic coding drills.
- Problems must be solvable in Python from stdin/stdout.
- Sample and hidden cases must match the statement.
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
        $title = trim((string) ($q['title'] ?? ''));
        $description = trim((string) ($q['description'] ?? $q['prompt'] ?? ''));
        if ($title === '' || $description === '') {
            return null;
        }
        $examples = is_array($q['examples'] ?? null) ? array_values($q['examples']) : [];
        $firstExample = is_array($examples[0] ?? null) ? $examples[0] : [];
        $cases = is_array($q['testCases'] ?? null) ? array_values($q['testCases']) : [];
        $sampleIn = (string) ($q['sampleInput'] ?? $q['exampleInput'] ?? $firstExample['input'] ?? '');
        $sampleOut = (string) ($q['sampleExpected'] ?? $q['exampleOutput'] ?? $firstExample['output'] ?? '');
        $h1In = (string) ($q['hiddenInput1'] ?? ($cases[1]['input'] ?? ''));
        $h1Out = (string) ($q['hiddenExpected1'] ?? ($cases[1]['expected'] ?? ''));
        $h2In = (string) ($q['hiddenInput2'] ?? ($cases[2]['input'] ?? ''));
        $h2Out = (string) ($q['hiddenExpected2'] ?? ($cases[2]['expected'] ?? ''));
        $starter = is_array($q['starterCode'] ?? null) ? $q['starterCode'] : [];
        $python = (string) ($q['pythonStarter'] ?? $starter['Python'] ?? "# Write your solution\n");
        $testCases = [
            ['id' => 's1', 'label' => 'Sample Test Case', 'input' => $sampleIn, 'expected' => $sampleOut, 'sample' => true],
        ];
        if ($h1In !== '' || $h1Out !== '') {
            $testCases[] = ['id' => 'h1', 'input' => $h1In, 'expected' => $h1Out, 'sample' => false];
        }
        if ($h2In !== '' || $h2Out !== '') {
            $testCases[] = ['id' => 'h2', 'input' => $h2In, 'expected' => $h2Out, 'sample' => false];
        }
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
}
