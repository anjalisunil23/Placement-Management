<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\SyllabusMcqTestModel;
use PMS\Models\SyllabusQuestionBankModel;
use PMS\Utils\Security;

/**
 * Generates multiple-choice questions from the official syllabus PDF loaded with Get.
 */
final class StaffCourseQuestionService
{
    private const MIN_COUNT = 0;
    private const MAX_COUNT = 20;
    private const MAX_TOTAL = 40;
    private const COOLDOWN_SECONDS = 8;
    private const GENERATE_BATCH_SIZE = 10;
    /** Max OpenAI calls per HTTP top-up request (shared-host proxy limits). */
    private const BATCH_TOPUP_MAX_API_CALLS = 12;
    /** Max OpenAI calls while filling one syllabus chunk inside a batch request. */
    private const BATCH_CHUNK_MAX_API_CALLS = 12;
    /** Wall clock per batch/top-up HTTP request (stay under typical LiteSpeed ~60s). */
    private const BATCH_REQUEST_WALL_SECONDS = 52;
    /** Max questions requested from OpenAI per call (provider slice; UI batch may still be 10). */
    private const DIFFICULTY_BATCH_AI_SLICE = 5;
    /** Default OpenAI calls per batch HTTP (client continue fills the rest on shared hosting). */
    private const DIFFICULTY_BATCH_CALLS_PER_HTTP = 1;
    /** Allow two slices (5+5) in one request when the batch count is 10. */
    private const DIFFICULTY_BATCH_CALLS_PER_HTTP_MAX = 2;
    private const SYLLABUS_PROMPT_MAX_CHARS = 42000;
    /** Enough for one batched pass + shortfall top-ups without exceeding shared-host timeouts. */
    private const GENERATE_MAX_API_CALLS = 18;
    /** Stop OpenAI loops before LiteSpeed/cPanel proxy timeouts (~60–120s). */
    private const GENERATE_MAX_WALL_SECONDS = 300;
    private const SYLLABUS_CACHE_TTL = 3600;

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
        @set_time_limit(600);
        @ignore_user_abort(true);

        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException('AI question generation is not configured. Contact the administrator.');
        }

        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');
        $course = $this->resolveLoadedSyllabus($body, $deptCode, $deptName, $deptShort, $this->seesAllCourses($user));

        $mixes = $this->parseMixes($body);
        $total = 0;
        $mixLines = [];
        foreach ($mixes as $mix) {
            $total += (int) $mix['count'];
            $mixLines[] = '- ' . $mix['difficulty'] . ': ' . $mix['count'];
        }
        $mixText = implode("\n", $mixLines);
        $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? 'staff'));

        $syllabus = $this->syllabusText($course);
        if (!AesSyllabusCipher::isUsableSyllabusText($syllabus)) {
            throw new \RuntimeException(
                'Could not read enough text from that syllabus PDF for AI generation. '
                . 'Click Get again; if the PDF opens but generation still fails, the file may be image-only (scanned).'
            );
        }
        $courseCode = (string) ($course['code'] ?? '');
        /** @var array<string, true> $promptKeys */
        $promptKeys = $this->promptKeysForSession([]);
        $system = OpenAIService::cleanUtf8(
            'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.'
        );
        $bankAvoid = $this->existingBankQuestionsAvoidBlock($courseCode);
        $progressKey = self::sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $deadline = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
        self::writeGenerationProgress($progressKey, [
            'phase' => 'generating',
            'message' => 'Preparing syllabus context…',
            'generated' => 0,
            'requested' => $total,
            'percent' => 0,
            'done' => false,
        ]);
        $questions = $this->assembleQuestionsForMixes(
            $course,
            $syllabus,
            $system,
            $mixes,
            $mixText,
            $bankAvoid,
            $promptKeys,
            $progressKey,
            $total,
            $deadline
        );
        $got = count($questions);
        self::writeGenerationProgress($progressKey, [
            'phase' => $got >= $total ? 'complete' : 'incomplete',
            'message' => $got >= $total
                ? "{$got} / {$total} completed"
                : "Generated {$got} of {$total} requested",
            'generated' => $got,
            'requested' => $total,
            'percent' => $total > 0 ? min(100, (int) round(($got / $total) * 100)) : 100,
            'done' => true,
        ]);
        if ($questions === []) {
            throw new \RuntimeException('AI did not return any usable questions. Please try again.');
        }
        foreach ($questions as $index => $question) {
            $questions[$index]['draftIndex'] = $index;
        }

        $sessionId = bin2hex(random_bytes(16));
        $_SESSION['staff_course_draft'] = [
            'id' => $sessionId,
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'course' => [
                'code' => (string) $course['code'],
                'title' => (string) $course['title'],
                'semsubId' => (string) ($course['semsubId'] ?? ''),
                'department' => (string) ($course['department'] ?? ''),
            ],
            'mixes' => $mixes,
            'questions' => $questions,
            'createdAt' => time(),
        ];

        return [
            'sessionId' => $sessionId,
            'courseCode' => (string) $course['code'],
            'courseTitle' => (string) $course['title'],
            'mixes' => $mixes,
            'requested' => $total,
            'questions' => $questions,
        ];
    }

    /**
     * One HTTP request per syllabus batch (up to GENERATE_BATCH_SIZE questions). The client loops after a single Generate click.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generateBatch(array $user, array $body): array
    {
        @set_time_limit(180);
        @ignore_user_abort(true);

        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException('AI question generation is not configured. Contact the administrator.');
        }

        if (!empty($body['topUp'])) {
            return $this->generateBatchTopUp($user, $body);
        }

        if (!empty($body['difficultyBatchMode'])) {
            return $this->generateDifficultyBatch($user, $body);
        }

        $mixes = $this->parseMixes($body);
        $total = $this->mixTotal($mixes);
        $chunks = $this->chunkMixesByTotal($mixes, self::GENERATE_BATCH_SIZE);
        $batchTotal = count($chunks);
        $batchIndex = (int) ($body['batchIndex'] ?? 0);
        $progressKey = self::sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $sessionId = trim((string) ($body['sessionId'] ?? ''));

        if ($sessionId !== '') {
            $this->hydrateGenerationDraft($sessionId);
        }

        if ($batchIndex < 0 || $batchIndex >= $batchTotal) {
            throw new \InvalidArgumentException('Invalid generation batch.');
        }

        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');
        $allCourses = $this->seesAllCourses($user);

        $system = OpenAIService::cleanUtf8(
            'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.'
        );

        $startingNewSession = ($batchIndex === 0 && ($sessionId === '' || !is_array($_SESSION['staff_course_draft'] ?? null)
            || (string) (($_SESSION['staff_course_draft']['id'] ?? '')) !== $sessionId
            || empty($_SESSION['staff_course_draft']['generating'])));

        if ($startingNewSession) {
            $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? 'staff'));
            $course = $this->resolveLoadedSyllabus($body, $deptCode, $deptName, $deptShort, $allCourses);
            $syllabus = $this->syllabusText($course);
            if (!AesSyllabusCipher::isUsableSyllabusText($syllabus)) {
                throw new \RuntimeException(
                    'Could not read enough text from that syllabus PDF for AI generation. '
                    . 'Click Get again; if the PDF opens but generation still fails, the file may be image-only (scanned).'
                );
            }
            $sessionId = bin2hex(random_bytes(16));
            $deadline = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
            $_SESSION['staff_course_draft'] = [
                'id' => $sessionId,
                'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
                'course' => [
                    'code' => (string) $course['code'],
                    'title' => (string) $course['title'],
                    'semsubId' => (string) ($course['semsubId'] ?? ''),
                    'department' => (string) ($course['department'] ?? ''),
                    'syllabusText' => $syllabus,
                ],
                'mixes' => $mixes,
                'questions' => [],
                'createdAt' => time(),
                'generating' => true,
                'genState' => [
                    'batchTotal' => $batchTotal,
                    'nextBatchIndex' => 0,
                    'deadline' => $deadline,
                ],
            ];
            self::writeGenerationProgress($progressKey, [
                'phase' => 'generating',
                'message' => 'Preparing syllabus context…',
                'generated' => 0,
                'requested' => $total,
                'percent' => 0,
                'done' => false,
                'batchIndex' => 0,
                'batchTotal' => $batchTotal,
            ]);
            $this->persistGenerationDraft($_SESSION['staff_course_draft']);
        } else {
            if ($sessionId === '') {
                throw new \InvalidArgumentException('Missing session for the next generation batch.');
            }
            $this->hydrateGenerationDraft($sessionId);
            if (!empty($body['retryEmptyBatch'])) {
                $pending = $_SESSION['staff_course_draft'] ?? null;
                if (is_array($pending) && !empty($pending['generating'])) {
                    if (!isset($pending['genState']) || !is_array($pending['genState'])) {
                        $pending['genState'] = [];
                    }
                    $pending['genState']['nextBatchIndex'] = $batchIndex;
                    unset($pending['genState']['phase']);
                    $this->persistGenerationDraft($pending);
                }
            }
            $draft = $this->requireGeneratingDraft($user, $sessionId, $batchIndex);
            $mixes = is_array($draft['mixes'] ?? null) ? $draft['mixes'] : $mixes;
            $total = $this->mixTotal($mixes);
            $course = [
                'code' => (string) ($draft['course']['code'] ?? ''),
                'title' => (string) ($draft['course']['title'] ?? ''),
                'semsubId' => (string) ($draft['course']['semsubId'] ?? ''),
                'department' => (string) ($draft['course']['department'] ?? ''),
                'syllabusText' => (string) ($draft['course']['syllabusText'] ?? ''),
            ];
            $syllabus = $this->syllabusText($course);
            $deadline = (float) ($draft['genState']['deadline'] ?? 0);
            if ($deadline <= 0 || $deadline < microtime(true) + 30) {
                $deadline = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
            }
            if (!isset($draft['genState']) || !is_array($draft['genState'])) {
                $draft['genState'] = [];
            }
            $draft['genState']['deadline'] = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
            $this->persistGenerationDraft($draft);
            $batchTotal = (int) ($draft['genState']['batchTotal'] ?? $batchTotal);
            $chunks = $this->chunkMixesByTotal($mixes, self::GENERATE_BATCH_SIZE);
            if ($batchIndex >= count($chunks)) {
                throw new \InvalidArgumentException('Invalid generation batch.');
            }
        }

        $courseCode = (string) ($course['code'] ?? '');
        $draft = $_SESSION['staff_course_draft'] ?? null;
        $selected = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        /** @var array<string, true> $promptKeys */
        $promptKeys = $this->promptKeysForSession($selected);

        $bankAvoid = $this->existingBankQuestionsAvoidBlock($courseCode);
        $chunk = $chunks[$batchIndex];
        $chunkTotal = $this->mixTotal($chunk);
        $batchWall = microtime(true) + self::BATCH_REQUEST_WALL_SECONDS;
        if (!isset($deadline) || $deadline <= 0) {
            $deadline = $batchWall;
        }
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($wasActive) {
            session_write_close();
        }
        /** @var list<array<string, mixed>> $batchQuestions */
        $batchQuestions = [];
        try {
            $chunkApiCalls = 0;
            $emptyChunkStreak = 0;
            while (
                $this->mixShortfall($chunk, $selected) !== []
                && $chunkApiCalls < self::BATCH_CHUNK_MAX_API_CALLS
                && !$this->generationDeadlineReached($deadline)
                && microtime(true) < $batchWall
            ) {
                $chunkShortfall = $this->mixShortfall($chunk, $selected);
                $partTotal = $this->mixTotal($chunkShortfall);
                if ($partTotal < 1) {
                    break;
                }
                if ($emptyChunkStreak >= 2 && $partTotal > 1) {
                    $firstLabel = (string) ($chunkShortfall[0]['difficulty'] ?? 'Medium');
                    $partTotal = 1;
                    $chunkShortfall = [['difficulty' => $firstLabel, 'count' => 1]];
                }
                $mixText = $this->mixLinesText($chunkShortfall);
                $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
                $part = $this->fetchQuestionBatch(
                    $course,
                    $syllabus,
                    $system,
                    $chunkShortfall,
                    $partTotal,
                    $mixText,
                    $avoid,
                    $selected,
                    $promptKeys,
                    true
                );
                $chunkApiCalls++;
                if ($part === []) {
                    $emptyChunkStreak++;
                    if ($emptyChunkStreak >= 6) {
                        break;
                    }
                    continue;
                }
                $emptyChunkStreak = 0;
                $selected = $this->mergeQuestionLists($selected, $part, $promptKeys);
                $batchQuestions = $this->mergeQuestionLists($batchQuestions, $part);
                $this->touchGenerationProgress(
                    $progressKey,
                    count($selected),
                    $total,
                    "Batch " . ($batchIndex + 1) . " of {$batchTotal} · " . count($selected) . " / {$total} questions"
                );
            }
        } finally {
            if ($wasActive && session_status() !== PHP_SESSION_ACTIVE) {
                Security::startSession(false);
            }
        }
        if ($sessionId !== '') {
            $this->hydrateGenerationDraft($sessionId);
        }
        if ($batchQuestions === [] && $chunkTotal > 0 && $batchIndex + 1 < $batchTotal) {
            $this->touchGenerationProgress(
                $progressKey,
                count($selected),
                $total,
                'Batch ' . ($batchIndex + 2) . " of {$batchTotal} is next…"
            );
        }

        $got = count($selected);
        $batchNum = $batchIndex + 1;
        $this->touchGenerationProgress(
            $progressKey,
            $got,
            $total,
            "Batch {$batchNum} of {$batchTotal} · {$got} / {$total} questions"
        );
        self::writeGenerationProgress($progressKey, [
            'phase' => 'generating',
            'message' => "Batch {$batchNum} of {$batchTotal} · {$got} / {$total} questions",
            'generated' => $got,
            'requested' => $total,
            'percent' => $total > 0 ? min(99, (int) round(($got / $total) * 100)) : 0,
            'done' => false,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
        ]);

        $selected = $this->assignDraftIndexes($selected);
        $isLastBatch = ($batchIndex + 1) >= $batchTotal;

        $stepMode = !empty($body['stepMode']);

        if (!$isLastBatch) {
            if ($stepMode) {
                return $this->pauseStepBatch(
                    $sessionId,
                    $course,
                    $mixes,
                    $selected,
                    $batchQuestions,
                    $progressKey,
                    $total,
                    $batchIndex,
                    $batchTotal,
                    $batchIndex + 1,
                    null
                );
            }
            $this->saveGeneratingDraftState($sessionId, $selected, $batchIndex + 1, null);

            return $this->releaseSessionAndReturn([
                'sessionId' => $sessionId,
                'courseCode' => $courseCode,
                'courseTitle' => (string) ($course['title'] ?? ''),
                'mixes' => $mixes,
                'requested' => $total,
                'questions' => $selected,
                'batchQuestions' => $batchQuestions,
                'batchIndex' => $batchIndex,
                'batchTotal' => $batchTotal,
                'nextBatchIndex' => $batchIndex + 1,
                'generating' => true,
                'batchComplete' => false,
            ]);
        }

        $this->saveGeneratingDraftState($sessionId, $selected, $batchTotal, 'topup');

        if ($this->mixShortfall($mixes, $this->selectionForMixCheck($selected, $mixes)) === []) {
            return $this->finalizeGenerationDraft(
                $sessionId,
                $course,
                $mixes,
                $selected,
                $progressKey,
                $total,
                $batchIndex,
                $batchTotal,
                $batchQuestions
            );
        }

        if ($stepMode) {
            return $this->pauseStepBatch(
                $sessionId,
                $course,
                $mixes,
                $selected,
                $batchQuestions,
                $progressKey,
                $total,
                $batchIndex,
                $batchTotal,
                $batchTotal,
                'topup'
            );
        }

        return $this->resumeGenerationTopUp(
            $sessionId,
            $course,
            $mixes,
            $selected,
            $progressKey,
            $total,
            $batchIndex,
            $batchTotal,
            $batchQuestions,
            0
        );
    }

    /**
     * Fixed Easy → Medium → Hard batches (one difficulty per HTTP request).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function generateDifficultyBatch(array $user, array $body): array
    {
        $parsed = $this->parseDifficultyBatchPlan($body);
        /** @var list<array{difficulty:string,count:int}> $batchPlan */
        $batchPlan = $parsed['plan'];
        /** @var array{Easy:int,Medium:int,Hard:int} $countByLabel */
        $countByLabel = $parsed['counts'];
        $batchTotal = count($batchPlan);
        $batchIndex = (int) ($body['batchIndex'] ?? 0);
        if ($batchIndex < 0 || $batchIndex >= $batchTotal) {
            throw new \InvalidArgumentException('Invalid generation batch.');
        }

        $progressKey = self::sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        if ($sessionId !== '') {
            $this->hydrateGenerationDraft($sessionId);
        }

        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $system = OpenAIService::cleanUtf8(
            'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.'
        );

        $startingNewSession = $sessionId === ''
            || !is_array($_SESSION['staff_course_draft'] ?? null)
            || (string) (($_SESSION['staff_course_draft']['id'] ?? '')) !== $sessionId;

        if ($startingNewSession) {
            if ($batchIndex !== 0) {
                throw new \InvalidArgumentException('Start difficulty batch generation from batch 1.');
            }
            $this->assertCooldown((string) ($user['_id'] ?? $user['id'] ?? 'staff'));
            $course = $this->resolveLoadedSyllabus(
                $body,
                (string) ($dept['code'] ?? ''),
                (string) ($dept['name'] ?? ''),
                (string) ($dept['shortName'] ?? ''),
                $this->seesAllCourses($user)
            );
            $syllabus = $this->syllabusText($course);
            if (!AesSyllabusCipher::isUsableSyllabusText($syllabus)) {
                throw new \RuntimeException(
                    'Could not read enough text from that syllabus PDF for AI generation. '
                    . 'Click Get again; if the PDF opens but generation still fails, the file may be image-only (scanned).'
                );
            }
            $sessionId = bin2hex(random_bytes(16));
            $mixes = $this->mixesFromCountByLabel($countByLabel);
            $_SESSION['staff_course_draft'] = [
                'id' => $sessionId,
                'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
                'course' => [
                    'code' => (string) $course['code'],
                    'title' => (string) $course['title'],
                    'semsubId' => (string) ($course['semsubId'] ?? ''),
                    'department' => (string) ($course['department'] ?? ''),
                    'syllabusText' => $syllabus,
                ],
                'mixes' => $mixes,
                'questions' => [],
                'createdAt' => time(),
                'generating' => true,
                'genState' => [
                    'difficultyBatchMode' => true,
                    'batchPlan' => $batchPlan,
                    'counts' => $countByLabel,
                    'batchTotal' => $batchTotal,
                    'difficultyBatchIndex' => 0,
                    'completedDifficulties' => [],
                    'deadline' => microtime(true) + self::GENERATE_MAX_WALL_SECONDS,
                ],
            ];
            $this->persistGenerationDraft($_SESSION['staff_course_draft']);
        } else {
            if ($sessionId === '') {
                throw new \InvalidArgumentException('Missing session for the next generation batch.');
            }
            if (!empty($body['retryDifficultyBatch'])) {
                $draft = $this->requireDraft($user, $sessionId);
                if (empty($draft['genState']['difficultyBatchMode'])) {
                    throw new \InvalidArgumentException('This session is not a difficulty batch run.');
                }
                if ((int) ($draft['genState']['difficultyBatchIndex'] ?? -1) !== $batchIndex) {
                    throw new \InvalidArgumentException('Retry the current batch only.');
                }
                unset($draft['genState']['awaitingBankSave']);
                $draft['generating'] = true;
                $draft['questions'] = [];
                $this->persistGenerationDraft($draft);
            } elseif (!empty($body['continueDifficultyBatch'])) {
                $draft = $this->requireDraft($user, $sessionId);
                if (empty($draft['genState']['difficultyBatchMode'])) {
                    throw new \InvalidArgumentException('This session is not a difficulty batch run.');
                }
                if ((int) ($draft['genState']['difficultyBatchIndex'] ?? -1) !== $batchIndex) {
                    throw new \InvalidArgumentException('Continue fill applies to the current batch only.');
                }
                $planForContinue = is_array($draft['genState']['batchPlan'] ?? null) ? $draft['genState']['batchPlan'] : $batchPlan;
                $plannedCount = (int) ($planForContinue[$batchIndex]['count'] ?? 0);
                $haveCount = count(is_array($draft['questions'] ?? null) ? $draft['questions'] : []);
                if ($plannedCount < 1 || $haveCount >= $plannedCount) {
                    throw new \InvalidArgumentException('This batch already has the requested number of questions.');
                }
                $draft['generating'] = true;
                $this->persistGenerationDraft($draft);
            } else {
                $draft = $this->requireDifficultyBatchDraft($user, $sessionId, $batchIndex);
            }
            $batchPlan = is_array($draft['genState']['batchPlan'] ?? null) ? $draft['genState']['batchPlan'] : $batchPlan;
            $batchTotal = count($batchPlan);
            $countByLabel = is_array($draft['genState']['counts'] ?? null) ? $draft['genState']['counts'] : $countByLabel;
            $course = [
                'code' => (string) ($draft['course']['code'] ?? ''),
                'title' => (string) ($draft['course']['title'] ?? ''),
                'semsubId' => (string) ($draft['course']['semsubId'] ?? ''),
                'department' => (string) ($draft['course']['department'] ?? ''),
                'syllabusText' => (string) ($draft['course']['syllabusText'] ?? ''),
            ];
        }

        $syllabus = $this->syllabusText($course);
        $courseCode = (string) ($course['code'] ?? '');
        $draft = $_SESSION['staff_course_draft'] ?? null;
        $selected = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        /** @var array<string, true> $promptKeys */
        $promptKeys = $this->promptKeysForSession($selected);
        $bankAvoid = $this->existingBankQuestionsAvoidBlock($courseCode);

        $chunk = [$batchPlan[$batchIndex]];
        $currentDifficulty = (string) ($chunk[0]['difficulty'] ?? 'Medium');
        $chunkTotal = (int) ($chunk[0]['count'] ?? 0);
        $this->assertBatchQuestionCountBody($body, $currentDifficulty, $chunkTotal);
        $deadline = (float) (is_array($draft['genState'] ?? null) ? ($draft['genState']['deadline'] ?? 0) : 0);
        $batchWall = microtime(true) + $this->batchRequestWallSeconds($chunkTotal);
        $maxChunkApiCalls = $this->difficultyBatchCallsAllowedPerHttp($body, $chunkTotal, count($selected));
        if ($deadline <= 0) {
            $deadline = $batchWall;
        }

        if ($progressKey !== '') {
            self::writeGenerationProgress($progressKey, [
                'phase' => 'generating',
                'message' => 'Batch ' . ($batchIndex + 1) . " of {$batchTotal} · {$currentDifficulty} · generating…",
                'generated' => count($selected),
                'requested' => $chunkTotal,
                'percent' => $chunkTotal > 0
                    ? min(99, (int) round((count($selected) / $chunkTotal) * 100))
                    : 0,
                'done' => false,
                'batchIndex' => $batchIndex,
                'batchTotal' => $batchTotal,
            ]);
        }

        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($wasActive) {
            session_write_close();
        }
        /** @var list<array<string, mixed>> $batchQuestions */
        $batchQuestions = $selected;
        try {
            $chunkApiCalls = 0;
            $emptyChunkStreak = 0;
            while (
                $this->mixShortfall($chunk, $selected) !== []
                && $chunkApiCalls < $maxChunkApiCalls
                && microtime(true) < $batchWall
                && !$this->generationDeadlineReached($deadline)
            ) {
                $chunkShortfall = $this->mixShortfall($chunk, $selected);
                $partTotal = $this->mixTotal($chunkShortfall);
                if ($partTotal < 1) {
                    break;
                }
                if ($emptyChunkStreak >= 2 && $partTotal > 1) {
                    $firstLabel = (string) ($chunkShortfall[0]['difficulty'] ?? 'Medium');
                    $partTotal = 1;
                    $chunkShortfall = [['difficulty' => $firstLabel, 'count' => 1]];
                } elseif ($partTotal > self::DIFFICULTY_BATCH_AI_SLICE) {
                    $firstLabel = (string) ($chunkShortfall[0]['difficulty'] ?? 'Medium');
                    $partTotal = self::DIFFICULTY_BATCH_AI_SLICE;
                    $chunkShortfall = [['difficulty' => $firstLabel, 'count' => $partTotal]];
                }
                $mixText = $this->mixLinesText($chunkShortfall);
                $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
                $part = $this->fetchQuestionBatch(
                    $course,
                    $syllabus,
                    $system,
                    $chunkShortfall,
                    $partTotal,
                    $mixText,
                    $avoid,
                    $selected,
                    $promptKeys,
                    false
                );
                $chunkApiCalls++;
                if ($part === []) {
                    $emptyChunkStreak++;
                    if ($emptyChunkStreak >= 8) {
                        break;
                    }
                    continue;
                }
                $emptyChunkStreak = 0;
                $selected = $this->mergeQuestionLists($selected, $part, $promptKeys);
                $batchQuestions = $this->mergeQuestionLists($batchQuestions, $part);
                if ($progressKey !== '') {
                    $this->touchGenerationProgress(
                        $progressKey,
                        count($batchQuestions),
                        $chunkTotal,
                        "Batch " . ($batchIndex + 1) . " of {$batchTotal} · {$currentDifficulty} · "
                        . count($batchQuestions) . " / {$chunkTotal}"
                    );
                }
            }
        } finally {
            if ($wasActive && session_status() !== PHP_SESSION_ACTIVE) {
                Security::startSession(false);
            }
        }

        if ($sessionId !== '') {
            $this->hydrateGenerationDraft($sessionId);
        }

        $batchQuestions = $this->capQuestionsToMixes($batchQuestions, $chunk);
        $display = $this->assignDraftIndexes($batchQuestions);
        $gotForBatch = count($display);
        $mixes = $this->mixesFromCountByLabel($countByLabel);
        $totalRequested = $this->mixTotal($mixes);

        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : [];
        $draft['questions'] = $display;
        $draft['generating'] = false;
        if (!isset($draft['genState']) || !is_array($draft['genState'])) {
            $draft['genState'] = [];
        }
        $draft['genState']['difficultyBatchMode'] = true;
        $draft['genState']['batchPlan'] = $batchPlan;
        $draft['genState']['counts'] = $countByLabel;
        $draft['genState']['batchTotal'] = $batchTotal;
        $draft['genState']['difficultyBatchIndex'] = $batchIndex;
        $draft['genState']['currentDifficulty'] = $currentDifficulty;
        $fulfilled = $gotForBatch >= $chunkTotal;
        $draft['genState']['awaitingBankSave'] = $fulfilled;
        $draft['genState']['batchFillIncomplete'] = !$fulfilled && $gotForBatch > 0;
        unset($draft['genState']['readyForBatchIndex']);
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);

        $batchNum = $batchIndex + 1;
        $batchReady = $gotForBatch > 0;
        if ($progressKey !== '') {
            self::writeGenerationProgress($progressKey, [
                'phase' => $batchReady ? ($fulfilled ? 'batch_ready' : 'batch_partial') : 'batch_error',
                'message' => $batchReady
                    ? ($fulfilled
                        ? "Batch {$batchNum} of {$batchTotal} · {$currentDifficulty} · generation complete"
                        : "Batch {$batchNum} of {$batchTotal} · {$currentDifficulty} · {$gotForBatch} / {$chunkTotal}")
                    : "Batch {$batchNum} of {$batchTotal} · {$currentDifficulty} · generation failed",
                'generated' => $gotForBatch,
                'requested' => $chunkTotal,
                'percent' => $chunkTotal > 0
                    ? ($fulfilled ? 100 : min(99, (int) round(($gotForBatch / $chunkTotal) * 100)))
                    : 0,
                'done' => false,
                'batchIndex' => $batchIndex,
                'batchTotal' => $batchTotal,
            ]);
        }

        $completed = is_array($draft['genState']['completedDifficulties'] ?? null)
            ? $draft['genState']['completedDifficulties'] : [];

        return $this->releaseSessionAndReturn([
            'sessionId' => $sessionId,
            'courseCode' => $courseCode,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'mixes' => $mixes,
            'requested' => $chunkTotal,
            'questions' => $display,
            'batchQuestions' => $display,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'currentDifficulty' => $currentDifficulty,
            'completedDifficulties' => $completed,
            'counts' => $countByLabel,
            'generating' => false,
            'awaitingBankSave' => $fulfilled,
            'needsContinueFill' => !$fulfilled && $gotForBatch > 0,
            'difficultyBatchMode' => true,
            'batchComplete' => false,
            'fulfilled' => $fulfilled,
            'generationFailedPartial' => $gotForBatch > 0 && $gotForBatch < $chunkTotal,
            'generationFailedEmpty' => $gotForBatch === 0 && $chunkTotal > 0,
            'generationStatus' => $gotForBatch <= 0 ? 'error' : ($fulfilled ? 'completed' : 'partial'),
        ]);
    }

    private function batchRequestWallSeconds(int $chunkTotal): int
    {
        $chunkTotal = max(1, $chunkTotal);

        return min(120, max(self::BATCH_REQUEST_WALL_SECONDS, 40 + $chunkTotal * 8));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function difficultyBatchCallsAllowedPerHttp(array $body, int $chunkTotal, int $haveCount): int
    {
        $shortfall = max(0, $chunkTotal - $haveCount);
        if ($shortfall < 1) {
            return 0;
        }
        $slicesNeeded = (int) max(1, ceil($shortfall / self::DIFFICULTY_BATCH_AI_SLICE));
        $cap = !empty($body['continueDifficultyBatch'])
            ? self::DIFFICULTY_BATCH_CALLS_PER_HTTP_MAX
            : ($chunkTotal > self::DIFFICULTY_BATCH_AI_SLICE
                ? self::DIFFICULTY_BATCH_CALLS_PER_HTTP_MAX
                : self::DIFFICULTY_BATCH_CALLS_PER_HTTP);

        return min($cap, $slicesNeeded);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertBatchQuestionCountBody(array $body, string $expectedDifficulty, int $expectedCount): void
    {
        $this->assertStaffBatchCount($expectedCount);
        $questionCount = (int) ($body['questionCount'] ?? 0);
        if ($questionCount > 0) {
            $this->assertStaffBatchCount($questionCount);
            if ($questionCount !== $expectedCount) {
                throw new \InvalidArgumentException(
                    "Question count must be 0, 5, or 10 for this batch (expected {$expectedCount}, received {$questionCount})."
                );
            }
        }
        $diffRaw = trim((string) ($body['difficulty'] ?? ''));
        if ($diffRaw !== '') {
            $normalized = $this->normalizeDifficulty($diffRaw);
            if ($normalized !== $expectedDifficulty) {
                throw new \InvalidArgumentException(
                    "Difficulty mismatch for this batch (expected {$expectedDifficulty})."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireDifficultyBatchDraft(array $user, string $sessionId, int $batchIndex): array
    {
        $draft = $this->requireDraft($user, $sessionId);
        if (empty($draft['genState']['difficultyBatchMode'])) {
            throw new \InvalidArgumentException('This session is not a difficulty batch run.');
        }
        $ready = (int) ($draft['genState']['readyForBatchIndex'] ?? -1);
        if ($ready >= 0 && $ready === $batchIndex) {
            unset($draft['genState']['readyForBatchIndex']);
            $draft['generating'] = true;
            $this->persistGenerationDraft($draft);

            return $draft;
        }
        if (!empty($draft['generating'])) {
            return $draft;
        }
        throw new \InvalidArgumentException('Add the current batch to the question bank before starting the next batch.');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{plan:list<array{difficulty:string,count:int}>,counts:array{Easy:int,Medium:int,Hard:int}}
     */
    private function parseDifficultyBatchPlan(array $body): array
    {
        $countByLabel = ['Easy' => 0, 'Medium' => 0, 'Hard' => 0];
        if (isset($body['mixes']) && is_array($body['mixes'])) {
            foreach ($body['mixes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = $this->normalizeDifficulty((string) ($row['difficulty'] ?? 'Medium'));
                $count = (int) ($row['count'] ?? 0);
                $this->assertStaffBatchCount($count);
                $countByLabel[$label] = $count;
            }
        } else {
            throw new \InvalidArgumentException('Missing difficulty counts for generation.');
        }

        /** @var list<array{difficulty:string,count:int}> $plan */
        $plan = [];
        foreach (['Easy', 'Medium', 'Hard'] as $label) {
            $count = (int) ($countByLabel[$label] ?? 0);
            if ($count > 0) {
                $plan[] = ['difficulty' => $label, 'count' => $count];
            }
        }
        if ($plan === []) {
            throw new \InvalidArgumentException('Please select at least one question for generation.');
        }

        return ['plan' => $plan, 'counts' => $countByLabel];
    }

    private function assertStaffBatchCount(int $count): void
    {
        if (!in_array($count, [0, 5, 10], true)) {
            throw new \InvalidArgumentException('Each difficulty batch must be 0, 5, or 10 questions.');
        }
    }

    /**
     * @param array{Easy:int,Medium:int,Hard:int} $countByLabel
     * @return list<array{difficulty:string,count:int}>
     */
    private function mixesFromCountByLabel(array $countByLabel): array
    {
        $mixes = [];
        foreach (['Easy', 'Medium', 'Hard'] as $label) {
            $count = (int) ($countByLabel[$label] ?? 0);
            if ($count > 0) {
                $mixes[] = ['difficulty' => $label, 'count' => $count];
            }
        }

        return $mixes;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function generateBatchTopUp(array $user, array $body): array
    {
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new \InvalidArgumentException('Missing session for generation top-up.');
        }
        $this->hydrateGenerationDraft($sessionId);
        $draft = $this->requireDraft($user, $sessionId);
        $stepMode = !empty($body['stepMode']);
        $awaitingStep = !empty($draft['genState']['awaitingNextBatch']);
        if ($awaitingStep) {
            $draft['generating'] = true;
            unset($draft['genState']['awaitingNextBatch']);
            $this->persistGenerationDraft($draft);
        }
        if (empty($draft['generating']) || (string) ($draft['genState']['phase'] ?? '') !== 'topup') {
            throw new \InvalidArgumentException('No generation top-up is pending for this session.');
        }

        $mixes = is_array($draft['mixes'] ?? null) ? $draft['mixes'] : $this->parseMixes($body);
        $total = $this->mixTotal($mixes);
        $progressKey = self::sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $batchTotal = (int) ($draft['genState']['batchTotal'] ?? 1);
        $batchIndex = max(0, $batchTotal - 1);
        $requestDeadline = microtime(true) + self::BATCH_REQUEST_WALL_SECONDS;
        $deadline = (float) ($draft['genState']['deadline'] ?? 0);
        if ($deadline <= 0 || $deadline < microtime(true) + 30) {
            $deadline = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
        }
        if ($deadline > $requestDeadline) {
            $deadline = $requestDeadline;
        }
        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : $draft;
        if (!isset($draft['genState']) || !is_array($draft['genState'])) {
            $draft['genState'] = [];
        }
        $draft['genState']['deadline'] = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
        $this->persistGenerationDraft($draft);

        $course = [
            'code' => (string) ($draft['course']['code'] ?? ''),
            'title' => (string) ($draft['course']['title'] ?? ''),
            'semsubId' => (string) ($draft['course']['semsubId'] ?? ''),
            'department' => (string) ($draft['course']['department'] ?? ''),
            'syllabusText' => (string) ($draft['course']['syllabusText'] ?? ''),
        ];
        $syllabus = $this->syllabusText($course);
        $system = OpenAIService::cleanUtf8(
            'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.'
        );
        $courseCode = (string) ($course['code'] ?? '');
        $selected = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        /** @var array<string, true> $promptKeys */
        $promptKeys = $this->promptKeysForSession($selected);
        $bankAvoid = $this->existingBankQuestionsAvoidBlock($courseCode);

        $topUpCalls = 0;
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($wasActive) {
            session_write_close();
        }
        try {
            $selected = $this->fillMixShortfalls(
                $course,
                $syllabus,
                $system,
                $mixes,
                $bankAvoid,
                $promptKeys,
                $selected,
                $topUpCalls,
                $progressKey,
                $total,
                $deadline,
                self::BATCH_TOPUP_MAX_API_CALLS
            );
        } finally {
            if ($wasActive && session_status() !== PHP_SESSION_ACTIVE) {
                Security::startSession(false);
            }
        }
        $this->hydrateGenerationDraft($sessionId);
        $prevCount = count(is_array($draft['questions'] ?? null) ? $draft['questions'] : []);
        $selected = $this->assignDraftIndexes($selected);
        $addedThisRequest = array_slice($selected, $prevCount);
        $got = count($selected);
        $stalls = (int) ($draft['genState']['topUpStalls'] ?? 0);
        if ($got <= $prevCount) {
            $stalls++;
        } else {
            $stalls = 0;
        }

        if ($this->mixShortfall($mixes, $this->selectionForMixCheck($selected, $mixes)) === []) {
            return $this->finalizeGenerationDraft(
                $sessionId,
                $course,
                $mixes,
                $selected,
                $progressKey,
                $total,
                $batchIndex,
                $batchTotal,
                []
            );
        }

        if ($stepMode) {
            return $this->pauseStepBatch(
                $sessionId,
                $course,
                $mixes,
                $selected,
                $addedThisRequest !== [] ? $addedThisRequest : $selected,
                $progressKey,
                $total,
                $batchIndex,
                $batchTotal,
                $batchTotal,
                'topup'
            );
        }

        return $this->resumeGenerationTopUp(
            $sessionId,
            $course,
            $mixes,
            $selected,
            $progressKey,
            $total,
            $batchIndex,
            $batchTotal,
            [],
            $stalls
        );
    }

    /**
     * @param list<array<string, mixed>> $batchQuestions
     * @return array<string, mixed>
     */
    private function pauseStepBatch(
        string $sessionId,
        array $course,
        array $mixes,
        array $selected,
        array $batchQuestions,
        string $progressKey,
        int $total,
        int $batchIndex,
        int $batchTotal,
        int $nextBatchIndex,
        ?string $phase
    ): array {
        $courseCode = (string) ($course['code'] ?? '');
        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : [];
        if ($draft === [] && $sessionId !== '') {
            $fromDisk = self::readGenerationDraftFile($sessionId);
            if (is_array($fromDisk)) {
                $draft = $fromDisk;
            }
        }
        $draft['questions'] = $selected;
        $draft['generating'] = false;
        if (!isset($draft['genState']) || !is_array($draft['genState'])) {
            $draft['genState'] = [];
        }
        $draft['genState']['awaitingNextBatch'] = true;
        $draft['genState']['nextBatchIndex'] = $nextBatchIndex;
        $draft['genState']['batchTotal'] = $batchTotal;
        $draft['genState']['stepMode'] = true;
        if ($phase !== null && $phase !== '') {
            $draft['genState']['phase'] = $phase;
        } else {
            unset($draft['genState']['phase']);
        }
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);

        $display = $this->assignDraftIndexes($batchQuestions);
        $got = count($selected);
        $batchNum = $batchIndex + 1;
        $this->touchGenerationProgress(
            $progressKey,
            $got,
            $total,
            "Batch {$batchNum} of {$batchTotal} ready · {$got} / {$total} total"
        );

        return $this->releaseSessionAndReturn([
            'sessionId' => $sessionId,
            'courseCode' => $courseCode,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'mixes' => $mixes,
            'requested' => $total,
            'questions' => $display,
            'batchQuestions' => $display,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'nextBatchIndex' => $nextBatchIndex,
            'generating' => false,
            'batchComplete' => false,
            'awaitingNextBatch' => true,
            'fulfilled' => false,
            'phase' => $phase ?? '',
            'stepMode' => true,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $selected
     * @param list<array{difficulty:string,count:int}> $mixes
     * @return list<array<string, mixed>>
     */
    private function selectionForMixCheck(array $selected, array $mixes): array
    {
        $selected = $this->dedupeBatchOnly($selected);

        return $this->capQuestionsToMixes($selected, $mixes);
    }

    /**
     * @param array<string, mixed> $course
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $selected
     * @param list<array<string, mixed>> $batchQuestions
     * @return array<string, mixed>
     */
    private function resumeGenerationTopUp(
        string $sessionId,
        array $course,
        array $mixes,
        array $selected,
        string $progressKey,
        int $total,
        int $batchIndex,
        int $batchTotal,
        array $batchQuestions,
        int $topUpStalls
    ): array {
        $selected = $this->assignDraftIndexes($selected);
        $got = count($selected);
        $courseCode = (string) ($course['code'] ?? '');

        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : [];
        if ($draft === [] && $sessionId !== '') {
            $fromDisk = self::readGenerationDraftFile($sessionId);
            if (is_array($fromDisk)) {
                $draft = $fromDisk;
            }
        }
        if (!isset($draft['genState']) || !is_array($draft['genState'])) {
            $draft['genState'] = [];
        }
        $draft['questions'] = $selected;
        $draft['generating'] = true;
        $draft['genState']['phase'] = 'topup';
        $draft['genState']['nextBatchIndex'] = $batchTotal;
        $draft['genState']['topUpStalls'] = $topUpStalls;
        $draft['genState']['deadline'] = microtime(true) + self::GENERATE_MAX_WALL_SECONDS;
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);

        $this->touchGenerationProgress(
            $progressKey,
            $got,
            $total,
            'Finishing counts… (' . $got . ' / ' . $total . ')'
        );

        return $this->releaseSessionAndReturn([
            'sessionId' => $sessionId,
            'courseCode' => $courseCode,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'mixes' => $mixes,
            'requested' => $total,
            'questions' => $selected,
            'batchQuestions' => $batchQuestions,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'generating' => true,
            'batchComplete' => false,
            'fulfilled' => false,
            'phase' => 'topup',
        ]);
    }

    /**
     * @param array<string, mixed> $course
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $selected
     * @param list<array<string, mixed>> $batchQuestions
     * @return array<string, mixed>
     */
    private function finalizeGenerationDraft(
        string $sessionId,
        array $course,
        array $mixes,
        array $selected,
        string $progressKey,
        int $total,
        int $batchIndex,
        int $batchTotal,
        array $batchQuestions
    ): array {
        $selected = $this->dedupeBatchOnly($selected);
        $selected = $this->capQuestionsToMixes($selected, $mixes);
        $selected = $this->assignDraftIndexes($selected);
        $got = count($selected);
        $courseCode = (string) ($course['code'] ?? '');
        if ($this->mixShortfall($mixes, $selected) !== []) {
            return $this->resumeGenerationTopUp(
                $sessionId,
                $course,
                $mixes,
                $selected,
                $progressKey,
                $total,
                $batchIndex,
                $batchTotal,
                $batchQuestions,
                0
            );
        }

        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : [];
        unset($draft['generating'], $draft['genState']);
        $draft['questions'] = $selected;
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);
        self::deleteGenerationDraftFile($sessionId);

        if ($selected === []) {
            unset($_SESSION['staff_course_draft']);
            self::deleteGenerationDraftFile($sessionId);
            throw new \RuntimeException('AI did not return any usable questions. Please try again.');
        }

        self::writeGenerationProgress($progressKey, [
            'phase' => $got >= $total ? 'complete' : 'incomplete',
            'message' => $got >= $total
                ? "{$got} / {$total} completed"
                : "Generated {$got} of {$total} requested",
            'generated' => $got,
            'requested' => $total,
            'percent' => $total > 0 ? min(100, (int) round(($got / $total) * 100)) : 100,
            'done' => true,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
        ]);

        $fulfilled = $got >= $total && $this->mixShortfall($mixes, $selected) === [];

        return $this->releaseSessionAndReturn([
            'sessionId' => $sessionId,
            'courseCode' => $courseCode,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'mixes' => $mixes,
            'requested' => $total,
            'questions' => $selected,
            'batchQuestions' => $batchQuestions,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'generating' => false,
            'batchComplete' => true,
            'fulfilled' => $fulfilled,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $selected
     */
    private function saveGeneratingDraftState(string $sessionId, array $selected, int $nextBatchIndex, ?string $phase): void
    {
        $draft = is_array($_SESSION['staff_course_draft'] ?? null) ? $_SESSION['staff_course_draft'] : [];
        if ($draft === [] && $sessionId !== '') {
            $fromDisk = self::readGenerationDraftFile($sessionId);
            if (is_array($fromDisk)) {
                $draft = $fromDisk;
            }
        }
        $draft['questions'] = $selected;
        if (!isset($draft['genState']) || !is_array($draft['genState'])) {
            $draft['genState'] = [];
        }
        $draft['genState']['nextBatchIndex'] = $nextBatchIndex;
        if ($phase !== null && $phase !== '') {
            $draft['genState']['phase'] = $phase;
        } else {
            unset($draft['genState']['phase']);
        }
        $draft['generating'] = true;
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function persistGenerationDraft(array $draft): void
    {
        $_SESSION['staff_course_draft'] = $draft;
        $id = trim((string) ($draft['id'] ?? ''));
        if ($id !== '') {
            self::writeGenerationDraftFile($id, $draft);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hydrateGenerationDraft(string $sessionId): ?array
    {
        $sessionId = self::sanitizeDraftSessionId($sessionId);
        if ($sessionId === '') {
            return null;
        }
        $disk = self::readGenerationDraftFile($sessionId);
        if ($disk !== null) {
            $_SESSION['staff_course_draft'] = $disk;

            return $disk;
        }
        $draft = $_SESSION['staff_course_draft'] ?? null;
        if (is_array($draft) && (string) ($draft['id'] ?? '') === $sessionId) {
            return $draft;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function releaseSessionAndReturn(array $payload): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function cancelGeneration(array $user, array $body): array
    {
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        if ($sessionId === '') {
            throw new \InvalidArgumentException('Missing generation session.');
        }
        $this->hydrateGenerationDraft($sessionId);
        $draft = $this->requireDraft($user, $sessionId);
        if (empty($draft['generating'])) {
            return [
                'sessionId' => $sessionId,
                'generating' => false,
                'questions' => is_array($draft['questions'] ?? null) ? $draft['questions'] : [],
            ];
        }
        unset($draft['generating'], $draft['genState']);
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);
        self::deleteGenerationDraftFile($sessionId);
        $questions = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];

        return [
            'sessionId' => $sessionId,
            'generating' => false,
            'questions' => $questions,
        ];
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    private function assignDraftIndexes(array $questions): array
    {
        foreach ($questions as $index => $question) {
            if (is_array($question)) {
                $questions[$index]['draftIndex'] = $index;
            }
        }

        return $questions;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireGeneratingDraft(array $user, string $sessionId, int $batchIndex): array
    {
        $draft = $this->requireDraft($user, $sessionId);
        $expected = (int) ($draft['genState']['nextBatchIndex'] ?? -1);
        if ($expected !== $batchIndex) {
            throw new \InvalidArgumentException(
                "Generation batches must be run in order (expected batch {$expected}, got {$batchIndex})."
            );
        }
        if (!empty($draft['genState']['awaitingNextBatch'])) {
            unset($draft['genState']['awaitingNextBatch']);
            $draft['generating'] = true;
            $this->persistGenerationDraft($draft);

            return $draft;
        }
        if (empty($draft['generating'])) {
            throw new \InvalidArgumentException('This generation session is no longer active. Generate questions again.');
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function saveSelected(array $user, array $body): array
    {
        $draft = $this->requireDraft($user, (string) ($body['sessionId'] ?? ''));
        $difficultyBatchMode = !empty($draft['genState']['difficultyBatchMode']);
        $awaitingStep = !empty($draft['genState']['awaitingNextBatch']);
        $awaitingBank = !empty($draft['genState']['awaitingBankSave']);
        if (!empty($draft['generating']) && !$awaitingStep && !$awaitingBank) {
            throw new \InvalidArgumentException('Wait until all generation batches finish, then select questions to add.');
        }
        if ($difficultyBatchMode && !$awaitingBank && empty($draft['generating'])) {
            throw new \InvalidArgumentException('Generate a batch first, then add questions to the bank.');
        }
        $indexes = [];
        foreach ((array) ($body['indexes'] ?? $body['selected'] ?? []) as $index) {
            $indexes[] = (int) $index;
        }
        $indexes = array_values(array_unique($indexes));
        $source = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        $picked = [];
        foreach ($indexes as $index) {
            if (!isset($source[$index]) || !is_array($source[$index])) {
                continue;
            }
            $picked[] = $source[$index];
        }
        if ($picked === []) {
            throw new \InvalidArgumentException('Select the questions you want to add to the syllabus question bank.');
        }

        $course = is_array($draft['course'] ?? null) ? $draft['course'] : [];
        $this->assertVisibleCourseCode($user, (string) ($course['code'] ?? ''));
        $knownKeys = (new SyllabusQuestionBankModel())->existingPromptKeys((string) ($course['code'] ?? ''));
        $picked = $this->dedupeWithinList($picked, $knownKeys);
        if ($picked === []) {
            throw new \InvalidArgumentException('The selected questions are already in the bank or duplicate each other.');
        }
        $bank = (new SyllabusQuestionBankModel())->addQuestions(
            $course,
            $picked,
            'Medium',
            (string) ($user['_id'] ?? $user['id'] ?? '')
        );
        $added = (int) ($bank['added'] ?? 0);

        if ($difficultyBatchMode) {
            if ($added < 1) {
                throw new \InvalidArgumentException('No new questions were added to the bank. Select different questions or retry generation.');
            }
            $genState = is_array($draft['genState'] ?? null) ? $draft['genState'] : [];
            $batchPlan = is_array($genState['batchPlan'] ?? null) ? $genState['batchPlan'] : [];
            $batchIndex = (int) ($genState['difficultyBatchIndex'] ?? 0);
            $currentDifficulty = (string) ($genState['currentDifficulty'] ?? '');
            $completed = is_array($genState['completedDifficulties'] ?? null) ? $genState['completedDifficulties'] : [];
            if ($currentDifficulty !== '' && !in_array($currentDifficulty, $completed, true)) {
                $completed[] = $currentDifficulty;
            }
            $nextIndex = $batchIndex + 1;
            $allDone = $nextIndex >= count($batchPlan);
            $counts = is_array($genState['counts'] ?? null) ? $genState['counts'] : [];
            unset($genState['awaitingBankSave']);
            $genState['completedDifficulties'] = $completed;
            if ($allDone) {
                unset($draft['genState']);
                $draft['questions'] = [];
                $draft['generating'] = false;
            } else {
                $genState['readyForBatchIndex'] = $nextIndex;
                $draft['genState'] = $genState;
                $draft['questions'] = [];
                $draft['generating'] = false;
            }
            $draft['createdAt'] = time();
            $this->persistGenerationDraft($draft);

            return [
                'sessionId' => (string) ($draft['id'] ?? ''),
                'courseCode' => (string) ($bank['courseCode'] ?? $course['code'] ?? ''),
                'courseTitle' => (string) ($bank['courseTitle'] ?? $course['title'] ?? ''),
                'added' => $added,
                'skipped' => (int) ($bank['skipped'] ?? 0),
                'questions' => [],
                'mixes' => $draft['mixes'] ?? [],
                'difficultyBatchMode' => true,
                'generationCompleted' => $allDone,
                'nextDifficultyBatchIndex' => $allDone ? null : $nextIndex,
                'completedDifficulties' => $completed,
                'counts' => $counts,
                'batchTotal' => count($batchPlan),
                'bank' => $this->listBank($user, (string) ($bank['courseCode'] ?? $course['code'] ?? '')),
            ];
        }

        $remaining = [];
        if ($awaitingStep) {
            foreach ($source as $index => $question) {
                if (!is_array($question)) {
                    continue;
                }
                if (in_array((int) $index, $indexes, true)) {
                    $question['savedToBank'] = true;
                }
                $question['draftIndex'] = count($remaining);
                $remaining[] = $question;
            }
        } else {
            foreach ($source as $index => $question) {
                if (!is_array($question) || in_array((int) $index, $indexes, true)) {
                    continue;
                }
                $question['draftIndex'] = count($remaining);
                $remaining[] = $question;
            }
        }
        $draft['questions'] = $remaining;
        $draft['createdAt'] = time();
        $this->persistGenerationDraft($draft);

        $display = $awaitingStep
            ? array_values(array_filter($remaining, static fn(array $q): bool => empty($q['savedToBank'])))
            : $remaining;
        foreach ($display as $i => $question) {
            if (is_array($question)) {
                $display[$i]['draftIndex'] = $i;
            }
        }

        return [
            'sessionId' => (string) ($draft['id'] ?? ''),
            'courseCode' => (string) ($bank['courseCode'] ?? $course['code'] ?? ''),
            'courseTitle' => (string) ($bank['courseTitle'] ?? $course['title'] ?? ''),
            'added' => (int) ($bank['added'] ?? 0),
            'skipped' => (int) ($bank['skipped'] ?? 0),
            'questions' => $display,
            'mixes' => $draft['mixes'] ?? [],
            'awaitingNextBatch' => $awaitingStep,
            'nextBatchIndex' => (int) ($draft['genState']['nextBatchIndex'] ?? 0),
            'batchTotal' => (int) ($draft['genState']['batchTotal'] ?? 0),
            'phase' => (string) ($draft['genState']['phase'] ?? ''),
            'stepMode' => !empty($draft['genState']['stepMode']),
            'bank' => $this->listBank($user, (string) ($bank['courseCode'] ?? $course['code'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listBank(array $user, string $courseCode = ''): array
    {
        $focus = SyllabusQuestionBankModel::normalizeCourseCode($courseCode);
        if ($focus !== '') {
            $this->assertVisibleCourseCode($user, $focus);
        }

        $allDepartments = $this->seesAllCourses($user);
        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');

        $visible = [];
        foreach ((new SyllabusQuestionBankModel())->listAll(2000) as $question) {
            $code = SyllabusQuestionBankModel::normalizeCourseCode((string) ($question['courseCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (!$allDepartments && !CourseSyllabusCatalog::subjectVisibleToStaff($code, $deptCode, $deptName, $deptShort)) {
                continue;
            }
            $visible[] = $question;
        }

        $courses = SyllabusQuestionBankModel::groupByCourseCode($visible);
        $focused = null;
        foreach ($courses as $course) {
            if ((string) ($course['courseCode'] ?? '') === $focus) {
                $focused = $course;
                break;
            }
        }

        return [
            'courses' => $courses,
            'totalCourses' => count($courses),
            'totalQuestions' => count($visible),
            'focusCode' => $focus,
            'courseCode' => $focus,
            'courseTitle' => (string) ($focused['courseTitle'] ?? ''),
            'total' => (int) ($focused['total'] ?? 0),
            'questions' => $focused['questions'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function deleteBankQuestion(array $user, string $questionId): array
    {
        $model = new SyllabusQuestionBankModel();
        $row = $model->findById($questionId);
        if ($row === null) {
            throw new \InvalidArgumentException('That question is not in the syllabus bank.');
        }
        $code = $this->assertVisibleCourseCode($user, (string) ($row['courseCode'] ?? ''));
        $model->delete($questionId);

        return $this->listBank($user, $code);
    }

    /**
     * Start an MCQ from the selected course question bank. Answers stay on the server.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startPractice(array $user, array $body): array
    {
        $testId = trim((string) ($body['testId'] ?? ''));
        if ($testId !== '') {
            throw new \InvalidArgumentException('Only students can take MCQ tests. Open the test to review questions.');
        }

        $code = $this->assertVisibleCourseCode($user, (string) ($body['courseCode'] ?? $body['code'] ?? ''));
        $rows = (new SyllabusQuestionBankModel())->listByCourseCode($code);
        if ($rows === []) {
            throw new \InvalidArgumentException('Add questions to the syllabus question bank first.');
        }

        return $this->openPractice($user, $code, trim((string) ($rows[0]['courseTitle'] ?? $code)), $rows, '');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listTests(array $user): array
    {
        $tests = [];
        foreach ((new SyllabusMcqTestModel())->listCards() as $test) {
            $codes = is_array($test['courseCodes'] ?? null) ? $test['courseCodes'] : [];
            if ($codes === []) {
                continue;
            }
            $visible = true;
            foreach ($codes as $code) {
                if (!$this->courseVisible($user, (string) $code)) {
                    $visible = false;
                    break;
                }
            }
            if (!$visible) {
                continue;
            }
            $tests[] = $test;
        }

        return ['tests' => $tests];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createTest(array $user, array $body): array
    {
        $title = trim((string) ($body['title'] ?? ''));
        $duration = (int) ($body['durationMinutes'] ?? 30);
        if ($duration < 5 || $duration > 180) {
            throw new \InvalidArgumentException('Duration must be between 5 and 180 minutes.');
        }
        $sources = is_array($body['sources'] ?? null) ? $body['sources'] : [];
        if ($sources === [] && trim((string) ($body['courseCode'] ?? '')) !== '') {
            $sources = [[
                'courseCode' => (string) $body['courseCode'],
                'mode' => 'random',
                'count' => 0,
            ]];
        }
        $bank = new SyllabusQuestionBankModel();
        $grouped = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $code = $this->assertVisibleCourseCode($user, (string) ($source['courseCode'] ?? $source['code'] ?? ''));
            if (!isset($grouped[$code])) {
                $grouped[$code] = ['manual' => [], 'random' => 0, 'all' => false];
            }
            $mode = strtolower(trim((string) ($source['mode'] ?? 'random')));
            if ($mode === 'manual') {
                foreach ((array) ($source['questionIds'] ?? $source['ids'] ?? []) as $id) {
                    $id = trim((string) $id);
                    if ($id !== '') {
                        $grouped[$code]['manual'][$id] = true;
                    }
                }
                continue;
            }
            $count = (int) ($source['count'] ?? 0);
            if ($count < 1) {
                $grouped[$code]['all'] = true;
                continue;
            }
            $grouped[$code]['random'] += $count;
        }
        $picked = [];
        $codes = [];
        foreach ($grouped as $code => $request) {
            $pool = $bank->listByCourseCode($code, 500);
            if ($pool === []) {
                throw new \InvalidArgumentException('Add questions to ' . $code . ' before creating an MCQ.');
            }
            $chosen = [];
            $chosenIds = [];
            foreach ($pool as $question) {
                $id = (string) ($question['id'] ?? '');
                if ($id !== '' && isset($request['manual'][$id])) {
                    $chosen[] = $question;
                    $chosenIds[$id] = true;
                }
            }
            if ($request['manual'] !== [] && $chosen === [] && (int) $request['random'] < 1) {
                throw new \InvalidArgumentException('Pick the questions to include from ' . $code . '.');
            }
            $need = !empty($request['all']) ? count($pool) : (int) $request['random'];
            if ($need > 0) {
                $rest = [];
                foreach ($pool as $question) {
                    $id = (string) ($question['id'] ?? '');
                    if ($id === '' || !isset($chosenIds[$id])) {
                        $rest[] = $question;
                    }
                }
                if ($need > count($rest)) {
                    $available = count($pool);
                    throw new \InvalidArgumentException($code . ' has only ' . $available . ' question' . ($available === 1 ? '' : 's') . '.');
                }
                shuffle($rest);
                foreach (array_slice($rest, 0, $need) as $question) {
                    $chosen[] = $question;
                }
            }
            if ($chosen === []) {
                throw new \InvalidArgumentException('Choose questions from ' . $code . '.');
            }
            foreach ($chosen as $question) {
                $picked[] = $question;
            }
            $codes[] = $code;
        }
        if ($picked === [] || $codes === []) {
            throw new \InvalidArgumentException('Choose at least one course and the questions to include.');
        }
        if ($title === '') {
            $title = implode(', ', $codes);
        }
        if (mb_strlen($title) > 120) {
            throw new \InvalidArgumentException('The MCQ title must be 120 characters or fewer.');
        }
        $test = (new SyllabusMcqTestModel())->createTest(
            $title,
            $duration,
            $picked,
            $codes,
            (string) ($user['_id'] ?? $user['id'] ?? '')
        );

        return [
            'test' => $test,
            'tests' => $this->listTests($user)['tests'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function deleteTest(array $user, string $testId): array
    {
        $model = new SyllabusMcqTestModel();
        $row = $model->findById($testId);
        if ($row === null) {
            throw new \InvalidArgumentException('That MCQ was not found.');
        }
        $this->assertTestCoursesVisible($user, $row);
        $model->delete($testId);

        return $this->listTests($user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getTest(array $user, string $testId): array
    {
        $row = (new SyllabusMcqTestModel())->findById($testId);
        if ($row === null) {
            throw new \InvalidArgumentException('That MCQ was not found.');
        }
        $this->assertTestCoursesVisible($user, $row);
        $view = (new SyllabusMcqTestModel())->publicView($row);
        $title = trim((string) ($row['title'] ?? ''));
        $questions = [];
        foreach ((array) ($row['questions'] ?? []) as $question) {
            if (!is_array($question)) {
                continue;
            }
            $questions[] = [
                'module' => (string) ($question['module'] ?? ''),
                'difficulty' => (string) ($question['difficulty'] ?? ''),
                'question' => (string) ($question['question'] ?? ''),
                'options' => array_values((array) ($question['options'] ?? [])),
                'correctIndex' => (int) ($question['correctIndex'] ?? -1),
                'description' => (string) ($question['description'] ?? $question['explanation'] ?? ''),
            ];
        }

        return array_merge($view, [
            'title' => $title !== '' ? $title : (string) ($view['title'] ?? ''),
            'questions' => $questions,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function startSavedTest(array $user, string $testId): array
    {
        $row = (new SyllabusMcqTestModel())->findById($testId);
        if ($row === null) {
            throw new \InvalidArgumentException('That MCQ was not found.');
        }
        $this->assertTestCoursesVisible($user, $row);
        $view = (new SyllabusMcqTestModel())->publicView($row);
        $code = (string) ($view['courseCode'] ?? '');
        $questions = [];
        foreach ((array) ($row['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = $question;
            }
        }
        if ($questions === []) {
            throw new \InvalidArgumentException('This MCQ has no questions.');
        }
        $title = trim((string) ($row['title'] ?? $code));
        $courseTitle = trim((string) ($view['courseTitle'] ?? ''));

        return $this->openPractice(
            $user,
            $code,
            $title !== '' ? $title : $code,
            $questions,
            (string) ($row['_id'] ?? $testId),
            max(1, (int) ($row['durationMinutes'] ?? 30)),
            $courseTitle
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array<string, mixed>> $questions
     * @return array<string, mixed>
     */
    private function openPractice(array $user, string $courseCode, string $title, array $questions, string $testId, int $durationMinutes = 0, string $courseDisplayTitle = ''): array
    {
        $sessionId = bin2hex(random_bytes(16));
        $courseTitle = trim($courseDisplayTitle) !== '' ? trim($courseDisplayTitle) : $title;
        $_SESSION['staff_course_practice'] = [
            'id' => $sessionId,
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'testId' => $testId,
            'courseCode' => $courseCode,
            'courseTitle' => $courseTitle,
            'title' => $title,
            'durationMinutes' => $durationMinutes,
            'questions' => $questions,
            'createdAt' => time(),
        ];

        return [
            'sessionId' => $sessionId,
            'testId' => $testId,
            'courseCode' => $courseCode,
            'courseTitle' => $courseTitle,
            'title' => $title,
            'durationMinutes' => $durationMinutes,
            'total' => count($questions),
            'questions' => $this->questionsForPractice($questions),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function courseVisible(array $user, string $courseCode): bool
    {
        try {
            $this->assertVisibleCourseCode($user, $courseCode);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
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
            throw new \InvalidArgumentException('This MCQ has ended. Start it again from the MCQ tab.');
        }
        if ((string) ($session['userId'] ?? '') !== $userId) {
            throw new \InvalidArgumentException('This MCQ has ended. Start it again from the MCQ tab.');
        }
        if ((time() - (int) ($session['createdAt'] ?? 0)) > 7200) {
            unset($_SESSION['staff_course_practice']);
            throw new \InvalidArgumentException('This MCQ has expired. Start it again from the MCQ tab.');
        }

        $graded = $this->gradeAnswers($session, is_array($body['answers'] ?? null) ? array_values($body['answers']) : []);
        unset($_SESSION['staff_course_practice']);

        return $graded;
    }

    /**
     * @param array<string, mixed> $session
     * @param list<mixed> $answers
     * @return array<string, mixed>
     */
    private function gradeAnswers(array $session, array $answers): array
    {
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
                'explanation' => (string) ($question['explanation'] ?? $question['description'] ?? ''),
            ];
        }

        return [
            'courseCode' => (string) ($session['courseCode'] ?? ''),
            'courseTitle' => (string) ($session['courseTitle'] ?? $session['title'] ?? ''),
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
                'difficulty' => (string) ($question['difficulty'] ?? ''),
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
    private function resolveLoadedSyllabus(array $body, string $deptCode, string $deptName, string $deptShort, bool $allDepartments = false): array
    {
        $code = strtoupper(trim((string) ($body['courseCode'] ?? $body['code'] ?? '')));
        $semsubId = trim((string) ($body['semsubId'] ?? $body['id'] ?? ''));
        $title = trim((string) ($body['courseTitle'] ?? $body['title'] ?? ''));
        if ($code === '' || $semsubId === '') {
            throw new \InvalidArgumentException('Click Get to load the syllabus first.');
        }
        if (!$allDepartments && !CourseSyllabusCatalog::subjectVisibleToStaff($code, $deptCode, $deptName, $deptShort)) {
            throw new \InvalidArgumentException('That course is outside your department.');
        }

        $encid = AesSyllabusCipher::encrypt($semsubId);
        if ($encid === '') {
            throw new \RuntimeException('Could not encode that course id.');
        }
        $cached = self::getSyllabusCache($semsubId, $encid);
        if ($cached === null) {
            throw new \InvalidArgumentException(
                'Syllabus text is not prepared for AI yet. Question generation reads the PDF in a separate step from viewing it.'
            );
        }
        $text = $cached;

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
     * Fetches the syllabus PDF and extracts text for OpenAI. Separate from viewing/downloading the PDF in the browser.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function prepareSyllabusForAi(array $user, array $body): array
    {
        @set_time_limit(300);
        @ignore_user_abort(true);

        $code = strtoupper(trim((string) ($body['courseCode'] ?? $body['code'] ?? '')));
        $semsubId = trim((string) ($body['semsubId'] ?? $body['id'] ?? ''));
        if ($semsubId === '') {
            throw new \InvalidArgumentException('Select a course and click Get before preparing the syllabus for AI.');
        }
        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');
        if ($code !== '' && !$this->seesAllCourses($user) && !CourseSyllabusCatalog::subjectVisibleToStaff($code, $deptCode, $deptName, $deptShort)) {
            throw new \InvalidArgumentException('That course is outside your department.');
        }

        $encid = AesSyllabusCipher::encrypt($semsubId);
        if ($encid === '') {
            throw new \RuntimeException('Could not encode that course id.');
        }

        $cached = self::getSyllabusCache($semsubId, $encid);
        if ($cached !== null) {
            $text = $cached;
        } else {
            $skipSessionClose = !empty($_SESSION['staff_course_draft']['generating']);
            if (session_status() === PHP_SESSION_ACTIVE && !$skipSessionClose) {
                session_write_close();
            }
            try {
                $pdf = AesSyllabusCipher::fetchPdf($encid);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($e->getMessage(), 502);
            }
            $text = AesSyllabusCipher::extractTextForEncid($encid, $pdf);
            if (session_status() !== PHP_SESSION_ACTIVE) {
                Security::startSession(false);
            }
            self::putSyllabusCache($semsubId, $encid, $text);
        }

        $readable = AesSyllabusCipher::isUsableSyllabusText($text);

        return [
            'semsubId' => $semsubId,
            'encid' => $encid,
            'courseCode' => $code,
            'syllabusTextChars' => mb_strlen($text),
            'syllabusReadable' => $readable,
            'syllabusPrepared' => true,
        ];
    }

    public static function putSyllabusCache(string $semsubId, string $encid, string $text): void
    {
        $semsubId = trim($semsubId);
        if ($semsubId === '') {
            return;
        }
        if (!isset($_SESSION['staff_syllabus_cache']) || !is_array($_SESSION['staff_syllabus_cache'])) {
            $_SESSION['staff_syllabus_cache'] = [];
        }
        $_SESSION['staff_syllabus_cache'][$semsubId] = [
            'encid' => $encid,
            'text' => $text,
            'at' => time(),
        ];
    }

    public static function getSyllabusCache(string $semsubId, string $encid): ?string
    {
        $semsubId = trim($semsubId);
        $encid = trim($encid);
        if ($semsubId === '' || $encid === '') {
            return null;
        }
        $row = $_SESSION['staff_syllabus_cache'][$semsubId] ?? null;
        if (!is_array($row) || (string) ($row['encid'] ?? '') !== $encid) {
            return null;
        }
        $at = (int) ($row['at'] ?? 0);
        if ($at <= 0 || (time() - $at) > self::SYLLABUS_CACHE_TTL) {
            return null;
        }
        $text = trim((string) ($row['text'] ?? ''));

        return $text !== '' ? $text : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readGenerationProgress(string $key): ?array
    {
        $key = self::sanitizeProgressKey($key);
        if ($key === '') {
            return null;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_staff_course_prog_' . hash('sha256', $key) . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public static function sanitizeProgressKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[a-f0-9\-]{8,64}$/i', $key)) {
            return '';
        }

        return $key;
    }

    public static function sanitizeDraftSessionId(string $sessionId): string
    {
        $sessionId = trim($sessionId);

        return preg_match('/^[a-f0-9]{32}$/i', $sessionId) ? $sessionId : '';
    }

    /**
     * @param array<string, mixed> $draft
     */
    public static function writeGenerationDraftFile(string $sessionId, array $draft): void
    {
        $sessionId = self::sanitizeDraftSessionId($sessionId);
        if ($sessionId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_staff_course_draft_' . hash('sha256', $sessionId) . '.json';
        $payload = array_merge($draft, ['id' => $sessionId, 'diskUpdatedAt' => time()]);
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function readGenerationDraftFile(string $sessionId): ?array
    {
        $sessionId = self::sanitizeDraftSessionId($sessionId);
        if ($sessionId === '') {
            return null;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_staff_course_draft_' . hash('sha256', $sessionId) . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public static function deleteGenerationDraftFile(string $sessionId): void
    {
        $sessionId = self::sanitizeDraftSessionId($sessionId);
        if ($sessionId === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_staff_course_draft_' . hash('sha256', $sessionId) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function writeGenerationProgress(string $key, array $data): void
    {
        $key = self::sanitizeProgressKey($key);
        if ($key === '') {
            return;
        }
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_staff_course_prog_' . hash('sha256', $key) . '.json';
        $payload = array_merge($data, ['updatedAt' => time()]);
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function touchGenerationProgress(string $key, int $generated, int $requested, string $message): void
    {
        if ($key === '') {
            return;
        }
        self::writeGenerationProgress($key, [
            'phase' => $generated >= $requested && $requested > 0 ? 'batch_ready' : 'generating',
            'message' => $message,
            'generated' => $generated,
            'requested' => $requested,
            'percent' => $requested > 0
                ? ($generated >= $requested
                    ? 100
                    : min(99, (int) round(($generated / $requested) * 100)))
                : 0,
            'done' => $generated >= $requested && $requested > 0,
        ]);
    }

    private function generationDeadlineReached(float $deadline): bool
    {
        if ($deadline <= 0) {
            return false;
        }

        return microtime(true) >= $deadline;
    }

    /**
     * @param array<string, mixed> $course
     */
    private function trimSyllabusForPrompt(string $syllabus): string
    {
        $syllabus = trim($syllabus);
        if ($syllabus === '') {
            return '';
        }
        if (mb_strlen($syllabus) <= self::SYLLABUS_PROMPT_MAX_CHARS) {
            return $syllabus;
        }

        return mb_substr($syllabus, 0, self::SYLLABUS_PROMPT_MAX_CHARS)
            . "\n\n[… syllabus trimmed for length; use content above …]";
    }

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

    /**
     * @param array<string, mixed> $body
     * @return list<array{difficulty:string,count:int}>
     */
    private function parseMixes(array $body): array
    {
        $mixes = [];
        if (isset($body['mixes']) && is_array($body['mixes'])) {
            foreach ($body['mixes'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $count = (int) ($row['count'] ?? 0);
                if ($count <= 0) {
                    continue;
                }
                $mixes[] = [
                    'difficulty' => $this->normalizeDifficulty((string) ($row['difficulty'] ?? '')),
                    'count' => $count,
                ];
            }
        } elseif (isset($body['counts']) && is_array($body['counts'])) {
            foreach (['Easy', 'Medium', 'Hard'] as $label) {
                $count = (int) ($body['counts'][$label] ?? $body['counts'][strtolower($label)] ?? 0);
                if ($count <= 0) {
                    continue;
                }
                $mixes[] = ['difficulty' => $label, 'count' => $count];
            }
        } else {
            $count = (int) ($body['count'] ?? 10);
            $mixes[] = [
                'difficulty' => $this->normalizeDifficulty((string) ($body['difficulty'] ?? 'Medium')),
                'count' => $count,
            ];
        }

        $seen = [];
        foreach ($mixes as $mix) {
            $count = (int) $mix['count'];
            if ($count < self::MIN_COUNT || $count > self::MAX_COUNT) {
                throw new \InvalidArgumentException('Each difficulty can have 0 to 20 questions.');
            }
            $difficulty = (string) $mix['difficulty'];
            $seen[$difficulty] = ((int) ($seen[$difficulty] ?? 0)) + $count;
        }
        $merged = [];
        $total = 0;
        foreach (['Easy', 'Medium', 'Hard'] as $label) {
            $count = (int) ($seen[$label] ?? 0);
            if ($count <= 0) {
                continue;
            }
            if ($count > self::MAX_COUNT) {
                throw new \InvalidArgumentException('Each difficulty can have at most 20 questions.');
            }
            $merged[] = ['difficulty' => $label, 'count' => $count];
            $total += $count;
        }
        if ($merged === [] || $total < 1) {
            throw new \InvalidArgumentException('Choose a number of questions for at least one difficulty.');
        }
        if ($total > self::MAX_TOTAL) {
            throw new \InvalidArgumentException('Generate at most 40 questions at a time.');
        }

        return $merged;
    }

    private function normalizeDifficulty(string $value): string
    {
        $value = ucfirst(strtolower(trim($value)));
        if (!in_array($value, ['Easy', 'Medium', 'Hard'], true)) {
            throw new \InvalidArgumentException('Difficulty must be Easy, Medium, or Hard.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireDraft(array $user, string $sessionId): array
    {
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new \InvalidArgumentException('Generate questions first, then select the ones to add.');
        }
        $this->hydrateGenerationDraft($sessionId);
        $draft = $_SESSION['staff_course_draft'] ?? null;
        if (!is_array($draft) || (string) ($draft['id'] ?? '') !== $sessionId) {
            throw new \InvalidArgumentException('Generate questions first, then select the ones to add.');
        }
        if ((string) ($draft['userId'] ?? '') !== $userId) {
            throw new \InvalidArgumentException('Generate questions first, then select the ones to add.');
        }
        if ((time() - (int) ($draft['createdAt'] ?? 0)) > 7200) {
            unset($_SESSION['staff_course_draft']);
            self::deleteGenerationDraftFile($sessionId);
            throw new \InvalidArgumentException('This generated set has expired. Generate questions again.');
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertVisibleCourseCode(array $user, string $courseCode): string
    {
        $code = SyllabusQuestionBankModel::normalizeCourseCode($courseCode);
        if ($code === '') {
            throw new \InvalidArgumentException('Click Get to load the syllabus first.');
        }
        if ($this->seesAllCourses($user)) {
            return $code;
        }
        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        if (!CourseSyllabusCatalog::subjectVisibleToStaff(
            $code,
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? ''),
            (string) ($dept['shortName'] ?? '')
        )) {
            throw new \InvalidArgumentException('That course is outside your department.');
        }

        return $code;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function seesAllCourses(array $user): bool
    {
        return RBACMiddleware::seesAllSyllabusCourses($user);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private function assertTestCoursesVisible(array $user, array $row): void
    {
        if ($this->seesAllCourses($user)) {
            return;
        }
        $codes = [];
        foreach ((array) ($row['courseCodes'] ?? []) as $code) {
            $normalized = SyllabusQuestionBankModel::normalizeCourseCode((string) $code);
            if ($normalized !== '') {
                $codes[] = $normalized;
            }
        }
        if ($codes === []) {
            $fallback = SyllabusQuestionBankModel::normalizeCourseCode((string) ($row['courseCode'] ?? ''));
            if ($fallback !== '') {
                $codes[] = $fallback;
            }
        }
        foreach ($codes as $code) {
            $this->assertVisibleCourseCode($user, $code);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function studentStudyBank(): array
    {
        $questions = (new SyllabusQuestionBankModel())->listAll(2000);

        return [
            'courses' => SyllabusQuestionBankModel::groupByCourseCode($questions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function studentTests(): array
    {
        return ['tests' => (new SyllabusMcqTestModel())->listCards()];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function studentStart(array $user, string $testId): array
    {
        $row = (new SyllabusMcqTestModel())->findById($testId);
        if ($row === null) {
            throw new \InvalidArgumentException('That MCQ was not found.');
        }
        $questions = [];
        foreach ((array) ($row['questions'] ?? []) as $question) {
            if (is_array($question)) {
                $questions[] = $question;
            }
        }
        if ($questions === []) {
            throw new \InvalidArgumentException('This MCQ has no questions.');
        }
        $view = (new SyllabusMcqTestModel())->publicView($row);
        $sessionId = bin2hex(random_bytes(16));
        $_SESSION['student_autonomous_mcq'] = [
            'id' => $sessionId,
            'userId' => (string) ($user['_id'] ?? $user['id'] ?? ''),
            'testId' => (string) ($row['_id'] ?? $testId),
            'courseCode' => (string) ($view['courseCode'] ?? ''),
            'title' => (string) ($view['title'] ?? ''),
            'durationMinutes' => (int) ($view['durationMinutes'] ?? 30),
            'questions' => $questions,
            'createdAt' => time(),
        ];

        return [
            'sessionId' => $sessionId,
            'testId' => (string) ($row['_id'] ?? $testId),
            'courseCode' => (string) ($view['courseCode'] ?? ''),
            'title' => (string) ($view['title'] ?? ''),
            'durationMinutes' => (int) ($view['durationMinutes'] ?? 30),
            'total' => count($questions),
            'questions' => $this->questionsForPractice($questions),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function studentSubmit(array $user, array $body): array
    {
        $session = $_SESSION['student_autonomous_mcq'] ?? null;
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if (!is_array($session) || $sessionId === '' || (string) ($session['id'] ?? '') !== $sessionId || (string) ($session['userId'] ?? '') !== $userId) {
            throw new \InvalidArgumentException('This MCQ has ended. Start it again.');
        }
        $graded = $this->gradeAnswers($session, is_array($body['answers'] ?? null) ? array_values($body['answers']) : []);
        unset($_SESSION['student_autonomous_mcq']);
        $graded['title'] = (string) ($session['title'] ?? '');
        $graded['testId'] = (string) ($session['testId'] ?? '');

        return $graded;
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
     * @param array<string, mixed> $course
     * @param list<array{difficulty:string,count:int}> $mixes
     */
    private function buildGeneratePrompt(
        array $course,
        string $syllabus,
        array $mixes,
        int $total,
        string $mixText,
        string $avoidBlock,
        bool $allowExtra = false
    ): string {
        $code = OpenAIService::cleanUtf8((string) ($course['code'] ?? ''));
        $title = OpenAIService::cleanUtf8((string) ($course['title'] ?? ''));
        $syllabus = OpenAIService::cleanUtf8($this->trimSyllabusForPrompt($syllabus));
        $mixText = OpenAIService::cleanUtf8($mixText);
        $avoidBlock = OpenAIService::cleanUtf8($avoidBlock);
        $avoid = trim($avoidBlock) !== ''
            ? "\n" . trim($avoidBlock) . "\n"
            : '';
        $extraHint = $allowExtra ? min(10, max(4, (int) ceil($total / 2))) : 0;
        $countRule = $allowExtra
            ? "- Return at least {$total} questions matching the difficulty counts above (include up to {$extraHint} extra in the same mix if helpful).\n"
            : "- Return exactly {$total} questions with the difficulty counts above.\n";
        $headline = $allowExtra
            ? "Write at least {$total} multiple-choice questions in this mix:\n"
            : "Write exactly {$total} multiple-choice questions in this mix (no more, no fewer):\n";

        return "Course: {$code} {$title}\n"
            . $headline
            . $mixText . "\n\n"
            . "Official syllabus:\n"
            . $syllabus . "\n"
            . $avoid
            . "Rules:\n"
            . $countRule
            . "- Every question must be answerable from the syllabus above.\n"
            . "- Do not invent topics, tools, or outcomes that are not in the syllabus.\n"
            . "- Spread questions across the modules and course outcomes.\n"
            . "- Each question has exactly four distinct options and one correct answer.\n"
            . "- correctIndex is the 0-based index of the correct option.\n"
            . "- Each question's difficulty field must be Easy, Medium, or Hard and match the mix.\n"
            . "- description is required: 1-2 sentences explaining why the correct option is right.\n"
            . "- Every question must be unique; do not repeat or rephrase the same question.\n\n"
            . 'Return this JSON shape:' . "\n"
            . '{"questions":[{"module":"Module 1","difficulty":"Medium","question":"...","options":["...","...","...","..."],"correctIndex":0,"description":"..."}]}';
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param array<string, true> $promptKeys
     * @return list<array<string, mixed>>
     */
    private function assembleQuestionsForMixes(
        array $course,
        string $syllabus,
        string $system,
        array $mixes,
        string $initialMixText,
        string $bankAvoid,
        array &$promptKeys,
        string $progressKey = '',
        int $requestedTotal = 0,
        float $deadline = 0.0
    ): array {
        $selected = [];
        $apiCalls = 0;
        $requestedTotal = max($requestedTotal, $this->mixTotal($mixes));

        foreach ($this->chunkMixesByTotal($mixes, self::GENERATE_BATCH_SIZE) as $chunk) {
            if ($this->generationDeadlineReached($deadline) || $apiCalls >= self::GENERATE_MAX_API_CALLS) {
                break;
            }
            $chunkTotal = $this->mixTotal($chunk);
            $mixText = $this->mixLinesText($chunk);
            $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
            $added = $this->fetchQuestionBatch(
                $course,
                $syllabus,
                $system,
                $chunk,
                $chunkTotal,
                $mixText,
                $avoid,
                $selected,
                $promptKeys,
                true
            );
            $apiCalls++;
            if ($added !== []) {
                $selected = $this->mergeQuestionLists($selected, $added, $promptKeys);
                $this->touchGenerationProgress(
                    $progressKey,
                    count($selected),
                    $requestedTotal,
                    'Generating questions… (' . count($selected) . ' / ' . $requestedTotal . ')'
                );
            }
        }

        $selected = $this->fillMixShortfalls(
            $course,
            $syllabus,
            $system,
            $mixes,
            $bankAvoid,
            $promptKeys,
            $selected,
            $apiCalls,
            $progressKey,
            $requestedTotal,
            $deadline
        );

        $selected = $this->dedupeBatchOnly($selected);

        return $this->capQuestionsToMixes($selected, $mixes);
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $selected
     * @param array<string, true> $promptKeys
     * @return list<array<string, mixed>>
     */
    private function fillMixShortfalls(
        array $course,
        string $syllabus,
        string $system,
        array $mixes,
        string $bankAvoid,
        array &$promptKeys,
        array $selected,
        int &$apiCalls,
        string $progressKey = '',
        int $requestedTotal = 0,
        float $deadline = 0.0,
        int $maxNewApiCalls = 0
    ): array {
        $requestedTotal = max($requestedTotal, $this->mixTotal($mixes));
        $callsAtStart = $apiCalls;

        $stalls = 0;
        while ($stalls < 10 && $apiCalls < self::GENERATE_MAX_API_CALLS && !$this->generationDeadlineReached($deadline)) {
            if ($maxNewApiCalls > 0 && ($apiCalls - $callsAtStart) >= $maxNewApiCalls) {
                break;
            }
            $shortfall = $this->mixShortfall($mixes, $selected);
            if ($shortfall === []) {
                break;
            }
            $madeProgress = false;
            foreach ($shortfall as $mix) {
                if ($this->generationDeadlineReached($deadline)) {
                    break 2;
                }
                if ($maxNewApiCalls > 0 && ($apiCalls - $callsAtStart) >= $maxNewApiCalls) {
                    break 2;
                }
                $label = (string) ($mix['difficulty'] ?? 'Medium');
                $need = (int) ($mix['count'] ?? 0);
                $emptyBatchStreak = 0;
                while ($need > 0 && $apiCalls < self::GENERATE_MAX_API_CALLS && $emptyBatchStreak < 4 && !$this->generationDeadlineReached($deadline)) {
                    if ($maxNewApiCalls > 0 && ($apiCalls - $callsAtStart) >= $maxNewApiCalls) {
                        break 3;
                    }
                    $chunk = min(6, $need);
                    $slice = [['difficulty' => $label, 'count' => $chunk]];
                    $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
                    $added = $this->fetchQuestionBatch(
                        $course,
                        $syllabus,
                        $system,
                        $slice,
                        $chunk,
                        $this->mixLinesText($slice),
                        $avoid,
                        $selected,
                        $promptKeys,
                        true
                    );
                    $apiCalls++;
                    if ($added === []) {
                        $emptyBatchStreak++;
                        continue;
                    }
                    $emptyBatchStreak = 0;
                    $selected = $this->mergeQuestionLists($selected, $added, $promptKeys);
                    $madeProgress = true;
                    $this->touchGenerationProgress(
                        $progressKey,
                        count($selected),
                        $requestedTotal,
                        'Generating questions… (' . count($selected) . ' / ' . $requestedTotal . ')'
                    );
                    $need = $this->shortfallForDifficulty($mixes, $selected, $label);
                }
            }
            if (!$madeProgress) {
                $stalls++;
            } else {
                $stalls = 0;
            }
        }

        foreach ($this->mixShortfall($mixes, $selected) as $mix) {
            if ($this->generationDeadlineReached($deadline)) {
                break;
            }
            if ($maxNewApiCalls > 0 && ($apiCalls - $callsAtStart) >= $maxNewApiCalls) {
                break;
            }
            $label = (string) ($mix['difficulty'] ?? 'Medium');
            $need = (int) ($mix['count'] ?? 0);
            $emptyStreak = 0;
            while ($need > 0 && $apiCalls < self::GENERATE_MAX_API_CALLS && $emptyStreak < 6 && !$this->generationDeadlineReached($deadline)) {
                if ($maxNewApiCalls > 0 && ($apiCalls - $callsAtStart) >= $maxNewApiCalls) {
                    break 2;
                }
                $slice = [['difficulty' => $label, 'count' => 1]];
                $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
                $added = $this->fetchQuestionBatch(
                    $course,
                    $syllabus,
                    $system,
                    $slice,
                    1,
                    $this->mixLinesText($slice),
                    $avoid,
                    $selected,
                    $promptKeys,
                    true
                );
                $apiCalls++;
                if ($added === []) {
                    $emptyStreak++;
                    continue;
                }
                $emptyStreak = 0;
                $selected = $this->mergeQuestionLists($selected, $added, $promptKeys);
                $this->touchGenerationProgress(
                    $progressKey,
                    count($selected),
                    $requestedTotal,
                    'Generating questions… (' . count($selected) . ' / ' . $requestedTotal . ')'
                );
                $need = $this->shortfallForDifficulty($mixes, $selected, $label);
            }
        }

        return $selected;
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @return list<list<array{difficulty:string,count:int}>>
     */
    private function chunkMixesByTotal(array $mixes, int $maxPerChunk): array
    {
        if ($maxPerChunk < 1) {
            return [$mixes];
        }
        $chunks = [];
        $current = [];
        $currentTotal = 0;
        foreach ($mixes as $mix) {
            $label = (string) ($mix['difficulty'] ?? 'Medium');
            $remaining = (int) ($mix['count'] ?? 0);
            while ($remaining > 0) {
                $room = $maxPerChunk - $currentTotal;
                if ($room <= 0 && $current !== []) {
                    $chunks[] = $current;
                    $current = [];
                    $currentTotal = 0;
                    $room = $maxPerChunk;
                }
                $take = min($remaining, $room);
                if ($take <= 0) {
                    break;
                }
                $current[] = ['difficulty' => $label, 'count' => $take];
                $currentTotal += $take;
                $remaining -= $take;
            }
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [$mixes];
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     */
    private function mixLinesText(array $mixes): string
    {
        $lines = [];
        foreach ($mixes as $mix) {
            $count = (int) ($mix['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $lines[] = '- ' . ($mix['difficulty'] ?? 'Medium') . ': ' . $count;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     */
    private function mixTotal(array $mixes): int
    {
        $total = 0;
        foreach ($mixes as $mix) {
            $total += (int) ($mix['count'] ?? 0);
        }

        return $total;
    }

    private function maxTokensForBatch(int $questionCount): int
    {
        $count = max(1, $questionCount);

        return min(16384, max(3072, $count * 520));
    }

    /**
     * @param list<array<string, mixed>> $selected
     */
    private function generationAvoidBlock(array $selected, string $bankAvoid): string
    {
        $recent = implode("\n", array_map(
            static fn(array $q): string => '- ' . trim((string) ($q['question'] ?? '')),
            array_slice($selected, -15)
        ));
        $parts = array_filter([trim($recent), trim($bankAvoid)]);

        return implode("\n", $parts);
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixesForRequest
     * @param list<array<string, mixed>> $selectedSoFar
     * @param array<string, true> $promptKeys
     * @return list<array<string, mixed>>
     */
    private function fetchQuestionBatch(
        array $course,
        string $syllabus,
        string $system,
        array $mixesForRequest,
        int $requestTotal,
        string $mixText,
        string $avoidBlock,
        array $selectedSoFar,
        array &$promptKeys,
        bool $allowExtra
    ): array {
        if ($requestTotal < 1) {
            return [];
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $prompt = $this->buildGeneratePrompt(
                $course,
                $syllabus,
                $mixesForRequest,
                $requestTotal,
                $mixText,
                $avoidBlock,
                $allowExtra
            );
            try {
                $raw = $this->openai->generateJson($system, $prompt, $this->maxTokensForBatch($requestTotal));
            } catch (\Throwable) {
                continue;
            }
            $valid = $this->dedupeBatchOnly($this->collectValidQuestions($raw['questions'] ?? $raw));
            $valid = $this->rejectKnownPrompts($valid, $promptKeys);
            $picked = $this->selectQuestionsForMixes($valid, $mixesForRequest, $selectedSoFar, $promptKeys);
            if ($picked !== []) {
                return $picked;
            }
        }

        return [];
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $selected
     */
    private function shortfallForDifficulty(array $mixes, array $selected, string $label): int
    {
        foreach ($this->mixShortfall($mixes, $selected) as $row) {
            if ((string) ($row['difficulty'] ?? '') === $label) {
                return (int) ($row['count'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @param list<array{difficulty:string,count:int}> $mixes
     * @return list<array<string, mixed>>
     */
    private function capQuestionsToMixes(array $questions, array $mixes): array
    {
        return $this->selectQuestionsForMixes($questions, $mixes, [], []);
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $selected
     * @return list<array{difficulty:string,count:int}>
     */
    private function mixShortfall(array $mixes, array $selected): array
    {
        $have = ['Easy' => 0, 'Medium' => 0, 'Hard' => 0];
        foreach ($selected as $question) {
            $difficulty = (string) ($question['difficulty'] ?? '');
            if (isset($have[$difficulty])) {
                $have[$difficulty]++;
            }
        }

        $shortfall = [];
        foreach ($mixes as $mix) {
            $label = (string) ($mix['difficulty'] ?? '');
            $need = (int) ($mix['count'] ?? 0) - (int) ($have[$label] ?? 0);
            if ($need > 0) {
                $shortfall[] = ['difficulty' => $label, 'count' => $need];
            }
        }

        return $shortfall;
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<array<string, mixed>> $added
     * @param array<string, true>|null $promptKeys
     * @return list<array<string, mixed>>
     */
    private function mergeQuestionLists(array $existing, array $added, ?array &$promptKeys = null): array
    {
        $seen = [];
        foreach ($existing as $question) {
            $key = $this->questionPromptKey($question);
            if ($key !== '') {
                $seen[$key] = true;
            }
        }
        foreach ($added as $question) {
            $key = $this->questionPromptKey($question);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($promptKeys !== null) {
                $promptKeys[$key] = true;
            }
            $existing[] = $question;
        }

        return $existing;
    }

    /**
     * @param list<array{difficulty:string,count:int}> $mixes
     * @param list<array<string, mixed>> $exclude
     * @param array<string, true> $forbiddenKeys
     * @return list<array<string, mixed>>
     */
    private function selectQuestionsForMixes(array $valid, array $mixes, array $exclude, array $forbiddenKeys = []): array
    {
        $excludeKeys = $forbiddenKeys;
        foreach ($exclude as $question) {
            $key = $this->questionPromptKey($question);
            if ($key !== '') {
                $excludeKeys[$key] = true;
            }
        }

        $buckets = ['Easy' => [], 'Medium' => [], 'Hard' => []];
        foreach ($valid as $question) {
            $key = $this->questionPromptKey($question);
            if ($key === '' || isset($excludeKeys[$key])) {
                continue;
            }
            $difficulty = (string) ($question['difficulty'] ?? 'Medium');
            if (!isset($buckets[$difficulty])) {
                continue;
            }
            $buckets[$difficulty][] = $question;
        }

        $out = [];
        foreach ($mixes as $mix) {
            $label = (string) ($mix['difficulty'] ?? '');
            $need = (int) ($mix['count'] ?? 0);
            if ($need <= 0 || !isset($buckets[$label])) {
                continue;
            }
            $taken = 0;
            foreach ($buckets[$label] as $question) {
                if ($taken >= $need) {
                    break;
                }
                $key = $this->questionPromptKey($question);
                if ($key === '' || isset($excludeKeys[$key])) {
                    continue;
                }
                $excludeKeys[$key] = true;
                $out[] = $question;
                $taken++;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $question
     */
    private function questionPromptKey(array $question): string
    {
        return SyllabusQuestionBankModel::normalizePromptKey((string) ($question['question'] ?? $question['prompt'] ?? ''));
    }

    /**
     * Keys for questions already in this generation draft (not the whole bank).
     *
     * @param list<array<string, mixed>> $questions
     * @return array<string, true>
     */
    private function promptKeysForSession(array $questions): array
    {
        $keys = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = $this->questionPromptKey($question);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function existingBankQuestionsAvoidBlock(string $courseCode): string
    {
        $courseCode = SyllabusQuestionBankModel::normalizeCourseCode($courseCode);
        if ($courseCode === '') {
            return '';
        }
        $lines = [];
        foreach ((new SyllabusQuestionBankModel())->listByCourseCode($courseCode, 40) as $question) {
            $text = trim((string) ($question['question'] ?? ''));
            if ($text !== '') {
                $lines[] = '- ' . $text;
            }
        }
        if ($lines === []) {
            return '';
        }

        return "Do not repeat or closely paraphrase questions already in the course question bank:\n" . implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @return list<array<string, mixed>>
     */
    private function dedupeBatchOnly(array $questions): array
    {
        $seen = [];
        $out = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = $this->questionPromptKey($question);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $question;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @param array<string, true> $knownKeys
     * @return list<array<string, mixed>>
     */
    private function rejectKnownPrompts(array $questions, array $knownKeys): array
    {
        $out = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = $this->questionPromptKey($question);
            if ($key === '' || isset($knownKeys[$key])) {
                continue;
            }
            $out[] = $question;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @param array<string, true> $knownKeys
     * @return list<array<string, mixed>>
     */
    private function dedupeWithinList(array $questions, array &$knownKeys): array
    {
        $out = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = $this->questionPromptKey($question);
            if ($key === '' || isset($knownKeys[$key])) {
                continue;
            }
            $knownKeys[$key] = true;
            $out[] = $question;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $questions
     * @param array<string, true> $promptKeys
     */
    private function registerQuestionKeys(array $questions, array &$promptKeys): void
    {
        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = $this->questionPromptKey($question);
            if ($key !== '') {
                $promptKeys[$key] = true;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectValidQuestions(mixed $raw): array
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
            $description = trim((string) ($item['description'] ?? $item['explanation'] ?? ''));
            $difficulty = ucfirst(strtolower(trim((string) ($item['difficulty'] ?? ''))));
            if (!in_array($difficulty, ['Easy', 'Medium', 'Hard'], true)) {
                $difficulty = 'Medium';
            }
            $out[] = [
                'module' => trim((string) ($item['module'] ?? '')),
                'difficulty' => $difficulty,
                'question' => $question,
                'options' => $options,
                'correctIndex' => $index,
                'explanation' => $description,
                'description' => $description,
            ];
        }

        return $out;
    }

}
