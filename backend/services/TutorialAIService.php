<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Middleware\AuthMiddleware;
use PMS\Models\TutorialExerciseModel;

/**
 * AI tutorial course/module/MCQ/activity generation via existing OpenAIService.
 * Preview-first: nothing persists until staff explicitly save.
 * Does not publish courses, execute code/SQL, or grade students.
 */
class TutorialAIService
{
    public const MAX_MODULES = 12;
    public const MIN_MODULES = 1;
    public const MAX_TOPIC_CHARS = 200;
    public const MAX_INSTRUCTIONS_CHARS = 2000;
    public const MAX_SYLLABUS_CHARS = 12000;
    public const MAX_BLOCKS_PER_MODULE = 80;
    public const COOLDOWN_SECONDS = 8;

    /** @var list<string> */
    public const ACADEMIC_FIELDS = [
        'engineering',
        'computer_applications',
        'business_administration',
        'other',
    ];

    /** @var list<string> */
    public const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    /** @var list<string> */
    private const BLOCK_TYPES = ['paragraph', 'heading', 'quote', 'code', 'divider'];

    /** @var list<string> */
    private const CODE_LANGUAGES = [
        'auto', 'text', 'python', 'javascript', 'typescript', 'java', 'c', 'cpp', 'csharp',
        'php', 'sql', 'html', 'css', 'json', 'bash', 'go',
    ];

    /** @var list<string> */
    public const ACTIVITY_TYPES = [
        'programming_task',
        'sql_query',
        'numerical',
        'short_answer',
        'case_study',
        'analytical_design',
    ];

    /** @var list<string> */
    private const ACTIVITY_LANGUAGES = [
        'c', 'cpp', 'java', 'python', 'javascript', 'php', 'sql', 'text',
    ];

    /** @var array<string, string> */
    private const FIELD_CATEGORY_HINT = [
        'engineering' => 'technologies',
        'computer_applications' => 'programming-languages',
        'business_administration' => 'other',
        'other' => 'other',
    ];

    private OpenAIService $openai;
    private TutorialService $tutorials;

