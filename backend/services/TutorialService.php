<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\DepartmentModel;
use PMS\Models\StudentExerciseAttemptModel;
use PMS\Models\StudentModel;
use PMS\Models\StudentTutorialModuleProgressModel;
use PMS\Models\StudentTutorialProgressModel;
use PMS\Models\TutorialCategoryModel;
use PMS\Models\TutorialExerciseModel;
use PMS\Models\TutorialLessonQuestionAttemptModel;
use PMS\Models\TutorialLessonQuestionModel;
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleActivitySubmissionModel;
use PMS\Models\TutorialModuleAssessmentModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialTestCaseModel;
use PMS\Models\UserModel;

/**
 * Tutorial catalog, authoring, and student visibility.
 * Programming practice is graded by TutorialsCodeExecutionService, which sends
 * source to an isolated runner. This class does not execute student code in PHP.
 */
final class TutorialService
{
    private const MIN_TIME_LIMIT_MS = 200;
    private const MAX_TIME_LIMIT_MS = 15000;
    private const MIN_MEMORY_LIMIT_KB = 2048;
    private const MAX_MEMORY_LIMIT_KB = 512000;
    private const MAX_SOURCE_CHARS = 65536;

    /** @var list<string> */
    private const HTML_TAGS = ['p', 'br', 'strong', 'em', 'u', 's', 'ol', 'ul', 'li', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code', 'a', 'img', 'span', 'hr'];

    /** @var list<string> */
    private const CODE_LANGUAGES = ['auto', 'text', 'python', 'javascript', 'typescript', 'java', 'c', 'cpp', 'csharp', 'php', 'sql', 'html', 'css', 'json', 'bash', 'go'];

    /** @var list<string> */
    private const DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math'];

    public function __construct(
        private ?TutorialCategoryModel $categories = null,
        private ?TutorialModel $tutorials = null,
        private ?TutorialModuleModel $modules = null,
        private ?TutorialExerciseModel $exercises = null,
        private ?TutorialTestCaseModel $testCases = null,
        private ?StudentModel $students = null,
        private ?DepartmentModel $departments = null,
        private ?StudentTutorialProgressModel $progress = null,
        private ?StudentTutorialModuleProgressModel $moduleProgress = null,
        private ?StudentExerciseAttemptModel $attempts = null,
        private ?TutorialsCodeExecutionService $executor = null,
        private ?UserModel $users = null,
    ) {
        $this->categories = $categories ?? new TutorialCategoryModel();
        $this->tutorials = $tutorials ?? new TutorialModel();
        $this->modules = $modules ?? new TutorialModuleModel();
        $this->exercises = $exercises ?? new TutorialExerciseModel();
        $this->testCases = $testCases ?? new TutorialTestCaseModel();
        $this->students = $students ?? new StudentModel();
        $this->departments = $departments ?? new DepartmentModel();
        $this->progress = $progress ?? new StudentTutorialProgressModel();
        $this->moduleProgress = $moduleProgress ?? new StudentTutorialModuleProgressModel();
        $this->attempts = $attempts ?? new StudentExerciseAttemptModel();
        $this->executor = $executor ?? new TutorialsCodeExecutionService();
        $this->users = $users ?? new UserModel();
    }

    public function useCodeExecution(TutorialsCodeExecutionService $executor): void
    {
        $this->executor = $executor;
    }

    /**
     * Passout year is the end year of a batch range such as MCA2025-27-S3 → 2027.
     */
    public static function passoutYear(string $classBatch): string
    {
        $raw = trim($classBatch);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/((?:19|20)\d{2})\s*[-–—−]\s*((?:19|20)\d{2})/u', $raw, $match) === 1) {
            return $match[2];
        }
        if (preg_match('/((?:19|20)\d{2})\s*[-–—−]\s*(\d{2})(?!\d)/u', $raw, $match) === 1) {
            $start = (int) $match[1];
            $end = (intdiv($start, 100) * 100) + (int) $match[2];
            if ($end >= $start) {
                return (string) $end;
            }
        }
        if (preg_match('/(?:19|20)\d{2}/', $raw, $match) === 1) {
            return $match[0];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listCategories(array $user): array
    {
        $this->assertReader($user);
        $out = [];
        foreach ($this->categories->listAll() as $row) {
            if ((string) ($row['status'] ?? '') !== 'active') {
                continue;
            }
            $out[] = $this->publicCategory($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCategory(array $user, array $input): array
    {
        $this->assertAuthor($user);
        $row = $this->categories->create([
            'name' => (string) ($input['name'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'status' => 'active',
            'createdBy' => $this->userId($user),
        ]);

        return $this->publicCategory($row);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCategory(array $user, string $id, array $input): array
    {
        $this->assertAuthor($user);
        $existing = $this->categories->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('Category not found.', 404);
        }
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string) $input['status']))
            : (string) ($existing['status'] ?? 'active');
        $row = $this->categories->updateCategory($id, [
            'name' => (string) ($input['name'] ?? $existing['name'] ?? ''),
            'description' => (string) ($input['description'] ?? $existing['description'] ?? ''),
            'status' => $status,
            'createdBy' => (string) ($existing['createdBy'] ?? ''),
        ]);
        if ($row === null) {
            throw new \RuntimeException('Category not found.', 404);
        }

        return $this->publicCategory($row);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{deleted: bool, deactivated: bool}
     */
    public function removeCategory(array $user, string $id): array
    {
        $this->assertAuthor($user);
        $existing = $this->categories->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('Category not found.', 404);
        }
        if ($this->tutorials->listByCategory($id) !== []) {
            $this->categories->updateCategory($id, [
                'name' => (string) ($existing['name'] ?? ''),
                'description' => (string) ($existing['description'] ?? ''),
                'status' => 'inactive',
                'createdBy' => (string) ($existing['createdBy'] ?? ''),
            ]);

            return ['deleted' => false, 'deactivated' => true];
        }
        $this->categories->delete($id);

        return ['deleted' => true, 'deactivated' => false];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listForStudent(array $user, array $query = []): array
    {
        $student = $this->requireStudent($user);
        $studentId = (string) ($student['_id'] ?? '');
        $departmentId = (string) ($student['departmentId'] ?? '');
        $year = self::passoutYear((string) ($student['classBatch'] ?? ''));
        $search = strtolower(trim((string) ($query['search'] ?? '')));
        if (strlen($search) > 200) {
            $search = substr($search, 0, 200);
        }
        $categoryId = trim((string) ($query['category'] ?? ''));
        $progressByTutorial = [];
        foreach ($this->progress->findAll(['studentId' => $studentId], 500) as $row) {
            $progressByTutorial[(string) ($row['tutorialId'] ?? '')] = $row;
        }
        $out = [];
        foreach ($this->tutorials->listByStatus('published') as $row) {
            if (!$this->visibleToStudent($row, $departmentId, $year)) {
                continue;
            }
            $view = $this->studentTutorial($row, false);
            if ($categoryId !== '' && (string) (($view['category']['id'] ?? '')) !== $categoryId) {
                continue;
            }
            if ($search !== '') {
                $hay = strtolower(($view['title'] ?? '') . ' ' . ($view['description'] ?? '') . ' ' . ($view['category']['name'] ?? '') . ' ' . ($view['topic'] ?? ''));
                if (!str_contains($hay, $search)) {
                    continue;
                }
            }
            $out[] = $view;
        }
        $currentModules = $this->modules->currentModuleIds(array_map(
            static fn (array $view): string => (string) ($view['id'] ?? ''),
            $out
        ));
        $completedByTutorial = [];
        foreach ($this->moduleProgress->findAll(['studentId' => $studentId], 2000) as $row) {
            $key = (string) ($row['tutorialId'] ?? '');
            $moduleId = (string) ($row['moduleId'] ?? '');
            if ($key !== '' && $moduleId !== '' && isset($currentModules[$key][$moduleId]) && !isset($completedByTutorial[$key][$moduleId])) {
                $completedByTutorial[$key][$moduleId] = true;
            }
        }
        foreach ($out as $index => $view) {
            $tutorialId = (string) ($view['id'] ?? '');
            $stored = $progressByTutorial[$tutorialId] ?? null;
            $total = (int) ($view['moduleCount'] ?? 0);
            $completed = count($completedByTutorial[$tutorialId] ?? []);
            $percent = $total === 0 ? 0 : (int) round(($completed / $total) * 100);
            $status = 'NOT_STARTED';
            if (is_array($stored)) {
                $status = ($stored['completedAt'] ?? null) && $total > 0 && $completed === $total ? 'COMPLETED' : 'IN_PROGRESS';
            }
            $view['progress'] = [
                'status' => $status,
                'progressPercent' => $status === 'COMPLETED' ? 100 : $percent,
                'completedModules' => $completed,
                'totalModules' => $total,
                'lastVisitedModuleId' => is_array($stored) ? ($stored['lastVisitedModuleId'] ?? null) : null,
                'completed' => $status === 'COMPLETED',
            ];
            $out[$index] = $view;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function showForStudent(array $user, string $id): array
    {
        $row = $this->publishedTutorialForStudent($user, $id);

        return $this->studentTutorial($row, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function moduleForStudent(array $user, string $tutorialId, string $moduleId): array
    {
        $this->publishedTutorialForStudent($user, $tutorialId);
        $module = $this->requireModule($moduleId);
        if ((string) ($module['tutorialId'] ?? '') !== $tutorialId) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }
        $studentId = $this->studentProfileId($user);
        $this->ensureStarted($studentId, $tutorialId, $moduleId);

        return $this->studentModule($module, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function exerciseForStudent(array $user, string $exerciseId): array
    {
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $this->publishedTutorialForStudent($user, (string) ($module['tutorialId'] ?? ''));

        return $this->studentExercise($exercise);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function progressForStudent(array $user, string $tutorialId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);

        return $this->progressView($this->studentProfileId($user), $tutorial);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function startProgress(array $user, string $tutorialId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $studentId = $this->studentProfileId($user);
        $existing = $this->progress->findFor($studentId, $tutorialId);
        if ($existing === null || (string) ($existing['status'] ?? '') !== 'COMPLETED') {
            $this->progress->saveFor($studentId, $tutorialId, [
                'startedAt' => (string) ($existing['startedAt'] ?? \PMS\Utils\DocumentHelper::now()),
                'completedAt' => $existing['completedAt'] ?? null,
                'lastVisitedModuleId' => $existing['lastVisitedModuleId'] ?? null,
                'status' => 'IN_PROGRESS',
                'progressPercent' => $this->calculatedPercent($studentId, $tutorialId),
            ]);
        }

        return $this->progressView($studentId, $tutorial);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function markModuleComplete(array $user, string $tutorialId, string $moduleId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $studentId = $this->studentProfileId($user);
        $this->moduleProgress->markComplete($studentId, $tutorialId, $moduleId);
        $this->ensureStarted($studentId, $tutorialId, $moduleId);

        return $this->progressView($studentId, $tutorial);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function unmarkModuleComplete(array $user, string $tutorialId, string $moduleId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $studentId = $this->studentProfileId($user);
        $this->moduleProgress->clearComplete($studentId, $moduleId);
        $this->clearTutorialCompletionFlag($studentId, $tutorialId);

        return $this->progressView($studentId, $tutorial);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function completeTutorial(array $user, string $tutorialId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $studentId = $this->studentProfileId($user);
        $modules = $this->modules->listByTutorial($tutorialId);
        if ($modules === []) {
            throw new \InvalidArgumentException('This tutorial has no modules to complete.');
        }
        $done = $this->completedModuleIds($studentId, $tutorialId, $modules);
        if (count($done) !== count($modules)) {
            throw new \InvalidArgumentException('Complete every module before completing the tutorial.');
        }
        $existing = $this->progress->findFor($studentId, $tutorialId);
        $this->progress->saveFor($studentId, $tutorialId, [
            'startedAt' => (string) ($existing['startedAt'] ?? \PMS\Utils\DocumentHelper::now()),
            'completedAt' => (string) ($existing['completedAt'] ?? \PMS\Utils\DocumentHelper::now()),
            'lastVisitedModuleId' => $existing['lastVisitedModuleId'] ?? null,
            'status' => 'COMPLETED',
            'progressPercent' => 100,
        ]);

        return $this->progressView($studentId, $tutorial);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function uncompleteTutorial(array $user, string $tutorialId): array
    {
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $studentId = $this->studentProfileId($user);
        $existing = $this->progress->findFor($studentId, $tutorialId);
        if (!is_array($existing) || !($existing['completedAt'] ?? null)) {
            throw new \InvalidArgumentException('This tutorial is not marked complete.');
        }
        $this->clearTutorialCompletionFlag($studentId, $tutorialId);

        return $this->progressView($studentId, $tutorial);
    }

    /**
     * Clear the course-level COMPLETED flag without removing module progress.
     */
    private function clearTutorialCompletionFlag(string $studentId, string $tutorialId): void
    {
        $existing = $this->progress->findFor($studentId, $tutorialId);
        if (!is_array($existing) || !($existing['_id'] ?? null)) {
            return;
        }
        if (!($existing['completedAt'] ?? null) && (string) ($existing['status'] ?? '') !== 'COMPLETED') {
            return;
        }
        $percent = $this->calculatedPercent($studentId, $tutorialId);
        $this->progress->update((string) $existing['_id'], [
            'completedAt' => null,
            'status' => 'IN_PROGRESS',
            'progressPercent' => $percent,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveAttempt(array $user, string $exerciseId, array $input): array
    {
        unset($input['studentId'], $input['departmentId'], $input['passingYear']);
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $tutorialId = (string) ($module['tutorialId'] ?? '');
        $this->publishedTutorialForStudent($user, $tutorialId);
        $source = (string) ($input['sourceCode'] ?? '');
        if (trim($source) === '') {
            throw new \InvalidArgumentException('Source code is required.');
        }
        if (strlen($source) > self::MAX_SOURCE_CHARS) {
            throw new \InvalidArgumentException('Source code is too long.');
        }
        $language = TutorialExerciseModel::normalizeLanguage((string) ($input['language'] ?? ''));
        if ($language === '' || $language !== (string) ($exercise['language'] ?? '')) {
            throw new \InvalidArgumentException('Use the language configured for this exercise.');
        }
        $studentId = $this->studentProfileId($user);
        $row = $this->attempts->record([
            'studentId' => $studentId,
            'tutorialId' => $tutorialId,
            'moduleId' => (string) ($module['_id'] ?? ''),
            'exerciseId' => $exerciseId,
            'language' => $language,
            'sourceCode' => $source,
        ]);

        return [
            'attemptId' => (string) ($row['_id'] ?? ''),
            'status' => 'ATTEMPTED',
            'submittedAt' => (string) ($row['submittedAt'] ?? ''),
            'message' => 'Your attempt has been saved.',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listAttempts(array $user, string $exerciseId): array
    {
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $this->publishedTutorialForStudent($user, (string) ($module['tutorialId'] ?? ''));
        $studentId = $this->studentProfileId($user);
        $out = [];
        foreach ($this->attempts->listForStudentExercise($studentId, $exerciseId, 20) as $row) {
            if ((string) ($row['studentId'] ?? '') !== $studentId) {
                continue;
            }
            $status = (string) ($row['status'] ?? 'ATTEMPTED');
            if (!in_array($status, ['ATTEMPTED', 'PASSED', 'FAILED'], true)) {
                $status = 'ATTEMPTED';
            }
            $out[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'language' => (string) ($row['language'] ?? ''),
                'sourceCode' => (string) ($row['sourceCode'] ?? ''),
                'status' => $status,
                'passed' => ($row['passed'] ?? false) === true,
                'testsPassed' => isset($row['testsPassed']) ? (int) $row['testsPassed'] : null,
                'testsFailed' => isset($row['testsFailed']) ? (int) $row['testsFailed'] : null,
                'testsTotal' => isset($row['testsTotal']) ? (int) $row['testsTotal'] : null,
                'submittedAt' => (string) ($row['submittedAt'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Execute the student's source once and return the runner's stdout and stderr.
     * Does not grade and does not persist a score.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function runExercise(array $user, string $exerciseId, array $input): array
    {
        [$exercise, $language, $source] = $this->practiceSource($user, $exerciseId, $input);
        if (array_key_exists('stdin', $input)) {
            $stdin = (string) $input['stdin'];
        } else {
            $stdin = '';
            foreach ($this->testCases->listByExercise($exerciseId) as $case) {
                if (($case['sample'] ?? false) === true) {
                    $stdin = (string) ($case['stdin'] ?? '');
                    break;
                }
            }
        }
        if (strlen($stdin) > 8000) {
            throw new \InvalidArgumentException('Input is too long.');
        }
        $ran = $this->executor->run($language, $source, $stdin, (int) ($exercise['timeLimitMs'] ?? 5000));

        return [
            'ok' => ($ran['ok'] ?? false) === true,
            'status' => (string) ($ran['status'] ?? ''),
            'stdout' => (string) ($ran['stdout'] ?? ''),
            'stderr' => (string) ($ran['stderr'] ?? ''),
            'timedOut' => ($ran['timedOut'] ?? false) === true,
            'durationMs' => (int) ($ran['durationMs'] ?? 0),
        ];
    }

    /**
     * Grade the source against stored test cases. Scores are computed here.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submitExercise(array $user, string $exerciseId, array $input): array
    {
        unset($input['passed'], $input['testsPassed'], $input['testsTotal'], $input['testsFailed'], $input['results'], $input['status'], $input['score']);
        [$exercise, $language, $source, $module, $tutorialId] = $this->practiceSource($user, $exerciseId, $input);
        $cases = $this->testCases->listByExercise($exerciseId);
        $graded = $this->executor->grade($language, $source, $cases, (int) ($exercise['timeLimitMs'] ?? 5000));
        $studentId = $this->studentProfileId($user);
        $row = $this->attempts->recordGraded([
            'studentId' => $studentId,
            'tutorialId' => $tutorialId,
            'moduleId' => (string) ($module['_id'] ?? ''),
            'exerciseId' => $exerciseId,
            'language' => $language,
            'sourceCode' => $source,
            'durationMs' => (int) ($graded['durationMs'] ?? 0),
        ], ($graded['passed'] ?? false) === true, (int) ($graded['testsPassed'] ?? 0), (int) ($graded['testsTotal'] ?? 0));
        $this->maybeCompleteReadyModules($user, $tutorialId);

        return [
            'attemptId' => (string) ($row['_id'] ?? ''),
            'status' => (string) ($row['status'] ?? 'FAILED'),
            'passed' => ($row['passed'] ?? false) === true,
            'testsTotal' => (int) ($row['testsTotal'] ?? 0),
            'testsPassed' => (int) ($row['testsPassed'] ?? 0),
            'testsFailed' => (int) ($row['testsFailed'] ?? 0),
            'durationMs' => (int) ($graded['durationMs'] ?? 0),
            'results' => $graded['results'] ?? [],
            'submittedAt' => (string) ($row['submittedAt'] ?? ''),
        ];
    }

    /**
     * Explicitly complete a lesson that has no lesson MCQs.
     * Opening a lesson does not call this.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function markLessonReviewed(array $user, string $tutorialId, string $moduleId, string $lessonId): array
    {
        $lessonId = trim($lessonId);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonId) !== 1) {
            throw new \InvalidArgumentException('Lesson not found.');
        }
        $this->publishedTutorialForStudent($user, $tutorialId);
        $module = $this->moduleOnTutorial($tutorialId, $moduleId);
        $lessons = $this->buildStudentLessons($this->lessonContentString($module['content'] ?? ''), (string) ($module['title'] ?? 'Lesson'));
        $known = false;
        foreach ($lessons as $lesson) {
            if ((string) ($lesson['id'] ?? '') === $lessonId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            throw new \InvalidArgumentException('Lesson not found.');
        }
        foreach ((new TutorialLessonQuestionModel())->listByModule($moduleId) as $question) {
            if ((string) ($question['lessonBlockId'] ?? '') === $lessonId) {
                throw new \InvalidArgumentException('Answer the practice questions for this lesson.');
            }
        }
        $studentId = $this->studentProfileId($user);
        $this->ensureStarted($studentId, $tutorialId, $moduleId);
        $stored = $this->progress->findFor($studentId, $tutorialId);
        $ids = [];
        foreach ((array) ($stored['completedLessonIds'] ?? []) as $existing) {
            $existing = (string) $existing;
            if ($existing !== '') {
                $ids[$existing] = $existing;
            }
        }
        $ids[$moduleId . ':' . $lessonId] = $moduleId . ':' . $lessonId;
        if (is_array($stored) && ($stored['_id'] ?? '') !== '') {
            $this->progress->update((string) $stored['_id'], ['completedLessonIds' => array_values($ids)]);
        }
        $this->maybeCompleteReadyModules($user, $tutorialId);

        return $this->progressForStudent($user, $tutorialId);
    }

    /**
     * Remove an explicit lesson-complete mark (lessons without MCQs only).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function unmarkLessonReviewed(array $user, string $tutorialId, string $moduleId, string $lessonId): array
    {
        $lessonId = trim($lessonId);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonId) !== 1) {
            throw new \InvalidArgumentException('Lesson not found.');
        }
        $this->publishedTutorialForStudent($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $studentId = $this->studentProfileId($user);
        $stored = $this->progress->findFor($studentId, $tutorialId);
        if (!is_array($stored) || !($stored['_id'] ?? null)) {
            return $this->progressForStudent($user, $tutorialId);
        }
        $key = $moduleId . ':' . $lessonId;
        $ids = [];
        foreach ((array) ($stored['completedLessonIds'] ?? []) as $existing) {
            $existing = (string) $existing;
            if ($existing !== '' && $existing !== $key) {
                $ids[$existing] = $existing;
            }
        }
        $this->progress->update((string) $stored['_id'], ['completedLessonIds' => array_values($ids)]);
        $this->clearTutorialCompletionFlag($studentId, $tutorialId);

        return $this->progressForStudent($user, $tutorialId);
    }

    /**
     * Lesson text used to create MCQs for a course that does not have them yet.
     *
     * @param array<string, mixed> $user
     * @return array<string, string>|null
     */
    public function lessonPracticeSource(array $user, string $tutorialId, string $moduleId, string $lessonId): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonId) !== 1) {
            return null;
        }
        $tutorial = $this->publishedTutorialForStudent($user, $tutorialId);
        $module = $this->moduleOnTutorial($tutorialId, $moduleId);
        $lessons = $this->buildStudentLessons($this->lessonContentString($module['content'] ?? ''), (string) ($module['title'] ?? 'Lesson'));
        foreach ($lessons as $lesson) {
            if ((string) ($lesson['id'] ?? '') !== $lessonId) {
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($lesson['html'] ?? ''))) ?? '');

            return [
                'tutorialId' => $tutorialId,
                'moduleId' => $moduleId,
                'lessonBlockId' => $lessonId,
                'lessonTitle' => (string) ($lesson['title'] ?? 'Lesson'),
                'lessonText' => mb_substr($text, 0, 6000),
                'topic' => (string) ($tutorial['topic'] ?? ''),
                'courseTitle' => (string) ($tutorial['title'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Lesson MCQs for the selected lesson. Correct answers are omitted.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function lessonPracticeForStudent(array $user, string $tutorialId, string $moduleId, string $lessonId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $lessonId) !== 1) {
            throw new \InvalidArgumentException('Lesson not found.');
        }
        $this->publishedTutorialForStudent($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $studentId = $this->studentProfileId($user);
        $correct = (new TutorialLessonQuestionAttemptModel())->correctQuestionIds($studentId, $tutorialId);
        $questions = [];
        foreach ((new TutorialLessonQuestionModel())->listByModule($moduleId) as $question) {
            if ((string) ($question['lessonBlockId'] ?? '') !== $lessonId) {
                continue;
            }
            $id = (string) ($question['_id'] ?? '');
            $questions[] = [
                'id' => $id,
                'question' => (string) ($question['question'] ?? ''),
                'options' => array_values((array) ($question['options'] ?? [])),
                'difficulty' => (string) ($question['difficulty'] ?? 'beginner'),
                'answeredCorrectly' => isset($correct[$id]),
            ];
        }

        return [
            'lessonBlockId' => $lessonId,
            'questions' => $questions,
            'questionCount' => count($questions),
        ];
    }

    /**
     * Saved lesson MCQs for staff review, including the correct option and explanation.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function lessonQuestionsForStaff(array $user, string $tutorialId, string $moduleId): array
    {
        $this->requireViewableTutorial($user, $tutorialId);
        $module = $this->moduleOnTutorial($tutorialId, $moduleId);
        $lessons = $this->buildStudentLessons(
            $this->lessonContentString($module['content'] ?? ''),
            (string) ($module['title'] ?? 'Lesson')
        );
        $grouped = [];
        foreach ($lessons as $lesson) {
            $id = (string) ($lesson['id'] ?? '');
            $grouped[$id] = [
                'id' => $id,
                'title' => (string) ($lesson['title'] ?? 'Lesson'),
                'questions' => [],
            ];
        }
        $count = 0;
        foreach ((new TutorialLessonQuestionModel())->listByModule($moduleId) as $question) {
            $lessonId = (string) ($question['lessonBlockId'] ?? '');
            if (!isset($grouped[$lessonId])) {
                $grouped[$lessonId] = [
                    'id' => $lessonId,
                    'title' => 'Other questions',
                    'questions' => [],
                ];
            }
            $grouped[$lessonId]['questions'][] = [
                'id' => (string) ($question['_id'] ?? ''),
                'question' => (string) ($question['question'] ?? ''),
                'options' => array_values((array) ($question['options'] ?? [])),
                'correctIndex' => (int) ($question['correctIndex'] ?? -1),
                'explanation' => (string) ($question['explanation'] ?? ''),
                'difficulty' => (string) ($question['difficulty'] ?? 'beginner'),
            ];
            $count++;
        }

        return [
            'moduleId' => $moduleId,
            'lessons' => array_values($grouped),
            'questionCount' => $count,
        ];
    }

    /**
     * Score one selected option. The client cannot supply the result.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function checkLessonAnswer(array $user, string $questionId, array $input): array
    {
        unset($input['correct'], $input['score'], $input['correctIndex'], $input['explanation']);
        if (!array_key_exists('selectedIndex', $input) || !is_int($input['selectedIndex']) && !is_numeric($input['selectedIndex'])) {
            throw new \InvalidArgumentException('Choose an answer.');
        }
        $selected = (int) $input['selectedIndex'];
        if ($selected < 0 || $selected > 3) {
            throw new \InvalidArgumentException('Choose an answer.');
        }
        $question = (new TutorialLessonQuestionModel())->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException('Question not found.', 404);
        }
        $tutorialId = (string) ($question['tutorialId'] ?? '');
        $this->publishedTutorialForStudent($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, (string) ($question['moduleId'] ?? ''));
        $correctIndex = (int) ($question['correctIndex'] ?? -1);
        $correct = $selected === $correctIndex;
        $studentId = $this->studentProfileId($user);
        $attempts = new TutorialLessonQuestionAttemptModel();
        $attempts->record($studentId, $questionId, $tutorialId, $selected, $correct);
        $this->maybeCompleteReadyModules($user, $tutorialId);

        return [
            'questionId' => $questionId,
            'selectedIndex' => $selected,
            'correct' => $correct,
            'correctIndex' => $correctIndex,
            'explanation' => (string) ($question['explanation'] ?? ''),
            'attempts' => $attempts->countForQuestion($studentId, $questionId),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listManaged(array $user): array
    {
        $this->assertAuthor($user);
        $userId = $this->userId($user);
        $byId = [];
        foreach ($this->tutorials->listByStatus('published') as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        foreach ($this->tutorials->listByCreator($userId) as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }
        if ($this->canManageCampusTutorials($user)) {
            foreach ($this->tutorials->listByStatus('unpublished') as $row) {
                $id = (string) ($row['_id'] ?? '');
                if ($id !== '') {
                    $byId[$id] = $row;
                }
            }
        }
        $rows = array_values($byId);
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });
        $out = [];
        foreach ($rows as $row) {
            if (!$this->canViewManagedTutorial($user, $row)) {
                continue;
            }
            $view = $this->managedTutorial($row, false, $user);
            $view['updatedAt'] = (string) ($row['updatedAt'] ?? '');
            $view['moduleCount'] = $this->modules->countForTutorial((string) ($row['_id'] ?? ''));
            $view['exerciseCount'] = $this->exerciseCount((string) ($row['_id'] ?? ''));
            $out[] = $view;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function showManaged(array $user, string $id): array
    {
        $row = $this->requireViewableTutorial($user, $id);

        return $this->managedTutorial($row, true, $user);
    }

    /**
     * Similar published (and own draft) tutorials for duplicate-topic awareness.
     *
     * @param array<string, mixed> $user
     * @return array{matches: list<array<string, mixed>>, hasMatches: bool}
     */
    public function findSimilarTutorials(array $user, string $topicOrTitle, ?string $excludeTutorialId = null): array
    {
        $this->assertAuthor($user);
        $needle = trim($topicOrTitle);
        if ($needle === '') {
            return ['matches' => [], 'hasMatches' => false];
        }
        $userId = $this->userId($user);
        $excludeTutorialId = $excludeTutorialId !== null && $excludeTutorialId !== '' ? $excludeTutorialId : null;
        $candidates = [];
        foreach ($this->tutorials->listByStatus('published') as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id !== '') {
                $candidates[$id] = $row;
            }
        }
        foreach ($this->tutorials->listByCreator($userId) as $row) {
            $id = (string) ($row['_id'] ?? '');
            $status = strtolower((string) ($row['status'] ?? ''));
            if ($id !== '' && ($status === 'draft' || $status === 'unpublished')) {
                $candidates[$id] = $row;
            }
        }
        $matches = [];
        foreach ($candidates as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($excludeTutorialId !== null && $id === $excludeTutorialId) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $topic = (string) ($row['topic'] ?? '');
            if (!$this->topicsSimilar($needle, $title) && !$this->topicsSimilar($needle, $topic)) {
                continue;
            }
            $isOwner = (string) ($row['createdBy'] ?? '') === $userId;
            $matches[] = [
                'id' => $id,
                'title' => $title,
                'topic' => $topic,
                'status' => (string) ($row['status'] ?? ''),
                'createdByName' => $this->creatorDisplayName((string) ($row['createdBy'] ?? ''), $isOwner),
                'isOwner' => $isOwner,
            ];
            if (count($matches) >= 8) {
                break;
            }
        }

        return [
            'matches' => $matches,
            'hasMatches' => $matches !== [],
        ];
    }

    /**
     * Normalize a topic/title for deterministic duplicate comparison.
     */
    public static function normalizeTopicKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createTutorial(array $user, array $input): array
    {
        $this->assertAuthor($user);
        unset($input['createdBy'], $input['status'], $input['createdAt'], $input['_id']);
        $row = $this->tutorials->create($this->tutorialPayload($input, $this->userId($user), 'draft'));

        return $this->managedTutorial($row, false, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTutorial(array $user, string $id, array $input): array
    {
        $existing = $this->requireMutableTutorial($user, $id);
        unset($input['createdBy'], $input['status'], $input['createdAt'], $input['_id']);
        $payload = $this->tutorialPayload($input, (string) ($existing['createdBy'] ?? ''), (string) ($existing['status'] ?? 'draft'));
        $row = $this->tutorials->updateTutorial($id, $payload);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false, $user);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteTutorial(array $user, string $id): void
    {
        $this->requireMutableTutorial($user, $id);
        foreach ($this->modules->listByTutorial($id) as $module) {
            $this->deleteModuleTree((string) ($module['_id'] ?? ''));
        }
        $this->tutorials->delete($id);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function publish(array $user, string $id): array
    {
        $this->requireMutableTutorial($user, $id);
        $check = $this->publishChecklist($user, $id);
        if (!$check['canPublish']) {
            throw new \InvalidArgumentException($check['errors'][0] ?? 'This course cannot be published yet.');
        }
        $row = $this->tutorials->updateTutorial($id, ['status' => 'published']);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{canPublish: bool, checks: list<array{label: string, ok: bool}>, warnings: list<string>, errors: list<string>}
     */
    public function publishChecklist(array $user, string $id): array
    {
        $existing = $this->requireMutableTutorial($user, $id);
        $checks = [];
        $errors = [];
        $warnings = [];
        $titleOk = trim((string) ($existing['title'] ?? '')) !== '';
        $checks[] = ['label' => 'Course title', 'ok' => $titleOk];
        if (!$titleOk) {
            $errors[] = 'A course title is required.';
        }
        $category = $this->categories->findById((string) ($existing['categoryId'] ?? ''));
        $categoryOk = is_array($category);
        $checks[] = ['label' => 'Category', 'ok' => $categoryOk];
        if (!$categoryOk) {
            $errors[] = 'Choose a valid category.';
        }
        $descriptionOk = trim((string) ($existing['description'] ?? '')) !== '';
        $checks[] = ['label' => 'Description', 'ok' => $descriptionOk];
        if (!$descriptionOk) {
            $errors[] = 'A description is required.';
        }
        $visibility = (string) ($existing['visibility'] ?? '');
        $departments = (array) ($existing['departmentIds'] ?? []);
        $years = (array) ($existing['passingYears'] ?? []);
        $visibilityOk = $visibility === 'all' || ($visibility === 'scoped' && ($departments !== [] || $years !== []));
        $checks[] = ['label' => 'Visibility', 'ok' => $visibilityOk];
        if (!$visibilityOk) {
            $errors[] = 'Choose who can learn this course.';
        }
        $modules = $this->modules->listByTutorial($id);
        $moduleOk = $modules !== [];
        $checks[] = ['label' => count($modules) . ' modules', 'ok' => $moduleOk];
        if (!$moduleOk) {
            $errors[] = 'Add at least one module before publishing.';
        }
        $orderOk = true;
        $seen = [];
        foreach ($modules as $module) {
            $order = (int) ($module['sortOrder'] ?? 0);
            $moduleTitle = trim((string) ($module['title'] ?? ''));
            if ($moduleTitle === '' || $order < 1 || isset($seen[$order])) {
                $orderOk = false;
            }
            $seen[$order] = true;
            $exerciseRows = $this->exercises->listByModule((string) ($module['_id'] ?? ''));
            if ($exerciseRows === []) {
                $warnings[] = ($moduleTitle !== '' ? $moduleTitle : 'A module') . ' has no exercise.';
            }
            foreach ($exerciseRows as $exercise) {
                $exerciseTitle = trim((string) ($exercise['title'] ?? ''));
                $instructions = trim(strip_tags((string) ($exercise['instructions'] ?? '')));
                $language = trim((string) ($exercise['language'] ?? ''));
                if ($exerciseTitle === '' || $instructions === '' || $language === '') {
                    $errors[] = 'Every exercise needs a title, instructions, and language.';
                    break 2;
                }
            }
        }
        $checks[] = ['label' => 'Module order', 'ok' => $moduleOk && $orderOk];
        if ($moduleOk && !$orderOk) {
            $errors[] = 'Each module needs a title and a unique order.';
        }

        return [
            'canPublish' => $errors === [],
            'checks' => $checks,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function unpublish(array $user, string $id): array
    {
        $this->requireMutableTutorial($user, $id);
        $row = $this->tutorials->updateTutorial($id, ['status' => 'unpublished']);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false, $user);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $user
     * @return array{url: string}
     */
    public function uploadLessonImage(array $user): array
    {
        $this->assertAuthor($user);
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            throw new \InvalidArgumentException('Choose an image to insert.');
        }
        $error = \PMS\Utils\Security::validateUploadedFile(
            $_FILES['image'],
            2 * 1024 * 1024,
            \PMS\Utils\Security::allowedPhotoExtensions()
        );
        if ($error) {
            throw new \InvalidArgumentException($error);
        }
        $ext = strtolower(pathinfo((string) ($_FILES['image']['name'] ?? ''), PATHINFO_EXTENSION));
        $storage = new ObjectStorageService();
        try {
            $path = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_TUTORIAL_IMAGES,
                'lesson_' . bin2hex(random_bytes(8)) . '.' . $ext,
                $_FILES['image']
            );
        } catch (\Throwable) {
            throw new \RuntimeException('The image could not be saved.', 500);
        }
        $stored = $storage->storedNameFromUri($path);

        return ['url' => $storage->mediaUrl(ObjectStorageService::FOLDER_TUTORIAL_IMAGES, $stored)];
    }

    public function createModule(array $user, string $tutorialId, array $input): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $row = $this->modules->create([
            'tutorialId' => $tutorialId,
            'title' => (string) ($input['title'] ?? ''),
            'subtitle' => (string) ($input['subtitle'] ?? ''),
            'sortOrder' => $input['sortOrder'] ?? $this->nextModuleOrder($tutorialId),
            'content' => $this->storeLessonContent((string) ($input['content'] ?? '')),
        ]);

        return $this->managedModule($row, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateModule(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $existing = $this->moduleOnTutorial($tutorialId, $moduleId);
        $row = $this->modules->updateModule($moduleId, [
            'tutorialId' => $tutorialId,
            'title' => (string) ($input['title'] ?? $existing['title'] ?? ''),
            'subtitle' => array_key_exists('subtitle', $input) ? (string) $input['subtitle'] : (string) ($existing['subtitle'] ?? ''),
            'sortOrder' => $input['sortOrder'] ?? $existing['sortOrder'] ?? 1,
            'content' => array_key_exists('content', $input)
                ? $this->storeLessonContent((string) $input['content'])
                : (string) ($existing['content'] ?? ''),
        ]);
        if ($row === null) {
            throw new \RuntimeException('Module not found.', 404);
        }

        return $this->managedModule($row, false);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteModule(array $user, string $tutorialId, string $moduleId): void
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $this->deleteModuleTree($moduleId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, mixed> $moduleIds
     * @return array<int, array<string, mixed>>
     */
    public function reorderModules(array $user, string $tutorialId, array $moduleIds): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $existing = $this->modules->listByTutorial($tutorialId);
        $known = [];
        foreach ($existing as $module) {
            $known[(string) ($module['_id'] ?? '')] = $module;
        }
        $clean = [];
        foreach ($moduleIds as $moduleId) {
            $moduleId = trim((string) $moduleId);
            if (!isset($known[$moduleId]) || isset($clean[$moduleId])) {
                throw new \InvalidArgumentException('Reorder list must contain each module of this tutorial once.');
            }
            $clean[$moduleId] = true;
        }
        if (count($clean) !== count($known)) {
            throw new \InvalidArgumentException('Reorder list must contain each module of this tutorial once.');
        }
        // Two-phase update avoids temporary duplicate sortOrder values while saving.
        $temp = 1000;
        foreach (array_keys($clean) as $moduleId) {
            $module = $known[$moduleId];
            $this->modules->updateModule($moduleId, [
                'tutorialId' => $tutorialId,
                'title' => (string) ($module['title'] ?? ''),
                'subtitle' => (string) ($module['subtitle'] ?? ''),
                'sortOrder' => $temp,
                'content' => (string) ($module['content'] ?? ''),
            ]);
            $temp++;
        }
        $order = 1;
        foreach (array_keys($clean) as $moduleId) {
            $module = $known[$moduleId];
            $this->modules->updateModule($moduleId, [
                'tutorialId' => $tutorialId,
                'title' => (string) ($module['title'] ?? ''),
                'subtitle' => (string) ($module['subtitle'] ?? ''),
                'sortOrder' => $order,
                'content' => (string) ($module['content'] ?? ''),
            ]);
            $order++;
        }

        return array_map(
            fn (array $module): array => $this->managedModule($module, false),
            $this->modules->listByTutorial($tutorialId)
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createExercise(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $instructions = trim(strip_tags(self::sanitizeHtml((string) ($input['instructions'] ?? ''))));
        if ($instructions === '') {
            throw new \InvalidArgumentException('Exercise instructions are required.');
        }
        $limits = $this->limits($input, 5000, 128000);
        $row = $this->exercises->create([
            'moduleId' => $moduleId,
            'title' => (string) ($input['title'] ?? ''),
            'instructions' => self::sanitizeHtml((string) ($input['instructions'] ?? '')),
            'language' => (string) ($input['language'] ?? ''),
            'boilerplate' => (string) ($input['boilerplate'] ?? ''),
            'timeLimitMs' => $limits['timeLimitMs'],
            'memoryLimitKb' => $limits['memoryLimitKb'],
            'sortOrder' => $input['sortOrder'] ?? $this->nextExerciseOrder($moduleId),
            'lessonBlockId' => (string) ($input['lessonBlockId'] ?? ''),
        ]);

        return $this->managedExercise($row, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateExercise(array $user, string $tutorialId, string $moduleId, string $exerciseId, array $input): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $existing = $this->exerciseOnModule($moduleId, $exerciseId);
        $limits = $this->limits($input, (int) ($existing['timeLimitMs'] ?? 5000), (int) ($existing['memoryLimitKb'] ?? 128000));
        $row = $this->exercises->updateExercise($exerciseId, [
            'moduleId' => $moduleId,
            'title' => (string) ($input['title'] ?? $existing['title'] ?? ''),
            'instructions' => array_key_exists('instructions', $input)
                ? self::sanitizeHtml((string) $input['instructions'])
                : (string) ($existing['instructions'] ?? ''),
            'language' => (string) ($input['language'] ?? $existing['language'] ?? ''),
            'boilerplate' => (string) ($input['boilerplate'] ?? $existing['boilerplate'] ?? ''),
            'timeLimitMs' => $limits['timeLimitMs'],
            'memoryLimitKb' => $limits['memoryLimitKb'],
            'sortOrder' => $input['sortOrder'] ?? $existing['sortOrder'] ?? 1,
            'lessonBlockId' => array_key_exists('lessonBlockId', $input)
                ? (string) $input['lessonBlockId']
                : (string) ($existing['lessonBlockId'] ?? ''),
        ]);
        if ($row === null) {
            throw new \RuntimeException('Exercise not found.', 404);
        }

        return $this->managedExercise($row, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, mixed> $exerciseIds
     * @return array<int, array<string, mixed>>
     */
    public function reorderExercises(array $user, string $tutorialId, string $moduleId, array $exerciseIds): array
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $existing = $this->exercises->listByModule($moduleId);
        $known = [];
        foreach ($existing as $exercise) {
            $known[(string) ($exercise['_id'] ?? '')] = $exercise;
        }
        $clean = [];
        foreach ($exerciseIds as $exerciseId) {
            $exerciseId = trim((string) $exerciseId);
            if (!isset($known[$exerciseId]) || isset($clean[$exerciseId])) {
                throw new \InvalidArgumentException('Reorder list must contain each exercise of this module once.');
            }
            $clean[$exerciseId] = true;
        }
        if (count($clean) !== count($known)) {
            throw new \InvalidArgumentException('Reorder list must contain each exercise of this module once.');
        }
        $order = 1;
        foreach (array_keys($clean) as $exerciseId) {
            $exercise = $known[$exerciseId];
            $this->exercises->updateExercise($exerciseId, [
                'moduleId' => $moduleId,
                'title' => (string) ($exercise['title'] ?? ''),
                'instructions' => (string) ($exercise['instructions'] ?? ''),
                'language' => (string) ($exercise['language'] ?? ''),
                'boilerplate' => (string) ($exercise['boilerplate'] ?? ''),
                'timeLimitMs' => (int) ($exercise['timeLimitMs'] ?? 5000),
                'memoryLimitKb' => (int) ($exercise['memoryLimitKb'] ?? 128000),
                'sortOrder' => $order,
            ]);
            $order++;
        }

        return array_map(
            fn (array $exercise): array => $this->managedExercise($exercise, true),
            $this->exercises->listByModule($moduleId)
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, mixed> $testCaseIds
     * @return array<int, array<string, mixed>>
     */
    public function reorderTestCases(array $user, string $exerciseId, array $testCaseIds): array
    {
        $this->ownedExercise($user, $exerciseId);
        $existing = $this->testCases->listByExercise($exerciseId);
        $known = [];
        foreach ($existing as $case) {
            $known[(string) ($case['_id'] ?? '')] = $case;
        }
        $clean = [];
        foreach ($testCaseIds as $testCaseId) {
            $testCaseId = trim((string) $testCaseId);
            if (!isset($known[$testCaseId]) || isset($clean[$testCaseId])) {
                throw new \InvalidArgumentException('Reorder list must contain each test case once.');
            }
            $clean[$testCaseId] = true;
        }
        if (count($clean) !== count($known)) {
            throw new \InvalidArgumentException('Reorder list must contain each test case once.');
        }
        $order = 1;
        foreach (array_keys($clean) as $testCaseId) {
            $case = $known[$testCaseId];
            $this->testCases->updateTestCase($testCaseId, [
                'exerciseId' => $exerciseId,
                'stdin' => (string) ($case['stdin'] ?? ''),
                'expectedOutput' => (string) ($case['expectedOutput'] ?? ''),
                'sample' => ($case['sample'] ?? false) === true,
                'sortOrder' => $order,
            ]);
            $order++;
        }

        return array_map(
            fn (array $case): array => $this->managedTestCase($case),
            $this->testCases->listByExercise($exerciseId)
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteExercise(array $user, string $tutorialId, string $moduleId, string $exerciseId): void
    {
        $this->requireMutableTutorial($user, $tutorialId);
        $this->moduleOnTutorial($tutorialId, $moduleId);
        $this->exerciseOnModule($moduleId, $exerciseId);
        $this->deleteExerciseTree($exerciseId);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createTestCase(array $user, string $exerciseId, array $input): array
    {
        $this->ownedExercise($user, $exerciseId);
        if (!array_key_exists('sample', $input) || !is_bool($input['sample'])) {
            throw new \InvalidArgumentException('Test case sample must be true or false.');
        }
        $row = $this->testCases->create([
            'exerciseId' => $exerciseId,
            'stdin' => (string) ($input['stdin'] ?? ''),
            'expectedOutput' => (string) ($input['expectedOutput'] ?? ''),
            'sample' => $input['sample'],
            'sortOrder' => $input['sortOrder'] ?? $this->nextCaseOrder($exerciseId),
        ]);

        return $this->managedTestCase($row);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTestCase(array $user, string $exerciseId, string $testCaseId, array $input): array
    {
        $this->ownedExercise($user, $exerciseId);
        $existing = $this->caseOnExercise($exerciseId, $testCaseId);
        $sample = array_key_exists('sample', $input) ? $input['sample'] : $existing['sample'];
        if (!is_bool($sample)) {
            throw new \InvalidArgumentException('Test case sample must be true or false.');
        }
        $row = $this->testCases->updateTestCase($testCaseId, [
            'exerciseId' => $exerciseId,
            'stdin' => array_key_exists('stdin', $input) ? (string) $input['stdin'] : (string) ($existing['stdin'] ?? ''),
            'expectedOutput' => array_key_exists('expectedOutput', $input) ? (string) $input['expectedOutput'] : (string) ($existing['expectedOutput'] ?? ''),
            'sample' => $sample,
            'sortOrder' => $input['sortOrder'] ?? $existing['sortOrder'] ?? 1,
        ]);
        if ($row === null) {
            throw new \RuntimeException('Test case not found.', 404);
        }

        return $this->managedTestCase($row);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteTestCase(array $user, string $exerciseId, string $testCaseId): void
    {
        $this->ownedExercise($user, $exerciseId);
        $this->caseOnExercise($exerciseId, $testCaseId);
        $this->testCases->delete($testCaseId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertReader(array $user): void
    {
        $role = AuthMiddleware::resolvedRole($user);
        if (!in_array($role, ['admin', 'placement_officer', 'staff', 'student'], true)) {
            throw new \RuntimeException('You do not have permission to view tutorials.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertAuthor(array $user): void
    {
        $role = AuthMiddleware::resolvedRole($user);
        if (!in_array($role, ['admin', 'placement_officer', 'staff'], true)) {
            throw new \RuntimeException('You do not have permission to manage tutorials.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    private function canManageCampusTutorials(array $user): bool
    {
        return in_array(AuthMiddleware::resolvedRole($user), ['admin', 'placement_officer'], true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireStudent(array $user): array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'student') {
            throw new \RuntimeException('You do not have permission to view tutorials.', 403);
        }
        $student = $this->students->findByUserId($this->userId($user));
        if (!is_array($student)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $student;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function publishedTutorialForStudent(array $user, string $id): array
    {
        $student = $this->requireStudent($user);
        $row = $this->tutorials->findById($id);
        $year = self::passoutYear((string) ($student['classBatch'] ?? ''));
        if (!is_array($row) || !$this->visibleToStudent($row, (string) ($student['departmentId'] ?? ''), $year)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $tutorial
     */
    private function visibleToStudent(array $tutorial, string $departmentId, string $passoutYear): bool
    {
        if ((string) ($tutorial['status'] ?? '') !== 'published') {
            return false;
        }
        $visibility = strtolower(trim((string) ($tutorial['visibility'] ?? 'all')));
        if ($visibility === '' || $visibility === 'all') {
            return true;
        }
        if ($visibility !== 'scoped') {
            return false;
        }
        $departments = array_map('strval', (array) ($tutorial['departmentIds'] ?? []));
        $years = array_map('strval', (array) ($tutorial['passingYears'] ?? []));
        $departmentOk = $departments === [] || in_array($departmentId, $departments, true);
        $yearOk = $years === [] || ($passoutYear !== '' && in_array($passoutYear, $years, true));

        return $departmentOk && $yearOk;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function studentProfileId(array $user): string
    {
        return (string) ($this->requireStudent($user)['_id'] ?? '');
    }

    /**
     * @param array<string, mixed> $tutorial
     * @return array<string, mixed>
     */
    private function progressView(string $studentId, array $tutorial): array
    {
        $tutorialId = (string) ($tutorial['_id'] ?? '');
        $modules = $this->modules->listByTutorial($tutorialId);
        $done = $this->completedModuleIds($studentId, $tutorialId, $modules);
        $total = count($modules);
        $percent = $total === 0 ? 0 : (int) round((count($done) / $total) * 100);
        $stored = $this->progress->findFor($studentId, $tutorialId);
        $status = 'NOT_STARTED';
        if (is_array($stored)) {
            $status = ($stored['completedAt'] ?? null) && $total > 0 && count($done) === $total
                ? 'COMPLETED'
                : 'IN_PROGRESS';
        }

        return [
            'tutorialId' => $tutorialId,
            'status' => $status,
            'progressPercent' => $status === 'COMPLETED' ? 100 : $percent,
            'completedModules' => count($done),
            'totalModules' => $total,
            'lastVisitedModuleId' => is_array($stored) ? ($stored['lastVisitedModuleId'] ?? null) : null,
            'completedModuleIds' => $done,
            'completed' => $status === 'COMPLETED',
            'workspace' => $this->workspaceProgress($studentId, $tutorialId, $modules, is_array($stored) ? $stored : null),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $modules
     * @return list<string>
     */
    private function completedModuleIds(string $studentId, string $tutorialId, array $modules): array
    {
        $known = [];
        foreach ($modules as $module) {
            $known[(string) ($module['_id'] ?? '')] = true;
        }
        $done = [];
        foreach ($this->moduleProgress->listForTutorial($studentId, $tutorialId) as $row) {
            $moduleId = (string) ($row['moduleId'] ?? '');
            if ($moduleId !== '' && isset($known[$moduleId])) {
                $done[$moduleId] = $moduleId;
            }
        }

        return array_values($done);
    }

    private function calculatedPercent(string $studentId, string $tutorialId): int
    {
        $modules = $this->modules->listByTutorial($tutorialId);
        if ($modules === []) {
            return 0;
        }

        return (int) round((count($this->completedModuleIds($studentId, $tutorialId, $modules)) / count($modules)) * 100);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: string, 2: string, 3: array<string, mixed>, 4: string}
     */
    private function practiceSource(array $user, string $exerciseId, array $input): array
    {
        unset($input['studentId'], $input['departmentId'], $input['passingYear']);
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $tutorialId = (string) ($module['tutorialId'] ?? '');
        $this->publishedTutorialForStudent($user, $tutorialId);
        $source = (string) ($input['sourceCode'] ?? '');
        if (trim($source) === '') {
            throw new \InvalidArgumentException('Source code is required.');
        }
        if (strlen($source) > self::MAX_SOURCE_CHARS) {
            throw new \InvalidArgumentException('Source code is too long.');
        }
        $language = TutorialExerciseModel::normalizeLanguage((string) ($input['language'] ?? ''));
        if ($language === '' || $language !== (string) ($exercise['language'] ?? '')) {
            throw new \InvalidArgumentException('Use the language configured for this exercise.');
        }
        if (!in_array($language, TutorialsCodeExecutionService::LANGUAGES, true)) {
            throw new \InvalidArgumentException('This exercise language cannot be executed.');
        }

        return [$exercise, $language, $source, $module, $tutorialId];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function maybeCompleteReadyModules(array $user, string $tutorialId): void
    {
        $studentId = $this->studentProfileId($user);
        $modules = $this->modules->listByTutorial($tutorialId);
        $workspace = $this->workspaceProgress($studentId, $tutorialId, $modules, $this->progress->findFor($studentId, $tutorialId));
        foreach ($workspace['readyModuleIds'] as $moduleId) {
            $this->moduleProgress->markComplete($studentId, $tutorialId, $moduleId);
        }
    }

    /**
     * Lesson, exercise, and activity completion for the learning workspace.
     * Opening a lesson is not completion. Existing module marks are left unchanged.
     *
     * @param array<int, array<string, mixed>> $modules
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>
     */
    private function workspaceProgress(string $studentId, string $tutorialId, array $modules, ?array $stored): array
    {
        $reviewed = [];
        foreach ((array) ($stored['completedLessonIds'] ?? []) as $key) {
            $key = (string) $key;
            if ($key !== '') {
                $reviewed[$key] = true;
            }
        }
        $correctQuestions = (new TutorialLessonQuestionAttemptModel())->correctQuestionIds($studentId, $tutorialId);
        $questionModel = new TutorialLessonQuestionModel();
        $activities = new TutorialModuleActivityModel();
        $submissions = new TutorialModuleActivitySubmissionModel();
        $lessonDone = 0;
        $lessonTotal = 0;
        $exerciseDone = 0;
        $exerciseTotal = 0;
        $activityDone = 0;
        $activityTotal = 0;
        $readyModules = [];
        $lessonFlags = [];
        foreach ($modules as $module) {
            $moduleId = (string) ($module['_id'] ?? '');
            if ($moduleId === '') {
                continue;
            }
            $lessons = $this->buildStudentLessons($this->lessonContentString($module['content'] ?? ''), (string) ($module['title'] ?? 'Lesson'));
            $linked = [];
            foreach ($questionModel->listByModule($moduleId) as $question) {
                $questionId = (string) ($question['_id'] ?? '');
                if ($questionId === '') {
                    continue;
                }
                $exerciseTotal++;
                $isPassed = isset($correctQuestions[$questionId]);
                if ($isPassed) {
                    $exerciseDone++;
                }
                $lessonBlockId = trim((string) ($question['lessonBlockId'] ?? ''));
                if ($lessonBlockId !== '') {
                    $linked[$lessonBlockId][] = $isPassed;
                }
            }
            $moduleLessonsReady = true;
            if ($lessons === []) {
                $moduleLessonsReady = false;
            }
            foreach ($lessons as $lesson) {
                $lessonTotal++;
                $lessonId = (string) ($lesson['id'] ?? '');
                $required = $linked[$lessonId] ?? [];
                $complete = $required !== []
                    ? !in_array(false, $required, true)
                    : isset($reviewed[$moduleId . ':' . $lessonId]);
                if ($complete) {
                    $lessonDone++;
                } else {
                    $moduleLessonsReady = false;
                }
                $lessonFlags[$moduleId . ':' . $lessonId] = $complete;
            }
            [$requiredActivities, $doneActivities] = $this->activityCounts($activities, $submissions, $studentId, $moduleId);
            $activityTotal += $requiredActivities;
            $activityDone += $doneActivities;
            if ($moduleLessonsReady && $doneActivities === $requiredActivities && $lessons !== []) {
                $readyModules[] = $moduleId;
            }
        }
        $units = $lessonTotal + $activityTotal;
        $doneUnits = $lessonDone + $activityDone;
        $percent = $units === 0 ? 0 : (int) round(($doneUnits / $units) * 100);

        return [
            'percent' => $percent,
            'completedModules' => count($readyModules),
            'totalModules' => count($modules),
            'completedLessons' => $lessonDone,
            'totalLessons' => $lessonTotal,
            'completedExercises' => $exerciseDone,
            'totalExercises' => $exerciseTotal,
            'completedActivities' => $activityDone,
            'totalActivities' => $activityTotal,
            'lessons' => $lessonFlags,
            'readyModuleIds' => $readyModules,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function activityCounts(
        TutorialModuleActivityModel $activities,
        TutorialModuleActivitySubmissionModel $submissions,
        string $studentId,
        string $moduleId
    ): array {
        $required = 0;
        $done = 0;
        foreach ($activities->listByModule($moduleId, false) as $activity) {
            if ((string) ($activity['status'] ?? '') !== 'published' || ($activity['archived'] ?? false) === true) {
                continue;
            }
            if ((string) ($activity['evaluationMode'] ?? '') === 'none') {
                continue;
            }
            $required++;
            $activityId = (string) ($activity['_id'] ?? '');
            foreach ($submissions->listForStudent($studentId, $activityId) as $attempt) {
                if ((string) ($attempt['status'] ?? '') === 'SUBMITTED') {
                    $done++;
                    break;
                }
            }
        }

        return [$required, $done];
    }

    private function ensureStarted(string $studentId, string $tutorialId, ?string $moduleId): void
    {
        $existing = $this->progress->findFor($studentId, $tutorialId);
        $completed = is_array($existing) && ($existing['completedAt'] ?? null)
            && $this->calculatedPercent($studentId, $tutorialId) === 100
            && $this->modules->listByTutorial($tutorialId) !== [];
        $this->progress->saveFor($studentId, $tutorialId, [
            'startedAt' => (string) ($existing['startedAt'] ?? \PMS\Utils\DocumentHelper::now()),
            'completedAt' => $completed ? ($existing['completedAt'] ?? null) : ($existing['completedAt'] ?? null),
            'lastVisitedModuleId' => $moduleId ?? ($existing['lastVisitedModuleId'] ?? null),
            'status' => $completed ? 'COMPLETED' : 'IN_PROGRESS',
            'progressPercent' => $this->calculatedPercent($studentId, $tutorialId),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private function canViewManagedTutorial(array $user, array $row): bool
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($status === 'published') {
            return true;
        }
        if ($status === 'unpublished' && $this->canManageCampusTutorials($user)) {
            return true;
        }

        return (string) ($row['createdBy'] ?? '') === $this->userId($user);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $row
     */
    private function canMutateManagedTutorial(array $user, array $row): bool
    {
        if ((string) ($row['createdBy'] ?? '') === $this->userId($user)) {
            return true;
        }
        $status = strtolower((string) ($row['status'] ?? ''));

        return $this->canManageCampusTutorials($user) && $status !== 'draft';
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireViewableTutorial(array $user, string $id): array
    {
        $this->assertAuthor($user);
        $row = $this->tutorials->findById($id);
        if (!is_array($row) || !$this->canViewManagedTutorial($user, $row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireMutableTutorial(array $user, string $id): array
    {
        $this->assertAuthor($user);
        $row = $this->tutorials->findById($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }
        if ($this->canMutateManagedTutorial($user, $row)) {
            return $row;
        }
        if (!$this->canViewManagedTutorial($user, $row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        throw new \RuntimeException('You can only change tutorials you created.', 403);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function ownedExercise(array $user, string $exerciseId): array
    {
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $this->requireMutableTutorial($user, (string) ($module['tutorialId'] ?? ''));

        return $exercise;
    }

    /**
     * @return list<string>
     */
    private function topicTokens(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }
        $stop = [
            'a', 'an', 'and', 'basics', 'course', 'for', 'fundamentals', 'in', 'into',
            'introduction', 'of', 'on', 'the', 'to', 'tutorial', 'with',
        ];
        $tokens = [];
        foreach (explode(' ', $normalized) as $token) {
            if ($token === '' || in_array($token, $stop, true) || mb_strlen($token) < 2) {
                continue;
            }
            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function topicsSimilar(string $left, string $right): bool
    {
        $a = self::normalizeTopicKey($left);
        $b = self::normalizeTopicKey($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $shorter = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longer = $shorter === $a ? $b : $a;
        if (mb_strlen($shorter) >= 4 && str_contains($longer, $shorter)) {
            return true;
        }
        $ta = $this->topicTokens($a);
        $tb = $this->topicTokens($b);
        if ($ta === [] || $tb === []) {
            return false;
        }
        $setB = array_fill_keys($tb, true);
        $overlap = 0;
        foreach ($ta as $token) {
            if (isset($setB[$token])) {
                $overlap++;
            }
        }
        if ($overlap === 0) {
            return false;
        }
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union > 0 && ($overlap / $union) >= 0.6;
    }

    private function creatorDisplayName(string $createdBy, bool $isOwner): string
    {
        if ($isOwner) {
            return 'You';
        }
        if ($createdBy === '') {
            return 'Staff';
        }
        $creator = $this->users->findById($createdBy);
        $name = trim((string) ($creator['name'] ?? ''));

        return $name !== '' ? $name : 'Staff';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function tutorialPayload(array $input, string $createdBy, string $status): array
    {
        $visibility = strtolower(trim((string) ($input['visibility'] ?? 'all')));
        if ($visibility !== 'scoped') {
            $visibility = 'all';
        }
        $departmentIds = [];
        $passingYears = [];
        if ($visibility === 'scoped') {
            $departmentIds = $this->departmentIds($input['departmentIds'] ?? []);
            foreach ((array) ($input['passingYears'] ?? []) as $year) {
                $year = trim((string) $year);
                if ($year === '') {
                    continue;
                }
                if (preg_match('/^(19|20)\d{2}$/', $year) !== 1) {
                    throw new \InvalidArgumentException('Tutorial passing years must be four-digit years.');
                }
                $passingYears[$year] = $year;
            }
            $passingYears = array_values($passingYears);
            if ($departmentIds === [] && $passingYears === []) {
                throw new \InvalidArgumentException('Choose at least one department or passing year.');
            }
        }
        $thumbnail = trim((string) ($input['thumbnail'] ?? ''));
        if ($thumbnail !== '' && (preg_match('#^(javascript|data):#i', $thumbnail) === 1 || strlen($thumbnail) > 500)) {
            throw new \InvalidArgumentException('Thumbnail must be an http(s) or stored file path.');
        }

        return [
            'title' => (string) ($input['title'] ?? ''),
            'categoryId' => (string) ($input['categoryId'] ?? ''),
            'topic' => (string) ($input['topic'] ?? ''),
            'description' => trim((string) ($input['description'] ?? '')),
            'thumbnail' => $thumbnail,
            'status' => $status,
            'visibility' => $visibility,
            'departmentIds' => $departmentIds,
            'passingYears' => $passingYears,
            'createdBy' => $createdBy,
        ];
    }

    /**
     * @return list<string>
     */
    private function departmentIds(mixed $value): array
    {
        $known = [];
        foreach ($this->departments->findAll([], 500) as $department) {
            $id = (string) ($department['_id'] ?? '');
            if ($id !== '' && DepartmentModel::isStudentAcademicDepartment((string) ($department['code'] ?? ''), (string) ($department['name'] ?? ''))) {
                $known[$id] = true;
            }
        }
        $ids = [];
        foreach ((array) $value as $departmentId) {
            $departmentId = trim((string) $departmentId);
            if ($departmentId === '') {
                continue;
            }
            if (!isset($known[$departmentId])) {
                throw new \InvalidArgumentException('One or more departments were not found.');
            }
            $ids[$departmentId] = $departmentId;
        }

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{timeLimitMs: int, memoryLimitKb: int}
     */
    private function limits(array $input, int $timeDefault, int $memoryDefault): array
    {
        $time = array_key_exists('timeLimitMs', $input) ? (int) $input['timeLimitMs'] : $timeDefault;
        $memory = array_key_exists('memoryLimitKb', $input) ? (int) $input['memoryLimitKb'] : $memoryDefault;
        if ($time < self::MIN_TIME_LIMIT_MS || $time > self::MAX_TIME_LIMIT_MS) {
            throw new \InvalidArgumentException('timeLimitMs must be between 200 and 15000.');
        }
        if ($memory < self::MIN_MEMORY_LIMIT_KB || $memory > self::MAX_MEMORY_LIMIT_KB) {
            throw new \InvalidArgumentException('memoryLimitKb must be between 2048 and 512000.');
        }

        return ['timeLimitMs' => $time, 'memoryLimitKb' => $memory];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireModule(string $id): array
    {
        $row = $this->modules->findById($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleOnTutorial(string $tutorialId, string $moduleId): array
    {
        $module = $this->requireModule($moduleId);
        if ((string) ($module['tutorialId'] ?? '') !== $tutorialId) {
            throw new \RuntimeException('Module not found.', 404);
        }

        return $module;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireExercise(string $id): array
    {
        $row = $this->exercises->findById($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function exerciseOnModule(string $moduleId, string $exerciseId): array
    {
        $exercise = $this->requireExercise($exerciseId);
        if ((string) ($exercise['moduleId'] ?? '') !== $moduleId) {
            throw new \RuntimeException('Exercise not found.', 404);
        }

        return $exercise;
    }

    /**
     * @return array<string, mixed>
     */
    private function caseOnExercise(string $exerciseId, string $testCaseId): array
    {
        $row = $this->testCases->findById($testCaseId);
        if (!is_array($row) || (string) ($row['exerciseId'] ?? '') !== $exerciseId) {
            throw new \RuntimeException('Test case not found.', 404);
        }

        return $row;
    }

    private function deleteModuleTree(string $moduleId): void
    {
        $questions = new TutorialLessonQuestionModel();
        $attempts = new TutorialLessonQuestionAttemptModel();
        foreach ($questions->listByModule($moduleId) as $question) {
            $questionId = (string) ($question['_id'] ?? '');
            if ($questionId === '') {
                continue;
            }
            $attempts->deleteForQuestion($questionId);
            $questions->delete($questionId);
        }
        foreach ($this->exercises->listByModule($moduleId) as $exercise) {
            $this->deleteExerciseTree((string) ($exercise['_id'] ?? ''));
        }
        $this->modules->delete($moduleId);
    }

    private function deleteExerciseTree(string $exerciseId): void
    {
        foreach ($this->testCases->listByExercise($exerciseId) as $case) {
            $this->testCases->delete((string) ($case['_id'] ?? ''));
        }
        $this->exercises->delete($exerciseId);
    }

    private function exerciseCount(string $tutorialId): int
    {
        return $this->exercises->countForTutorial($tutorialId);
    }

    private function nextModuleOrder(string $tutorialId): int
    {
        $max = 0;
        foreach ($this->modules->listByTutorial($tutorialId) as $module) {
            $max = max($max, (int) ($module['sortOrder'] ?? 0));
        }

        return $max + 1;
    }

    private function nextExerciseOrder(string $moduleId): int
    {
        $max = 0;
        foreach ($this->exercises->listByModule($moduleId) as $exercise) {
            $max = max($max, (int) ($exercise['sortOrder'] ?? 0));
        }

        return $max + 1;
    }

    private function nextCaseOrder(string $exerciseId): int
    {
        $max = 0;
        foreach ($this->testCases->listByExercise($exerciseId) as $case) {
            $max = max($max, (int) ($case['sortOrder'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userId(array $user): string
    {
        return (string) ($user['_id'] ?? $user['id'] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicCategory(array $row): array
    {
        return [
            'id' => (string) ($row['_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function studentTutorial(array $row, bool $withModules): array
    {
        $category = $this->categories->findById((string) ($row['categoryId'] ?? ''));
        $view = [
            'id' => (string) ($row['_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'topic' => (string) ($row['topic'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'thumbnail' => (string) ($row['thumbnail'] ?? ''),
            'category' => is_array($category) ? $this->publicCategory($category) : null,
        ];
        if ($withModules) {
            $view['modules'] = array_map(
                fn (array $module): array => $this->studentModule($module, false),
                $this->modules->listByTutorial((string) ($row['_id'] ?? ''))
            );
            $view['moduleCount'] = count($view['modules']);
            $view['exerciseCount'] = $this->exerciseCount((string) ($row['_id'] ?? ''));
        } else {
            $view['moduleCount'] = $this->modules->countForTutorial((string) ($row['_id'] ?? ''));
            $view['exerciseCount'] = $this->exerciseCount((string) ($row['_id'] ?? ''));
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $module
     * @return array<string, mixed>
     */
    private function studentModule(array $module, bool $includeContent): array
    {
        $rawForOutline = $this->lessonContentString($module['content'] ?? '');
        $outline = $this->buildStudentLessons($rawForOutline, (string) ($module['title'] ?? 'Lesson'));
        $moduleId = (string) ($module['_id'] ?? '');
        $assessment = $moduleId !== '' ? (new TutorialModuleAssessmentModel())->findByModule($moduleId) : null;
        $view = [
            'id' => $moduleId,
            'title' => (string) ($module['title'] ?? ''),
            'subtitle' => (string) ($module['subtitle'] ?? ''),
            'sortOrder' => (int) ($module['sortOrder'] ?? 0),
            'hasPublishedAssessment' => is_array($assessment)
                && (string) ($assessment['status'] ?? '') === 'published',
            'lessonOutline' => array_map(
                static fn (array $lesson): array => [
                    'id' => (string) ($lesson['id'] ?? ''),
                    'title' => (string) ($lesson['title'] ?? 'Lesson'),
                ],
                $outline
            ),
        ];
        if ($includeContent) {
            $rawContent = $this->lessonContentString($module['content'] ?? '');
            $view['content'] = $this->presentLessonContent($rawContent);
            $view['lessons'] = $this->buildStudentLessons($rawContent, (string) ($module['title'] ?? 'Lesson'));
            $view['exercises'] = [];
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $exercise
     * @return array<string, mixed>
     */
    private function studentExercise(array $exercise): array
    {
        $public = [];
        foreach ($this->testCases->listByExercise((string) ($exercise['_id'] ?? '')) as $case) {
            if (($case['sample'] ?? false) !== true) {
                continue;
            }
            $public[] = [
                'id' => (string) ($case['_id'] ?? ''),
                'stdin' => (string) ($case['stdin'] ?? ''),
                'expectedOutput' => (string) ($case['expectedOutput'] ?? ''),
                'sample' => true,
                'sortOrder' => (int) ($case['sortOrder'] ?? 0),
            ];
        }

        return [
            'id' => (string) ($exercise['_id'] ?? ''),
            'title' => (string) ($exercise['title'] ?? ''),
            'instructions' => self::sanitizeHtml((string) ($exercise['instructions'] ?? '')),
            'language' => (string) ($exercise['language'] ?? ''),
            'boilerplate' => (string) ($exercise['boilerplate'] ?? ''),
            'timeLimitMs' => (int) ($exercise['timeLimitMs'] ?? 0),
            'memoryLimitKb' => (int) ($exercise['memoryLimitKb'] ?? 0),
            'lessonBlockId' => (string) ($exercise['lessonBlockId'] ?? ''),
            'testCases' => $public,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $viewer
     * @return array<string, mixed>
     */
    private function managedTutorial(array $row, bool $withChildren, ?array $viewer = null): array
    {
        $createdBy = (string) ($row['createdBy'] ?? '');
        $isOwner = is_array($viewer) && $createdBy !== '' && $createdBy === $this->userId($viewer);
        $canEdit = is_array($viewer) ? $this->canMutateManagedTutorial($viewer, $row) : false;
        $view = [
            'id' => (string) ($row['_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'categoryId' => (string) ($row['categoryId'] ?? ''),
            'topic' => (string) ($row['topic'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'thumbnail' => (string) ($row['thumbnail'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'visibility' => (string) ($row['visibility'] ?? ''),
            'departmentIds' => array_values((array) ($row['departmentIds'] ?? [])),
            'passingYears' => array_values((array) ($row['passingYears'] ?? [])),
            'createdBy' => $createdBy,
            'createdByName' => $this->creatorDisplayName($createdBy, $isOwner),
            'isOwner' => $isOwner,
            'canEdit' => $canEdit,
        ];
        if ($withChildren) {
            $view['modules'] = [];
            foreach ($this->modules->listByTutorial((string) ($row['_id'] ?? '')) as $module) {
                $view['modules'][] = $this->managedModule($module, true);
            }
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $module
     * @return array<string, mixed>
     */
    private function managedModule(array $module, bool $withExercises): array
    {
        $view = [
            'id' => (string) ($module['_id'] ?? ''),
            'tutorialId' => (string) ($module['tutorialId'] ?? ''),
            'title' => (string) ($module['title'] ?? ''),
            'subtitle' => (string) ($module['subtitle'] ?? ''),
            'sortOrder' => (int) ($module['sortOrder'] ?? 0),
            'content' => $this->lessonContentString($module['content'] ?? ''),
        ];
        if ($withExercises) {
            $view['exercises'] = [];
            foreach ($this->exercises->listByModule((string) ($module['_id'] ?? '')) as $exercise) {
                $view['exercises'][] = $this->managedExercise($exercise, true);
            }
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $exercise
     * @return array<string, mixed>
     */
    private function managedExercise(array $exercise, bool $withCases): array
    {
        $view = [
            'id' => (string) ($exercise['_id'] ?? ''),
            'moduleId' => (string) ($exercise['moduleId'] ?? ''),
            'title' => (string) ($exercise['title'] ?? ''),
            'instructions' => (string) ($exercise['instructions'] ?? ''),
            'language' => (string) ($exercise['language'] ?? ''),
            'boilerplate' => (string) ($exercise['boilerplate'] ?? ''),
            'timeLimitMs' => (int) ($exercise['timeLimitMs'] ?? 0),
            'memoryLimitKb' => (int) ($exercise['memoryLimitKb'] ?? 0),
            'sortOrder' => (int) ($exercise['sortOrder'] ?? 0),
            'lessonBlockId' => (string) ($exercise['lessonBlockId'] ?? ''),
        ];
        if ($withCases) {
            $view['testCases'] = array_map(
                fn (array $case): array => $this->managedTestCase($case),
                $this->testCases->listByExercise((string) ($exercise['_id'] ?? ''))
            );
        }

        return $view;
    }

    /**
     * Split lesson JSON into navigable sections (H2 boundaries). HTML-only modules become one lesson.
     *
     * @return list<array{id: string, title: string, html: string}>
     */
    private function buildStudentLessons(string $rawContent, string $fallbackTitle): array
    {
        $fallbackTitle = trim($fallbackTitle) !== '' ? trim($fallbackTitle) : 'Lesson';
        $trim = trim($rawContent);
        if ($trim !== '' && str_starts_with($trim, '{')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded) && (int) ($decoded['version'] ?? 0) === 1 && is_array($decoded['blocks'] ?? null)) {
                $document = $this->cleanLessonDocument($decoded);
                $sections = [];
                $current = null;
                foreach ((array) ($document['blocks'] ?? []) as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $isSectionStart = (($block['type'] ?? '') === 'heading') && (int) ($block['level'] ?? 2) === 2;
                    if ($isSectionStart) {
                        if ($current !== null) {
                            $sections[] = $current;
                        }
                        $title = trim((string) ($block['text'] ?? ''));
                        $current = [
                            'id' => (string) ($block['id'] ?? ('lesson-' . (count($sections) + 1))),
                            'title' => $title !== '' ? $title : ('Lesson ' . (count($sections) + 1)),
                            'blocks' => [$block],
                        ];
                        continue;
                    }
                    if ($current === null) {
                        $current = [
                            'id' => 'intro',
                            'title' => $fallbackTitle,
                            'blocks' => [],
                        ];
                    }
                    $current['blocks'][] = $block;
                }
                if ($current !== null) {
                    $sections[] = $current;
                }
                if ($sections === []) {
                    return [[
                        'id' => 'module',
                        'title' => $fallbackTitle,
                        'html' => $this->lessonDocumentToHtml($document),
                    ]];
                }

                return array_map(
                    fn (array $section): array => [
                        'id' => (string) $section['id'],
                        'title' => (string) $section['title'],
                        'html' => $this->lessonDocumentToHtml([
                            'version' => 1,
                            'blocks' => $section['blocks'],
                        ]),
                    ],
                    $sections
                );
            }
        }

        return [[
            'id' => 'module',
            'title' => $fallbackTitle,
            'html' => $this->presentLessonContent($rawContent),
        ]];
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private function managedTestCase(array $case): array
    {
        return [
            'id' => (string) ($case['_id'] ?? ''),
            'exerciseId' => (string) ($case['exerciseId'] ?? ''),
            'stdin' => (string) ($case['stdin'] ?? ''),
            'expectedOutput' => (string) ($case['expectedOutput'] ?? ''),
            'sample' => ($case['sample'] ?? false) === true,
            'sortOrder' => (int) ($case['sortOrder'] ?? 0),
        ];
    }

    private function lessonContentString(mixed $raw): string
    {
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return (string) $raw;
    }

    private function storeLessonContent(string $raw): string
    {
        $trim = trim($raw);
        if ($trim !== '' && str_starts_with($trim, '{')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded) && (int) ($decoded['version'] ?? 0) === 1 && is_array($decoded['blocks'] ?? null)) {
                return json_encode($this->cleanLessonDocument($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return self::sanitizeHtml($raw);
    }

    private function presentLessonContent(string $raw): string
    {
        $trim = trim($raw);
        if ($trim !== '' && str_starts_with($trim, '{')) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded) && (int) ($decoded['version'] ?? 0) === 1 && is_array($decoded['blocks'] ?? null)) {
                return $this->lessonDocumentToHtml($this->cleanLessonDocument($decoded));
            }
        }

        return self::sanitizeHtml($raw);
    }

    /**
     * @param array{version?: int, blocks?: list<array<string, mixed>>} $document
     */
    private function lessonDocumentToHtml(array $document): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '';
        foreach ((array) ($document['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'heading') {
                $tag = (int) ($block['level'] ?? 2) === 3 ? 'h3' : 'h2';
                $html .= '<' . $tag . '>' . $esc((string) ($block['text'] ?? '')) . '</' . $tag . '>';
                continue;
            }
            if ($type === 'quote') {
                $html .= '<blockquote>' . $esc((string) ($block['text'] ?? '')) . '</blockquote>';
                continue;
            }
            if ($type === 'divider') {
                $html .= '<hr/>';
                continue;
            }
            if ($type === 'image') {
                $alt = $esc((string) ($block['alt'] ?? ''));
                $html .= '<figure class="lesson-figure"><img src="' . $esc((string) ($block['url'] ?? '')) . '" alt="' . $alt . '">';
                if (trim((string) ($block['alt'] ?? '')) !== '') {
                    $html .= '<figcaption>' . $alt . '</figcaption>';
                }
                $html .= '</figure>';
                continue;
            }
            if ($type === 'code') {
                $language = $esc((string) ($block['language'] ?? 'text'));
                $html .= '<pre class="tutorial-code-block" data-language="' . $language . '" data-role="code"><code>' . $esc((string) ($block['source'] ?? '')) . '</code></pre>';
                $output = trim((string) ($block['exampleOutput'] ?? ''));
                if ($output !== '') {
                    $html .= '<pre class="tutorial-code-block" data-language="text" data-role="output"><code>' . $esc($output) . '</code></pre>';
                }
                continue;
            }
            $text = trim((string) ($block['text'] ?? ''));
            if ($text !== '') {
                $html .= '<p>' . nl2br($esc($text), false) . '</p>';
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{version: int, blocks: list<array<string, mixed>>}
     */
    private function cleanLessonDocument(array $document): array
    {
        $blocks = [];
        foreach (array_slice((array) $document['blocks'], 0, 200) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? ''));
            if ($id === '') {
                $id = bin2hex(random_bytes(4));
            }
            if ($type === 'paragraph' || $type === 'quote') {
                $blocks[] = ['id' => $id, 'type' => $type, 'text' => mb_substr(trim(strip_tags((string) ($block['text'] ?? ''))), 0, 20000)];
            } elseif ($type === 'heading') {
                $level = (int) ($block['level'] ?? 2) === 3 ? 3 : 2;
                $blocks[] = ['id' => $id, 'type' => 'heading', 'level' => $level, 'text' => mb_substr(trim(strip_tags((string) ($block['text'] ?? ''))), 0, 300)];
            } elseif ($type === 'code') {
                $language = strtolower(trim((string) ($block['language'] ?? 'auto')));
                if (!in_array($language, self::CODE_LANGUAGES, true)) {
                    $language = 'auto';
                }
                $blocks[] = [
                    'id' => $id,
                    'type' => 'code',
                    'language' => $language,
                    'source' => mb_substr((string) ($block['source'] ?? ''), 0, 20000),
                    'exampleOutput' => mb_substr((string) ($block['exampleOutput'] ?? ''), 0, 20000),
                ];
            } elseif ($type === 'image') {
                $url = trim((string) ($block['url'] ?? ''));
                if (preg_match('#^(https?://|/)#i', $url) !== 1 || preg_match('#^(javascript|data):#i', $url) === 1) {
                    continue;
                }
                $blocks[] = ['id' => $id, 'type' => 'image', 'url' => mb_substr($url, 0, 500), 'alt' => mb_substr(trim(strip_tags((string) ($block['alt'] ?? ''))), 0, 180)];
            } elseif ($type === 'divider') {
                $blocks[] = ['id' => $id, 'type' => 'divider'];
            }
        }
        if ($blocks === []) {
            $blocks[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'paragraph', 'text' => ''];
        }

        return ['version' => 1, 'blocks' => $blocks];
    }

    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $wrapped = '<div id="tutorial-html-root">' . $html . '</div>';
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return '';
        }
        $root = $doc->getElementById('tutorial-html-root');
        if (!$root instanceof \DOMElement) {
            return '';
        }
        self::sanitizeElement($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function sanitizeElement(\DOMElement $element): void
    {
        $child = $element->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_TAGS, true)) {
                    $element->removeChild($child);
                    $child = $next;
                    continue;
                }
                if (!in_array($tag, self::HTML_TAGS, true)) {
                    while ($child->firstChild !== null) {
                        $element->insertBefore($child->firstChild, $child);
                    }
                    $element->removeChild($child);
                    $child = $element->firstChild;
                    continue;
                }
                self::sanitizeAttributes($child, $tag);
                self::sanitizeElement($child);
            }
            $child = $next;
        }
    }

    private static function sanitizeAttributes(\DOMElement $element, string $tag): void
    {
        $keep = [];
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = trim($element->getAttribute('href'));
            if (preg_match('#^https?://#i', $href) === 1 || str_starts_with($href, '/')) {
                $keep['href'] = $href;
            }
        }
        if ($tag === 'img' && $element->hasAttribute('src')) {
            $src = trim($element->getAttribute('src'));
            if (preg_match('#^https?://#i', $src) === 1 || str_starts_with($src, '/')) {
                $keep['src'] = $src;
                $keep['alt'] = trim($element->getAttribute('alt'));
            }
        }
        if ($tag === 'pre' && $element->getAttribute('class') === 'tutorial-code-block') {
            $keep['class'] = 'tutorial-code-block';
            $language = strtolower(trim($element->getAttribute('data-language')));
            $role = strtolower(trim($element->getAttribute('data-role')));
            $keep['data-language'] = in_array($language, self::CODE_LANGUAGES, true) ? $language : 'text';
            $keep['data-role'] = $role === 'output' ? 'output' : 'code';
        }
        while ($element->attributes->length > 0) {
            $element->removeAttribute($element->attributes->item(0)->name);
        }
        foreach ($keep as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }
}
