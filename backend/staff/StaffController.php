<?php

declare(strict_types=1);

namespace PMS\Staff;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\RecommendationModel;
use PMS\Services\StaffDataService;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;

/**
 * Staff module — department-scoped views and company recommendations.
 */
final class StaffController
{
    /** GET /api/staff/profile */
    public function profile(): void
    {
        $scope = (new StaffDataService())->requireScope();

        Response::success([
            'user'        => DocumentHelper::serialize($scope['user']),
            'department'  => $scope['ctx']['department']
                ? DocumentHelper::serialize($scope['ctx']['department'])
                : null,
            'designation' => $scope['ctx']['profile']['designation'] ?? null,
        ]);
    }

    /** GET /api/staff/dashboard */
    public function dashboard(): void
    {
        $scope = (new StaffDataService())->requireScope();
        Response::success((new StaffDataService())->dashboardStats(
            $scope['officerCtx'],
            (string) $scope['user']['_id']
        ));
    }

    /** GET /api/staff/recommendations */
    public function listRecommendations(): void
    {
        $user = RBACMiddleware::requireStaff();
        $rows = (new StaffDataService())->listMyRecommendations((string) $user['_id']);
        Response::success($rows);
    }

    /** POST /api/staff/recommendations */
    public function createRecommendation(): void
    {
        $user = RBACMiddleware::requireStaff();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        $errors = Validator::validate($input, [
            'companyName' => 'required',
            'category'    => 'required',
            'reason'      => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $contactErrors = Validator::validate($input['contact'] ?? [], [
            'name'  => 'required',
            'email' => 'required|email',
            'phone' => 'required',
        ]);
        if (!empty($contactErrors)) {
            Response::error('Contact validation failed.', 422, $contactErrors);
        }

        $id = (new RecommendationModel())->createRecommendation((string) $user['_id'], $input);
        Response::success(['id' => $id], 'Company recommended.', 201);
    }

    /** GET /api/staff/students */
    public function listStudents(): void
    {
        $scope = (new StaffDataService())->requireScope();
        Response::success((new StaffDataService())->listStudents($scope['officerCtx']));
    }

    /** GET /api/staff/drives */
    public function listDrives(): void
    {
        $scope = (new StaffDataService())->requireScope();
        Response::success((new StaffDataService())->listDrives($scope['ctx']));
    }

    /** GET /api/staff/hiring-overview */
    public function hiringOverview(): void
    {
        $scope = (new StaffDataService())->requireScope();
        Response::success((new StaffDataService())->hiringOverview($scope['ctx'], $scope['officerCtx']));
    }
}
