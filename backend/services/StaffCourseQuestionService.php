<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\SyllabusMcqTestModel;
use PMS\Models\SyllabusQuestionBankModel;

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
        $promptKeys = (new SyllabusQuestionBankModel())->existingPromptKeys($courseCode);
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

        $mixes = $this->parseMixes($body);
        $total = $this->mixTotal($mixes);
        $chunks = $this->chunkMixesByTotal($mixes, self::GENERATE_BATCH_SIZE);
        $batchTotal = count($chunks);
        $batchIndex = (int) ($body['batchIndex'] ?? 0);
        if ($batchIndex < 0 || $batchIndex >= $batchTotal) {
            throw new \InvalidArgumentException('Invalid generation batch.');
        }

        $progressKey = self::sanitizeProgressKey((string) ($body['progressKey'] ?? ''));
        $sessionId = trim((string) ($body['sessionId'] ?? ''));

        $ctx = StaffContext::resolve($user);
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $deptCode = (string) ($dept['code'] ?? '');
        $deptName = (string) ($dept['name'] ?? '');
        $deptShort = (string) ($dept['shortName'] ?? '');
        $allCourses = $this->seesAllCourses($user);

        $system = OpenAIService::cleanUtf8(
            'You write college examination questions. Use only the supplied official syllabus text. Return JSON only.'
        );

        if ($batchIndex === 0) {
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
        } else {
            if ($sessionId === '') {
                throw new \InvalidArgumentException('Missing session for the next generation batch.');
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
            $batchTotal = (int) ($draft['genState']['batchTotal'] ?? $batchTotal);
        }

        $courseCode = (string) ($course['code'] ?? '');
        /** @var array<string, true> $promptKeys */
        $promptKeys = (new SyllabusQuestionBankModel())->existingPromptKeys($courseCode);
        $draft = $_SESSION['staff_course_draft'] ?? null;
        $selected = is_array($draft['questions'] ?? null) ? $draft['questions'] : [];
        foreach ($selected as $question) {
            if (!is_array($question)) {
                continue;
            }
            $key = SyllabusQuestionBankModel::normalizePromptKey((string) ($question['question'] ?? ''));
            if ($key !== '') {
                $promptKeys[$key] = true;
            }
        }

        $bankAvoid = $this->existingBankQuestionsAvoidBlock($courseCode);
        $chunk = $chunks[$batchIndex];
        $chunkTotal = $this->mixTotal($chunk);
        $mixText = $this->mixLinesText($chunk);
        $avoid = $this->generationAvoidBlock($selected, $bankAvoid);
        $batchQuestions = $this->fetchQuestionBatch(
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
        if ($batchQuestions !== []) {
            $selected = $this->mergeQuestionLists($selected, $batchQuestions, $promptKeys);
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

        if (!$isLastBatch) {
            $_SESSION['staff_course_draft']['questions'] = $selected;
            $_SESSION['staff_course_draft']['genState']['nextBatchIndex'] = $batchIndex + 1;
            $_SESSION['staff_course_draft']['createdAt'] = time();

            return [
                'sessionId' => $sessionId,
                'courseCode' => $courseCode,
                'courseTitle' => (string) ($course['title'] ?? ''),
                'mixes' => $mixes,
                'requested' => $total,
                'questions' => $selected,
                'batchQuestions' => $batchQuestions,
                'batchIndex' => $batchIndex,
                'batchTotal' => $batchTotal,
                'batchComplete' => false,
            ];
        }

        $topUpCalls = 0;
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
            $deadline
        );
        $selected = $this->dedupeBatchOnly($selected);
        $selected = $this->capQuestionsToMixes($selected, $mixes);
        $selected = $this->assignDraftIndexes($selected);
        $got = count($selected);

        unset($_SESSION['staff_course_draft']['generating'], $_SESSION['staff_course_draft']['genState']);
        $_SESSION['staff_course_draft']['questions'] = $selected;
        $_SESSION['staff_course_draft']['createdAt'] = time();

        if ($selected === []) {
            unset($_SESSION['staff_course_draft']);
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

        return [
            'sessionId' => $sessionId,
            'courseCode' => $courseCode,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'mixes' => $mixes,
            'requested' => $total,
            'questions' => $selected,
            'batchQuestions' => $batchQuestions,
            'batchIndex' => $batchIndex,
            'batchTotal' => $batchTotal,
            'batchComplete' => true,
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
        if (empty($draft['generating'])) {
            throw new \InvalidArgumentException('This generation session is no longer active. Generate questions again.');
        }
        $expected = (int) ($draft['genState']['nextBatchIndex'] ?? -1);
        if ($expected !== $batchIndex) {
            throw new \InvalidArgumentException('Generation batches must be run in order.');
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
        if (!empty($draft['generating'])) {
            throw new \InvalidArgumentException('Wait until all generation batches finish, then select questions to add.');
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

        $remaining = [];
        foreach ($source as $index => $question) {
            if (!is_array($question) || in_array((int) $index, $indexes, true)) {
                continue;
            }
            $question['draftIndex'] = count($remaining);
            $remaining[] = $question;
        }
        $draft['questions'] = $remaining;
        $draft['createdAt'] = time();
        $_SESSION['staff_course_draft'] = $draft;

        return [
            'sessionId' => (string) ($draft['id'] ?? ''),
            'courseCode' => (string) ($bank['courseCode'] ?? $course['code'] ?? ''),
            'courseTitle' => (string) ($bank['courseTitle'] ?? $course['title'] ?? ''),
            'added' => (int) ($bank['added'] ?? 0),
            'skipped' => (int) ($bank['skipped'] ?? 0),
            'questions' => $remaining,
            'mixes' => $draft['mixes'] ?? [],
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
            try {
                $pdf = AesSyllabusCipher::fetchPdf($encid);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException($e->getMessage(), 502);
            }
            $text = AesSyllabusCipher::extractTextForEncid($encid, $pdf);
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
            'phase' => 'generating',
            'message' => $message,
            'generated' => $generated,
            'requested' => $requested,
            'percent' => $requested > 0 ? min(99, (int) round(($generated / $requested) * 100)) : 0,
            'done' => false,
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
        $draft = $_SESSION['staff_course_draft'] ?? null;
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        $sessionId = trim($sessionId);
        if (!is_array($draft) || $sessionId === '' || (string) ($draft['id'] ?? '') !== $sessionId) {
            throw new \InvalidArgumentException('Generate questions first, then select the ones to add.');
        }
        if ((string) ($draft['userId'] ?? '') !== $userId) {
            throw new \InvalidArgumentException('Generate questions first, then select the ones to add.');
        }
        if ((time() - (int) ($draft['createdAt'] ?? 0)) > 7200) {
            unset($_SESSION['staff_course_draft']);
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
        $syllabus = OpenAIService::cleanUtf8($syllabus);
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
        float $deadline = 0.0
    ): array {
        $requestedTotal = max($requestedTotal, $this->mixTotal($mixes));

        $stalls = 0;
        while ($stalls < 10 && $apiCalls < self::GENERATE_MAX_API_CALLS && !$this->generationDeadlineReached($deadline)) {
            $shortfall = $this->mixShortfall($mixes, $selected);
            if ($shortfall === []) {
                break;
            }
            $madeProgress = false;
            foreach ($shortfall as $mix) {
                if ($this->generationDeadlineReached($deadline)) {
                    break 2;
                }
                $label = (string) ($mix['difficulty'] ?? 'Medium');
                $need = (int) ($mix['count'] ?? 0);
                $emptyBatchStreak = 0;
                while ($need > 0 && $apiCalls < self::GENERATE_MAX_API_CALLS && $emptyBatchStreak < 4 && !$this->generationDeadlineReached($deadline)) {
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
            $label = (string) ($mix['difficulty'] ?? 'Medium');
            $need = (int) ($mix['count'] ?? 0);
            $emptyStreak = 0;
            while ($need > 0 && $apiCalls < self::GENERATE_MAX_API_CALLS && $emptyStreak < 6 && !$this->generationDeadlineReached($deadline)) {
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
