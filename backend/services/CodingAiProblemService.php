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
    private OllamaService $ollama;

    public function __construct(?OllamaService $ollama = null)
    {
        $this->ollama = $ollama ?? new OllamaService();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $category, string $topic, string $difficulty, int $count, string $instructions = ''): array
    {
        $category = $this->normalizeCategory($category);
        $difficulty = $this->normalizeDifficulty($difficulty);
        $topic = trim($topic);
        $count = max(1, min(10, $count));
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
        $raw = trim($value);
        foreach (CodingTestModel::CATEGORIES as $cat) {
            if (strcasecmp($cat, $raw) === 0) {
                return $cat;
            }
        }
        return 'Programming';
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
}
