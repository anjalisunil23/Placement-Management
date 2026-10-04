<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\TutorialActivityService;
use PMS\Services\TutorialAIService;
use PMS\Services\TutorialAssessmentService;
use PMS\Services\TutorialService;
use PMS\Utils\Response;

/**
 * Tutorials under Practice. Student responses never include hidden test cases.
 */
final class TutorialController
{
    public function __construct(
        private ?TutorialService $service = null,
        private ?TutorialAIService $ai = null,
        private ?TutorialAssessmentService $assessments = null,
        private ?TutorialActivityService $activities = null,
    ) {
        $this->service = $service ?? new TutorialService();
        $this->ai = $ai ?? new TutorialAIService(null, $this->service);
        $this->assessments = $assessments ?? new TutorialAssessmentService($this->service, $this->ai);
        $this->activities = $activities ?? new TutorialActivityService($this->service);
    }

    public function listCategories(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->listCategories($user));
    }

    public function createCategory(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createCategory($user, $this->body()), 'Category created.', 201);
    }

    public function updateCategory(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateCategory($user, $id, $this->body()), 'Category updated.');
    }

    public function deleteCategory(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $result = $this->service->removeCategory($user, $id);
        $message = $result['deactivated'] ? 'Category deactivated because tutorials still use it.' : 'Category deleted.';
        Response::success($result, $message);
    }

    public function index(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->listForStudent($user, $_GET));
    }

    public function show(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->showForStudent($user, $id));
    }

    public function showModule(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->moduleForStudent($user, $tutorialId, $moduleId));
    }

    public function showExercise(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->exerciseForStudent($user, $id));
    }

    public function progress(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->progressForStudent($user, $id));
    }

    public function startProgress(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->startProgress($user, $id));
    }

    public function moduleProgress(string $id, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->markModuleComplete($user, $id, $moduleId));
    }

    public function completeTutorial(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->completeTutorial($user, $id));
    }

    public function saveAttempt(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->saveAttempt($user, $id, $this->body()), 'Attempt saved.', 201);
    }

    public function listAttempts(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->listAttempts($user, $id));
    }

    public function manageIndex(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->listManaged($user));
    }

    public function manageCreate(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createTutorial($user, $this->body()), 'Tutorial created.', 201);
    }

    public function uploadMedia(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->uploadLessonImage($user), 'Image saved.', 201);
    }

    public function manageShow(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->showManaged($user, $id));
    }

    public function manageUpdate(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateTutorial($user, $id, $this->body()), 'Tutorial updated.');
    }

    public function manageDelete(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteTutorial($user, $id);
        Response::success(null, 'Tutorial deleted.');
    }

    public function publishChecklist(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->publishChecklist($user, $id));
    }

    public function publish(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->publish($user, $id), 'Tutorial published.');
    }

    public function unpublish(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->unpublish($user, $id), 'Tutorial unpublished.');
    }

    public function aiStatus(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->ai->checkStatus($user));
    }

    public function aiGenerateCourse(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->ai->generateCoursePreview($user, $this->body()), 'AI course draft generated.');
    }

    public function aiSaveCourse(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->ai->saveCourseDraft($user, $this->body()), 'AI course saved as draft.', 201);
    }

    public function aiGenerateLessonExercises(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->ai->generateLessonExercisesPreview($user, $this->body()),
            'AI lesson exercises generated.'
        );
    }

    public function aiGenerateModule(string $tutorialId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->ai->generateModulePreview($user, $tutorialId, $this->body()),
            'AI module draft generated.'
        );
    }

    public function aiSaveModule(string $tutorialId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->ai->saveModuleDraft($user, $tutorialId, $this->body()),
            'AI module saved as draft.',
            201
        );
    }

    public function assessmentGenerate(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->assessments->generateForModule($user, $tutorialId, $moduleId, $this->body()),
            'AI MCQs generated.'
        );
    }

    public function assessmentSaveGenerated(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->assessments->saveGenerated($user, $tutorialId, $moduleId, $this->body()),
            'Generated MCQs saved.',
            201
        );
    }

    public function assessmentManageGet(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->assessments->getManaged($user, $tutorialId, $moduleId));
    }

    public function assessmentManageSave(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->assessments->saveAssessment($user, $tutorialId, $moduleId, $this->body()),
            'Assessment saved.'
        );
    }

    public function assessmentStudentGet(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->assessments->getForStudent($user, $tutorialId, $moduleId));
    }

    public function assessmentStudentStart(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->assessments->startAttempt($user, $tutorialId, $moduleId),
            'Assessment started.'
        );
    }

    public function assessmentStudentSubmit(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->assessments->submitAttempt($user, $tutorialId, $moduleId, $this->body()),
            'Assessment submitted.'
        );
    }

    public function assessmentStudentAttempts(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->assessments->listAttemptsForStudent($user, $tutorialId, $moduleId));
    }

    public function activitiesManageList(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->listManaged($user, $tutorialId, $moduleId));
    }

    public function activitiesManageCreate(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->create($user, $tutorialId, $moduleId, $this->body()),
            'Activity created.',
            201
        );
    }

    public function activitiesManageReorder(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $ids = is_array($body['activityIds'] ?? null) ? $body['activityIds'] : [];
        Response::success(
            $this->activities->reorder($user, $tutorialId, $moduleId, $ids),
            'Activities reordered.'
        );
    }

    public function activitiesManageGenerate(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->generateForModule($user, $tutorialId, $moduleId, $this->body()),
            'AI activity draft generated.'
        );
    }

    public function activitiesManageSaveGenerated(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->saveGenerated($user, $tutorialId, $moduleId, $this->body()),
            'Generated activity saved as draft.',
            201
        );
    }

    public function activitiesManageGet(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->getManaged($user, $tutorialId, $moduleId, $activityId));
    }

    public function activitiesManageUpdate(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->update($user, $tutorialId, $moduleId, $activityId, $this->body()),
            'Activity updated.'
        );
    }

    public function activitiesManageDelete(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->archive($user, $tutorialId, $moduleId, $activityId),
            'Activity archived.'
        );
    }

    public function activitiesManagePublish(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->publish($user, $tutorialId, $moduleId, $activityId),
            'Activity published.'
        );
    }

    public function activitiesManageUnpublish(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->unpublish($user, $tutorialId, $moduleId, $activityId),
            'Activity unpublished.'
        );
    }

    public function activitiesManageSubmissions(string $tutorialId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->listSubmissionsManaged($user, $tutorialId, [
            'moduleId' => (string) ($_GET['moduleId'] ?? ''),
            'activityId' => (string) ($_GET['activityId'] ?? ''),
            'status' => (string) ($_GET['status'] ?? 'all'),
            'limit' => (int) ($_GET['limit'] ?? 100),
        ]));
    }

    public function activitiesManageSubmissionGet(string $tutorialId, string $submissionId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->getSubmissionManaged($user, $tutorialId, $submissionId));
    }

    public function activitiesManageReviewSave(string $tutorialId, string $submissionId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->saveReviewManaged($user, $tutorialId, $submissionId, $this->body()),
            'Review draft saved.'
        );
    }

    public function activitiesManageReviewFinalize(string $tutorialId, string $submissionId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->finalizeReviewManaged($user, $tutorialId, $submissionId, $this->body()),
            'Review finalized.'
        );
    }

    public function activitiesStudentList(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->listForStudent($user, $tutorialId, $moduleId));
    }

    public function activitiesStudentGet(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->getForStudent($user, $tutorialId, $moduleId, $activityId));
    }

    public function activitiesStudentAttempts(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->activities->listAttemptsForStudent($user, $tutorialId, $moduleId, $activityId));
    }

    public function activitiesStudentStart(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->startAttempt($user, $tutorialId, $moduleId, $activityId),
            'Activity attempt started.'
        );
    }

    public function activitiesStudentSave(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->saveResponse($user, $tutorialId, $moduleId, $activityId, $this->body()),
            'Response saved.'
        );
    }

    public function activitiesStudentSubmit(string $tutorialId, string $moduleId, string $activityId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(
            $this->activities->submitResponse($user, $tutorialId, $moduleId, $activityId, $this->body()),
            'Activity submitted.'
        );
    }

    public function createModule(string $tutorialId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createModule($user, $tutorialId, $this->body()), 'Module created.', 201);
    }

    public function updateModule(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateModule($user, $tutorialId, $moduleId, $this->body()), 'Module updated.');
    }

    public function deleteModule(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteModule($user, $tutorialId, $moduleId);
        Response::success(null, 'Module deleted.');
    }

    public function reorderModules(string $tutorialId): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        Response::success($this->service->reorderModules($user, $tutorialId, (array) ($body['moduleIds'] ?? [])));
    }

    public function createExercise(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createExercise($user, $tutorialId, $moduleId, $this->body()), 'Exercise created.', 201);
    }

    public function updateExercise(string $tutorialId, string $moduleId, string $exerciseId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateExercise($user, $tutorialId, $moduleId, $exerciseId, $this->body()), 'Exercise updated.');
    }

    public function deleteExercise(string $tutorialId, string $moduleId, string $exerciseId): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteExercise($user, $tutorialId, $moduleId, $exerciseId);
        Response::success(null, 'Exercise deleted.');
    }

    public function reorderExercises(string $tutorialId, string $moduleId): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        Response::success($this->service->reorderExercises($user, $tutorialId, $moduleId, (array) ($body['exerciseIds'] ?? [])));
    }

    public function reorderTestCases(string $exerciseId): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        Response::success($this->service->reorderTestCases($user, $exerciseId, (array) ($body['testCaseIds'] ?? [])));
    }

    public function createTestCase(string $exerciseId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createTestCase($user, $exerciseId, $this->body()), 'Test case created.', 201);
    }

    public function updateTestCase(string $exerciseId, string $testCaseId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateTestCase($user, $exerciseId, $testCaseId, $this->body()), 'Test case updated.');
    }

    public function deleteTestCase(string $exerciseId, string $testCaseId): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteTestCase($user, $exerciseId, $testCaseId);
        Response::success(null, 'Test case deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
