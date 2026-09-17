<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeQuestionBankModel;
use PMS\Models\AptitudeTestModel;

/**
 * AI aptitude question generation via OpenAI + validation/preview mapping.
 */
final class AptitudeAiQuestionService
{
    private const MAX_COUNT = 50;
    private const MIN_COOLDOWN_SECONDS = 8;

    /** @var array<string, list<string>> */
    public const TOPICS_BY_CATEGORY = [
        'Quantitative Aptitude' => [
            'Percentage', 'Profit and Loss', 'Time and Work', 'Time, Speed and Distance',
            'Ratio and Proportion', 'Probability', 'Averages', 'Number System',
            'Simple Interest', 'Compound Interest', 'Permutation and Combination',
        ],
        'Logical Reasoning' => [
            'Coding-Decoding', 'Blood Relations', 'Syllogism', 'Seating Arrangement',
            'Number Series', 'Direction Sense', 'Puzzles', 'Analytical Reasoning',
        ],
        'Verbal Ability' => [
            'Reading Comprehension', 'Grammar', 'Synonyms and Antonyms', 'Sentence Correction',
            'Para Jumbles', 'Vocabulary',
        ],
        'Data Interpretation' => [
            'Tables', 'Bar Graphs', 'Pie Charts', 'Line Graphs', 'Mixed Charts',
        ],
        'Numerical Ability' => [
            'Percentage', 'Averages', 'Number System', 'Ratio and Proportion', 'Probability',
        ],
        'General Aptitude' => [
            'Mixed Aptitude', 'Campus Placement', 'General Knowledge',
        ],
    ];

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
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function generateForUser(array $admin, array $body): array
    {
        AptitudeAccessService::requireManager($admin);
        $this->assertCooldown((string) ($admin['_id'] ?? $admin['id'] ?? ''));

        $category = AptitudeTestModel::normalizeCategory((string) ($body['category'] ?? 'General Aptitude'));
        $topic = trim((string) ($body['topic'] ?? ''));
        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium'));
        $count = max(1, min(self::MAX_COUNT, (int) ($body['count'] ?? 5)));
        $marks = max(0.25, min(100, (float) ($body['marks'] ?? 1)));
        $language = trim((string) ($body['language'] ?? 'English')) ?: 'English';
        $instructions = trim((string) ($body['instructions'] ?? ''));

        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }
        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            throw new \InvalidArgumentException('Invalid difficulty.');
        }

        $negativeMarking = filter_var($body['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = $negativeMarking ? max(0, (float) ($body['negativeMarks'] ?? 0)) : 0.0;

        $result = $this->generate($category, $topic, $difficulty, $count, $marks, $language, $instructions, $negativeMarks);
        $this->logGeneration($admin, $category, $topic, $difficulty, $count, true);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(
        string $category,
        string $topic,
        string $difficulty,
        int $count,
        float $marks = 1.0,
        string $language = 'English',
        string $instructions = '',
        float $negativeMarks = 0.0
    ): array {
        $category = AptitudeTestModel::normalizeCategory($category);
        $difficulty = AptitudeTestModel::normalizeDifficulty($difficulty);
        $topic = trim($topic);
        $count = max(1, min(self::MAX_COUNT, $count));

        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }

        $system = 'You are an aptitude question generator for a university placement preparation system. Return ONLY valid JSON with no markdown or commentary.';
        $user = $this->buildPrompt($category, $topic, $difficulty, $count, $marks, $language, $instructions);

        try {
            $raw = $this->openai->generateJson($system, $user);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude AI] generate failed: ' . $e->getMessage());
            throw new \RuntimeException('AI question generation is temporarily unavailable. Please try again.');
        }

        $questions = $this->extractQuestions($raw);
        $validated = [];
        $errors = [];
        foreach ($questions as $i => $q) {
            $mapped = $this->mapAiQuestion(is_array($q) ? $q : [], $category, $topic, $difficulty, $marks, $negativeMarks);
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

            $preview[] = array_merge($q, [
                'tempId' => 'ai-' . ($i + 1) . '-' . bin2hex(random_bytes(4)),
                'source' => 'AI',
                'duplicateInBank' => $duplicateInBank,
                'duplicateInBatch' => $duplicateInBatch,
                'duplicateMessage' => $duplicateInBank
                    ? 'This question already exists in the bank and will not be added if saved unchanged.'
                    : ($duplicateInBatch ? 'Duplicate question within this AI batch.' : null),
                'selected' => !$duplicateInBank && !$duplicateInBatch,
            ]);
        }

        return [
            'questions' => $preview,
            'requested' => $count,
            'received' => count($preview),
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function saveApprovedForUser(array $admin, array $questions, string $fallbackCategory = 'General Aptitude'): array
    {
        AptitudeAccessService::requireManager($admin);
        if ($questions === []) {
            throw new \InvalidArgumentException('No questions selected to save.');
        }

        try {
            return $this->saveApproved(
                $questions,
                $fallbackCategory,
                (string) ($admin['_id'] ?? $admin['id'] ?? '')
            );
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude AI] save failed: ' . $e->getMessage());
            throw new \RuntimeException('Could not save AI questions. Please try again.');
        }
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
                    'prompt' => trim((string) ($q['prompt'] ?? $q['question'] ?? '')),
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
            $row['source'] = 'AI';
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
        float $marks,
        string $language,
        string $instructions
    ): string {
        $extra = trim($instructions);
        $extraBlock = $extra !== '' ? "\nAdditional instructions:\n{$extra}\n" : '';

        return <<<PROMPT
You are an aptitude question generator for a university placement preparation system.

Generate high-quality original multiple-choice aptitude questions.

Requirements:
- Generate exactly {$count} questions.
- Each question must have exactly four options.
- Only one option must be correct.
- The correct answer must be mathematically/logically valid.
- Provide a short explanation.
- Match the requested category, topic, and difficulty.
- Avoid duplicate questions.
- Avoid ambiguous wording.
- Avoid trick questions unless explicitly requested.
- Ensure numerical calculations are correct.
- Make questions suitable for campus placement preparation.
- Return ONLY valid structured JSON.
- Do not include Markdown.
- Do not include additional commentary.

Category:
{$category}

Topic:
{$topic}

Difficulty:
{$difficulty}

Number of questions:
{$count}

Marks:
{$marks}

Language:
{$language}
{$extraBlock}
Use this exact JSON schema:
{
  "questions": [
    {
      "question": "string",
      "options": ["string", "string", "string", "string"],
      "correctAnswer": 0,
      "explanation": "string",
      "category": "{$category}",
      "topic": "{$topic}",
      "difficulty": "{$difficulty}",
      "marks": {$marks}
    }
  ]
}

Rules for correctAnswer: 0 = option A, 1 = B, 2 = C, 3 = D.
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
        if (isset($raw['question']) || isset($raw['options'])) {
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
        string $fallbackDifficulty,
        float $defaultMarks,
        float $negativeMarks
    ): ?array {
        $norm = AptitudeTestModel::normalizeMcq($q, $fallbackCategory);
        if ($norm === null) {
            return null;
        }

        $options = array_values((array) ($norm['options'] ?? []));
        if (count($options) !== 4) {
            return null;
        }

        $normOpts = array_map(static fn (string $o): string => strtolower(trim($o)), $options);
        if (count($normOpts) !== count(array_unique($normOpts))) {
            return null;
        }

        $correctIndex = (int) ($norm['correctIndex'] ?? -1);
        if ($correctIndex < 0 || $correctIndex > 3) {
            return null;
        }

        $explanation = trim((string) ($norm['explanation'] ?? ''));
        if ($explanation === '') {
            return null;
        }

        $topic = trim((string) ($q['topic'] ?? $fallbackTopic));
        if ($topic === '') {
            return null;
        }

        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? $fallbackDifficulty));
        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            return null;
        }

        $marks = max(0.25, (float) ($q['marks'] ?? $defaultMarks));

        return [
            'prompt' => $norm['prompt'],
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'marks' => $marks,
            'negative_marks' => $negativeMarks > 0 ? $negativeMarks : 0,
        ];
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapPreviewToRow(array $q, string $fallbackCategory): ?array
    {
        $norm = AptitudeTestModel::normalizeMcq($q, $fallbackCategory);
        if ($norm === null) {
            return null;
        }

        $options = array_values((array) ($norm['options'] ?? []));
        if (count($options) !== 4 || in_array('', array_map('trim', $options), true)) {
            return null;
        }

        $normOpts = array_map(static fn (string $o): string => strtolower(trim($o)), $options);
        if (count($normOpts) !== count(array_unique($normOpts))) {
            return null;
        }

        $correctIndex = (int) ($norm['correctIndex'] ?? -1);
        if ($correctIndex < 0 || $correctIndex > 3) {
            return null;
        }

        $topic = trim((string) ($q['topic'] ?? ''));
        $explanation = trim((string) ($norm['explanation'] ?? ''));
        if ($topic === '' || $explanation === '') {
            return null;
        }

        return [
            'prompt' => $norm['prompt'],
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'topic' => $topic,
            'difficulty' => AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? 'Medium')),
            'marks' => max(0.25, (float) ($q['marks'] ?? $norm['marks'] ?? 1)),
            'source' => 'AI',
        ];
    }

    private function assertCooldown(string $userId): void
    {
        if ($userId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_aptitude_ai_' . md5($userId) . '.json';
        $now = time();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $last = is_array($data) ? (int) ($data['ts'] ?? 0) : 0;
            if ($last > 0 && ($now - $last) < self::MIN_COOLDOWN_SECONDS) {
                throw new \RuntimeException('Please wait a few seconds before generating again.');
            }
        }
        @file_put_contents($path, json_encode(['ts' => $now]));
    }

    /**
     * @param array<string, mixed> $admin
     */
    private function logGeneration(
        array $admin,
        string $category,
        string $topic,
        string $difficulty,
        int $count,
        bool $success
    ): void {
        error_log(sprintf(
            '[PMS Aptitude AI] user=%s category=%s topic=%s difficulty=%s count=%d success=%s',
            (string) ($admin['_id'] ?? $admin['id'] ?? 'unknown'),
            $category,
            $topic,
            $difficulty,
            $count,
            $success ? 'yes' : 'no'
        ));
    }
}
