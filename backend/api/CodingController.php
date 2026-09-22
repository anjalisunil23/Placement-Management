<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Middleware\AuthMiddleware;
use PMS\Services\AptitudeAccessService;
use PMS\Services\CodingService;
use PMS\Utils\Response;

final class CodingController
{
    private ?CodingService $service = null;

    private function service(): CodingService
    {
        return $this->service ??= new CodingService();
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
            ? $this->service()->listAllForAdmin($user)
            : $this->service()->listPublishedForUser($user);
        Response::success(['tests' => $tests]);
    }

    public function createTest(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->createTest($user, $this->body()), 'Coding test created.');
    }

    public function updateTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->updateTest($user, $id, $this->body()), 'Coding test updated.');
    }

    public function deleteTest(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteTest($user, $id);
        Response::success(null, 'Coding test deleted.');
    }

    public function listBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $category = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
        $difficulty = isset($_GET['difficulty']) ? trim((string) $_GET['difficulty']) : null;
        Response::success(['problems' => $this->service()->listBank($user, $category ?: null, $difficulty ?: null)]);
    }

    public function generateAiBank(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->generateAiBankProblems($user, $this->body()));
    }

    public function saveAiBank(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $problems = is_array($body['problems'] ?? null) ? $body['problems'] : (is_array($body['questions'] ?? null) ? $body['questions'] : []);
        Response::success($this->service()->saveAiBankProblems($user, $problems), 'Problems saved.');
    }

    public function progressFilters(): void
    {
        $user = AuthMiddleware::authenticate();
        $filters = [
            'department' => $_GET['department'] ?? ($_GET['departmentId'] ?? ''),
            'course' => $_GET['course'] ?? ($_GET['branch'] ?? ''),
            'class' => $_GET['class'] ?? ($_GET['batch'] ?? ($_GET['classBatch'] ?? '')),
        ];
        Response::success((new \PMS\Services\AptitudeService())->progressFilterOptions($user, $filters));
    }

    public function createBankProblem(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->saveBankProblem($user, $this->body()), 'Problem saved.');
    }

    public function updateBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->saveBankProblem($user, $this->body(), $id), 'Problem saved.');
    }

    public function deleteBankProblem(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteBankProblem($user, $id);
        Response::success(null, 'Problem deleted.');
    }

    /** POST /api/coding/problem-bank/bulk-delete */
    public function bulkDeleteBankProblems(): void
    {
        $user = AuthMiddleware::authenticate();
        $body = $this->body();
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        Response::success(
            $this->service()->bulkDeleteBankProblems($user, $ids),
            'Problems deleted.'
        );
    }

    public function start(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->start($user, $id));
    }

    public function submit(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->submit($user, $id, $this->body()), 'Submitted.');
    }

    public function myProgress(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->myProgress($user));
    }

    public function progressDirectory(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->directory($user, $_GET));
    }

    public function contestBoard(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->contestBoard($user));
    }

    public function subjectProgress(string $userId): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->subjectProgress($user, $userId));
    }

    /** GET /api/coding/company-block/companies */
    public function listCompanyBlockCompanies(): void
    {
        AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockCompanies());
    }

    /** GET /api/coding/company-block */
    public function listCompanyBlock(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockForAdmin($user));
    }

    /** GET /api/coding/company-block/sets/{id} */
    public function getCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->getCompanyBlockSet($user, $id, false));
    }

    /** DELETE /api/coding/company-block/sets/{id} */
    public function deleteCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        $this->service()->deleteCompanyBlockSet($user, $id);
        Response::success(null, 'Company problem set deleted.');
    }

    /** GET /api/coding/student/company-block */
    public function listStudentCompanyBlock(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->listCompanyBlockForStudent($user));
    }

    /** GET /api/coding/student/company-block/sets/{id} */
    public function getStudentCompanyBlockSet(string $id): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success($this->service()->getCompanyBlockSet($user, $id, true));
    }
}
