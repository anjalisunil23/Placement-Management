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
use PMS\Models\TutorialModel;
use PMS\Models\TutorialModuleModel;
use PMS\Models\TutorialTestCaseModel;

/**
 * Tutorial catalog, authoring, and student visibility.
 * Does not run student code.
 */
final class TutorialService
{
    private const MIN_TIME_LIMIT_MS = 200;
    private const MAX_TIME_LIMIT_MS = 15000;
    private const MIN_MEMORY_LIMIT_KB = 2048;
    private const MAX_MEMORY_LIMIT_KB = 512000;
    private const MAX_SOURCE_CHARS = 65536;

    /** @var list<string> */
    private const HTML_TAGS = ['p', 'br', 'strong', 'em', 'u', 's', 'ol', 'ul', 'li', 'h1', 'h2', 'h3', 'blockquote', 'pre', 'code', 'a', 'img', 'span'];

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
            $out[] = [
                'id' => (string) ($row['_id'] ?? ''),
                'language' => (string) ($row['language'] ?? ''),
                'sourceCode' => (string) ($row['sourceCode'] ?? ''),
                'status' => 'ATTEMPTED',
                'submittedAt' => (string) ($row['submittedAt'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listManaged(array $user): array
    {
        $this->assertAuthor($user);
        $rows = AuthMiddleware::resolvedRole($user) === 'admin'
            ? $this->tutorials->findAll([], 500, 0, ['createdAt' => -1])
            : $this->tutorials->listByCreator($this->userId($user));
        $out = [];
        foreach ($rows as $row) {
            $view = $this->managedTutorial($row, false);
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
        $row = $this->ownedTutorial($user, $id);

        return $this->managedTutorial($row, true);
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

        return $this->managedTutorial($row, false);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTutorial(array $user, string $id, array $input): array
    {
        $existing = $this->ownedTutorial($user, $id);
        unset($input['createdBy'], $input['status'], $input['createdAt'], $input['_id']);
        $payload = $this->tutorialPayload($input, (string) ($existing['createdBy'] ?? ''), (string) ($existing['status'] ?? 'draft'));
        $row = $this->tutorials->updateTutorial($id, $payload);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteTutorial(array $user, string $id): void
    {
        $this->ownedTutorial($user, $id);
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
        $this->ownedTutorial($user, $id);
        $check = $this->publishChecklist($user, $id);
        if (!$check['canPublish']) {
            throw new \InvalidArgumentException($check['errors'][0] ?? 'This course cannot be published yet.');
        }
        $row = $this->tutorials->updateTutorial($id, ['status' => 'published']);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{canPublish: bool, checks: list<array{label: string, ok: bool}>, warnings: list<string>, errors: list<string>}
     */
    public function publishChecklist(array $user, string $id): array
    {
        $existing = $this->ownedTutorial($user, $id);
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
        $this->ownedTutorial($user, $id);
        $row = $this->tutorials->updateTutorial($id, ['status' => 'unpublished']);
        if ($row === null) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $this->managedTutorial($row, false);
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
        $this->ownedTutorial($user, $tutorialId);
        $row = $this->modules->create([
            'tutorialId' => $tutorialId,
            'title' => (string) ($input['title'] ?? ''),
            'sortOrder' => $input['sortOrder'] ?? $this->nextModuleOrder($tutorialId),
            'content' => self::sanitizeHtml((string) ($input['content'] ?? '')),
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
        $this->ownedTutorial($user, $tutorialId);
        $existing = $this->moduleOnTutorial($tutorialId, $moduleId);
        $row = $this->modules->updateModule($moduleId, [
            'tutorialId' => $tutorialId,
            'title' => (string) ($input['title'] ?? $existing['title'] ?? ''),
            'sortOrder' => $input['sortOrder'] ?? $existing['sortOrder'] ?? 1,
            'content' => array_key_exists('content', $input)
                ? self::sanitizeHtml((string) $input['content'])
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
        $this->ownedTutorial($user, $tutorialId);
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
        $this->ownedTutorial($user, $tutorialId);
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
        $order = 1;
        foreach (array_keys($clean) as $moduleId) {
            $module = $known[$moduleId];
            $this->modules->updateModule($moduleId, [
                'tutorialId' => $tutorialId,
                'title' => (string) ($module['title'] ?? ''),
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
        $this->ownedTutorial($user, $tutorialId);
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
        $this->ownedTutorial($user, $tutorialId);
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
        $this->ownedTutorial($user, $tutorialId);
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
        $this->ownedTutorial($user, $tutorialId);
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
        $visibility = (string) ($tutorial['visibility'] ?? '');
        if ($visibility === 'all') {
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
     * @return array<string, mixed>
     */
    private function ownedTutorial(array $user, string $id): array
    {
        $this->assertAuthor($user);
        $row = $this->tutorials->findById($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }
        if (AuthMiddleware::resolvedRole($user) !== 'admin' && (string) ($row['createdBy'] ?? '') !== $this->userId($user)) {
            throw new \RuntimeException('You can only change tutorials you created.', 403);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function ownedExercise(array $user, string $exerciseId): array
    {
        $exercise = $this->requireExercise($exerciseId);
        $module = $this->requireModule((string) ($exercise['moduleId'] ?? ''));
        $this->ownedTutorial($user, (string) ($module['tutorialId'] ?? ''));

        return $exercise;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function tutorialPayload(array $input, string $createdBy, string $status): array
    {
        $visibility = strtolower(trim((string) ($input['visibility'] ?? '')));
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
        $view = [
            'id' => (string) ($module['_id'] ?? ''),
            'title' => (string) ($module['title'] ?? ''),
            'sortOrder' => (int) ($module['sortOrder'] ?? 0),
        ];
        if ($includeContent) {
            $view['content'] = self::sanitizeHtml((string) ($module['content'] ?? ''));
            $view['exercises'] = [];
            foreach ($this->exercises->listByModule((string) ($module['_id'] ?? '')) as $exercise) {
                $view['exercises'][] = [
                    'id' => (string) ($exercise['_id'] ?? ''),
                    'title' => (string) ($exercise['title'] ?? ''),
                    'language' => (string) ($exercise['language'] ?? ''),
                    'sortOrder' => (int) ($exercise['sortOrder'] ?? 0),
                ];
            }
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
            'testCases' => $public,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function managedTutorial(array $row, bool $withChildren): array
    {
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
            'createdBy' => (string) ($row['createdBy'] ?? ''),
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
            'sortOrder' => (int) ($module['sortOrder'] ?? 0),
            'content' => (string) ($module['content'] ?? ''),
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
        while ($element->attributes->length > 0) {
            $element->removeAttribute($element->attributes->item(0)->name);
        }
        foreach ($keep as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }
}
