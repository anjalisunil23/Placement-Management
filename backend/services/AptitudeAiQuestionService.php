<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeQuestionBankModel;
use PMS\Models\AptitudeTestModel;

/**
 * AI aptitude question generation via Ollama + validation/preview mapping.
 */
final class AptitudeAiQuestionService
{
    /** @var array<string, list<string>> */
    public const TOPICS_BY_CATEGORY = [
        'Quantitative Aptitude' => [
            'Percentages', 'Profit and Loss', 'Time and Work', 'Time, Speed and Distance',
            'Ratio and Proportion', 'Average', 'Probability', 'Number System',
            'Permutation and Combination',
        ],
        'Logical Reasoning' => [
            'Coding-Decoding', 'Blood Relations', 'Seating Arrangement', 'Syllogism',
            'Number Series', 'Direction Sense', 'Puzzles',
        ],
        'Verbal Ability' => [
            'Reading Comprehension', 'Grammar', 'Synonyms and Antonyms', 'Sentence Correction',
            'Para Jumbles', 'Vocabulary',
        ],
        'Data Interpretation' => [
            'Tables', 'Bar Graphs', 'Pie Charts', 'Line Graphs', 'Mixed Charts',
        ],
        'General Aptitude' => [
            'Mixed Aptitude', 'Campus Placement', 'General Knowledge',
        ],
    ];

    private OllamaService $ollama;

    public function __construct(?OllamaService $ollama = null)
    {
        $this->ollama = $ollama ?? new OllamaService();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(
        string $category,
        string $topic,
        string $difficulty,
        int $count,
        string $instructions = ''
    ): array {
        $category = AptitudeTestModel::normalizeCategory($category);
        $difficulty = AptitudeTestModel::normalizeDifficulty($difficulty);
        $topic = trim($topic);
        $count = max(1, min(50, $count));

        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }

        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            throw new \InvalidArgumentException('Invalid difficulty.');
        }

        $prompt = $this->buildPrompt($category, $topic, $difficulty, $count, $instructions);
        $raw = $this->ollama->generateJson($prompt);
        $questions = $this->extractQuestions($raw);

        $validated = [];
        $errors = [];
        foreach ($questions as $i => $q) {
            $mapped = $this->mapAiQuestion(is_array($q) ? $q : [], $category, $topic, $difficulty);
            if ($mapped === null) {
                $errors[] = 'Question ' . ($i + 1) . ' failed validation.';
                continue;
            }
            $validated[] = $mapped;
        }

        if ($validated === []) {
            $detail = $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : 'No valid questions in AI response.';
            throw new \RuntimeException($detail);
        }

        if (count($validated) !== $count) {
            throw new \RuntimeException(
                'AI generated ' . count($validated) . ' valid question(s) but ' . $count . ' were requested. Please regenerate.'
            );
        }

        $bank = new AptitudeQuestionBankModel();
        $bankIndex = $bank->loadNormalizedPromptIndex();
        $batchKeys = [];
        $preview = [];

        foreach ($validated as $i => $q) {
            $key = AptitudeQuestionBankModel::normalizePromptKey((string) ($q['prompt'] ?? ''));
            $duplicateInBank = isset($bankIndex[$key]);
            $duplicateInBatch = isset($batchKeys[$key]);
            $batchKeys[$key] = true;

            $preview[] = [
                'tempId' => 'ai-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'prompt' => $q['prompt'],
                'options' => $q['options'],
                'correctIndex' => $q['correctIndex'],
                'explanation' => $q['explanation'],
                'category' => $q['category'],
                'topic' => $q['topic'],
                'difficulty' => $q['difficulty'],
                'marks' => $q['marks'],
                'source' => 'ai',
                'duplicateInBank' => $duplicateInBank,
                'duplicateInBatch' => $duplicateInBatch,
                'duplicateMessage' => $duplicateInBank
                    ? 'This question already exists in the bank and will not be added if saved unchanged.'
                    : ($duplicateInBatch ? 'Duplicate question within this AI batch.' : null),
                'selected' => !$duplicateInBank && !$duplicateInBatch,
            ];
        }