    public function __construct(?OpenAIService $openai = null, ?TutorialService $tutorials = null)
    {
        $this->openai = $openai ?? new OpenAIService();
        $this->tutorials = $tutorials ?? new TutorialService();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(array $user): array
    {
        $this->assertAuthor($user);

        return $this->openai->checkStatus();
    }

    /**
     * Generate a complete course preview. Does not persist.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateCoursePreview(array $user, array $input): array
    {
        $this->assertAuthor($user);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? ''));
        @set_time_limit(600);

        $req = $this->normalizeCourseRequest($input);
        $this->assertAiConfigured();
        @set_time_limit(600);

        $system = $this->courseSystemPrompt($req['academicField']);
        $userPrompt = $this->courseUserPrompt($req);

        try {
            $raw = $this->callGenerateJson($system, $userPrompt);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS TutorialAI] generate course failed: ' . $e->getMessage());
            throw new \RuntimeException('AI course generation is temporarily unavailable. Please try again.');
        }

        $normalized = $this->normalizeCourseDocument($raw, $req);
        $previewId = 'course-' . bin2hex(random_bytes(6));

        return [
            'previewId' => $previewId,
            'scope' => 'course',
            'model' => $this->openai->checkStatus()['model'] ?? '',
            'generatedAt' => gmdate('c'),
            'preferences' => [
                'mcqsPerModule' => $req['mcqsPerModule'],
                'practicalPreference' => $req['practicalPreference'],
            ],
            'suggestedCategorySlug' => self::FIELD_CATEGORY_HINT[$req['academicField']] ?? 'other',
            'course' => $normalized['course'],
            'modules' => $normalized['modules'],
            'moduleCount' => count($normalized['modules']),
        ];
    }

    /**
     * Persist a previously generated (and re-validated) course as draft.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveCourseDraft(array $user, array $input): array
    {
        $this->assertAuthor($user);
        $categoryId = trim((string) ($input['categoryId'] ?? ''));
        if ($categoryId === '') {
            throw new \InvalidArgumentException('Choose a category before saving the generated course.');
        }

        $visibility = strtolower(trim((string) ($input['visibility'] ?? 'all')));
        if ($visibility !== 'scoped') {
            $visibility = 'all';
        }

        $reqHints = [
            'topic' => (string) (($input['course']['topic'] ?? $input['topic'] ?? '')),
            'academicField' => (string) ($input['academicField'] ?? $input['course']['academicField'] ?? 'other'),
            'difficulty' => (string) ($input['difficulty'] ?? $input['course']['difficulty'] ?? 'beginner'),
            'moduleCount' => is_array($input['modules'] ?? null) ? count($input['modules']) : 1,
            'mcqsPerModule' => (int) ($input['preferences']['mcqsPerModule'] ?? $input['mcqsPerModule'] ?? 0),
            'practicalPreference' => (string) ($input['preferences']['practicalPreference'] ?? $input['practicalPreference'] ?? 'none'),
            'additionalInstructions' => '',
            'syllabusText' => '',
            'estimatedDurationMinutes' => (int) ($input['course']['estimatedDurationMinutes'] ?? 0),
        ];
        $normalized = $this->normalizeCourseDocument([
            'version' => 1,
            'course' => is_array($input['course'] ?? null) ? $input['course'] : [],
            'modules' => is_array($input['modules'] ?? null) ? $input['modules'] : [],
        ], $this->normalizeCourseRequest(array_merge($reqHints, [
            'topic' => $reqHints['topic'] !== '' ? $reqHints['topic'] : 'Course',
            'moduleCount' => max(1, min(self::MAX_MODULES, $reqHints['moduleCount'])),
        ])));

        $pdo = Database::pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }

        try {
            $course = $this->tutorials->createTutorial($user, [
                'title' => (string) $normalized['course']['title'],
                'topic' => (string) $normalized['course']['topic'],
                'description' => (string) $normalized['course']['description'],
                'categoryId' => $categoryId,
                'visibility' => $visibility,
                'departmentIds' => (array) ($input['departmentIds'] ?? []),
                'passingYears' => (array) ($input['passingYears'] ?? []),
            ]);
            $tutorialId = (string) ($course['id'] ?? '');
            if ($tutorialId === '') {
                throw new \RuntimeException('The generated course could not be saved.');
            }

            $savedModules = [];
            foreach ($normalized['modules'] as $index => $module) {
                $content = json_encode([
                    'version' => 1,
                    'blocks' => $module['lessonDocument']['blocks'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($content === false) {
                    throw new \RuntimeException('A generated module could not be encoded.');
                }
                $saved = $this->tutorials->createModule($user, $tutorialId, [
                    'title' => (string) $module['title'],
                    'subtitle' => (string) ($module['subtitle'] ?? ''),
                    'sortOrder' => $index + 1,
                    'content' => $content,
                ]);
                $moduleId = (string) ($saved['id'] ?? '');
                $exerciseCount = $this->persistGeneratedExercises(
                    $user,
                    $tutorialId,
                    $moduleId,
                    is_array($module['exercises'] ?? null) ? $module['exercises'] : []
                );
                $savedModules[] = [
                    'id' => $moduleId,
                    'title' => (string) ($saved['title'] ?? ''),
                    'sortOrder' => (int) ($saved['sortOrder'] ?? ($index + 1)),
                    'exerciseCount' => $exerciseCount,
                ];
            }

            if ($started) {
                $pdo->commit();
            }

            return [
                'tutorial' => $course,
                'modules' => $savedModules,
                'status' => 'draft',
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Generate one module for an existing course. Does not persist.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateModulePreview(array $user, string $tutorialId, array $input): array
    {
        $this->assertAuthor($user);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? ''));
        @set_time_limit(600);

        $course = $this->tutorials->showManaged($user, $tutorialId);
        $req = $this->normalizeModuleRequest($input, $course);
        $this->assertAiConfigured();
        @set_time_limit(600);

        $system = $this->moduleSystemPrompt($req['academicField']);
        $userPrompt = $this->moduleUserPrompt($req, $course);

        try {
            $raw = $this->callGenerateJson($system, $userPrompt);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS TutorialAI] generate module failed: ' . $e->getMessage());
            throw new \RuntimeException('AI module generation is temporarily unavailable. Please try again.');
        }

        $modulePayload = is_array($raw['module'] ?? null) ? $raw['module'] : $raw;
        $module = $this->attachLessonExercises(
            $this->normalizeModule($modulePayload, $req['difficulty'], $req['academicField']),
            is_array($modulePayload['exercises'] ?? null) ? $modulePayload['exercises'] : []
        );
        $previewId = 'module-' . bin2hex(random_bytes(6));

        return [
            'previewId' => $previewId,
            'scope' => 'module',
            'tutorialId' => (string) ($course['id'] ?? $tutorialId),
            'courseTitle' => (string) ($course['title'] ?? ''),
            'courseStatus' => (string) ($course['status'] ?? ''),
            'model' => $this->openai->checkStatus()['model'] ?? '',
            'generatedAt' => gmdate('c'),
            'preferences' => [
                'mcqsPerModule' => $req['mcqsPerModule'],
                'practicalPreference' => $req['practicalPreference'],
            ],
            'module' => $module,
        ];
    }

    /**
     * Persist a generated module into an existing course. Course status unchanged.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveModuleDraft(array $user, string $tutorialId, array $input): array
    {
        $this->assertAuthor($user);
        $course = $this->tutorials->showManaged($user, $tutorialId);
        $statusBefore = (string) ($course['status'] ?? 'draft');

        $moduleIn = is_array($input['module'] ?? null) ? $input['module'] : $input;
        $difficulty = $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner'));
        $field = $this->normalizeAcademicField((string) ($input['academicField'] ?? 'other'));
        $module = $this->attachLessonExercises(
            $this->normalizeModule($moduleIn, $difficulty, $field),
            is_array($moduleIn['exercises'] ?? null) ? $moduleIn['exercises'] : []
        );

        $content = json_encode([
            'version' => 1,
            'blocks' => $module['lessonDocument']['blocks'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) {
            throw new \RuntimeException('The generated module could not be encoded.');
        }

        $saved = $this->tutorials->createModule($user, $tutorialId, [
            'title' => (string) $module['title'],
            'subtitle' => (string) ($module['subtitle'] ?? ''),
            'content' => $content,
        ]);
        $this->persistGeneratedExercises(
            $user,
            $tutorialId,
            (string) ($saved['id'] ?? ''),
            is_array($module['exercises'] ?? null) ? $module['exercises'] : []
        );

        $fresh = $this->tutorials->showManaged($user, $tutorialId);

        return [
            'module' => $saved,
            'tutorialId' => $tutorialId,
            'courseStatus' => (string) ($fresh['status'] ?? $statusBefore),
            'statusUnchanged' => (string) ($fresh['status'] ?? '') === $statusBefore,
        ];
    }

    /**
     * Public for unit tests — normalize a full course AI document.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $req
     * @return array{version: int, course: array<string, mixed>, modules: list<array<string, mixed>>}
     */
    public function normalizeCourseDocument(array $raw, array $req): array
    {
        $courseIn = is_array($raw['course'] ?? null) ? $raw['course'] : [];
        $title = trim(strip_tags((string) ($courseIn['title'] ?? '')));
        if ($title === '') {
            throw new \InvalidArgumentException('Generated course is missing a title.');
        }
        $description = trim(strip_tags((string) ($courseIn['description'] ?? '')));
        if ($description === '') {
            $description = 'AI-generated draft course on ' . (string) $req['topic'] . '. Review before publishing.';
        }
        $topic = trim(strip_tags((string) ($courseIn['topic'] ?? $req['topic'])));
        if ($topic === '') {
            $topic = (string) $req['topic'];
        }
        $objectives = [];
        foreach ((array) ($courseIn['learningObjectives'] ?? []) as $item) {
            $text = trim(strip_tags((string) $item));
            if ($text !== '') {
                $objectives[] = mb_substr($text, 0, 300);
            }
            if (count($objectives) >= 12) {
                break;
            }
        }
        $duration = (int) ($courseIn['estimatedDurationMinutes'] ?? $req['estimatedDurationMinutes'] ?? 0);
        if ($duration < 0) {
            $duration = 0;
        }
        if ($duration > 10080) {
            $duration = 10080;
        }

        $modulesIn = is_array($raw['modules'] ?? null) ? $raw['modules'] : [];
        if ($modulesIn === []) {
            throw new \InvalidArgumentException('Generated course has no modules.');
        }
        if (count($modulesIn) > self::MAX_MODULES) {
            $modulesIn = array_slice($modulesIn, 0, self::MAX_MODULES);
        }

        $modules = [];
        foreach ($modulesIn as $module) {
            if (!is_array($module)) {
                continue;
            }
            $normalizedModule = $this->normalizeModule($module, (string) $req['difficulty'], (string) $req['academicField']);
            $modules[] = $this->attachLessonExercises(
                $normalizedModule,
                is_array($module['exercises'] ?? null) ? $module['exercises'] : []
            );
        }
        if ($modules === []) {
            throw new \InvalidArgumentException('Generated course has no valid modules.');
        }

        return [
            'version' => 1,
            'course' => [
                'title' => mb_substr($title, 0, 160),
                'description' => mb_substr($description, 0, 4000),
                'academicField' => (string) $req['academicField'],
                'difficulty' => (string) $req['difficulty'],
                'learningObjectives' => $objectives,
                'estimatedDurationMinutes' => $duration,
                'topic' => mb_substr($topic, 0, self::MAX_TOPIC_CHARS),
            ],
            'modules' => $modules,
        ];
    }

    /**
     * Public for unit tests.
     *
     * @param array<string, mixed> $module
     * @return array<string, mixed>
     */
    public function normalizeModule(array $module, string $difficulty, string $academicField): array
    {
        $title = trim(strip_tags((string) ($module['title'] ?? '')));
        if ($title === '') {
            throw new \InvalidArgumentException('A generated module is missing a title.');
        }
        $subtitle = trim(strip_tags((string) ($module['subtitle'] ?? $module['description'] ?? '')));
        if (mb_strlen($subtitle) > 240) {
            $subtitle = mb_substr($subtitle, 0, 240);
        }
        $description = trim(strip_tags((string) ($module['description'] ?? '')));
        $objectives = [];
        foreach ((array) ($module['learningObjectives'] ?? []) as $item) {
            $text = trim(strip_tags((string) $item));
            if ($text !== '') {
                $objectives[] = mb_substr($text, 0, 300);
            }
            if (count($objectives) >= 10) {
                break;
            }
        }

        $lesson = is_array($module['lessonDocument'] ?? null)
            ? $module['lessonDocument']
            : (is_array($module['lesson'] ?? null) ? $module['lesson'] : null);
        if (!is_array($lesson)) {
            throw new \InvalidArgumentException('Module "' . $title . '" is missing lesson content.');
        }
        $blocksIn = is_array($lesson['blocks'] ?? null) ? $lesson['blocks'] : [];
        if ($blocksIn === []) {
            throw new \InvalidArgumentException('Module "' . $title . '" has no lesson blocks.');
        }

        $blocks = $this->normalizeBlocks($blocksIn, $academicField);
        if ($blocks === []) {
            throw new \InvalidArgumentException('Module "' . $title . '" has no supported lesson blocks.');
        }

        return [
            'title' => mb_substr($title, 0, 160),
            'subtitle' => $subtitle,
            'description' => mb_substr($description, 0, 2000),
            'learningObjectives' => $objectives,
            'difficulty' => $this->normalizeDifficulty($difficulty),
            'lessonDocument' => [
                'version' => 1,
                'blocks' => $blocks,
            ],
        ];
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    public function normalizeBlocks(array $blocks, string $academicField = 'other'): array
    {
        $out = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS_PER_MODULE) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = strtolower(trim((string) ($block['type'] ?? '')));
            if ($type === 'image') {
                // Phase 2: never invent image URLs.
                continue;
            }
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? ''));
            if ($id === '') {
                $id = bin2hex(random_bytes(4));
            }
            if ($type === 'paragraph' || $type === 'quote') {
                $text = mb_substr(trim(strip_tags((string) ($block['text'] ?? ''))), 0, 20000);
                if ($text === '' && $type === 'paragraph') {
                    continue;
                }
                $out[] = ['id' => $id, 'type' => $type, 'text' => $text];
                continue;
            }
            if ($type === 'heading') {
                $text = mb_substr(trim(strip_tags((string) ($block['text'] ?? ''))), 0, 300);
                if ($text === '') {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'heading',
                    'level' => (int) ($block['level'] ?? 2) === 3 ? 3 : 2,
                    'text' => $text,
                ];
                continue;
            }
            if ($type === 'divider') {
                $out[] = ['id' => $id, 'type' => 'divider'];
                continue;
            }
            if ($type === 'code') {
                if (!$this->fieldAllowsCode($academicField)) {
                    $source = trim((string) ($block['source'] ?? ''));
                    if ($source !== '') {
                        $out[] = [
                            'id' => $id,
                            'type' => 'paragraph',
                            'text' => mb_substr('Example / illustration: ' . strip_tags($source), 0, 20000),
                        ];
                    }
                    continue;
                }
                $language = strtolower(trim((string) ($block['language'] ?? 'auto')));
                if (!in_array($language, self::CODE_LANGUAGES, true)) {
                    $language = 'auto';
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'code',
                    'language' => $language,
                    'source' => mb_substr((string) ($block['source'] ?? ''), 0, 20000),
                    'exampleOutput' => mb_substr((string) ($block['exampleOutput'] ?? ''), 0, 20000),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalizeCourseRequest(array $input): array
    {
        $topic = trim(strip_tags((string) ($input['topic'] ?? '')));
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required.');
        }
        if (mb_strlen($topic) > self::MAX_TOPIC_CHARS) {
            throw new \InvalidArgumentException('Topic must be ' . self::MAX_TOPIC_CHARS . ' characters or fewer.');
        }
        $moduleCount = (int) ($input['moduleCount'] ?? 0);
        if ($moduleCount < self::MIN_MODULES || $moduleCount > self::MAX_MODULES) {
            throw new \InvalidArgumentException('Module count must be between ' . self::MIN_MODULES . ' and ' . self::MAX_MODULES . '.');
        }
        $instructions = trim(strip_tags((string) ($input['additionalInstructions'] ?? $input['instructions'] ?? '')));
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS_CHARS) {
            throw new \InvalidArgumentException('Additional instructions are too long.');
        }
        $syllabus = trim((string) ($input['syllabusText'] ?? $input['syllabus'] ?? $input['referenceText'] ?? ''));
        $syllabus = strip_tags($syllabus);
        if (mb_strlen($syllabus) > self::MAX_SYLLABUS_CHARS) {
            throw new \InvalidArgumentException('Syllabus or reference text must be ' . self::MAX_SYLLABUS_CHARS . ' characters or fewer.');
        }
        $mcqs = (int) ($input['mcqsPerModule'] ?? 0);
        if ($mcqs < 0 || $mcqs > 20) {
            throw new \InvalidArgumentException('MCQs per module must be between 0 and 20.');
        }
        $practical = strtolower(trim((string) ($input['practicalPreference'] ?? 'none')));
        if (!in_array($practical, ['none', 'light', 'moderate', 'heavy'], true)) {
            $practical = 'none';
        }
        $duration = (int) ($input['estimatedDurationMinutes'] ?? 0);
        if ($duration < 0 || $duration > 10080) {
            $duration = 0;
        }

        return [
            'topic' => $topic,
            'academicField' => $this->normalizeAcademicField((string) ($input['academicField'] ?? 'other')),
            'difficulty' => $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner')),
            'moduleCount' => $moduleCount,
            'mcqsPerModule' => $mcqs,
            'practicalPreference' => $practical,
            'additionalInstructions' => $instructions,
            'syllabusText' => $syllabus,
            'estimatedDurationMinutes' => $duration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function callGenerateJson(string $system, string $user): array
    {
        return $this->openai->generateJson($system, $user);
    }

    protected function assertAiConfigured(): void
    {
        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException('AI tutorial generation is not configured. Contact the administrator.');
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertAuthor(array $user): void
    {
        $role = AuthMiddleware::resolvedRole($user);
        if (!in_array($role, ['admin', 'placement_officer', 'staff'], true)) {
            throw new \RuntimeException('You do not have permission to generate tutorials.', 403);
        }
    }

    private function assertCooldown(string $userId): void
    {
        if ($userId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_tutorial_ai_' . hash('sha256', $userId) . '.cooldown';
        if (is_file($path)) {
            $mtime = @filemtime($path);
            if ($mtime !== false && (time() - $mtime) < self::COOLDOWN_SECONDS) {
                throw new \RuntimeException('Please wait a few seconds before generating again.');
            }
        }
        @file_put_contents($path, (string) time());
    }

    public function normalizeAcademicField(string $field): string
    {
        $field = strtolower(trim($field));
        $aliases = [
            'engineering' => 'engineering',
            'eng' => 'engineering',
            'computer_applications' => 'computer_applications',
            'computer applications' => 'computer_applications',
            'mca' => 'computer_applications',
            'cs' => 'computer_applications',
            'business_administration' => 'business_administration',
            'business administration' => 'business_administration',
            'mba' => 'business_administration',
            'business' => 'business_administration',
            'other' => 'other',
        ];
        if (!isset($aliases[$field])) {
            throw new \InvalidArgumentException('Academic field must be engineering, computer_applications, business_administration, or other.');
        }

        return $aliases[$field];
    }

    public function normalizeDifficulty(string $difficulty): string
    {
        $difficulty = strtolower(trim($difficulty));
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new \InvalidArgumentException('Difficulty must be beginner, intermediate, or advanced.');
        }

        return $difficulty;
    }

    private function fieldAllowsCode(string $academicField): bool
    {
        return in_array($academicField, ['computer_applications', 'engineering'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $course
     * @return array<string, mixed>
     */
    private function normalizeModuleRequest(array $input, array $course): array
    {
        $topic = trim(strip_tags((string) ($input['topic'] ?? $input['moduleTopic'] ?? '')));
        if ($topic === '') {
            throw new \InvalidArgumentException('Module topic is required.');
        }
        if (mb_strlen($topic) > self::MAX_TOPIC_CHARS) {
            throw new \InvalidArgumentException('Module topic must be ' . self::MAX_TOPIC_CHARS . ' characters or fewer.');
        }
        $instructions = trim(strip_tags((string) ($input['additionalInstructions'] ?? $input['instructions'] ?? '')));
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS_CHARS) {
            throw new \InvalidArgumentException('Additional instructions are too long.');
        }
        $field = (string) ($input['academicField'] ?? '');
        if ($field === '') {
            $field = $this->inferFieldFromCourse($course);
        }
        $mcqs = (int) ($input['mcqsPerModule'] ?? 0);
        if ($mcqs < 0 || $mcqs > 20) {
            $mcqs = 0;
        }
        $practical = strtolower(trim((string) ($input['practicalPreference'] ?? 'none')));
        if (!in_array($practical, ['none', 'light', 'moderate', 'heavy'], true)) {
            $practical = 'none';
        }

        return [
            'topic' => $topic,
            'difficulty' => $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner')),
            'academicField' => $this->normalizeAcademicField($field),
            'additionalInstructions' => $instructions,
            'mcqsPerModule' => $mcqs,
            'practicalPreference' => $practical,
        ];
    }

    /**
     * @param array<string, mixed> $course
     */
    private function inferFieldFromCourse(array $course): string
    {
        $hay = strtolower((string) ($course['topic'] ?? '') . ' ' . (string) ($course['title'] ?? '') . ' ' . (string) ($course['description'] ?? ''));
        if (preg_match('/\b(market|finance|hr|account|business|mba)\b/', $hay) === 1) {
            return 'business_administration';
        }
        if (preg_match('/\b(iot|robot|electron|mechanical|electrical|network|cloud)\b/', $hay) === 1) {
            return 'engineering';
        }
        if (preg_match('/\b(python|java|dbms|sql|web|data structure|programming|software)\b/', $hay) === 1) {
            return 'computer_applications';
        }

        return 'other';
    }

    private function courseSystemPrompt(string $academicField): string
    {
        $fieldGuide = $this->fieldGuide($academicField);

        return <<<SYSTEM
You are an expert university curriculum author for a placement-oriented learning portal.
Return ONLY valid JSON (no markdown). Generate educational lesson content as structured blocks.
Do not invent image URLs. Do not include HTML. Do not execute or claim to run code.
Code blocks are demonstrations only.
{$fieldGuide}
Also include programming exercises that practise the concepts in each level-2 lesson heading.
Do not include mcqs or practicalActivities arrays. Use the exercises array only.
Do not claim that code was executed.
SYSTEM;
    }

    private function moduleSystemPrompt(string $academicField): string
    {
        $fieldGuide = $this->fieldGuide($academicField);

        return <<<SYSTEM
You author a single tutorial module for an existing university course.
Return ONLY valid JSON (no markdown) with one module object.
Use structured lesson blocks compatible with: paragraph, heading, quote, code, divider.
Do not invent image URLs. Do not include HTML. Do not duplicate existing modules.
{$fieldGuide}
Also include programming exercises linked to each level-2 lesson heading.
Do not include mcqs or practicalActivities. Use the exercises array only.
SYSTEM;
    }

    private function fieldGuide(string $academicField): string
    {
        return match ($academicField) {
            'engineering' => 'Academic field: Engineering. Prefer concept explanations, formulas, numerical examples, applications, and design thinking. Include code only when it clearly helps (e.g. IoT/cloud snippets).',
            'computer_applications' => 'Academic field: Computer Applications. Prefer programming explanations, algorithms, SQL/web examples, and code demonstrations with optional exampleOutput.',
            'business_administration' => 'Academic field: Business Administration. Prefer business concepts, cases, financial reasoning, and analytical explanations. Do NOT generate programming code blocks.',
            default => 'Academic field: Other / general. Prefer clear explanations and examples appropriate to the topic. Use code blocks only if the topic is clearly technical.',
        };
    }

    /**
     * @param array<string, mixed> $req
     */
    private function courseUserPrompt(array $req): string
    {
        $allowCode = $this->fieldAllowsCode((string) $req['academicField']) ? 'yes' : 'no';
        $syllabus = (string) $req['syllabusText'];
        $syllabusBlock = $syllabus !== '' ? "Reference syllabus/notes (use as guidance, do not copy verbatim):\n{$syllabus}\n" : '';
        $prefs = 'Optional later MCQ preference (do not emit mcqs): mcqsPerModule='
            . (int) $req['mcqsPerModule'] . '.';

        return <<<PROMPT
Generate a complete draft course.

Topic: {$req['topic']}
Academic field: {$req['academicField']}
Difficulty: {$req['difficulty']}
Module count: {$req['moduleCount']}
Estimated duration minutes (hint): {$req['estimatedDurationMinutes']}
Code blocks allowed: {$allowCode}
Additional instructions: {$req['additionalInstructions']}
{$prefs}
{$syllabusBlock}
Required JSON shape:
{
  "version": 1,
  "course": {
    "title": "string",
    "description": "string",
    "academicField": "{$req['academicField']}",
    "difficulty": "{$req['difficulty']}",
    "learningObjectives": ["string"],
    "estimatedDurationMinutes": 120,
    "topic": "string"
  },
  "modules": [
    {
      "title": "string",
      "subtitle": "string",
      "description": "string",
      "learningObjectives": ["string"],
      "lessonDocument": {
        "version": 1,
        "blocks": [
          {"type":"heading","level":2,"text":"Python Variables"},
          {"type":"paragraph","text":"..."},
          {"type":"code","language":"python","source":"...","exampleOutput":"..."}
        ]
      },
      "exercises": [
        {
          "lessonTitle": "Python Variables",
          "title": "Store a student name",
          "instructions": "Write a program that stores a name and prints it.",
          "language": "python",
          "boilerplate": "name = \"\"\n",
          "testCases": [
            {"stdin": "", "expectedOutput": "Ada\n", "sample": true},
            {"stdin": "", "expectedOutput": "Ada\n", "sample": false}
          ]
        }
      ]
    }
  ]
}

Rules:
- Produce exactly {$req['moduleCount']} modules. Each module needs at least two level-2 headings. Each heading is one lesson.
- Under each level-2 lesson, include at least 4 teaching blocks (paragraph, quote, or code) that explain that lesson.
- For every level-2 lesson, include 2 or 3 programming exercises whose lessonTitle exactly matches that heading text.
- Exercises must practise only the concepts taught in that lesson.
- Each exercise needs a title, instructions, language, optional boilerplate, one public sample test case (sample true) and one hidden test case (sample false).
- Programming language must be one of: python, javascript, java, c, cpp, php, sql.
- Lesson code-block language must be one of: auto, text, python, javascript, typescript, java, c, cpp, csharp, php, sql, html, css, json, bash, go.
- If code blocks are not allowed, still write the lesson in paragraphs, and still include programming exercises only when the topic is clearly a programming topic; otherwise include exercises in python that print or calculate the lesson idea.
PROMPT;
    }

    /**
     * @param array<string, mixed> $req
     * @param array<string, mixed> $course
     */
    private function moduleUserPrompt(array $req, array $course): string
    {
        $existing = [];
        foreach ((array) ($course['modules'] ?? []) as $module) {
            if (!is_array($module)) {
                continue;
            }
            $existing[] = trim((string) ($module['title'] ?? ''));
        }
        $existingList = $existing === [] ? '(none yet)' : implode('; ', array_slice(array_filter($existing), 0, 40));
        $allowCode = $this->fieldAllowsCode((string) $req['academicField']) ? 'yes' : 'no';

        return <<<PROMPT
Create one new module for this course.

Course title: {$course['title']}
Course description: {$course['description']}
Existing module titles: {$existingList}
Requested module topic: {$req['topic']}
Difficulty: {$req['difficulty']}
Academic field: {$req['academicField']}
Code blocks allowed: {$allowCode}
Additional instructions: {$req['additionalInstructions']}
Do not emit MCQs. Include programming exercises for each level-2 lesson.

Return JSON:
{
  "module": {
    "title": "string",
    "subtitle": "string",
    "description": "string",
    "learningObjectives": ["string"],
    "lessonDocument": {
      "version": 1,
      "blocks": [
        {"type":"heading","level":2,"text":"..."},
        {"type":"paragraph","text":"..."}
      ]
    },
    "exercises": [
      {
        "lessonTitle": "exact level-2 heading text",
        "title": "string",
        "instructions": "string",
        "language": "python",
        "boilerplate": "",
        "testCases": [
          {"stdin": "", "expectedOutput": "ok\n", "sample": true},
          {"stdin": "", "expectedOutput": "ok\n", "sample": false}
        ]
      }
    ]
  }
}

Avoid duplicating existing module titles or covering the same ground unnecessarily.
Include at least two level-2 lessons and at least 2 programming exercises for each, with lessonTitle matching the heading.
PROMPT;
    }

    /**
     * Bind programming exercises to level-2 lesson headings.
     * Invalid lesson links are matched by lesson title, or dropped when no lesson remains.
     *
     * @param array<string, mixed> $module
     * @param list<mixed> $rawExercises
     * @return array<string, mixed>
     */
    public function attachLessonExercises(array $module, array $rawExercises): array
    {
        $blocks = is_array($module['lessonDocument']['blocks'] ?? null) ? $module['lessonDocument']['blocks'] : [];
        $blocks = $this->stabilizeLessonHeadings($blocks, (string) ($module['title'] ?? 'Lesson'));
        if (!isset($module['lessonDocument']) || !is_array($module['lessonDocument'])) {
            $module['lessonDocument'] = ['version' => 1, 'blocks' => $blocks];
        } else {
            $module['lessonDocument']['blocks'] = $blocks;
        }
        $module['exercises'] = $this->normalizeProgrammingExercises($rawExercises, $this->lessonHeadings($blocks));

        return $module;
    }

    /**
     * Regenerate programming exercises for one lesson. Does not persist.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{lessonBlockId: string, exercises: list<array<string, mixed>>}
     */
    public function generateLessonExercisesPreview(array $user, array $input): array
    {
        $this->assertAuthor($user);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? ''));
        @set_time_limit(600);

        $lessonBlockId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['lessonBlockId'] ?? '')) ?? '';
        if ($lessonBlockId === '' || strlen($lessonBlockId) > 64) {
            throw new \InvalidArgumentException('Choose a lesson before regenerating exercises.');
        }
        $lessonTitle = mb_substr(trim(strip_tags((string) ($input['lessonTitle'] ?? 'Lesson'))), 0, 300);
        if ($lessonTitle === '') {
            $lessonTitle = 'Lesson';
        }
        $lessonText = mb_substr(trim(strip_tags((string) ($input['lessonText'] ?? ''))), 0, 6000);
        $topic = mb_substr(trim(strip_tags((string) ($input['topic'] ?? $lessonTitle))), 0, self::MAX_TOPIC_CHARS);
        if ($topic === '') {
            $topic = $lessonTitle;
        }
        $difficulty = $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner'));
        $field = $this->normalizeAcademicField((string) ($input['academicField'] ?? 'computer_applications'));
        $count = (int) ($input['count'] ?? 3);
        if ($count < 2) {
            $count = 2;
        }
        if ($count > 4) {
            $count = 4;
        }

        $this->assertAiConfigured();
        @set_time_limit(600);

        $system = <<<'SYSTEM'
You write programming practice for one university lesson.
Return ONLY valid JSON. Do not include markdown, HTML, MCQs, or practicalActivities.
Do not execute code and do not claim that code was run.
Each exercise must practise only the concepts in the supplied lesson.
SYSTEM;
        $userPrompt = <<<PROMPT
Topic: {$topic}
Difficulty: {$difficulty}
Academic field: {$field}
Lesson title: {$lessonTitle}
Lesson explanation:
{$lessonText}

Return JSON:
{
  "exercises": [
    {
      "lessonTitle": "{$lessonTitle}",
      "title": "string",
      "instructions": "problem statement and what the student should do",
      "language": "python",
      "boilerplate": "",
      "testCases": [
        {"stdin": "", "expectedOutput": "example\\n", "sample": true},
        {"stdin": "", "expectedOutput": "example\\n", "sample": false}
      ]
    }
  ]
}

Produce exactly {$count} exercises.
Language must be one of: python, javascript, java, c, cpp, php, sql.
Every exercise needs one public sample test case and one hidden test case.
PROMPT;

        try {
            $raw = $this->callGenerateJson($system, $userPrompt);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS TutorialAI] generate lesson exercises failed: ' . $e->getMessage());
            throw new \RuntimeException('AI exercise generation is temporarily unavailable. Please try again.');
        }

        $list = is_array($raw['exercises'] ?? null) ? $raw['exercises'] : [];
        $module = $this->attachLessonExercises([
            'title' => $lessonTitle,
            'lessonDocument' => [
                'version' => 1,
                'blocks' => [[
                    'id' => $lessonBlockId,
                    'type' => 'heading',
                    'level' => 2,
                    'text' => $lessonTitle,
                ]],
            ],
        ], $list);

        return [
            'lessonBlockId' => $lessonBlockId,
            'exercises' => is_array($module['exercises'] ?? null) ? $module['exercises'] : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function stabilizeLessonHeadings(array $blocks, string $fallbackTitle): array
    {
        $seen = [];
        $hasLesson = false;
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'heading' || (int) ($block['level'] ?? 2) !== 2) {
                continue;
            }
            $hasLesson = true;
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($block['id'] ?? '')) ?? '';
            if ($id === '' || isset($seen[$id]) || strlen($id) > 64) {
                $id = 'lesson' . bin2hex(random_bytes(4));
            }
            $seen[$id] = true;
            $blocks[$index]['id'] = $id;
            $blocks[$index]['level'] = 2;
        }
        if (!$hasLesson) {
            $title = trim($fallbackTitle) !== '' ? trim($fallbackTitle) : 'Lesson';
            array_unshift($blocks, [
                'id' => 'lesson' . bin2hex(random_bytes(4)),
                'type' => 'heading',
                'level' => 2,
                'text' => mb_substr($title, 0, 300),
            ]);
        }

