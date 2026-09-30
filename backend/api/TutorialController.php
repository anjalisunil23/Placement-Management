<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\TutorialService;
use PMS\Utils\Response;

/**
 * Tutorials under Practice. Student responses never include hidden test cases.
 */
final class TutorialController
{
    public function __construct(private ?TutorialService $service = null)
    {
        $this->service = $service ?? new TutorialService();
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
