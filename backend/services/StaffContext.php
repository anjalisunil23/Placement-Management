<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

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
        $profile = self::ensureProfile($user);

        $departmentId = !empty($profile['departmentId']) ? (string) $profile['departmentId'] : null;
        $department = $departmentId ? (new DepartmentModel())->findById($departmentId) : null;

        return [
            'profile'      => $profile,
            'departmentId' => $departmentId,
            'department'   => $department,
        ];
    }

    /**
     * Ensure a staff profile exists (AES sign-in may create the user before the profile row).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function ensureProfile(array $user): array
    {
        $staffModel = new StaffModel();
        $userId = (string) ($user['_id'] ?? '');
        if ($userId === '') {
            Response::notFound('Staff profile not found.');
        }

        $profile = $staffModel->findByUserId($userId);
        if ($profile) {
            return $profile;
        }

        $aes = new AesLoginService();
        $merged = $aes->applyAesSessionToUserFields([
            'name'  => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ]);

        $deptId = self::resolveDepartmentIdFromHints(
            (string) ($merged['department'] ?? ''),
            (string) ($merged['departmentName'] ?? '')
        );

        $designation = trim((string) ($merged['designation'] ?? ''));
        $staffModel->createProfile($userId, [
            'departmentId' => $deptId,
            'designation'  => $designation !== '' ? $designation : 'Faculty',
            'phone'        => trim((string) ($merged['phone'] ?? '')),
        ]);

        $profile = $staffModel->findByUserId($userId);
        if (!$profile) {
            Response::notFound('Staff profile not found.');
        }

        return $profile;
    }

    private static function resolveDepartmentIdFromHints(string $code, string $name): ?string
    {
        $deptModel = new DepartmentModel();
        foreach (array_filter([$code, $name]) as $hint) {
            $hint = trim($hint);
            if ($hint === '') {
                continue;
            }
            $dept = $deptModel->findByCode($hint)
                ?? $deptModel->findByCode(strtoupper($hint));
            if ($dept) {
                return (string) ($dept['_id'] ?? '');
            }
        }

        return null;
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
            'departmentId' => $staffCtx['departmentId'] ?? '',
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
        if (empty($ctx['departmentId'])) {
            return [];
        }
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