        return $blocks;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array{id: string, text: string}>
     */
    private function lessonHeadings(array $blocks): array
    {
        $lessons = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'heading' || (int) ($block['level'] ?? 2) !== 2) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            $text = trim((string) ($block['text'] ?? ''));
            if ($id === '' || $text === '') {
                continue;
            }
            $lessons[] = ['id' => $id, 'text' => $text];
        }

        return $lessons;
    }

    /**
     * @param list<mixed> $rawExercises
     * @param list<array{id: string, text: string}> $lessons
     * @return list<array<string, mixed>>
     */
    private function normalizeProgrammingExercises(array $rawExercises, array $lessons): array
    {
        if ($lessons === []) {
            return [];
        }
        $byId = [];
        $byTitle = [];
        foreach ($lessons as $lesson) {
            $byId[$lesson['id']] = $lesson;
            $byTitle[$this->lessonKey($lesson['text'])] = $lesson;
        }
        $counts = [];
        $out = [];
        foreach ($rawExercises as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = mb_substr(trim(strip_tags((string) ($item['title'] ?? ''))), 0, 160);
            $instructions = trim(strip_tags((string) ($item['instructions'] ?? $item['problem'] ?? $item['description'] ?? '')));
            if ($title === '' || $instructions === '') {
                continue;
            }
            $instructions = mb_substr($instructions, 0, 8000);
            $language = TutorialExerciseModel::normalizeLanguage((string) ($item['language'] ?? 'python'));
            if ($language === '') {
                $language = 'python';
            }
            $lesson = null;
            $blockId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($item['lessonBlockId'] ?? '')) ?? '';
            if ($blockId !== '' && isset($byId[$blockId])) {
                $lesson = $byId[$blockId];
            } else {
                $key = $this->lessonKey((string) ($item['lessonTitle'] ?? ''));
                if ($key !== '' && isset($byTitle[$key])) {
                    $lesson = $byTitle[$key];
                } elseif (count($lessons) === 1) {
                    $lesson = $lessons[0];
                }
            }
            if ($lesson === null) {
                continue;
            }
            $lessonId = $lesson['id'];
            $counts[$lessonId] = ($counts[$lessonId] ?? 0) + 1;
            if ($counts[$lessonId] > 6) {
                continue;
            }
            $cases = $this->normalizeGeneratedTestCases(is_array($item['testCases'] ?? null) ? $item['testCases'] : []);
            if ($cases === []) {
                continue;
            }
            $out[] = [
                'title' => $title,
                'instructions' => $instructions,
                'language' => $language,
                'boilerplate' => mb_substr((string) ($item['boilerplate'] ?? $item['starterCode'] ?? ''), 0, 20000),
                'lessonBlockId' => $lessonId,
                'lessonTitle' => $lesson['text'],
                'testCases' => $cases,
            ];
            if (count($out) >= 36) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $rawCases
     * @return list<array{stdin: string, expectedOutput: string, sample: bool}>
     */
    private function normalizeGeneratedTestCases(array $rawCases): array
    {
        $out = [];
        foreach ($rawCases as $case) {
            if (!is_array($case)) {
                continue;
            }
            $expected = mb_substr((string) ($case['expectedOutput'] ?? ''), 0, 8000);
            if (trim($expected) === '') {
                continue;
            }
            $sample = false;
            if (is_bool($case['sample'] ?? null)) {
                $sample = $case['sample'];
            } elseif (is_string($case['sample'] ?? null)) {
                $sample = in_array(strtolower(trim($case['sample'])), ['1', 'true', 'yes', 'public'], true);
            }
            $out[] = [
                'stdin' => mb_substr((string) ($case['stdin'] ?? ''), 0, 8000),
                'expectedOutput' => $expected,
                'sample' => $sample,
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        if ($out === []) {
            return [];
        }
        $hasPublic = false;
        $hasHidden = false;
        foreach ($out as $case) {
            if ($case['sample']) {
                $hasPublic = true;
            } else {
                $hasHidden = true;
            }
        }
        if (!$hasPublic) {
            $out[0]['sample'] = true;
        }
        if (count($out) >= 2 && !$hasHidden) {
            $out[count($out) - 1]['sample'] = false;
        }

        return $out;
    }

    private function lessonKey(string $text): string
    {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? ''));

        return $text;
    }

    /**
     * @param array<string, mixed> $user
     * @param list<mixed> $exercises
     */
    private function persistGeneratedExercises(array $user, string $tutorialId, string $moduleId, array $exercises): int
    {
        if ($tutorialId === '' || $moduleId === '') {
            return 0;
        }
        $saved = 0;
        foreach ($exercises as $index => $exercise) {
            if (!is_array($exercise)) {
                continue;
            }
            $lessonBlockId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($exercise['lessonBlockId'] ?? '')) ?? '';
            if ($lessonBlockId === '') {
                continue;
            }
            $created = $this->tutorials->createExercise($user, $tutorialId, $moduleId, [
                'title' => (string) ($exercise['title'] ?? ''),
                'instructions' => (string) ($exercise['instructions'] ?? ''),
                'language' => (string) ($exercise['language'] ?? 'python'),
                'boilerplate' => (string) ($exercise['boilerplate'] ?? ''),
                'sortOrder' => $index + 1,
                'lessonBlockId' => $lessonBlockId,
            ]);
            $exerciseId = (string) ($created['id'] ?? '');
            if ($exerciseId === '') {
                continue;
            }
            $order = 1;
            foreach ((array) ($exercise['testCases'] ?? []) as $case) {
                if (!is_array($case)) {
                    continue;
                }
                $expected = (string) ($case['expectedOutput'] ?? '');
                if (trim($expected) === '') {
                    continue;
                }
                $this->tutorials->createTestCase($user, $exerciseId, [
                    'stdin' => (string) ($case['stdin'] ?? ''),
                    'expectedOutput' => $expected,
                    'sample' => (bool) ($case['sample'] ?? false),
                    'sortOrder' => $order,
                ]);
                $order++;
            }
            $saved++;
        }

        return $saved;
    }

    /**
     * Generate MCQs for a module from lesson context. Preview only.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateMcqPreview(array $user, array $context, array $input): array
    {
        $this->assertAuthor($user);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? ''));
        $this->assertAiConfigured();
        @set_time_limit(600);

        $count = (int) ($input['questionCount'] ?? $input['count'] ?? 5);
        if ($count < 1 || $count > 20) {
            throw new \InvalidArgumentException('Question count must be between 1 and 20.');
        }
        $difficulty = $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner'));
        $field = $this->normalizeAcademicField((string) ($context['academicField'] ?? $input['academicField'] ?? 'other'));
        $instructions = trim(strip_tags((string) ($input['additionalInstructions'] ?? $input['instructions'] ?? '')));
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS_CHARS) {
            throw new \InvalidArgumentException('Additional instructions are too long.');
        }
        $distribution = trim(strip_tags((string) ($input['difficultyDistribution'] ?? '')));
        if ($distribution === '') {
            $distribution = 'mostly ' . $difficulty;
        }

        $system = <<<SYSTEM
You write multiple-choice questions for a university tutorial module.
Return ONLY valid JSON. Each question must have exactly 4 options and one correctAnswer index 0-3.
Base every question on the provided lesson content. Do not invent unrelated topics.
Explanations must teach why the correct option is right.
SYSTEM;
        $lessonExcerpt = mb_substr(trim(strip_tags((string) ($context['lessonText'] ?? ''))), 0, 8000);
        $objectives = '';
        foreach ((array) ($context['learningObjectives'] ?? []) as $item) {
            $text = trim(strip_tags((string) $item));
            if ($text !== '') {
                $objectives .= '- ' . $text . "\n";
            }
        }
        $userPrompt = <<<PROMPT
Course title: {$context['courseTitle']}
Course description: {$context['courseDescription']}
Academic field: {$field}
Module title: {$context['moduleTitle']}
Module description: {$context['moduleDescription']}
Learning objectives:
{$objectives}
Lesson content:
{$lessonExcerpt}

Generate {$count} MCQs. Difficulty focus: {$distribution}.
Additional instructions: {$instructions}

JSON shape:
{
  "questions": [
    {
      "question": "string",
      "options": ["A","B","C","D"],
      "correctAnswer": 0,
      "explanation": "string",
      "difficulty": "beginner",
      "marks": 1
    }
  ]
}
PROMPT;

        try {
            $raw = $this->callGenerateJson($system, $userPrompt);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS TutorialAI] generate MCQ failed: ' . $e->getMessage());
            throw new \RuntimeException('AI MCQ generation is temporarily unavailable. Please try again.');
        }

        $questions = $this->normalizeMcqQuestions(is_array($raw['questions'] ?? null) ? $raw['questions'] : []);
        if ($questions === []) {
            throw new \RuntimeException('No valid MCQs were returned. Please try again.');
        }

        return [
            'previewId' => 'mcq-' . bin2hex(random_bytes(6)),
            'scope' => 'module_mcq',
            'tutorialId' => (string) ($context['tutorialId'] ?? ''),
            'moduleId' => (string) ($context['moduleId'] ?? ''),
            'model' => $this->openai->checkStatus()['model'] ?? '',
            'generatedAt' => gmdate('c'),
            'questions' => $questions,
            'questionCount' => count($questions),
        ];
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeMcqQuestions(array $rows): array
    {
        $out = [];
        foreach (array_slice($rows, 0, 20) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $question = trim($this->sanitizeMcqText((string) ($row['question'] ?? '')));
            if ($question === '') {
                throw new \InvalidArgumentException('A generated question is empty.');
            }
            $options = [];
            foreach (array_values((array) ($row['options'] ?? [])) as $option) {
                $text = trim($this->sanitizeMcqText((string) $option));
                if ($text !== '') {
                    $options[] = mb_substr($text, 0, 500);
                }
            }
            if (count($options) !== 4) {
                throw new \InvalidArgumentException('Each MCQ needs exactly four non-empty options.');
            }
            $correct = (int) ($row['correctAnswer'] ?? $row['correctIndex'] ?? -1);
            if ($correct < 0 || $correct > 3) {
                throw new \InvalidArgumentException('Correct answer must be an index from 0 to 3.');
            }
            $explanation = trim($this->sanitizeMcqText((string) ($row['explanation'] ?? '')));
            if ($explanation === '') {
                throw new \InvalidArgumentException('Each MCQ needs an explanation.');
            }
            $difficulty = strtolower(trim((string) ($row['difficulty'] ?? 'beginner')));
            if (!in_array($difficulty, self::DIFFICULTIES, true)) {
                $difficulty = 'beginner';
            }
            $marks = (int) ($row['marks'] ?? 1);
            if ($marks < 1 || $marks > 20) {
                $marks = 1;
            }
            $out[] = [
                'tempId' => 'q-' . ($index + 1) . '-' . bin2hex(random_bytes(3)),
                'selected' => true,
                'question' => mb_substr($question, 0, 2000),
                'options' => $options,
                'correctIndex' => $correct,
                'correctAnswer' => $correct,
                'explanation' => mb_substr($explanation, 0, 4000),
                'difficulty' => $difficulty,
                'marks' => $marks,
            ];
        }

        return $out;
    }

    /**
     * Preserve angle-bracket text such as HTML tags in MCQ options.
     */
    private function sanitizeMcqText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
        $value = preg_replace('/<\/?(script|iframe|object|embed)[^>]*>/iu', '', $value) ?? $value;

        return $value;
    }

    /**
     * Generate one practical activity preview. Does not persist.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $context course/module lesson context
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateActivityPreview(array $user, array $context, array $input): array
    {
        $this->assertAuthor($user);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? ''));
        $this->assertAiConfigured();
        @set_time_limit(600);

        $type = strtolower(trim((string) ($input['activityType'] ?? '')));
        if (!in_array($type, self::ACTIVITY_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported activity type.');
        }
        $topic = trim(strip_tags((string) ($input['topic'] ?? $input['title'] ?? '')));
        if ($topic === '') {
            throw new \InvalidArgumentException('Topic is required for activity generation.');
        }
        if (mb_strlen($topic) > self::MAX_TOPIC_CHARS) {
            throw new \InvalidArgumentException('Topic is too long.');
        }
        $difficulty = $this->normalizeDifficulty((string) ($input['difficulty'] ?? 'beginner'));
        $field = $this->normalizeAcademicField((string) (
            $input['academicField'] ?? $context['academicField'] ?? 'other'
        ));
        $instructions = trim(strip_tags((string) ($input['additionalInstructions'] ?? $input['instructions'] ?? '')));
        if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS_CHARS) {
            throw new \InvalidArgumentException('Additional instructions are too long.');
        }
        $requestedMode = strtolower(trim((string) ($input['evaluationMode'] ?? '')));
        $defaultMode = $this->defaultEvaluationMode($type);
        $evaluationMode = $requestedMode !== '' ? $requestedMode : $defaultMode;
        $this->assertActivityEvaluationMode($type, $evaluationMode);
        $preferredLanguage = strtolower(trim((string) ($input['language'] ?? 'python')));
        if (!in_array($preferredLanguage, self::ACTIVITY_LANGUAGES, true)) {
            $preferredLanguage = 'python';
        }

        $lessonExcerpt = mb_substr(trim(strip_tags((string) ($context['lessonText'] ?? ''))), 0, 6000);
        $system = <<<SYSTEM
You author university tutorial practical activities. Return ONLY valid JSON matching the requested schema.
Do not execute code or SQL. Do not invent unsupported fields.
Keep instructions clear and self-contained. Prefer concise, teachable tasks.
Never wrap JSON in markdown.
SYSTEM;
        $schema = $this->activityJsonSchemaHint($type, $evaluationMode);
        $userPrompt = <<<PROMPT
Course title: {$context['courseTitle']}
Course description: {$context['courseDescription']}
Module title: {$context['moduleTitle']}
Module description: {$context['moduleDescription']}
Academic field: {$field}
Lesson excerpt:
{$lessonExcerpt}

Create ONE practical activity.
Activity type: {$type}
Topic / problem area: {$topic}
Difficulty: {$difficulty}
Evaluation mode: {$evaluationMode}
Preferred programming language (if programming_task): {$preferredLanguage}
Additional staff instructions: {$instructions}

{$schema}
PROMPT;

        try {
            $raw = $this->callGenerateJson($system, $userPrompt);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[PMS TutorialAI] generate activity failed: ' . $e->getMessage());
            throw new \RuntimeException('AI activity generation is temporarily unavailable. Please try again.');
        }

        $activity = $this->normalizeActivityPreview($raw, [
            'activityType' => $type,
            'difficulty' => $difficulty,
            'academicField' => $field,
            'evaluationMode' => $evaluationMode,
            'language' => $preferredLanguage,
        ]);

        return [
            'previewId' => 'act-' . bin2hex(random_bytes(6)),
            'scope' => 'module_activity',
            'tutorialId' => (string) ($context['tutorialId'] ?? ''),
            'moduleId' => (string) ($context['moduleId'] ?? ''),
            'model' => $this->openai->checkStatus()['model'] ?? '',
            'generatedAt' => gmdate('c'),
            'activity' => $activity,
        ];
    }

    /**
     * Normalize/validate an AI or client activity preview against the activity schema.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public function normalizeActivityPreview(array $raw, array $defaults = []): array
    {
        $type = strtolower(trim((string) ($raw['activityType'] ?? $defaults['activityType'] ?? '')));
        if (!in_array($type, self::ACTIVITY_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported activity type.');
        }
        $title = trim(strip_tags((string) ($raw['title'] ?? '')));
        if ($title === '') {
            throw new \InvalidArgumentException('Generated activity title is required.');
        }
        $instructions = (string) ($raw['instructions'] ?? $raw['problemStatement'] ?? $raw['caseDescription'] ?? '');
        $instructions = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $instructions) ?? $instructions;
        $instructions = preg_replace('/<\/?(script|iframe|object|embed)[^>]*>/iu', '', $instructions) ?? $instructions;
        if (trim(strip_tags($instructions)) === '') {
            throw new \InvalidArgumentException('Generated activity instructions are required.');
        }
        if (mb_strlen($instructions) > 20000) {
            throw new \InvalidArgumentException('Generated activity instructions are too long.');
        }
        $difficulty = strtolower(trim((string) ($raw['difficulty'] ?? $defaults['difficulty'] ?? 'beginner')));
        if (!in_array($difficulty, self::DIFFICULTIES, true)) {
            $difficulty = 'beginner';
        }
        $field = $this->normalizeAcademicField((string) ($raw['academicField'] ?? $defaults['academicField'] ?? 'other'));
        $mode = strtolower(trim((string) ($raw['evaluationMode'] ?? $defaults['evaluationMode'] ?? $this->defaultEvaluationMode($type))));
        $this->assertActivityEvaluationMode($type, $mode);

        $configIn = is_array($raw['config'] ?? null) ? $raw['config'] : [];
        $answerIn = is_array($raw['answerKey'] ?? null) ? $raw['answerKey'] : [];
        // Accept common flat AI shapes.
        foreach (['language', 'boilerplate', 'schemaDescription', 'unit', 'tolerance', 'selfCheckRubric', 'maxLength', 'deliverableHint', 'parts', 'promptHint'] as $key) {
            if (!array_key_exists($key, $configIn) && array_key_exists($key, $raw)) {
                $configIn[$key] = $raw[$key];
            }
        }
        foreach (['modelAnswer', 'expectedValue', 'keywords'] as $key) {
            if (!array_key_exists($key, $answerIn) && array_key_exists($key, $raw)) {
                $answerIn[$key] = $raw[$key];
            }
        }
        if ($type === 'programming_task' && !isset($configIn['language'])) {
            $configIn['language'] = (string) ($defaults['language'] ?? 'python');
        }

        [$config, $answerKey] = $this->normalizeActivityConfigAndKey($type, $mode, $configIn, $answerIn);

        return [
            'title' => mb_substr($title, 0, 160),
            'instructions' => $instructions,
            'activityType' => $type,
            'academicField' => $field,
            'difficulty' => $difficulty,
            'evaluationMode' => $mode,
            'status' => 'draft',
            'config' => $config,
            'answerKey' => $answerKey,
        ];
    }

    private function defaultEvaluationMode(string $type): string
    {
        return match ($type) {
            'numerical' => 'auto_compare',
            'short_answer' => 'self_check',
            default => 'tutor_review',
        };
    }

    private function assertActivityEvaluationMode(string $type, string $mode): void
    {
        $allowed = match ($type) {
            'programming_task', 'sql_query', 'case_study', 'analytical_design' => ['none', 'tutor_review'],
            'numerical' => ['none', 'tutor_review', 'auto_compare'],
            'short_answer' => ['none', 'tutor_review', 'self_check'],
            default => [],
        };
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException('Evaluation mode is not allowed for this activity type.');
        }
    }

    private function activityJsonSchemaHint(string $type, string $mode): string
    {
        return match ($type) {
            'programming_task' => <<<'ACT_SCHEMA'
Schema shape:
{
  "title": "string",
  "instructions": "problem statement",
  "activityType": "programming_task",
  "difficulty": "beginner",
  "evaluationMode": "tutor_review",
  "config": { "language": "python", "boilerplate": "starter code", "promptHint": "" },
  "answerKey": { "modelAnswer": "optional solution text" }
}
ACT_SCHEMA,
            'sql_query' => <<<'ACT_SCHEMA'
Schema shape:
{
  "title": "string",
  "instructions": "problem statement",
  "activityType": "sql_query",
  "difficulty": "beginner",
  "evaluationMode": "tutor_review",
  "config": { "schemaDescription": "tables and columns", "promptHint": "" },
  "answerKey": { "modelAnswer": "optional SQL" }
}
ACT_SCHEMA,
            'numerical' => <<<ACT_SCHEMA
Schema shape:
{
  "title": "string",
  "instructions": "problem statement",
  "activityType": "numerical",
  "difficulty": "beginner",
  "evaluationMode": "{$mode}",
  "config": { "unit": "ohm", "tolerance": 0.01, "promptHint": "" },
  "answerKey": { "expectedValue": 5 }
}
ACT_SCHEMA,
            'short_answer' => <<<ACT_SCHEMA
Schema shape:
{
  "title": "string",
  "instructions": "question",
  "activityType": "short_answer",
  "difficulty": "beginner",
  "evaluationMode": "{$mode}",
  "config": { "maxLength": 1000, "selfCheckRubric": "guidance after submit" },
  "answerKey": { "modelAnswer": "optional", "keywords": ["word1", "word2"] }
}
ACT_SCHEMA,
            'case_study' => <<<'ACT_SCHEMA'
Schema shape:
{
  "title": "string",
  "instructions": "case description",
  "activityType": "case_study",
  "difficulty": "beginner",
  "evaluationMode": "tutor_review",
  "config": {
    "parts": [
      { "id": "part-1", "prompt": "question 1" },
      { "id": "part-2", "prompt": "question 2" }
    ]
  },
  "answerKey": { "modelAnswer": "optional rubric" }
}
ACT_SCHEMA,
            default => <<<'ACT_SCHEMA'
Schema shape:
{
  "title": "string",
  "instructions": "problem specification including requirements/constraints",
  "activityType": "analytical_design",
  "difficulty": "beginner",
  "evaluationMode": "tutor_review",
  "config": { "maxLength": 5000, "deliverableHint": "what to submit" },
  "answerKey": { "modelAnswer": "optional rubric" }
}
ACT_SCHEMA,
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $answerKey
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function normalizeActivityConfigAndKey(string $type, string $mode, array $config, array $answerKey): array
    {
        $json = json_encode($config);
        if (is_string($json) && strlen($json) > 32000) {
            throw new \InvalidArgumentException('Activity configuration is too large.');
        }

        $normalizedConfig = match ($type) {
            'programming_task' => [
                'language' => $this->normalizeActivityLanguage((string) ($config['language'] ?? 'python')),
                'boilerplate' => mb_substr((string) ($config['boilerplate'] ?? ''), 0, 20000),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'sql_query' => [
                'schemaDescription' => mb_substr(trim(strip_tags((string) ($config['schemaDescription'] ?? ''))), 0, 8000),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'numerical' => [
                'unit' => mb_substr(trim(strip_tags((string) ($config['unit'] ?? ''))), 0, 40),
                'tolerance' => max(0.0, (float) ($config['tolerance'] ?? 0)),
                'promptHint' => mb_substr(trim(strip_tags((string) ($config['promptHint'] ?? ''))), 0, 2000),
            ],
            'short_answer' => [
                'maxLength' => min(5000, max(50, (int) ($config['maxLength'] ?? 1000))),
                'selfCheckRubric' => mb_substr(trim(strip_tags((string) ($config['selfCheckRubric'] ?? ''))), 0, 4000),
            ],
            'case_study' => [
                'parts' => $this->normalizeActivityParts(is_array($config['parts'] ?? null) ? $config['parts'] : []),
            ],
            'analytical_design' => [
                'maxLength' => min(20000, max(100, (int) ($config['maxLength'] ?? 5000))),
                'deliverableHint' => mb_substr(trim(strip_tags((string) ($config['deliverableHint'] ?? ''))), 0, 2000),
            ],
            default => [],
        };

        $normalizedKey = [];
        if ($type === 'numerical') {
            if ($mode === 'auto_compare' && !array_key_exists('expectedValue', $answerKey)) {
                throw new \InvalidArgumentException('Numerical auto_compare activities require answerKey.expectedValue.');
            }
            if (array_key_exists('expectedValue', $answerKey)) {
                if (!is_numeric($answerKey['expectedValue'])) {
                    throw new \InvalidArgumentException('Numerical expectedValue must be numeric.');
                }
                $normalizedKey['expectedValue'] = (float) $answerKey['expectedValue'];
            }
            if (isset($answerKey['modelAnswer'])) {
                $normalizedKey['modelAnswer'] = mb_substr(trim(strip_tags((string) $answerKey['modelAnswer'])), 0, 4000);
            }
        } elseif ($type === 'short_answer') {
            if (isset($answerKey['modelAnswer'])) {
                $normalizedKey['modelAnswer'] = mb_substr(trim(strip_tags((string) $answerKey['modelAnswer'])), 0, 4000);
            }
            $keywords = [];
            foreach (array_values((array) ($answerKey['keywords'] ?? [])) as $word) {
                $text = trim(strip_tags((string) $word));
                if ($text !== '') {
                    $keywords[] = mb_substr($text, 0, 80);
                }
            }
            if ($keywords !== []) {
                $normalizedKey['keywords'] = array_slice($keywords, 0, 20);
            }
            if ($mode === 'self_check' && ($normalizedKey['modelAnswer'] ?? '') === '' && $keywords === []) {
                throw new \InvalidArgumentException('Self-check short answers need a modelAnswer or keywords.');
            }
        } elseif (isset($answerKey['modelAnswer'])) {
            $normalizedKey['modelAnswer'] = mb_substr(trim(strip_tags((string) $answerKey['modelAnswer'])), 0, 8000);
        }

        return [$normalizedConfig, $normalizedKey];
    }

    private function normalizeActivityLanguage(string $language): string
    {
        $language = strtolower(trim(strip_tags($language)));
        $aliases = [
            'c++' => 'cpp',
            'cplusplus' => 'cpp',
            'js' => 'javascript',
            'node' => 'javascript',
            'py' => 'python',
            'plain' => 'text',
            'plaintext' => 'text',
        ];
        $language = $aliases[$language] ?? $language;
        if (!in_array($language, self::ACTIVITY_LANGUAGES, true)) {
            return 'text';
        }

        return $language;
    }

    /**
     * @param list<mixed> $parts
     * @return list<array<string, string>>
     */
    private function normalizeActivityParts(array $parts): array
    {
        $out = [];
        foreach (array_slice($parts, 0, 12) as $index => $part) {
            if (!is_array($part)) {
                continue;
            }
            $prompt = trim(strip_tags((string) ($part['prompt'] ?? $part['question'] ?? '')));
            if ($prompt === '') {
                continue;
            }
            $id = trim(strip_tags((string) ($part['id'] ?? ('part-' . ($index + 1)))));
            if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) {
                $id = 'part-' . ($index + 1);
            }
            $out[] = [
                'id' => mb_substr($id, 0, 40),
                'prompt' => mb_substr($prompt, 0, 2000),
            ];
        }
        if ($out === []) {
            throw new \InvalidArgumentException('Case study activities need at least one part prompt.');
        }
        // Ensure stable unique ids.
        $seen = [];
        foreach ($out as $i => $part) {
            $id = $part['id'];
            if (isset($seen[$id])) {
                $id = 'part-' . ($i + 1);
                $out[$i]['id'] = $id;
            }
            $seen[$id] = true;
        }

        return $out;
    }
}
