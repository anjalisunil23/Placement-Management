<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Resolves department scope for placement officers vs admin.
 */
final class PlacementOfficerContext
{
    /**
     * @return array{
     *   isAdmin: bool,
     *   departmentId: string|null,
     *   department: array<string, mixed>|null,
     *   profile: array<string, mixed>|null
     * }
     */
    public static function resolve(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return [
                'isAdmin'      => true,
                'departmentId' => null,
                'department'   => null,
                'profile'      => null,
            ];
        }

        if (($user['role'] ?? '') !== 'placement_officer') {
            Response::forbidden('Placement officer access required.');
        }

        $profile = (new PlacementOfficerModel())->findByUserId((string) $user['_id']);
        if (!$profile || empty($profile['departmentId'])) {
            Response::forbidden('Your account is not linked to a department. Contact admin.');
        }

        $departmentId = (string) $profile['departmentId'];
        $department = (new DepartmentModel())->findById($departmentId);

        return [
            'isAdmin'      => false,
            'departmentId' => $departmentId,
            'department'   => $department,
            'profile'      => $profile,
        ];
    }

    /**
     * @return array<string, mixed> MongoDB filter for students collection
     */
    public static function studentCollectionFilter(array $ctx): array
    {
        if ($ctx['isAdmin']) {
            return [];
        }
        $oid = Security::toObjectId($ctx['departmentId']);
        return $oid ? ['departmentId' => $oid] : [];
    }

    /**
     * @return string[]
     */
    public static function studentIdsInDepartment(array $ctx): array
    {
        $students = (new StudentModel())->findAll(self::studentCollectionFilter($ctx), 5000);
        return array_map(fn ($s) => (string) $s['_id'], $students);
    }

    /**
     * @return string[]
     */
    public static function userIdsInDepartment(array $ctx): array
    {
        $students = (new StudentModel())->findAll(self::studentCollectionFilter($ctx), 5000);
        return array_map(fn ($s) => (string) $s['userId'], $students);
    }

    public static function assertStudentInDepartment(string $studentId, array $ctx): void
    {
        if ($ctx['isAdmin']) {
            return;
        }
        $student = (new StudentModel())->findById($studentId);
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }

    public static function assertUserStudentInDepartment(string $userId, array $ctx): void
    {
        if ($ctx['isAdmin']) {
            return;
        }
        $student = (new StudentModel())->findByUserId($userId);
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }

    /**
     * @param array<string, mixed> $drive
     */
    public static function driveMatchesDepartment(array $drive, array $ctx): bool
    {
        if ($ctx['isAdmin']) {
            return true;
        }
        $branches = $drive['branches'] ?? [];
        if (empty($branches)) {
            return true;
        }
        $deptCode = $ctx['department']['code'] ?? '';
        return $deptCode && in_array($deptCode, $branches, true);
    }

    /**
     * @param array<string, mixed> $input drive create payload
     * @return array<string, mixed>
     */
    public static function applyDepartmentToDriveInput(array $input, array $ctx): array
    {
        if ($ctx['isAdmin']) {
            return $input;
        }
        $deptCode = $ctx['department']['code'] ?? '';
        if ($deptCode === '') {
            Response::forbidden('Department code not configured.');
        }
        $input['branches'] = [$deptCode];
        $input['departmentId'] = $ctx['departmentId'];
        return $input;
    }
}