        return [
            'questions' => $preview,
            'requested' => $count,
            'received' => count($preview),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function saveApproved(array $questions, string $fallbackCategory, ?string $createdBy): array
    {
        $bank = new AptitudeQuestionBankModel();
        $bankIndex = $bank->loadNormalizedPromptIndex();
        $toInsert = [];
        $skipped = [];

        foreach (array_values($questions) as $i => $q) {
            if (!is_array($q)) {
                continue;
            }
            if (array_key_exists('selected', $q) && empty($q['selected'])) {
                continue;
            }

            $row = $this->mapPreviewToRow($q, $fallbackCategory);
            if ($row === null) {
                $skipped[] = [
                    'index' => $i,
                    'prompt' => trim((string) ($q['prompt'] ?? '')),
                    'reason' => 'invalid',
                    'message' => 'Question failed validation and was not saved.',
                ];
                continue;
            }

            $key = AptitudeQuestionBankModel::normalizePromptKey($row['prompt']);
            if (isset($bankIndex[$key])) {
                $skipped[] = [
                    'index' => $i,
                    'prompt' => $row['prompt'],
                    'reason' => 'duplicate',
                    'message' => 'This question already exists and will not be added.',
                ];
                continue;
            }

            $bankIndex[$key] = true;
            $row['source'] = 'ai';
            $toInsert[] = $row;
        }

        if ($toInsert === []) {
            throw new \RuntimeException('No questions could be saved. Check for duplicates or validation errors.');
        }

        $result = $bank->bulkInsert($toInsert, $fallbackCategory, $createdBy);

        return [
            'added' => $result['added'],
            'items' => $result['items'],
            'skipped' => $skipped,
        ];
    }

    private function buildPrompt(
        string $category,
        string $topic,
        string $difficulty,
        int $count,
        string $instructions
    ): string {
        $extra = trim($instructions);
        $extraBlock = $extra !== '' ? "\nAdditional instructions:\n{$extra}\n" : '';

        return <<<PROMPT
You are an expert aptitude test question writer for campus placement exams in India.
Generate exactly {$count} multiple-choice aptitude questions.

Requirements:
- Category: {$category}
- Topic: {$topic}
- Difficulty: {$difficulty}
- Each question must have exactly four distinct options (option_a, option_b, option_c, option_d).
- correct_answer must exactly match one of the four option texts (same wording).
- Include a clear explanation showing how the correct answer is derived.
- Use realistic numerical values where applicable (Indian Rupee ₹ symbol is fine).
- Questions must be suitable for engineering campus placement aptitude tests.
- Do not repeat the same question or paraphrase the same problem.
- All option texts within a question must be unique.
{$extraBlock}
Respond with JSON only. No markdown, no code fences, no text before or after the JSON.

Use this exact JSON schema:
{
  "questions": [
    {
      "question": "string",
      "option_a": "string",
      "option_b": "string",
      "option_c": "string",
      "option_d": "string",
      "correct_answer": "string",
      "explanation": "string",
      "category": "{$category}",
      "topic": "{$topic}",
      "difficulty": "{$difficulty}"
    }
  ]
}
PROMPT;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function extractQuestions(array $raw): array
    {
        if (isset($raw['questions']) && is_array($raw['questions'])) {
            return array_values(array_filter($raw['questions'], 'is_array'));
        }
        if (isset($raw['question']) || isset($raw['option_a'])) {
            return [$raw];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapAiQuestion(
        array $q,
        string $fallbackCategory,
        string $fallbackTopic,
        string $fallbackDifficulty
    ): ?array {
        $prompt = trim((string) ($q['question'] ?? $q['prompt'] ?? ''));
        if ($prompt === '') {
            return null;
        }

        $options = [
            trim((string) ($q['option_a'] ?? '')),
            trim((string) ($q['option_b'] ?? '')),
            trim((string) ($q['option_c'] ?? '')),
            trim((string) ($q['option_d'] ?? '')),
        ];

        if (in_array('', $options, true)) {
            return null;
        }

        $normOpts = array_map(static fn (string $o): string => strtolower(trim($o)), $options);
        if (count($normOpts) !== count(array_unique($normOpts))) {
            return null;
        }

        $correctRaw = trim((string) ($q['correct_answer'] ?? ''));
        if ($correctRaw === '') {
            return null;
        }

        $correctIndex = -1;
        foreach ($options as $i => $opt) {
            if (strcasecmp($opt, $correctRaw) === 0) {
                $correctIndex = $i;
                break;
            }
        }
        if ($correctIndex < 0) {
            return null;
        }

        $explanation = trim((string) ($q['explanation'] ?? ''));
        if ($explanation === '') {
            return null;
        }

        $category = AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory));
        $topic = trim((string) ($q['topic'] ?? $fallbackTopic));
        if ($topic === '') {
            return null;
        }

        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? $fallbackDifficulty));
        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            return null;
        }

        return [
            'prompt' => $prompt,
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => $category,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'marks' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapPreviewToRow(array $q, string $fallbackCategory): ?array
    {
        $options = array_values(array_map('strval', (array) ($q['options'] ?? [])));
        if (count($options) !== 4) {
            return null;
        }
        $options = array_map('trim', $options);
        if (in_array('', $options, true)) {
            return null;
        }

        $normOpts = array_map(static fn (string $o): string => strtolower($o), $options);
        if (count($normOpts) !== count(array_unique($normOpts))) {
            return null;
        }

        $correctIndex = (int) ($q['correctIndex'] ?? -1);
        if ($correctIndex < 0 || $correctIndex > 3) {
            return null;
        }

        $prompt = trim((string) ($q['prompt'] ?? ''));
        $explanation = trim((string) ($q['explanation'] ?? ''));
        $topic = trim((string) ($q['topic'] ?? ''));
        if ($prompt === '' || $explanation === '' || $topic === '') {
            return null;
        }

        $category = AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory));
        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? 'Medium'));
        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            return null;
        }

        return [
            'prompt' => $prompt,
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => $category,
            'topic' => $topic,
            'difficulty' => $difficulty,
            'marks' => max(1, (float) ($q['marks'] ?? 1)),
        ];
    }
}
