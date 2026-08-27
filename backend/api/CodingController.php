<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\AptitudeAccessService;
use PMS\Services\CodingService;
use PMS\Utils\Response;

final class CodingController
{
    private CodingService $service;

    public function __construct()
    {
        $this->service = new CodingService();
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '{}';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function access(): void
    {
        $user = AuthMiddleware::authenticate();
        $role = AuthMiddleware::resolvedRole($user);
        Response::success([
            'canTake' => AptitudeAccessService::canTake($user),
            'canManage' => AptitudeAccessService::canManage($user),
            'canViewDirectory' => AptitudeAccessService::canViewDirectory($user),
            'role' => $role,
            'scope' => AptitudeAccessService::scopeInfo($user),
        ]);
    }

    public function meta(): void
    {
        AuthMiddleware::authenticate();
        Response::success([
            'categories' => \PMS\Models\CodingTestModel::CATEGORIES,
            'difficulties' => \PMS\Models\CodingTestModel::DIFFICULTIES,
            'statuses' => \PMS\Models\CodingTestModel::STATUSES,
        ]);
    }

    public function listTests(): void
    {
        $user = AptitudeAccessService::requirePortalUser();
        $manage = isset($_GET['manage']) && (string) $_GET['manage'] === '1';
        $tests = ($manage && AptitudeAccessService::canManage($user))
            ? $this->service->listAllForAdmin($user)
            : $this->service->listPublishedForUser($user);
        Response::success(['tests' => $tests]);
    }

    public function createTest(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->createTest($user, $this->body()), 'Coding test created.');
    }

    public function updateTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->updateTest($user, $id, $this->body()), 'Coding test updated.');
    }

    public function deleteTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteTest($user, $id);
        Response::success(null, 'Coding test deleted.');
    }

    public function listBank(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(['problems' => $this->service->listBank($user)]);
    }

    public function createBankProblem(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->saveBankProblem($user, $this->body()), 'Problem saved.');
    }

    public function updateBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->saveBankProblem($user, $this->body(), $id), 'Problem saved.');
    }

    public function deleteBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service->deleteBankProblem($user, $id);
        Response::success(null, 'Problem deleted.');
    }

    public function start(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->start($user, $id));
    }

    public function submit(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->submit($user, $id, $this->body()), 'Submitted.');
    }

    public function myProgress(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->myProgress($user));
    }

    public function progressDirectory(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->directory($user, $_GET));
    }

    public function subjectProgress(string $userId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service->subjectProgress($user, $userId));
    }
}
