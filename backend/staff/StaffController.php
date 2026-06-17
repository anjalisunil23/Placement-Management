<?php

declare(strict_types=1);

namespace PMS\Staff;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\RecommendationModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Validator;

/**
 * Staff module — company recommendations.
 */
final class StaffController
{
    /** GET /api/staff/recommendations */
    public function listRecommendations(): void
    {
        RBACMiddleware::requireStaff();
        $model = new RecommendationModel();
        Response::success(DocumentHelper::serializeMany($model->findAll([], 100)));
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
}
