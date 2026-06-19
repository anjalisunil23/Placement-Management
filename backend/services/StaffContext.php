<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Resolves department scope for faculty / staff users.
 */
final class StaffContext
{
    /**
     * @return array{
     *   departmentId: string,
     *   department: array<string, mixed>|null,
     *   profile: array<string, mixed>
     * }
     */
    public static function resolve(array $user): array
    {
        if (($user['role'] ?? '') !== 'staff') {
            Response::forbidden('Staff access required.');
        }

        $profile = (new StaffModel())->findByUserId((string) $user['_id']);
        if (!$profile || empty($profile['departmentId'])) {
            Response::forbidden('Your account is not linked to a department. Contact admin.');
        }

        $departmentId = (string) $profile['departmentId'];
        $department = (new DepartmentModel())->findById($departmentId);

        return [
            'departmentId' => $departmentId,
            'department'   => $department,
            'profile'      => $profile,
        ];
    }

    /**
     * Officer-compatible context for reusing department-scoped services.
     *
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    public static function officerCompatible(array $staffCtx): array
    {
        return [
            'isAdmin'      => false,
            'departmentId' => $staffCtx['departmentId'],
            'department'   => $staffCtx['department'],
            'profile'      => $staffCtx['profile'],
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public static function studentCollectionFilter(array $ctx): array
    {
        $oid = Security::toObjectId($ctx['departmentId']);
        return $oid ? ['departmentId' => $oid] : [];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return string[]
     */
    public static function studentIdsInDepartment(array $ctx): array
    {
        $students = (new StudentModel())->findAll(self::studentCollectionFilter($ctx), 5000);
        return array_map(fn ($s) => (string) $s['_id'], $students);
    }
}
