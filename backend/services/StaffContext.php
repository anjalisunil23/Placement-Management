<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StaffModel;
use PMS\Utils\Response;

/**
 * Resolves staff profile and department scope.
 */
final class StaffContext
{
    /**
     * @return array{
     *   profile: array<string, mixed>,
     *   departmentId: string|null,
     *   department: array<string, mixed>|null
     * }
     */
    public static function resolve(array $user): array
    {
        $profile = (new StaffModel())->findByUserId((string) $user['_id']);
        if (!$profile) {
            Response::notFound('Staff profile not found.');
        }

        $departmentId = !empty($profile['departmentId']) ? (string) $profile['departmentId'] : null;
        $department = $departmentId ? (new DepartmentModel())->findById($departmentId) : null;

        return [
            'profile'      => $profile,
            'departmentId' => $departmentId,
            'department'   => $department,
        ];
    }
}
