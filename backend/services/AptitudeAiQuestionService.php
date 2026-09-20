<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AptitudeJdQuestionSetModel;
use PMS\Models\AptitudeQuestionBankModel;
use PMS\Models\AptitudeTestModel;

/**
 * AI aptitude question generation via OpenAI + validation/preview mapping.
 */
final class AptitudeAiQuestionService
{
    public const MAX_COUNT = 200;

    private const MIN_COOLDOWN_SECONDS = 8;
    private const JD_BATCH_SIZE = 20;
    private const MAX_JD_BATCH_ATTEMPTS = 15;
    private const MAX_EXISTING_PROMPT_HINTS = 25;

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

        $mode = strtolower(trim((string) ($body['generationMode'] ?? $body['mode'] ?? 'category'));
        if (in_array($mode, ['jd', 'job_description', 'job description'], true)) {
            return $this->generateFromJdForUser($admin, $body);
        }

        @set_time_limit(600);

        $category = AptitudeTestModel::normalizeCategory((string) ($body['category'] ?? 'General Aptitude'));
        $topic = trim((string) ($body['topic'] ?? ''));
        $language = trim((string) ($body['language'] ?? 'English')) ?: 'English';
        $instructions = trim((string) ($body['instructions'] ?? ''));

        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }

        $negativeMarking = filter_var($body['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = $negativeMarking ? max(0, (float) ($body['negativeMarks'] ?? 0)) : 0.0;

        $batches = $this->normalizeGenerationBatches($body);
        if ($batches === []) {
            throw new \InvalidArgumentException('Add at least one generation row with a question count.');
        }

        $progressKey = $this->sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $requested = array_sum(array_map(static fn (array $b): int => (int) ($b['count'] ?? 0), $batches));
        $bankIndex = (new AptitudeQuestionBankModel())->loadNormalizedPromptIndex();
        $merged = [];
        $generatedSoFar = 0;

        $this->writeGenerationProgress($progressKey, [
            'phase' => 'generating',
            'message' => 'Generating questions…',
            'generated' => 0,
            'requested' => $requested,
            'percent' => 0,
            'done' => false,
        ]);

        foreach ($batches as $batch) {
            $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($batch['difficulty'] ?? 'Medium'));
            $this->writeGenerationProgress($progressKey, [
                'phase' => 'generating',
                'message' => "Generating {$difficulty} questions… ({$generatedSoFar} / {$requested})",
                'generated' => $generatedSoFar,
                'requested' => $requested,
                'percent' => $requested > 0
                    ? min(99, (int) round(($generatedSoFar / $requested) * 100))
                    : 0,
                'done' => false,
            ]);

            $result = $this->generate(
                $category,
                $topic,
                $batch['difficulty'],
                $batch['count'],
                $batch['marks'],
                $language,
                $instructions,
                $negativeMarks,
                $bankIndex
            );
            foreach ($result['questions'] ?? [] as $question) {
                $merged[] = $question;
            }
            $generatedSoFar = count($merged);
            $this->logGeneration($admin, $category, $topic, $batch['difficulty'], $batch['count'], true);
        }

        $received = count($merged);

        $this->writeGenerationProgress($progressKey, [
            'phase' => $received >= $requested ? 'complete' : 'incomplete',
            'message' => $received >= $requested
                ? "{$received} / {$requested} completed"
                : "Generated {$received} of {$requested} requested",
            'generated' => $received,
            'requested' => $requested,
            'percent' => $requested > 0 ? min(100, (int) round(($received / $requested) * 100)) : 100,
            'done' => true,
        ]);

        return [
            'questions' => $merged,
            'requested' => $requested,
            'received' => $received,
            'partial' => $received < $requested,
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public function generateFromJdForUser(array $admin, array $body): array
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

        $language = trim((string) ($body['language'] ?? 'English')) ?: 'English';
        $instructions = trim((string) ($body['instructions'] ?? ''));
        $negativeMarking = filter_var($body['negativeMarking'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $negativeMarks = $negativeMarking ? max(0, (float) ($body['negativeMarks'] ?? 0)) : 0.0;

        $batches = $this->normalizeGenerationBatches($body);
        if ($batches === []) {
            throw new \InvalidArgumentException('Add at least one generation row with a question count.');
        }

        $progressKey = $this->sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $requested = array_sum(array_map(static fn (array $b): int => (int) ($b['count'] ?? 0), $batches));

        $this->writeGenerationProgress($progressKey, [
            'phase' => 'generating',
            'message' => 'Preparing JD context…',
            'generated' => 0,
            'requested' => $requested,
            'percent' => 0,
            'done' => false,
        ]);

        $jdContext = $this->buildLocalJdContext($jobDescription);

        $bank = new AptitudeQuestionBankModel();
        $bankIndex = $bank->loadNormalizedPromptIndex();
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];
        /** @var list<string> $seenPromptTexts */
        $seenPromptTexts = [];
        $merged = [];
        $tempCounter = 0;

        foreach ($batches as $batch) {
            $quota = $this->generateJdDifficultyQuota(
                $jobDescription,
                $jdContext,
                $batch,
                $language,
                $instructions,
                $negativeMarks,
                $seenKeys,
                $seenPromptTexts,
                $bankIndex,
                $progressKey,
                $requested,
                count($merged),
                $tempCounter
            );
            foreach ($quota['questions'] as $question) {
                $merged[] = $question;
            }
            $this->logGeneration(
                $admin,
                'General Aptitude',
                'Job Description',
                (string) ($batch['difficulty'] ?? 'Medium'),
                (int) ($batch['count'] ?? 0),
                (bool) ($quota['complete'] ?? false)
            );
        }

        $received = count($merged);
        $complete = $received >= $requested;

        $this->writeGenerationProgress($progressKey, [
            'phase' => $complete ? 'complete' : 'incomplete',
            'message' => $complete
                ? "{$received} / {$requested} completed"
                : "Generated {$received} of {$requested} requested",
            'generated' => $received,
            'requested' => $requested,
            'percent' => $requested > 0 ? min(100, (int) round(($received / $requested) * 100)) : 100,
            'done' => true,
        ]);

        $base = [
            'questions' => $merged,
            'requested' => $requested,
            'received' => $received,
            'generationMode' => 'jd',
        ];

        if (!$complete) {
            $shortfall = $requested - $received;

            return array_merge($base, [
                'partial' => true,
                'incomplete' => true,
                'shortfall' => $shortfall,
                'message' => "We generated {$received} valid questions out of the requested {$requested}. "
                    . "The AI could not generate {$shortfall} additional unique question(s) "
                    . 'from the available Job Description content.',
            ]);
        }

        return array_merge($base, [
            'partial' => false,
            'incomplete' => false,
            'message' => "{$received} questions generated successfully",
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readGenerationProgress(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-f0-9\-]{8,64}$/i', $key)) {
            return null;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_apt_ai_prog_' . hash('sha256', $key) . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function generateFromJd(
        string $jobDescription,
        string $difficulty,
        int $count,
        float $marks = 1.0,
        string $language = 'English',
        string $instructions = '',
        float $negativeMarks = 0.0
    ): array {
        $difficulty = AptitudeTestModel::normalizeDifficulty($difficulty);
        $count = max(1, min(self::MAX_COUNT, $count));
        $jdContext = $this->buildLocalJdContext($jobDescription);
        $bankIndex = (new AptitudeQuestionBankModel())->loadNormalizedPromptIndex();
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];
        /** @var list<string> $seenPromptTexts */
        $seenPromptTexts = [];
        $tempCounter = 0;

        $quota = $this->generateJdDifficultyQuota(
            $jobDescription,
            $jdContext,
            ['difficulty' => $difficulty, 'count' => $count, 'marks' => $marks],
            $language,
            $instructions,
            $negativeMarks,
            $seenKeys,
            $seenPromptTexts,
            $bankIndex,
            '',
            $count,
            0,
            $tempCounter
        );

        $received = count($quota['questions']);

        return [
            'questions' => $quota['questions'],
            'requested' => $count,
            'received' => $received,
            'partial' => $received < $count,
            'incomplete' => !($quota['complete'] ?? false),
        ];
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
        float $negativeMarks = 0.0,
        ?array $bankIndex = null
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

        $received = count($validated);
        if ($received !== $count) {
            error_log('[PMS Aptitude AI] batch shortfall: requested ' . $count . ', received ' . $received);
        }

        if ($bankIndex === null) {
            $bankIndex = (new AptitudeQuestionBankModel())->loadNormalizedPromptIndex();
        }
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
            'received' => $received,
            'partial' => $received !== $count,
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
            $source = trim((string) ($q['source'] ?? 'AI'));
            if ($source === 'AI_JD') {
                throw new \InvalidArgumentException(
                    'JD-based questions must be saved to the Company Block, not the general question bank.'
                );
            }
            $row['source'] = in_array($source, ['AI', 'AI_JD'], true) ? $source : 'AI';
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

    /**
     * @param array<string, mixed> $admin
     * @param array<int, array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    public function saveJdSetForUser(
        array $admin,
        array $questions,
        string $jdTitle,
        string $companyId,
        ?string $companyName = null,
        ?string $jdFilename = null,
        ?string $jdFile = null,
        ?string $jdFileUrl = null,
        ?string $jdMimeType = null
    ): array {
        AptitudeAccessService::requireManager($admin);
        $jdTitle = trim($jdTitle);
        if ($jdTitle === '') {
            throw new \InvalidArgumentException('JD title is required.');
        }
        $companyId = trim($companyId);
        if ($companyId === '') {
            throw new \InvalidArgumentException('Company is required.');
        }
        if ($questions === []) {
            throw new \InvalidArgumentException('No questions selected to save.');
        }

        $toSave = [];
        foreach (array_values($questions) as $q) {
            if (!is_array($q)) {
                continue;
            }
            if (array_key_exists('selected', $q) && empty($q['selected'])) {
                continue;
            }
            $row = $this->mapPreviewToRow($q, 'General Aptitude');
            if ($row === null) {
                continue;
            }
            $row['source'] = 'AI_JD';
            $toSave[] = $row;
        }

        if ($toSave === []) {
            throw new \RuntimeException('No questions could be saved. Check validation errors.');
        }

        return (new AptitudeJdQuestionSetModel())->createSet(
            $jdTitle,
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
      "correctAnswerIndex": 0,
      "correctOptionLetter": "A",
      "explanation": "string",
      "category": "{$category}",
      "topic": "{$topic}",
      "difficulty": "{$difficulty}",
      "marks": {$marks}
    }
  ]
}

Rules for correctAnswerIndex: MUST be 0-based — 0 = option A, 1 = B, 2 = C, 3 = D. Never use 1–4.
correctOptionLetter MUST be A, B, C, or D and MUST match correctAnswerIndex.
The explanation MUST clearly support the chosen option and include the exact text of the correct option.
One of the four options MUST exactly match the final numeric answer shown in the explanation.
Double-check every calculation before returning JSON.
PROMPT;
    }

    private function sanitizeProgressKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-f0-9\-]{8,64}$/i', $key)) {
            return '';
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeGenerationProgress(string $key, array $data): void
    {
        $key = $this->sanitizeProgressKey($key);
        if ($key === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_apt_ai_prog_' . hash('sha256', $key) . '.json';
        $payload = array_merge($data, ['updatedAt' => time()]);
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Fast local JD context — avoids an extra OpenAI round trip before generation.
     * The full JD text is still included in every batch prompt.
     *
     * @return array{skills:list<array{name:string,weight:int}>,topics:list<string>,summary:string}
     */
    private function buildLocalJdContext(string $jobDescription): array
    {
        $summary = trim(preg_replace('/\s+/u', ' ', $jobDescription) ?? $jobDescription);
        if (mb_strlen($summary) > 800) {
            $summary = mb_substr($summary, 0, 800) . '…';
        }

        return [
            'skills' => [],
            'topics' => [],
            'summary' => $summary,
        ];
    }

    /**
     * @return array{skills:list<array{name:string,weight:int}>,topics:list<string>,summary:string}
     */
    private function analyzeJdContext(string $jobDescription, string $language): array
    {
        $fallback = [
            'skills' => [],
            'topics' => [],
            'summary' => mb_substr($jobDescription, 0, 800),
        ];

        if (!$this->openai->isConfigured()) {
            return $fallback;
        }

        $excerpt = mb_strlen($jobDescription) > 12000
            ? mb_substr($jobDescription, 0, 12000) . '…'
            : $jobDescription;

        try {
            $raw = $this->openai->generateJson(
                'You analyze job descriptions for campus placement aptitude test planning. Return ONLY valid JSON.',
                <<<PROMPT
Analyze this job description and return JSON with this schema:
{
  "skills": [{"name": "string", "weight": 1}],
  "topics": ["string"],
  "summary": "string"
}

Rules:
- List 3-12 important technical skills/concepts from the JD.
- weight is 1 (low) to 5 (high) based on emphasis in the JD.
- topics are concise labels for question topics (e.g. "Java OOP", "SQL", "REST APIs").
- summary is one short paragraph of what the role requires.
- Language context: {$language}

Job Description:
{$excerpt}
PROMPT
            );
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude AI] JD analysis failed: ' . $e->getMessage());

            return $fallback;
        }

        $skills = [];
        foreach ((array) ($raw['skills'] ?? []) as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $name = trim((string) ($skill['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $weight = max(1, min(5, (int) ($skill['weight'] ?? 3)));
            $skills[] = ['name' => $name, 'weight' => $weight];
        }

        $topics = [];
        foreach ((array) ($raw['topics'] ?? []) as $topic) {
            $t = trim((string) $topic);
            if ($t !== '') {
                $topics[] = $t;
            }
        }

        $summary = trim((string) ($raw['summary'] ?? ''));
        if ($summary === '') {
            $summary = $fallback['summary'];
        }

        return [
            'skills' => $skills,
            'topics' => $topics,
            'summary' => $summary,
        ];
    }

    /**
     * @param array{skills:list<array{name:string,weight:int}>,topics:list<string>,summary:string} $jdContext
     * @param array{difficulty:string,count:int,marks:float} $batchSpec
     * @param array<string, true> $seenKeys
     * @param list<string> $seenPromptTexts
     * @param array<string, true> $bankIndex
     * @return array{questions:list<array<string,mixed>>,received:int,target:int,complete:bool}
     */
    private function generateJdDifficultyQuota(
        string $jobDescription,
        array $jdContext,
        array $batchSpec,
        string $language,
        string $instructions,
        float $negativeMarks,
        array &$seenKeys,
        array &$seenPromptTexts,
        array $bankIndex,
        string $progressKey,
        int $totalRequested,
        int $totalGeneratedSoFar,
        int &$tempCounter
    ): array {
        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($batchSpec['difficulty'] ?? 'Medium'));
        $target = max(1, (int) ($batchSpec['count'] ?? 1));
        $marks = max(0.25, (float) ($batchSpec['marks'] ?? 1));
        /** @var list<array<string, mixed>> $collected */
        $collected = [];
        $attempts = 0;

        while (count($collected) < $target && $attempts < self::MAX_JD_BATCH_ATTEMPTS) {
            $attempts++;
            $remaining = $target - count($collected);
            $batchSize = min(self::JD_BATCH_SIZE, $remaining);
            $generatedTotal = $totalGeneratedSoFar + count($collected);

            $this->writeGenerationProgress($progressKey, [
                'phase' => 'generating',
                'message' => "Generating {$difficulty} questions… ({$generatedTotal} / {$totalRequested})",
                'generated' => $generatedTotal,
                'requested' => $totalRequested,
                'percent' => $totalRequested > 0
                    ? min(99, (int) round(($generatedTotal / $totalRequested) * 100))
                    : 0,
                'done' => false,
            ]);

            $existingHints = $this->collectExistingPromptHints($seenPromptTexts);
            $topicGuide = $this->buildTopicGuideForBatch($jdContext, $collected, $batchSize);

            try {
                $rawQuestions = $this->callJdBatchApi(
                    $jobDescription,
                    $jdContext,
                    $difficulty,
                    $batchSize,
                    $marks,
                    $language,
                    $instructions,
                    $existingHints,
                    $topicGuide
                );
            } catch (\RuntimeException $e) {
                if ($this->isRetryableAiError($e->getMessage())) {
                    error_log('[PMS Aptitude AI] JD batch retry: ' . $e->getMessage());
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                error_log('[PMS Aptitude AI] JD batch failed: ' . $e->getMessage());
                continue;
            }

            $validated = $this->validateJdBatchToPreview(
                $rawQuestions,
                $difficulty,
                $marks,
                $negativeMarks,
                $seenKeys,
                $seenPromptTexts,
                $bankIndex,
                $tempCounter
            );

            foreach ($validated as $question) {
                if (count($collected) >= $target) {
                    break;
                }
                $collected[] = $question;
            }

            if ($validated === [] && $attempts >= self::MAX_JD_BATCH_ATTEMPTS) {
                break;
            }

            if (count($collected) < $target) {
                $this->writeGenerationProgress($progressKey, [
                    'phase' => 'generating_remaining',
                    'message' => 'Generating remaining questions…',
                    'generated' => $totalGeneratedSoFar + count($collected),
                    'requested' => $totalRequested,
                    'percent' => $totalRequested > 0
                        ? min(99, (int) round((($totalGeneratedSoFar + count($collected)) / $totalRequested) * 100))
                        : 0,
                    'done' => false,
                ]);
            }
        }

        return [
            'questions' => $collected,
            'received' => count($collected),
            'target' => $target,
            'complete' => count($collected) >= $target,
        ];
    }

    /**
     * @param list<string> $existingHints
     * @return list<array<string, mixed>>
     */
    private function callJdBatchApi(
        string $jobDescription,
        array $jdContext,
        string $difficulty,
        int $batchSize,
        float $marks,
        string $language,
        string $instructions,
        array $existingHints,
        string $topicGuide
    ): array {
        $system = 'You are an aptitude question generator for a university placement preparation system. '
            . 'Analyze job descriptions and generate relevant technical/aptitude MCQs. '
            . 'Return ONLY valid JSON with no markdown or commentary.';
        $user = $this->buildJdBatchPrompt(
            $jobDescription,
            $jdContext,
            $difficulty,
            $batchSize,
            $marks,
            $language,
            $instructions,
            $existingHints,
            $topicGuide
        );

        $raw = $this->openai->generateJson($system, $user);

        return $this->extractQuestions($raw);
    }

    private function isRetryableAiError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'invalid json')
            || str_contains($lower, 'empty response')
            || str_contains($lower, 'temporarily unavailable');
    }

    /**
     * @param list<string> $seenPromptTexts
     * @return list<string>
     */
    private function collectExistingPromptHints(array $seenPromptTexts): array
    {
        if ($seenPromptTexts === []) {
            return [];
        }

        return array_slice($seenPromptTexts, -self::MAX_EXISTING_PROMPT_HINTS);
    }

    /**
     * @param array{skills:list<array{name:string,weight:int}>,topics:list<string>,summary:string} $jdContext
     * @param list<array<string, mixed>> $collected
     */
    private function buildTopicGuideForBatch(array $jdContext, array $collected, int $batchSize): string
    {
        $topicCounts = [];
        foreach ($collected as $q) {
            $topic = trim((string) ($q['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $key = strtolower($topic);
            $topicCounts[$key] = ($topicCounts[$key] ?? 0) + 1;
        }

        $skills = $jdContext['skills'] ?? [];
        if ($skills === []) {
            $topics = $jdContext['topics'] ?? [];
            if ($topics === []) {
                return "Generate {$batchSize} questions distributed across the main skills mentioned in the JD.";
            }

            return 'Prioritize these JD topics: ' . implode(', ', array_slice($topics, 0, 8))
                . ". Generate {$batchSize} questions with balanced coverage.";
        }

        usort($skills, static function (array $a, array $b) use ($topicCounts): int {
            $aKey = strtolower((string) ($a['name'] ?? ''));
            $bKey = strtolower((string) ($b['name'] ?? ''));
            $aCount = $topicCounts[$aKey] ?? 0;
            $bCount = $topicCounts[$bKey] ?? 0;
            if ($aCount !== $bCount) {
                return $aCount <=> $bCount;
            }

            return ((int) ($b['weight'] ?? 1)) <=> ((int) ($a['weight'] ?? 1));
        });

        $focus = [];
        foreach (array_slice($skills, 0, 6) as $skill) {
            $focus[] = (string) ($skill['name'] ?? '');
        }
        $focus = array_values(array_filter($focus, static fn (string $s): bool => $s !== ''));

        if ($focus === []) {
            return "Generate {$batchSize} questions distributed across the main skills in the JD.";
        }

        return 'For this batch, prioritize underrepresented JD skills/topics: '
            . implode(', ', $focus)
            . ". Generate {$batchSize} questions with reasonable variety.";
    }

    /**
     * @param list<array<string, mixed>> $rawQuestions
     * @param array<string, true> $seenKeys
     * @param list<string> $seenPromptTexts
     * @param array<string, true> $bankIndex
     * @return list<array<string, mixed>>
     */
    private function validateJdBatchToPreview(
        array $rawQuestions,
        string $difficulty,
        float $marks,
        float $negativeMarks,
        array &$seenKeys,
        array &$seenPromptTexts,
        array $bankIndex,
        int &$tempCounter
    ): array {
        $fallbackCategory = 'General Aptitude';
        $fallbackTopic = 'Job Description';
        /** @var list<array<string, mixed>> $preview */
        $preview = [];
        /** @var array<string, true> $batchKeys */
        $batchKeys = [];

        foreach ($rawQuestions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $mapped = $this->mapAiQuestion(
                $q,
                $fallbackCategory,
                $fallbackTopic,
                $difficulty,
                $marks,
                $negativeMarks
            );
            if ($mapped === null) {
                continue;
            }

            $key = AptitudeQuestionBankModel::normalizePromptKey((string) ($mapped['prompt'] ?? ''));
            if ($key === '' || isset($seenKeys[$key]) || isset($batchKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $batchKeys[$key] = true;
            $seenPromptTexts[] = mb_substr((string) $mapped['prompt'], 0, 160);
            $tempCounter++;

            $duplicateInBank = isset($bankIndex[$key]);
            $preview[] = array_merge($mapped, [
                'tempId' => 'ai-jd-' . $tempCounter . '-' . bin2hex(random_bytes(4)),
                'source' => 'AI_JD',
                'duplicateInBank' => $duplicateInBank,
                'duplicateInBatch' => false,
                'duplicateMessage' => $duplicateInBank
                    ? 'This question already exists in the bank and will not be added if saved unchanged.'
                    : null,
                'selected' => !$duplicateInBank,
            ]);
        }

        return $preview;
    }

    /**
     * @param array{skills:list<array{name:string,weight:int}>,topics:list<string>,summary:string} $jdContext
     * @param list<string> $existingHints
     */
    private function buildJdBatchPrompt(
        string $jobDescription,
        array $jdContext,
        string $difficulty,
        int $count,
        float $marks,
        string $language,
        string $instructions,
        array $existingHints,
        string $topicGuide
    ): string {
        $extra = trim($instructions);
        $extraBlock = $extra !== '' ? "\nAdditional instructions:\n{$extra}\n" : '';

        $contextBlock = trim((string) ($jdContext['summary'] ?? ''));
        if ($contextBlock !== '') {
            $contextBlock = "\nJD analysis summary (reuse for every question in this batch):\n{$contextBlock}\n";
        }

        $skills = $jdContext['skills'] ?? [];
        if ($skills !== []) {
            $skillLines = [];
            foreach ($skills as $skill) {
                $skillLines[] = '- ' . ($skill['name'] ?? '') . ' (emphasis: ' . ($skill['weight'] ?? 3) . '/5)';
            }
            $contextBlock .= "\nIdentified JD skills:\n" . implode("\n", $skillLines) . "\n";
        }

        $avoidBlock = '';
        if ($existingHints !== []) {
            $lines = [];
            foreach (array_values($existingHints) as $i => $hint) {
                $lines[] = ($i + 1) . '. ' . $hint;
            }
            $avoidBlock = "\nGenerate {$count} NEW questions.\n"
                . "Do NOT repeat or closely paraphrase any of these already generated questions:\n"
                . implode("\n", $lines) . "\n";
        }

        return <<<PROMPT
You are generating campus placement aptitude/technical MCQs from a real company job description.

{$contextBlock}
{$topicGuide}

Requirements:
- Generate exactly {$count} questions at {$difficulty} difficulty.
- Every question MUST be relevant to skills, concepts, or responsibilities in the JD.
- Do NOT generate questions about technologies not mentioned in the JD unless explicitly requested.
- When multiple skills are present, distribute questions across important ones based on JD emphasis.
- Each question must have exactly four distinct options with only one correct answer.
- Provide a short explanation that supports the correct option.
- Set category to "General Aptitude" or a fitting aptitude category.
- Set topic to the specific skill/concept tested (e.g. OOP, SQL, REST APIs, React).
- Avoid duplicate, ambiguous, or trick questions unless requested.
- Verify numerical calculations before returning JSON.
- Language: {$language}
- Return ONLY valid structured JSON with no markdown.
{$avoidBlock}
Job Description:
{$jobDescription}

Difficulty: {$difficulty}
Number of questions in THIS batch: {$count}
Marks per question: {$marks}
{$extraBlock}
Use this exact JSON schema:
{
  "questions": [
    {
      "question": "string",
      "options": ["string", "string", "string", "string"],
      "correctAnswerIndex": 0,
      "correctOptionLetter": "A",
      "explanation": "string",
      "category": "General Aptitude",
      "topic": "specific skill from JD",
      "difficulty": "{$difficulty}",
      "marks": {$marks}
    }
  ]
}

Rules for correctAnswerIndex: MUST be 0-based — 0 = option A, 1 = B, 2 = C, 3 = D. Never use 1–4.
correctOptionLetter MUST be A, B, C, or D and MUST match correctAnswerIndex.
The explanation MUST clearly support the chosen option and include the exact text of the correct option.
Double-check every answer key before returning JSON.
PROMPT;
    }

    private function buildJdPrompt(
        string $jobDescription,
        string $difficulty,
        int $count,
        float $marks,
        string $language,
        string $instructions
    ): string {
        $extra = trim($instructions);
        $extraBlock = $extra !== '' ? "\nAdditional instructions:\n{$extra}\n" : '';

        return <<<PROMPT
You are generating campus placement aptitude/technical MCQs from a real company job description.

Before generating questions, analyze the job description and identify:
- Technical skills (languages, frameworks, tools)
- Core concepts (OOP, data structures, DBMS, networks, REST APIs, etc.)
- Job responsibilities (development, testing, debugging, etc.)
- Other requirements (problem solving, communication, analytical skills)

Use this analysis to decide what questions to generate.

Requirements:
- Generate exactly {$count} questions at {$difficulty} difficulty.
- Every question MUST be relevant to skills, concepts, or responsibilities mentioned in the JD.
- Do NOT generate questions about technologies not mentioned in the JD unless explicitly requested in additional instructions.
- When multiple skills are present, distribute questions reasonably across the important ones.
- Prioritize skills marked Required, Must have, Essential, or Mandatory in the JD.
- Each question must have exactly four distinct options with only one correct answer.
- Provide a short explanation that supports the correct option.
- Set category to "General Aptitude" or a fitting aptitude category.
- Set topic to the specific skill/concept tested (e.g. OOP, SQL, REST APIs, React).
- Avoid duplicate, ambiguous, or trick questions unless requested.
- Verify numerical calculations before returning JSON.
- Language: {$language}
- Return ONLY valid structured JSON with no markdown.

Job Description:
{$jobDescription}

Difficulty: {$difficulty}
Number of questions: {$count}
Marks per question: {$marks}
{$extraBlock}
Use this exact JSON schema:
{
  "questions": [
    {
      "question": "string",
      "options": ["string", "string", "string", "string"],
      "correctAnswerIndex": 0,
      "correctOptionLetter": "A",
      "explanation": "string",
      "category": "General Aptitude",
      "topic": "specific skill from JD",
      "difficulty": "{$difficulty}",
      "marks": {$marks}
    }
  ]
}

Rules for correctAnswerIndex: MUST be 0-based — 0 = option A, 1 = B, 2 = C, 3 = D. Never use 1–4.
correctOptionLetter MUST be A, B, C, or D and MUST match correctAnswerIndex.
The explanation MUST clearly support the chosen option and include the exact text of the correct option.
Double-check every answer key before returning JSON.
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
        $mapped = $this->mapAiQuestionCore($q, $fallbackCategory, $fallbackTopic, $fallbackDifficulty, $defaultMarks);
        if ($mapped === null) {
            return null;
        }

        return array_merge($mapped, [
            'negative_marks' => $negativeMarks > 0 ? $negativeMarks : 0,
        ]);
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapPreviewToRow(array $q, string $fallbackCategory): ?array
    {
        $mapped = $this->mapUserApprovedQuestion(
            $q,
            $fallbackCategory,
            trim((string) ($q['topic'] ?? '')),
            (string) ($q['difficulty'] ?? 'Medium'),
            (float) ($q['marks'] ?? 1)
        );
        if ($mapped === null) {
            return null;
        }

        $source = trim((string) ($q['source'] ?? 'AI'));

        return array_merge($mapped, [
            'source' => in_array($source, ['AI', 'AI_JD'], true) ? $source : 'AI',
        ]);
    }

    /**
     * Trust PO-reviewed preview payload (including manually chosen correctIndex).
     *
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapUserApprovedQuestion(
        array $q,
        string $fallbackCategory,
        string $fallbackTopic,
        string $fallbackDifficulty,
        float $defaultMarks
    ): ?array {
        $prompt = trim((string) ($q['prompt'] ?? $q['question'] ?? $q['question_text'] ?? ''));
        if ($prompt === '') {
            return null;
        }

        $options = $this->normalizeAiOptions($q);
        if ($options === null) {
            return null;
        }

        $explanation = trim((string) ($q['explanation'] ?? $q['solution'] ?? ''));
        if ($explanation === '') {
            return null;
        }

        $explicitCorrect = $this->parseExplicitCorrectIndex($q);
        if ($explicitCorrect !== null) {
            $correctIndex = $explicitCorrect;
        } else {
            $correctIndex = $this->resolveAiCorrectIndex($q, $options, $explanation);
            if ($correctIndex === null) {
                return null;
            }
            $fromExplanation = AptitudeTestModel::findUniqueOptionInExplanation($options, $explanation);
            if ($fromExplanation !== null) {
                $correctIndex = $fromExplanation;
            }
        }

        $topic = trim((string) ($q['topic'] ?? $fallbackTopic));
        if ($topic === '') {
            return null;
        }

        $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($q['difficulty'] ?? $fallbackDifficulty));
        if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
            return null;
        }

        $row = [
            'prompt' => $prompt,
            'options' => $options,
            'correctIndex' => $correctIndex,
            'explanation' => $explanation,
            'category' => AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'marks' => max(0.25, (float) ($q['marks'] ?? $defaultMarks)),
        ];
        if ($explicitCorrect !== null) {
            $row['lockCorrectIndex'] = true;
        }

        return $row;
    }

    private function parseExplicitCorrectIndex(array $q): ?int
    {
        if (array_key_exists('correctIndex', $q) && $q['correctIndex'] !== '' && $q['correctIndex'] !== null) {
            $idx = (int) $q['correctIndex'];
            if ($idx >= 0 && $idx <= 3) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>|null
     */
    private function mapAiQuestionCore(
        array $q,
        string $fallbackCategory,
        string $fallbackTopic,
        string $fallbackDifficulty,
        float $defaultMarks
    ): ?array {
        $prompt = trim((string) ($q['prompt'] ?? $q['question'] ?? $q['question_text'] ?? ''));
        if ($prompt === '') {
            return null;
        }

        $options = $this->normalizeAiOptions($q);
        if ($options === null) {
            return null;
        }

        $explanation = trim((string) ($q['explanation'] ?? $q['solution'] ?? ''));
        if ($explanation === '') {
            return null;
        }

        $correctIndex = $this->resolveAiCorrectIndex($q, $options, $explanation);
        if ($correctIndex === null) {
            return null;
        }
        $aligned = AptitudeTestModel::alignOptionsWithExplanation($options, $explanation, $correctIndex);
        $options = $aligned['options'];
        $correctIndex = $aligned['correctIndex'];
        $explanation = AptitudeTestModel::ensureExplanationMentionsCorrectOption($options, $correctIndex, $explanation);

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
            'category' => AptitudeTestModel::normalizeCategory((string) ($q['category'] ?? $fallbackCategory)),
            'topic' => $topic,
            'difficulty' => $difficulty,
            'marks' => max(0.25, (float) ($q['marks'] ?? $defaultMarks)),
        ];
    }

    /**
     * @param array<string, mixed> $q
     * @return list<string>|null
     */
    private function normalizeAiOptions(array $q): ?array
    {
        $raw = $q['options'] ?? null;
        $options = [];

        if (is_array($raw)) {
            if ($raw !== [] && array_keys($raw) !== range(0, count($raw) - 1)) {
                $letters = ['A', 'B', 'C', 'D'];
                for ($i = 0; $i < 4; $i++) {
                    $keys = [
                        $letters[$i],
                        strtolower($letters[$i]),
                        'option_' . strtolower($letters[$i]),
                    ];
                    foreach ($keys as $key) {
                        if (array_key_exists($key, $raw)) {
                            $options[$i] = AptitudeTestModel::sanitizeOptionText((string) $raw[$key]);
                            break;
                        }
                    }
                }
                ksort($options);
                $options = array_values($options);
            } else {
                foreach ($raw as $opt) {
                    $options[] = AptitudeTestModel::sanitizeOptionText((string) $opt);
                }
            }
        }

        if (count($options) < 4) {
            $fallback = [
                trim((string) ($q['option_a'] ?? $q['optionA'] ?? '')),
                trim((string) ($q['option_b'] ?? $q['optionB'] ?? '')),
                trim((string) ($q['option_c'] ?? $q['optionC'] ?? '')),
                trim((string) ($q['option_d'] ?? $q['optionD'] ?? '')),
            ];
            if (count(array_filter($fallback, static fn (string $o): bool => $o !== '')) === 4) {
                $options = $fallback;
            }
        }

        $options = array_values(array_map(
            static fn (string $o): string => AptitudeTestModel::sanitizeOptionText($o),
            array_slice($options, 0, 4)
        ));
        if (count($options) !== 4 || in_array('', $options, true)) {
            return null;
        }

        $normOpts = array_map(static fn (string $o): string => strtolower($o), $options);
        if (count($normOpts) !== count(array_unique($normOpts))) {
            return null;
        }

        return $options;
    }

    /**
     * @param list<string> $options
     */
    private function resolveAiCorrectIndex(array $q, array $options, string $explanation): ?int
    {
        $explanationIndex = AptitudeTestModel::findUniqueOptionInExplanation($options, $explanation);
        if ($explanationIndex !== null) {
            return $explanationIndex;
        }

        $letterIndex = $this->parseOptionLetterIndex(
            $q['correctOptionLetter'] ?? $q['correct_option_letter'] ?? $q['correctLetter'] ?? null
        );
        if ($letterIndex !== null) {
            return $letterIndex;
        }

        $numericCandidates = $this->parseNumericCorrectCandidates($q);
        if (count($numericCandidates) === 1) {
            return $numericCandidates[0];
        }

        return null;
    }

    private function parseOptionLetterIndex(mixed $raw): ?int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $letter = strtoupper(trim((string) $raw));
        if (strlen($letter) !== 1 || $letter < 'A' || $letter > 'D') {
            return null;
        }

        return ord($letter) - ord('A');
    }

    /**
     * @return list<int>
     */
    private function parseNumericCorrectCandidates(array $q): array
    {
        $raw = $q['correctAnswerIndex'] ?? $q['correctIndex'] ?? $q['correctAnswer'] ?? $q['correct_answer'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw) && !ctype_digit($raw)) {
            $letterIndex = $this->parseOptionLetterIndex($raw);
            return $letterIndex !== null ? [$letterIndex] : [];
        }

        $n = (int) $raw;
        $candidates = [];

        if ($n >= 0 && $n <= 3) {
            $candidates[] = $n;
        }
        if ($n >= 1 && $n <= 4) {
            $oneBased = $n - 1;
            if (!in_array($oneBased, $candidates, true)) {
                $candidates[] = $oneBased;
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array{difficulty:string,count:int,marks:float}>
     */
    private function normalizeGenerationBatches(array $body): array
    {
        $rawBatches = $body['batches'] ?? null;
        if (!is_array($rawBatches) || $rawBatches === []) {
            $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium'));
            $count = max(1, min(self::MAX_COUNT, (int) ($body['count'] ?? 5)));
            $marks = max(0.25, min(100, (float) ($body['marks'] ?? 1)));
            if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
                throw new \InvalidArgumentException('Invalid difficulty.');
            }

            return [[
                'difficulty' => $difficulty,
                'count' => $count,
                'marks' => $marks,
            ]];
        }

        $batches = [];
        $totalCount = 0;
        foreach ($rawBatches as $batch) {
            if (!is_array($batch)) {
                continue;
            }
            $difficulty = AptitudeTestModel::normalizeDifficulty((string) ($batch['difficulty'] ?? 'Medium'));
            $count = max(0, min(self::MAX_COUNT, (int) ($batch['count'] ?? 0)));
            $marks = max(0.25, min(100, (float) ($batch['marks'] ?? 1)));
            if ($count <= 0) {
                continue;
            }
            if (!in_array($difficulty, AptitudeTestModel::DIFFICULTIES, true)) {
                throw new \InvalidArgumentException('Invalid difficulty.');
            }
            $totalCount += $count;
            if ($totalCount > self::MAX_COUNT) {
                throw new \InvalidArgumentException('Total questions cannot exceed ' . self::MAX_COUNT . '.');
            }
            $batches[] = [
                'difficulty' => $difficulty,
                'count' => $count,
                'marks' => $marks,
            ];
        }

        return $batches;
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
