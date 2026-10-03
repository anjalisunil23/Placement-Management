<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\TutorialModuleActivityModel;
use PMS\Models\TutorialModuleActivityReviewModel;
use PMS\Models\TutorialModuleActivitySubmissionModel;
use PMS\Utils\Security;

/**
 * Staff authoring foundation for Tutorial practical activities.
 * Independent of MCQ assessments and Coding execution.
 * Student submission / tutor review workflows are deferred to later phases.
 */
final class TutorialActivityService
{
    public function __construct(
        private ?TutorialService $tutorials = null,
        private ?TutorialModuleActivityModel $activities = null,
        private ?TutorialModuleActivitySubmissionModel $submissions = null,
        private ?TutorialModuleActivityReviewModel $reviews = null,
    ) {
        $this->tutorials = $tutorials ?? new TutorialService();
        $this->activities = $activities ?? new TutorialModuleActivityModel();
        $this->submissions = $submissions ?? new TutorialModuleActivitySubmissionModel();
        $this->reviews = $reviews ?? new TutorialModuleActivityReviewModel();
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
        $this->requireManagedModule($user, $tutorialId, $moduleId);
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
        $existing = $this->requireManagedActivity($user, $tutorialId, $moduleId, $activityId);
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
        $this->requireManagedActivity($user, $tutorialId, $moduleId, $activityId);
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
        $this->requireManagedModule($user, $tutorialId, $moduleId);
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
        $existing = $this->requireManagedActivity($user, $tutorialId, $moduleId, $activityId);
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
        $existing = $this->requireManagedActivity($user, $tutorialId, $moduleId, $activityId);
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
     * Student-safe projection used by tests and reserved for later student APIs.
     * Never includes answerKey.
     *
     * @param array<string, mixed> $activity
     * @return array<string, mixed>
     */
    public function studentSafeView(array $activity): array
    {
        return [
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
            'config' => $this->publicConfig(
                (string) ($activity['activityType'] ?? ''),
                is_array($activity['config'] ?? null) ? $activity['config'] : []
            ),
        ];
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
        // Strip nothing critical yet; answer keys live separately. Keep config student-safe by type.
        unset($config['expectedValue']);
        if ($type === 'numerical') {
            return [
                'unit' => (string) ($config['unit'] ?? ''),
                'tolerance' => (float) ($config['tolerance'] ?? 0),
                'promptHint' => (string) ($config['promptHint'] ?? ''),
            ];
        }

        return $config;
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
     */
    private function assertCoursePublished(array $user, string $tutorialId): void
    {
        $course = $this->tutorials->showManaged($user, $tutorialId);
        if ((string) ($course['status'] ?? '') !== 'published') {
            throw new \InvalidArgumentException('Publish the course before publishing this activity.');
        }
    }
}
