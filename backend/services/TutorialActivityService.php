<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Middleware\AuthMiddleware;
use PMS\Models\StudentModel;
use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleActivityReviewModel;
use PMS\Models\TutorialModuleActivitySubmissionModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Tutorial practical activities: staff authoring + student submission backend.
 * Independent of MCQ assessments and Coding execution. Never executes code/SQL.
 */
final class TutorialActivityService
{
    public function __construct(
        private ?TutorialService $tutorials = null,
        private ?TutorialModuleActivityModel $activities = null,
        private ?TutorialModuleActivitySubmissionModel $submissions = null,
        private ?TutorialModuleActivityReviewModel $reviews = null,
        private ?TutorialAIService $ai = null,
    ) {
        $this->tutorials = $tutorials ?? new TutorialService();
        $this->activities = $activities ?? new TutorialModuleActivityModel();
        $this->submissions = $submissions ?? new TutorialModuleActivitySubmissionModel();
        $this->reviews = $reviews ?? new TutorialModuleActivityReviewModel();
        $this->ai = $ai ?? new TutorialAIService(null, $this->tutorials);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listManaged(array $user, string $tutorialId, string $moduleId): array
    {
        $this->requireManagedModule($user, $tutorialId, $moduleId);
        $rows = $this->activities->listByModule($moduleId, false);

        return [
            'activities' => array_map(fn (array $row): array => $this->managedView($row, true), $rows),
            'count' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getManaged(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $activity = $this->requireManagedActivity($user, $tutorialId, $moduleId, $activityId);

        return $this->managedView($activity, true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->requireEditableManagedModule($user, $tutorialId, $moduleId);
        $userId = (string) ($user['_id'] ?? $user['id'] ?? '');
        $status = strtolower(trim((string) ($input['status'] ?? 'draft')));
        if ($status === 'published') {
            $this->assertCoursePublished($user, $tutorialId);
        } else {
            $status = 'draft';
        }
        $row = $this->activities->create([
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'title' => (string) ($input['title'] ?? ''),
            'instructions' => (string) ($input['instructions'] ?? ''),
            'activityType' => (string) ($input['activityType'] ?? ''),
            'academicField' => (string) ($input['academicField'] ?? 'other'),
            'difficulty' => (string) ($input['difficulty'] ?? 'beginner'),
            'sortOrder' => $input['sortOrder'] ?? $this->activities->nextSortOrder($moduleId),
            'status' => $status,
            'evaluationMode' => (string) ($input['evaluationMode'] ?? 'tutor_review'),
            'config' => is_array($input['config'] ?? null) ? $input['config'] : [],
            'answerKey' => is_array($input['answerKey'] ?? null) ? $input['answerKey'] : [],
            'archived' => false,
            'createdBy' => $userId,
            'lessonBlockId' => (string) ($input['lessonBlockId'] ?? ''),
        ]);

        return $this->managedView($row, true);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(array $user, string $tutorialId, string $moduleId, string $activityId, array $input): array
    {
        $existing = $this->requireEditableManagedActivity($user, $tutorialId, $moduleId, $activityId);
        if (($existing['archived'] ?? false) === true) {
            throw new \InvalidArgumentException('Archived activities cannot be edited. Create a new activity instead.');
        }
        $status = array_key_exists('status', $input)
            ? strtolower(trim((string) $input['status']))
            : (string) ($existing['status'] ?? 'draft');
        if ($status === 'published') {
            $this->assertCoursePublished($user, $tutorialId);
        } elseif (!in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }
        $row = $this->activities->updateActivity($activityId, [
            'title' => array_key_exists('title', $input) ? (string) $input['title'] : (string) ($existing['title'] ?? ''),
            'instructions' => array_key_exists('instructions', $input) ? (string) $input['instructions'] : (string) ($existing['instructions'] ?? ''),
            'activityType' => array_key_exists('activityType', $input) ? (string) $input['activityType'] : (string) ($existing['activityType'] ?? ''),
            'academicField' => array_key_exists('academicField', $input) ? (string) $input['academicField'] : (string) ($existing['academicField'] ?? 'other'),
            'difficulty' => array_key_exists('difficulty', $input) ? (string) $input['difficulty'] : (string) ($existing['difficulty'] ?? 'beginner'),
            'sortOrder' => array_key_exists('sortOrder', $input) ? (int) $input['sortOrder'] : (int) ($existing['sortOrder'] ?? 1),
            'status' => $status,
            'evaluationMode' => array_key_exists('evaluationMode', $input) ? (string) $input['evaluationMode'] : (string) ($existing['evaluationMode'] ?? 'tutor_review'),
            'config' => array_key_exists('config', $input) && is_array($input['config'])
                ? $input['config']
                : (is_array($existing['config'] ?? null) ? $existing['config'] : []),
            'answerKey' => array_key_exists('answerKey', $input) && is_array($input['answerKey'])
                ? $input['answerKey']
                : (is_array($existing['answerKey'] ?? null) ? $existing['answerKey'] : []),
            'archived' => ($existing['archived'] ?? false) === true,
            'lessonBlockId' => array_key_exists('lessonBlockId', $input)
                ? (string) $input['lessonBlockId']
                : (string) ($existing['lessonBlockId'] ?? ''),
        ]);
        if ($row === null) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $this->managedView($row, true);
    }

    /**
     * Soft-archive. Preserves the row and any future historical submissions.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function archive(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $this->requireEditableManagedActivity($user, $tutorialId, $moduleId, $activityId);
        $row = $this->activities->archive($activityId);
        if ($row === null) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $this->managedView($row, true);
    }

    /**
     * @param array<string, mixed> $user
     * @param list<mixed> $activityIds
     * @return array<string, mixed>
     */
    public function reorder(array $user, string $tutorialId, string $moduleId, array $activityIds): array
    {
        $this->requireEditableManagedModule($user, $tutorialId, $moduleId);
        $existing = $this->activities->listByModule($moduleId, false);
        $known = [];
        foreach ($existing as $row) {
            $known[(string) ($row['_id'] ?? '')] = $row;
        }
        $clean = [];
        foreach ($activityIds as $id) {
            $id = trim((string) $id);
            if (!isset($known[$id]) || isset($clean[$id])) {
                throw new \InvalidArgumentException('Reorder list must contain each active activity of this module once.');
            }
            $clean[$id] = true;
        }
        if (count($clean) !== count($known)) {
            throw new \InvalidArgumentException('Reorder list must contain each active activity of this module once.');
        }
        $order = 1;
        foreach (array_keys($clean) as $id) {
            $row = $known[$id];
            $this->activities->updateActivity($id, [
                'title' => (string) ($row['title'] ?? ''),
                'instructions' => (string) ($row['instructions'] ?? ''),
                'activityType' => (string) ($row['activityType'] ?? ''),
                'academicField' => (string) ($row['academicField'] ?? 'other'),
                'difficulty' => (string) ($row['difficulty'] ?? 'beginner'),
                'sortOrder' => $order,
                'status' => (string) ($row['status'] ?? 'draft'),
                'evaluationMode' => (string) ($row['evaluationMode'] ?? 'tutor_review'),
                'config' => is_array($row['config'] ?? null) ? $row['config'] : [],
                'answerKey' => is_array($row['answerKey'] ?? null) ? $row['answerKey'] : [],
                'archived' => false,
            ]);
            $order++;
        }

        return $this->listManaged($user, $tutorialId, $moduleId);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function publish(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $existing = $this->requireEditableManagedActivity($user, $tutorialId, $moduleId, $activityId);
        if (($existing['archived'] ?? false) === true) {
            throw new \InvalidArgumentException('Archived activities cannot be published.');
        }
        $this->assertCoursePublished($user, $tutorialId);
        $row = $this->activities->updateActivity($activityId, [
            'title' => (string) ($existing['title'] ?? ''),
            'instructions' => (string) ($existing['instructions'] ?? ''),
            'activityType' => (string) ($existing['activityType'] ?? ''),
            'academicField' => (string) ($existing['academicField'] ?? 'other'),
            'difficulty' => (string) ($existing['difficulty'] ?? 'beginner'),
            'sortOrder' => (int) ($existing['sortOrder'] ?? 1),
            'status' => 'published',
            'evaluationMode' => (string) ($existing['evaluationMode'] ?? 'tutor_review'),
            'config' => is_array($existing['config'] ?? null) ? $existing['config'] : [],
            'answerKey' => is_array($existing['answerKey'] ?? null) ? $existing['answerKey'] : [],
            'archived' => false,
        ]);
        if ($row === null) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $this->managedView($row, true);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function unpublish(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $existing = $this->requireEditableManagedActivity($user, $tutorialId, $moduleId, $activityId);
        $row = $this->activities->updateActivity($activityId, [
            'title' => (string) ($existing['title'] ?? ''),
            'instructions' => (string) ($existing['instructions'] ?? ''),
            'activityType' => (string) ($existing['activityType'] ?? ''),
            'academicField' => (string) ($existing['academicField'] ?? 'other'),
            'difficulty' => (string) ($existing['difficulty'] ?? 'beginner'),
            'sortOrder' => (int) ($existing['sortOrder'] ?? 1),
            'status' => 'draft',
            'evaluationMode' => (string) ($existing['evaluationMode'] ?? 'tutor_review'),
            'config' => is_array($existing['config'] ?? null) ? $existing['config'] : [],
            'answerKey' => is_array($existing['answerKey'] ?? null) ? $existing['answerKey'] : [],
            'archived' => ($existing['archived'] ?? false) === true,
        ]);
        if ($row === null) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $this->managedView($row, true);
    }

    /**
     * AI practical-activity preview for a managed module. Does not persist.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generateForModule(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->requireEditableManagedModule($user, $tutorialId, $moduleId);
        $ctx = $this->staffModuleContext($user, $tutorialId, $moduleId);
        if (isset($input['academicField']) && is_string($input['academicField']) && $input['academicField'] !== '') {
            $ctx['academicField'] = $input['academicField'];
        }

        return $this->ai->generateActivityPreview($user, $ctx, $input);
    }

    /**
     * Persist an approved activity preview as a draft via create().
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveGenerated(array $user, string $tutorialId, string $moduleId, array $input): array
    {
        $this->requireEditableManagedModule($user, $tutorialId, $moduleId);
        $raw = is_array($input['activity'] ?? null) ? $input['activity'] : $input;
        $normalized = $this->ai->normalizeActivityPreview($raw, [
            'activityType' => (string) ($raw['activityType'] ?? $input['activityType'] ?? ''),
            'difficulty' => (string) ($raw['difficulty'] ?? $input['difficulty'] ?? 'beginner'),
            'academicField' => (string) ($raw['academicField'] ?? $input['academicField'] ?? 'other'),
            'evaluationMode' => (string) ($raw['evaluationMode'] ?? $input['evaluationMode'] ?? ''),
        ]);
        $normalized['status'] = 'draft';

        return $this->create($user, $tutorialId, $moduleId, $normalized);
    }

    /**
     * Staff queue of submitted practical-activity attempts for a managed course.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listSubmissionsManaged(array $user, string $tutorialId, array $filters = []): array
    {
        $course = $this->tutorials->showManaged($user, $tutorialId);
        $moduleId = trim((string) ($filters['moduleId'] ?? ''));
        $activityId = trim((string) ($filters['activityId'] ?? ''));
        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));
        if (!in_array($status, ['all', 'pending', 'reviewed'], true)) {
            $status = 'all';
        }
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $rows = $this->submissions->listSubmittedForTutorial(
            $tutorialId,
            $moduleId !== '' ? $moduleId : null,
            $activityId !== '' ? $activityId : null,
            $limit,
            0
        );
        $moduleTitles = [];
        foreach ((array) ($course['modules'] ?? []) as $module) {
            $moduleTitles[(string) ($module['id'] ?? '')] = (string) ($module['title'] ?? 'Module');
        }
        $out = [];
        foreach ($rows as $row) {
            $item = $this->managedSubmissionSummary($row, $moduleTitles);
            $reviewStatus = (string) ($item['review']['status'] ?? 'pending');
            if ($status === 'pending' && $reviewStatus === 'reviewed') {
                continue;
            }
            if ($status === 'reviewed' && $reviewStatus !== 'reviewed') {
                continue;
            }
            $out[] = $item;
        }

        return [
            'submissions' => $out,
            'count' => count($out),
            'tutorial' => [
                'id' => (string) ($course['id'] ?? $tutorialId),
                'title' => (string) ($course['title'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getSubmissionManaged(array $user, string $tutorialId, string $submissionId): array
    {
        $this->tutorials->showManaged($user, $tutorialId);
        $submission = $this->requireManagedSubmission($tutorialId, $submissionId);
        if ((string) ($submission['status'] ?? '') !== 'SUBMITTED') {
            throw new \InvalidArgumentException('Only submitted attempts can be reviewed.');
        }

        return $this->managedSubmissionDetail($submission);
    }

    /**
     * Save a draft (pending) tutor review. Does not finalize.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveReviewManaged(array $user, string $tutorialId, string $submissionId, array $input): array
    {
        return $this->persistReviewManaged($user, $tutorialId, $submissionId, $input, false);
    }

    /**
     * Finalize a tutor review (status=reviewed). Idempotent if already reviewed with same payload.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function finalizeReviewManaged(array $user, string $tutorialId, string $submissionId, array $input): array
    {
        return $this->persistReviewManaged($user, $tutorialId, $submissionId, $input, true);
    }

    /**
     * Student-safe projection. Never includes answerKey.
     *
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    public function studentSafeView(array $activity): array
    {
        return [
            'id' => (string) ($activity['_id'] ?? $activity['id'] ?? ''),
            'tutorialId' => (string) ($activity['tutorialId'] ?? ''),
            'moduleId' => (string) ($activity['moduleId'] ?? ''),
            'title' => (string) ($activity['title'] ?? ''),
            'instructions' => (string) ($activity['instructions'] ?? ''),
            'activityType' => (string) ($activity['activityType'] ?? ''),
            'academicField' => (string) ($activity['academicField'] ?? 'other'),
            'difficulty' => (string) ($activity['difficulty'] ?? 'beginner'),
            'sortOrder' => (int) ($activity['sortOrder'] ?? 0),
            'status' => (string) ($activity['status'] ?? 'draft'),
            'evaluationMode' => (string) ($activity['evaluationMode'] ?? 'tutor_review'),
            'lessonBlockId' => (string) ($activity['lessonBlockId'] ?? ''),
            'config' => $this->publicConfig(
                (string) ($activity['activityType'] ?? ''),
                is_array($activity['config'] ?? null) ? $activity['config'] : []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listForStudent(array $user, string $tutorialId, string $moduleId): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $this->studentProfile($user);
        $rows = [];
        foreach ($this->activities->listByModule($moduleId, false) as $row) {
            if ((string) ($row['status'] ?? '') !== 'published') {
                continue;
            }
            if (($row['archived'] ?? false) === true) {
                continue;
            }
            $rows[] = $this->studentSafeView($row);
        }

        return [
            'activities' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getForStudent(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $activity = $this->requirePublishedActivityForStudent($user, $tutorialId, $moduleId, $activityId);

        return $this->studentSafeView($activity);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function listAttemptsForStudent(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $activity = $this->requireActivityInModule($tutorialId, $moduleId, $activityId);
        $rows = $this->submissions->listForStudent((string) ($student['_id'] ?? ''), (string) ($activity['_id'] ?? ''));

        return [
            'attempts' => array_map(fn (array $row): array => $this->publicAttempt($row), $rows),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function startAttempt(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $activity = $this->requirePublishedActivityForStudent($user, $tutorialId, $moduleId, $activityId);
        $student = $this->studentProfile($user);
        $studentId = (string) ($student['_id'] ?? '');
        $mode = (string) ($activity['evaluationMode'] ?? 'tutor_review');
        if ($mode === 'none') {
            throw new \RuntimeException('This activity does not accept student submissions.', 403);
        }
        $existing = $this->submissions->findInProgress($studentId, $activityId);
        if ($existing !== null) {
            return [
                'attempt' => $this->publicAttempt($existing),
                'resumed' => true,
            ];
        }
        $snapshot = $this->buildActivitySnapshot($activity);
        try {
            $attempt = $this->submissions->createSubmission([
                'activityId' => $activityId,
                'tutorialId' => $tutorialId,
                'moduleId' => $moduleId,
                'studentId' => $studentId,
                'attemptNumber' => $this->submissions->nextAttemptNumber($studentId, $activityId),
                'status' => 'IN_PROGRESS',
                'payload' => [],
                'autoResult' => null,
                'activitySnapshot' => $snapshot,
            ]);
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                error_log('[PMS TutorialActivity] startAttempt failed: ' . $e->getMessage());
                throw new \RuntimeException('Could not start the activity. Please try again.', 500);
            }
            $raceExisting = $this->submissions->findInProgress($studentId, $activityId);
            if ($raceExisting !== null) {
                return [
                    'attempt' => $this->publicAttempt($raceExisting),
                    'resumed' => true,
                ];
            }
            throw new \RuntimeException('Could not start the activity. Please try again.', 409);
        }

        return [
            'attempt' => $this->publicAttempt($attempt),
            'resumed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveResponse(array $user, string $tutorialId, string $moduleId, string $activityId, array $input): array
    {
        $attempt = $this->requireOwnedInProgressAttempt($user, $tutorialId, $moduleId, $activityId, $input);
        $snapshot = is_array($attempt['activitySnapshot'] ?? null) ? $attempt['activitySnapshot'] : [];
        $type = (string) ($snapshot['activityType'] ?? '');
        $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];
        $response = $this->normalizeResponse($type, $config, $input, false);
        $updated = $this->submissions->updateSubmission((string) ($attempt['_id'] ?? ''), [
            'status' => 'IN_PROGRESS',
            'payload' => $response,
        ]);
        if ($updated === null) {
            throw new \RuntimeException('Activity attempt not found.', 404);
        }

        return [
            'attempt' => $this->publicAttempt($updated),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submitResponse(array $user, string $tutorialId, string $moduleId, string $activityId, array $input): array
    {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $studentId = (string) ($student['_id'] ?? '');
        $activity = $this->requireActivityInModule($tutorialId, $moduleId, $activityId);
        $attempt = $this->resolveOwnedAttempt($studentId, $activityId, $input);
        if ((string) ($attempt['status'] ?? '') === 'SUBMITTED') {
            return [
                'attempt' => $this->publicAttempt($attempt),
                'idempotent' => true,
            ];
        }
        if ((string) ($attempt['status'] ?? '') !== 'IN_PROGRESS') {
            throw new \RuntimeException('This attempt cannot be submitted.', 409);
        }

        $snapshot = is_array($attempt['activitySnapshot'] ?? null) ? $attempt['activitySnapshot'] : [];
        $type = (string) ($snapshot['activityType'] ?? ($activity['activityType'] ?? ''));
        $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];
        $mode = (string) ($snapshot['evaluationMode'] ?? ($activity['evaluationMode'] ?? 'tutor_review'));
        $answerKey = is_array($snapshot['answerKey'] ?? null) ? $snapshot['answerKey'] : [];
        $existingPayload = is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [];
        $hasIncoming = $this->inputHasResponse($input);
        $response = $hasIncoming
            ? $this->normalizeResponse($type, $config, $input, true)
            : $this->normalizeResponse($type, $config, ['response' => $existingPayload], true);

        $autoResult = $this->evaluateSubmission($type, $mode, $config, $answerKey, $response);

        $pdo = Database::pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $fresh = $this->submissions->findById((string) ($attempt['_id'] ?? ''));
            if (is_array($fresh) && (string) ($fresh['status'] ?? '') === 'SUBMITTED') {
                if ($startedTx && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                return [
                    'attempt' => $this->publicAttempt($fresh),
                    'idempotent' => true,
                ];
            }
            $updated = $this->submissions->updateSubmission((string) ($attempt['_id'] ?? ''), [
                'status' => 'SUBMITTED',
                'payload' => $response,
                'autoResult' => $autoResult,
                'submittedAt' => DocumentHelper::now(),
            ]);
            if ($updated === null) {
                throw new \RuntimeException('Activity attempt not found.', 404);
            }
            if ($mode === 'tutor_review') {
                $this->ensurePendingReview($updated, $activity);
            }
            if ($startedTx) {
                $pdo->commit();
            }

            return [
                'attempt' => $this->publicAttempt($updated),
                'idempotent' => false,
            ];
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    private function managedView(array $activity, bool $withAnswerKey): array
    {
        $view = [
            'id' => (string) ($activity['_id'] ?? ''),
            'tutorialId' => (string) ($activity['tutorialId'] ?? ''),
            'moduleId' => (string) ($activity['moduleId'] ?? ''),
            'title' => (string) ($activity['title'] ?? ''),
            'instructions' => (string) ($activity['instructions'] ?? ''),
            'activityType' => (string) ($activity['activityType'] ?? ''),
            'academicField' => (string) ($activity['academicField'] ?? 'other'),
            'difficulty' => (string) ($activity['difficulty'] ?? 'beginner'),
            'sortOrder' => (int) ($activity['sortOrder'] ?? 0),
            'status' => (string) ($activity['status'] ?? 'draft'),
            'evaluationMode' => (string) ($activity['evaluationMode'] ?? 'tutor_review'),
            'lessonBlockId' => (string) ($activity['lessonBlockId'] ?? ''),
            'config' => is_array($activity['config'] ?? null) ? $activity['config'] : [],
            'archived' => ($activity['archived'] ?? false) === true,
            'createdBy' => (string) ($activity['createdBy'] ?? ''),
            'submissionCount' => $this->submissions->countByActivity((string) ($activity['_id'] ?? '')),
        ];
        if ($withAnswerKey) {
            $view['answerKey'] = is_array($activity['answerKey'] ?? null) ? $activity['answerKey'] : [];
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function publicConfig(string $type, array $config): array
    {
        unset($config['expectedValue']);
        if ($type === 'numerical') {
            return [
                'unit' => (string) ($config['unit'] ?? ''),
                'tolerance' => (float) ($config['tolerance'] ?? 0),
                'promptHint' => (string) ($config['promptHint'] ?? ''),
            ];
        }
        if ($type === 'short_answer') {
            return [
                'maxLength' => (int) ($config['maxLength'] ?? 1000),
                // Rubric is disclosed after self-check submit; keep empty in live activity view.
                'selfCheckRubric' => '',
            ];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    private function buildActivitySnapshot(array $activity): array
    {
        return [
            'id' => (string) ($activity['_id'] ?? ''),
            'title' => (string) ($activity['title'] ?? ''),
            'instructions' => (string) ($activity['instructions'] ?? ''),
            'activityType' => (string) ($activity['activityType'] ?? ''),
            'academicField' => (string) ($activity['academicField'] ?? 'other'),
            'difficulty' => (string) ($activity['difficulty'] ?? 'beginner'),
            'evaluationMode' => (string) ($activity['evaluationMode'] ?? 'tutor_review'),
            'config' => is_array($activity['config'] ?? null) ? $activity['config'] : [],
            'answerKey' => is_array($activity['answerKey'] ?? null) ? $activity['answerKey'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function publicAttempt(array $attempt): array
    {
        $snapshot = is_array($attempt['activitySnapshot'] ?? null) ? $attempt['activitySnapshot'] : [];
        $publicSnapshot = $this->studentSafeView([
            '_id' => (string) ($snapshot['id'] ?? ''),
            'tutorialId' => (string) ($attempt['tutorialId'] ?? ''),
            'moduleId' => (string) ($attempt['moduleId'] ?? ''),
            'title' => (string) ($snapshot['title'] ?? ''),
            'instructions' => (string) ($snapshot['instructions'] ?? ''),
            'activityType' => (string) ($snapshot['activityType'] ?? ''),
            'academicField' => (string) ($snapshot['academicField'] ?? 'other'),
            'difficulty' => (string) ($snapshot['difficulty'] ?? 'beginner'),
            'sortOrder' => 0,
            'status' => 'published',
            'evaluationMode' => (string) ($snapshot['evaluationMode'] ?? 'tutor_review'),
            'config' => is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [],
        ]);
        // Historical self-check rubric disclosure after submit.
        if (
            (string) ($attempt['status'] ?? '') === 'SUBMITTED'
            && (string) ($snapshot['evaluationMode'] ?? '') === 'self_check'
            && (string) ($snapshot['activityType'] ?? '') === 'short_answer'
        ) {
            $publicSnapshot['config']['selfCheckRubric'] = (string) (($snapshot['config']['selfCheckRubric'] ?? '') ?: '');
        }

        $reviewRow = $this->reviews->findBySubmission((string) ($attempt['_id'] ?? ''));
        $autoResult = $this->publicAutoResult(
            is_array($attempt['autoResult'] ?? null) ? $attempt['autoResult'] : null,
            $reviewRow
        );

        return [
            'id' => (string) ($attempt['_id'] ?? ''),
            'activityId' => (string) ($attempt['activityId'] ?? ''),
            'tutorialId' => (string) ($attempt['tutorialId'] ?? ''),
            'moduleId' => (string) ($attempt['moduleId'] ?? ''),
            'attemptNumber' => (int) ($attempt['attemptNumber'] ?? 0),
            'status' => (string) ($attempt['status'] ?? ''),
            'payload' => is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [],
            'autoResult' => $autoResult,
            'review' => $this->publicReviewForStudent($reviewRow),
            'activitySnapshot' => $publicSnapshot,
            'startedAt' => $attempt['startedAt'] ?? ($attempt['createdAt'] ?? null),
            'submittedAt' => $attempt['submittedAt'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>|null $autoResult
     * @param array<string, mixed>|null $review
     * @return array<string, mixed>|null
     */
    private function publicAutoResult(?array $autoResult, ?array $review = null): ?array
    {
        if ($autoResult === null) {
            return null;
        }
        $mode = (string) ($autoResult['mode'] ?? '');
        if ($mode === 'auto_compare') {
            return [
                'mode' => 'auto_compare',
                'matched' => ($autoResult['matched'] ?? false) === true,
            ];
        }
        if ($mode === 'self_check') {
            return [
                'mode' => 'self_check',
                'rubric' => (string) ($autoResult['rubric'] ?? ''),
                'keywordsMatched' => (int) ($autoResult['keywordsMatched'] ?? 0),
                'keywordsTotal' => (int) ($autoResult['keywordsTotal'] ?? 0),
            ];
        }
        if ($mode === 'tutor_review') {
            $reviewed = is_array($review) && (string) ($review['status'] ?? '') === 'reviewed';

            return [
                'mode' => 'tutor_review',
                'status' => $reviewed ? 'reviewed' : 'pending_review',
            ];
        }

        return ['mode' => $mode !== '' ? $mode : 'none'];
    }

    /**
     * @param array<string, mixed>|null $review
     * @return array<string, mixed>|null
     */
    private function publicReviewForStudent(?array $review): ?array
    {
        if (!is_array($review)) {
            return null;
        }
        $status = (string) ($review['status'] ?? 'pending');
        if ($status !== 'reviewed') {
            return ['status' => 'pending'];
        }

        return [
            'status' => 'reviewed',
            'score' => array_key_exists('score', $review) && $review['score'] !== null ? (float) $review['score'] : null,
            'maxScore' => array_key_exists('maxScore', $review) && $review['maxScore'] !== null ? (float) $review['maxScore'] : null,
            'passed' => array_key_exists('passed', $review) && $review['passed'] !== null
                ? (($review['passed'] ?? false) === true)
                : null,
            'feedback' => (string) ($review['feedback'] ?? ''),
            'reviewedAt' => $review['updatedAt'] ?? ($review['createdAt'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function requireOwnedInProgressAttempt(
        array $user,
        string $tutorialId,
        string $moduleId,
        string $activityId,
        array $input
    ): array {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $student = $this->studentProfile($user);
        $this->requireActivityInModule($tutorialId, $moduleId, $activityId);
        $attempt = $this->resolveOwnedAttempt((string) ($student['_id'] ?? ''), $activityId, $input);
        if ((string) ($attempt['status'] ?? '') !== 'IN_PROGRESS') {
            throw new \RuntimeException('Submitted attempts cannot be edited.', 409);
        }

        return $attempt;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function resolveOwnedAttempt(string $studentId, string $activityId, array $input): array
    {
        $attemptId = trim((string) ($input['attemptId'] ?? ''));
        if ($attemptId !== '' && !Security::isValidId($attemptId)) {
            throw new \RuntimeException('Activity attempt not found.', 404);
        }
        $attempt = $attemptId !== ''
            ? $this->submissions->findById($attemptId)
            : $this->submissions->findInProgress($studentId, $activityId);
        if (
            !is_array($attempt)
            || (string) ($attempt['studentId'] ?? '') !== $studentId
            || (string) ($attempt['activityId'] ?? '') !== $activityId
        ) {
            throw new \RuntimeException('Activity attempt not found.', 404);
        }

        return $attempt;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function inputHasResponse(array $input): bool
    {
        if (array_key_exists('response', $input) && is_array($input['response'])) {
            return true;
        }
        foreach (['source', 'sql', 'value', 'text', 'parts', 'unit'] as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeResponse(string $type, array $config, array $input, bool $requireComplete): array
    {
        $raw = is_array($input['response'] ?? null) ? $input['response'] : $input;
        // Never trust client evaluation fields.
        unset(
            $raw['score'],
            $raw['passed'],
            $raw['correct'],
            $raw['matched'],
            $raw['autoResult'],
            $raw['expectedValue'],
            $raw['answerKey'],
            $raw['isCorrect'],
            $raw['percent'],
            $raw['marks']
        );

        return match ($type) {
            'programming_task' => $this->normalizeTextField($raw, 'source', 20000, $requireComplete, 'Source code'),
            'sql_query' => $this->normalizeTextField($raw, 'sql', 20000, $requireComplete, 'SQL'),
            'numerical' => $this->normalizeNumericalResponse($raw, $requireComplete),
            'short_answer' => $this->normalizeTextField(
                $raw,
                'text',
                min(5000, max(50, (int) ($config['maxLength'] ?? 1000))),
                $requireComplete,
                'Answer'
            ),
            'case_study' => $this->normalizeCaseStudyResponse($raw, $config, $requireComplete),
            'analytical_design' => $this->normalizeTextField(
                $raw,
                'text',
                min(20000, max(100, (int) ($config['maxLength'] ?? 5000))),
                $requireComplete,
                'Response'
            ),
            default => throw new \InvalidArgumentException('Unsupported activity type.'),
        };
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeTextField(array $raw, string $key, int $maxLen, bool $requireComplete, string $label): array
    {
        $text = (string) ($raw[$key] ?? '');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
        if (mb_strlen($text) > $maxLen) {
            throw new \InvalidArgumentException($label . ' is too long.');
        }
        if ($requireComplete && trim($text) === '') {
            throw new \InvalidArgumentException($label . ' is required.');
        }

        return [$key => $text];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeNumericalResponse(array $raw, bool $requireComplete): array
    {
        if (!array_key_exists('value', $raw) || $raw['value'] === '' || $raw['value'] === null) {
            if ($requireComplete) {
                throw new \InvalidArgumentException('Numeric value is required.');
            }

            return [
                'value' => null,
                'unit' => mb_substr(trim(strip_tags((string) ($raw['unit'] ?? ''))), 0, 40),
            ];
        }
        if (!is_numeric($raw['value'])) {
            throw new \InvalidArgumentException('Numeric value must be a number.');
        }
        $value = (float) $raw['value'];
        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Numeric value must be a finite number.');
        }

        return [
            'value' => $value,
            'unit' => mb_substr(trim(strip_tags((string) ($raw['unit'] ?? ''))), 0, 40),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeCaseStudyResponse(array $raw, array $config, bool $requireComplete): array
    {
        $expected = [];
        foreach (is_array($config['parts'] ?? null) ? $config['parts'] : [] as $part) {
            if (!is_array($part)) {
                continue;
            }
            $id = trim((string) ($part['id'] ?? ''));
            if ($id !== '') {
                $expected[$id] = true;
            }
        }
        if ($expected === []) {
            throw new \InvalidArgumentException('Case study configuration is invalid.');
        }
        $incoming = $raw['parts'] ?? [];
        $normalized = [];
        if (is_array($incoming)) {
            $isList = array_is_list($incoming);
            foreach ($incoming as $key => $value) {
                if ($isList) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $id = trim((string) ($value['id'] ?? ''));
                    $text = (string) ($value['response'] ?? $value['text'] ?? '');
                } else {
                    $id = trim((string) $key);
                    $text = is_array($value) ? (string) ($value['response'] ?? $value['text'] ?? '') : (string) $value;
                }
                if ($id === '' || !isset($expected[$id])) {
                    throw new \InvalidArgumentException('Response references an unknown case-study part.');
                }
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
                if (mb_strlen($text) > 2000) {
                    throw new \InvalidArgumentException('A case-study part response is too long.');
                }
                $normalized[$id] = $text;
            }
        }
        if ($requireComplete) {
            foreach (array_keys($expected) as $id) {
                if (!isset($normalized[$id]) || trim($normalized[$id]) === '') {
                    throw new \InvalidArgumentException('Answer every case-study part before submitting.');
                }
            }
        }

        return ['parts' => $normalized];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $answerKey
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function evaluateSubmission(
        string $type,
        string $mode,
        array $config,
        array $answerKey,
        array $response
    ): array {
        if ($mode === 'auto_compare' && $type === 'numerical') {
            if (!array_key_exists('expectedValue', $answerKey) || !is_numeric($answerKey['expectedValue'])) {
                throw new \RuntimeException('This activity cannot be auto-scored.', 500);
            }
            $expected = (float) $answerKey['expectedValue'];
            $tolerance = max(0.0, (float) ($config['tolerance'] ?? 0));
            $value = (float) ($response['value'] ?? NAN);
            $matched = is_finite($value) && abs($value - $expected) <= $tolerance;

            return [
                'mode' => 'auto_compare',
                'matched' => $matched,
            ];
        }
        if ($mode === 'self_check' && $type === 'short_answer') {
            $text = mb_strtolower((string) ($response['text'] ?? ''));
            $keywords = [];
            foreach (array_values((array) ($answerKey['keywords'] ?? [])) as $word) {
                $word = trim((string) $word);
                if ($word !== '') {
                    $keywords[] = $word;
                }
            }
            $matched = 0;
            foreach ($keywords as $word) {
                if ($word !== '' && str_contains($text, mb_strtolower($word))) {
                    $matched++;
                }
            }

            return [
                'mode' => 'self_check',
                'rubric' => (string) ($config['selfCheckRubric'] ?? ''),
                'keywordsMatched' => $matched,
                'keywordsTotal' => count($keywords),
            ];
        }
        if ($mode === 'tutor_review') {
            return ['mode' => 'tutor_review', 'status' => 'pending_review'];
        }

        return ['mode' => $mode];
    }

    /**
     * @param array<string, mixed> $submission
     * @param array<string, mixed> $activity
     */
    private function ensurePendingReview(array $submission, array $activity): void
    {
        $submissionId = (string) ($submission['_id'] ?? '');
        if ($submissionId === '' || $this->reviews->findBySubmission($submissionId) !== null) {
            return;
        }
        $reviewer = (string) ($activity['createdBy'] ?? '');
        if (!Security::isValidId($reviewer)) {
            return;
        }
        try {
            $this->reviews->createReview([
                'submissionId' => $submissionId,
                'activityId' => (string) ($submission['activityId'] ?? ''),
                'reviewerUserId' => $reviewer,
                'status' => 'pending',
                'score' => null,
                'maxScore' => null,
                'passed' => null,
                'feedback' => '',
                'privateNotes' => '',
            ]);
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requirePublishedActivityForStudent(
        array $user,
        string $tutorialId,
        string $moduleId,
        string $activityId
    ): array {
        $this->tutorials->moduleForStudent($user, $tutorialId, $moduleId);
        $this->studentProfile($user);
        $activity = $this->requireActivityInModule($tutorialId, $moduleId, $activityId);
        if ((string) ($activity['status'] ?? '') !== 'published' || ($activity['archived'] ?? false) === true) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActivityInModule(string $tutorialId, string $moduleId, string $activityId): array
    {
        if (!Security::isValidId($activityId)) {
            throw new \RuntimeException('Activity not found.', 404);
        }
        $activity = $this->activities->findById($activityId);
        if (
            !is_array($activity)
            || (string) ($activity['tutorialId'] ?? '') !== $tutorialId
            || (string) ($activity['moduleId'] ?? '') !== $moduleId
        ) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $activity;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function studentProfile(array $user): array
    {
        if (AuthMiddleware::resolvedRole($user) !== 'student') {
            throw new \RuntimeException('Tutorial not found.', 404);
        }
        $student = (new StudentModel())->findByUserId((string) ($user['_id'] ?? $user['id'] ?? ''));
        if (!is_array($student)) {
            throw new \RuntimeException('Tutorial not found.', 404);
        }

        return $student;
    }

    private function isDuplicateKeyException(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        if ($code === '23000') {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, '1062') || stripos($msg, 'Duplicate') !== false;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function persistReviewManaged(
        array $user,
        string $tutorialId,
        string $submissionId,
        array $input,
        bool $finalize
    ): array {
        $this->tutorials->showManaged($user, $tutorialId);
        $reviewerUserId = (string) ($user['_id'] ?? $user['id'] ?? '');
        if (!Security::isValidId($reviewerUserId)) {
            throw new \RuntimeException('You do not have permission to manage tutorials.', 403);
        }
        $submission = $this->requireManagedSubmission($tutorialId, $submissionId);
        if ((string) ($submission['status'] ?? '') !== 'SUBMITTED') {
            throw new \InvalidArgumentException('Only submitted attempts can be reviewed.');
        }
        $snapshot = is_array($submission['activitySnapshot'] ?? null) ? $submission['activitySnapshot'] : [];
        $mode = (string) ($snapshot['evaluationMode'] ?? '');
        if ($mode !== 'tutor_review') {
            throw new \InvalidArgumentException('Manual tutor review is only available for tutor_review activities.');
        }
        // Never let staff overwrite automated evaluation payloads.
        $autoMode = (string) (($submission['autoResult']['mode'] ?? '') ?: '');
        if (in_array($autoMode, ['auto_compare', 'self_check'], true)) {
            throw new \InvalidArgumentException('Automated evaluation results cannot be overwritten by tutor review.');
        }

        $score = $this->nullableScore($input, 'score');
        $maxScore = $this->nullableScore($input, 'maxScore');
        if ($score !== null && $maxScore === null) {
            throw new \InvalidArgumentException('maxScore is required when score is provided.');
        }
        if ($maxScore !== null && $maxScore <= 0) {
            throw new \InvalidArgumentException('maxScore must be greater than zero.');
        }
        if ($score !== null && $maxScore !== null && $score > $maxScore) {
            throw new \InvalidArgumentException('Score cannot exceed maxScore.');
        }
        $passed = null;
        if (array_key_exists('passed', $input) && $input['passed'] !== null && $input['passed'] !== '') {
            $passed = ($input['passed'] === true || $input['passed'] === 1 || $input['passed'] === '1');
        }
        $feedback = (string) ($input['feedback'] ?? '');
        $privateNotes = (string) ($input['privateNotes'] ?? '');
        $status = $finalize ? 'reviewed' : 'pending';

        $existing = $this->reviews->findBySubmission((string) ($submission['_id'] ?? ''));
        $payload = [
            'submissionId' => (string) ($submission['_id'] ?? ''),
            'activityId' => (string) ($submission['activityId'] ?? ''),
            'reviewerUserId' => $reviewerUserId,
            'status' => $status,
            'score' => $score,
            'maxScore' => $maxScore,
            'passed' => $passed,
            'feedback' => $feedback,
            'privateNotes' => $privateNotes,
        ];
        if (is_array($existing)) {
            // Idempotent finalize: already reviewed stays reviewed.
            if ($finalize && (string) ($existing['status'] ?? '') === 'reviewed' && !$this->reviewInputChanged($existing, $payload)) {
                return $this->managedSubmissionDetail($submission);
            }
            $this->reviews->updateReview((string) ($existing['_id'] ?? ''), $payload);
        } else {
            try {
                $this->reviews->createReview($payload);
            } catch (\PDOException $e) {
                if (!$this->isDuplicateKeyException($e)) {
                    throw $e;
                }
                $race = $this->reviews->findBySubmission((string) ($submission['_id'] ?? ''));
                if (!is_array($race)) {
                    throw new \RuntimeException('Could not save the review. Please try again.', 409);
                }
                $this->reviews->updateReview((string) ($race['_id'] ?? ''), $payload);
            }
        }

        return $this->managedSubmissionDetail($submission);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     */
    private function reviewInputChanged(array $existing, array $incoming): bool
    {
        $fields = ['score', 'maxScore', 'passed', 'feedback', 'privateNotes'];
        foreach ($fields as $field) {
            $left = $existing[$field] ?? null;
            $right = $incoming[$field] ?? null;
            if ($field === 'feedback' || $field === 'privateNotes') {
                if (trim((string) $left) !== trim((string) $right)) {
                    return true;
                }
                continue;
            }
            if ($left === null && $right === null) {
                continue;
            }
            if ((string) $left !== (string) $right) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nullableScore(array $input, string $key): ?float
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        if (!is_numeric($input[$key])) {
            throw new \InvalidArgumentException(ucfirst($key) . ' must be numeric.');
        }
        $value = (float) $input[$key];
        if (!is_finite($value) || $value < 0 || $value > 1000) {
            throw new \InvalidArgumentException(ucfirst($key) . ' is out of range.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireManagedSubmission(string $tutorialId, string $submissionId): array
    {
        if (!Security::isValidId($submissionId)) {
            throw new \RuntimeException('Submission not found.', 404);
        }
        $submission = $this->submissions->findById($submissionId);
        if (!is_array($submission) || (string) ($submission['tutorialId'] ?? '') !== $tutorialId) {
            throw new \RuntimeException('Submission not found.', 404);
        }

        return $submission;
    }

    /**
     * @param array<string, mixed> $submission
     * @param array<string, string> $moduleTitles
     * @return array<string, mixed>
     */
    private function managedSubmissionSummary(array $submission, array $moduleTitles = []): array
    {
        $activityId = (string) ($submission['activityId'] ?? '');
        $activity = Security::isValidId($activityId) ? $this->activities->findById($activityId) : null;
        $snapshot = is_array($submission['activitySnapshot'] ?? null) ? $submission['activitySnapshot'] : [];
        $review = $this->reviews->findBySubmission((string) ($submission['_id'] ?? ''));
        $student = $this->studentSummaryForStaff((string) ($submission['studentId'] ?? ''));
        $mode = (string) ($snapshot['evaluationMode'] ?? ($activity['evaluationMode'] ?? ''));
        $reviewStatus = is_array($review)
            ? (string) ($review['status'] ?? 'pending')
            : (($mode === 'tutor_review') ? 'pending' : 'n/a');

        return [
            'id' => (string) ($submission['_id'] ?? ''),
            'tutorialId' => (string) ($submission['tutorialId'] ?? ''),
            'moduleId' => (string) ($submission['moduleId'] ?? ''),
            'moduleTitle' => $moduleTitles[(string) ($submission['moduleId'] ?? '')] ?? '',
            'activityId' => $activityId,
            'activityTitle' => (string) ($snapshot['title'] ?? ($activity['title'] ?? 'Activity')),
            'activityType' => (string) ($snapshot['activityType'] ?? ($activity['activityType'] ?? '')),
            'evaluationMode' => $mode,
            'attemptNumber' => (int) ($submission['attemptNumber'] ?? 0),
            'status' => (string) ($submission['status'] ?? ''),
            'submittedAt' => $submission['submittedAt'] ?? null,
            'student' => $student,
            'review' => [
                'status' => $reviewStatus,
                'score' => is_array($review) && $review['score'] !== null ? (float) $review['score'] : null,
                'maxScore' => is_array($review) && $review['maxScore'] !== null ? (float) $review['maxScore'] : null,
            ],
            'autoResult' => $this->publicAutoResult(
                is_array($submission['autoResult'] ?? null) ? $submission['autoResult'] : null,
                $review
            ),
        ];
    }

    /**
     * @param array<string, mixed> $submission
     * @return array<string, mixed>
     */
    private function managedSubmissionDetail(array $submission): array
    {
        $summary = $this->managedSubmissionSummary($submission);
        $snapshot = is_array($submission['activitySnapshot'] ?? null) ? $submission['activitySnapshot'] : [];
        $review = $this->reviews->findBySubmission((string) ($submission['_id'] ?? ''));
        $summary['payload'] = is_array($submission['payload'] ?? null) ? $submission['payload'] : [];
        $summary['activitySnapshot'] = [
            'id' => (string) ($snapshot['id'] ?? ''),
            'title' => (string) ($snapshot['title'] ?? ''),
            'instructions' => (string) ($snapshot['instructions'] ?? ''),
            'activityType' => (string) ($snapshot['activityType'] ?? ''),
            'academicField' => (string) ($snapshot['academicField'] ?? 'other'),
            'difficulty' => (string) ($snapshot['difficulty'] ?? 'beginner'),
            'evaluationMode' => (string) ($snapshot['evaluationMode'] ?? ''),
            'config' => is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [],
            // Staff may see answerKey for context while grading.
            'answerKey' => is_array($snapshot['answerKey'] ?? null) ? $snapshot['answerKey'] : [],
        ];
        $summary['review'] = is_array($review) ? [
            'id' => (string) ($review['_id'] ?? ''),
            'status' => (string) ($review['status'] ?? 'pending'),
            'score' => $review['score'] !== null ? (float) $review['score'] : null,
            'maxScore' => $review['maxScore'] !== null ? (float) $review['maxScore'] : null,
            'passed' => array_key_exists('passed', $review) && $review['passed'] !== null
                ? (($review['passed'] ?? false) === true)
                : null,
            'feedback' => (string) ($review['feedback'] ?? ''),
            'privateNotes' => (string) ($review['privateNotes'] ?? ''),
            'reviewerUserId' => (string) ($review['reviewerUserId'] ?? ''),
            'updatedAt' => $review['updatedAt'] ?? null,
            'createdAt' => $review['createdAt'] ?? null,
        ] : [
            'id' => '',
            'status' => ((string) ($snapshot['evaluationMode'] ?? '') === 'tutor_review') ? 'pending' : 'n/a',
            'score' => null,
            'maxScore' => null,
            'passed' => null,
            'feedback' => '',
            'privateNotes' => '',
            'reviewerUserId' => '',
            'updatedAt' => null,
            'createdAt' => null,
        ];
        $summary['reviewable'] = (string) ($snapshot['evaluationMode'] ?? '') === 'tutor_review';

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function studentSummaryForStaff(string $studentId): array
    {
        if (!Security::isValidId($studentId)) {
            return ['id' => '', 'registerNumber' => '', 'name' => ''];
        }
        $student = (new StudentModel())->findById($studentId);
        if (!is_array($student)) {
            return ['id' => $studentId, 'registerNumber' => '', 'name' => ''];
        }
        $name = '';
        $userId = (string) ($student['userId'] ?? '');
        if (Security::isValidId($userId)) {
            $user = (new UserModel())->findById($userId);
            $name = is_array($user) ? (string) ($user['name'] ?? '') : '';
        }

        return [
            'id' => $studentId,
            'registerNumber' => (string) ($student['registerNumber'] ?? ''),
            'name' => $name,
            'classBatch' => (string) ($student['classBatch'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function staffModuleContext(array $user, string $tutorialId, string $moduleId): array
    {
        $pair = $this->requireManagedModule($user, $tutorialId, $moduleId);
        $course = $pair['course'];
        $module = $pair['module'];
        $lessonText = '';
        $content = (string) ($module['content'] ?? '');
        if ($content !== '' && str_starts_with(trim($content), '{')) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && is_array($decoded['blocks'] ?? null)) {
                foreach ($decoded['blocks'] as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = (string) ($block['type'] ?? '');
                    if (in_array($type, ['paragraph', 'heading', 'quote'], true)) {
                        $lessonText .= (string) ($block['text'] ?? '') . "\n";
                    } elseif ($type === 'code') {
                        $lessonText .= (string) ($block['source'] ?? '') . "\n";
                    }
                }
            }
        } else {
            $lessonText = strip_tags($content);
        }

        return [
            'tutorialId' => $tutorialId,
            'moduleId' => $moduleId,
            'courseTitle' => (string) ($course['title'] ?? ''),
            'courseDescription' => (string) ($course['description'] ?? ''),
            'academicField' => 'other',
            'moduleTitle' => (string) ($module['title'] ?? ''),
            'moduleDescription' => (string) ($module['subtitle'] ?? ''),
            'lessonText' => $lessonText,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireManagedModule(array $user, string $tutorialId, string $moduleId): array
    {
        if (!Security::isValidId($tutorialId) || !Security::isValidId($moduleId)) {
            throw new \RuntimeException('Module not found.', 404);
        }
        $course = $this->tutorials->showManaged($user, $tutorialId);
        foreach ((array) ($course['modules'] ?? []) as $row) {
            if ((string) ($row['id'] ?? '') === $moduleId) {
                return ['course' => $course, 'module' => $row];
            }
        }
        throw new \RuntimeException('Module not found.', 404);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireEditableManagedModule(array $user, string $tutorialId, string $moduleId): array
    {
        $ctx = $this->requireManagedModule($user, $tutorialId, $moduleId);
        if (($ctx['course']['canEdit'] ?? false) !== true) {
            throw new \RuntimeException('You can only change tutorials you created.', 403);
        }

        return $ctx;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireManagedActivity(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $this->requireManagedModule($user, $tutorialId, $moduleId);
        if (!Security::isValidId($activityId)) {
            throw new \RuntimeException('Activity not found.', 404);
        }
        $activity = $this->activities->findById($activityId);
        if (
            !is_array($activity)
            || (string) ($activity['tutorialId'] ?? '') !== $tutorialId
            || (string) ($activity['moduleId'] ?? '') !== $moduleId
        ) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $activity;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function requireEditableManagedActivity(array $user, string $tutorialId, string $moduleId, string $activityId): array
    {
        $this->requireEditableManagedModule($user, $tutorialId, $moduleId);
        if (!Security::isValidId($activityId)) {
            throw new \RuntimeException('Activity not found.', 404);
        }
        $activity = $this->activities->findById($activityId);
        if (
            !is_array($activity)
            || (string) ($activity['tutorialId'] ?? '') !== $tutorialId
            || (string) ($activity['moduleId'] ?? '') !== $moduleId
        ) {
            throw new \RuntimeException('Activity not found.', 404);
        }

        return $activity;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertCoursePublished(array $user, string $tutorialId): void
    {
        $course = $this->tutorials->showManaged($user, $tutorialId);
        if ((string) ($course['status'] ?? '') !== 'published') {
            throw new \InvalidArgumentException('Publish the course before publishing this activity.');
        }
    }
}
