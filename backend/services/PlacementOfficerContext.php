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
        $effectiveRole = \PMS\Middleware\AuthMiddleware::resolvedRole($user);
        if ($effectiveRole === 'admin') {
            return [
                'isAdmin'      => true,
                'departmentId' => null,
                'department'   => null,
                'profile'      => null,
            ];
        }

        if ($effectiveRole !== 'placement_officer') {
            Response::forbidden('Placement officer access required.');
        }

        $profile = (new PlacementOfficerModel())->findByUserId((string) $user['_id']);
        if (!$profile) {
            $staffProfile = StaffContext::ensureProfile($user);
            if (empty($staffProfile['departmentId'])) {
                Response::forbidden('Placement officer profile not found. Contact admin.');
            }
            $profile = [
                'userId' => $user['_id'] ?? null,
                'departmentId' => $staffProfile['departmentId'] ?? null,
                'designation' => $staffProfile['designation'] ?? 'HOD',
                'virtual' => true,
            ];
        }

        if (empty($profile['departmentId'])) {
            return [
                'isAdmin'      => false,
                'departmentId' => null,
                'department'   => null,
                'profile'      => $profile,
            ];
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
     * @return array<string, mixed> Filter for students table queries
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
    public static function userIdsInDepartment(array $ctx): array
    {
        return (new StudentModel())->pluckField('userId', self::studentCollectionFilter($ctx), 5000);
    }

    /**
     * @return string[]
     */
    public static function studentIdsInDepartment(array $ctx): array
    {
        return (new StudentModel())->findIds(self::studentCollectionFilter($ctx), 5000);
    }

    /**
     * @return string[]
     */
    public static function registerNumbersInDepartment(array $ctx): array
    {
        $students = (new StudentModel())->findAll(self::studentCollectionFilter($ctx), 5000);
        return array_values(array_filter(array_map(
            fn ($s) => strtoupper((string) ($s['registerNumber'] ?? '')),
            $students
        )));
    }

    public static function assertStudentInDepartment(string $studentId, array $ctx): void
    {
        if ($ctx['isAdmin'] || empty($ctx['departmentId'])) {
            return;
        }
        $studentModel = new StudentModel();
        $student = $studentModel->findById($studentId)
            ?? $studentModel->findByUserId($studentId)
            ?? $studentModel->findByRegisterNumber(strtoupper(trim($studentId)));
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }

    public static function assertUserStudentInDepartment(string $userId, array $ctx): void
    {
        if ($ctx['isAdmin'] || empty($ctx['departmentId'])) {
            return;
        }
        $student = (new StudentModel())->findByUserId($userId);
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }

    /**
     * @param array<string, mixed> $drive
     * @return list<string>
     */
    public static function driveBranchCodes(array $drive): array
    {
        $branches = $drive['branches'] ?? [];
        if (!is_array($branches)) {
            if (is_string($branches) && trim($branches) !== '') {
                $branches = array_map('trim', explode(',', $branches));
            } else {
                $branches = [];
            }
        }

        $codes = [];
        foreach ($branches as $branch) {
            $raw = strtoupper(trim((string) $branch));
            if ($raw !== '') {
                $codes[] = $raw;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param array<string, mixed> $drive
     */
    public static function driveMatchesDepartment(array $drive, array $ctx): bool
    {
        if ($ctx['isAdmin'] || empty($ctx['departmentId'])) {
            return true;
        }

        $deptOid = (string) $ctx['departmentId'];
        $deptCode = strtoupper(trim((string) ($ctx['department']['code'] ?? '')));
        $driveDeptId = (string) ($drive['departmentId'] ?? '');
        if ($driveDeptId !== '' && $driveDeptId === $deptOid) {
            return true;
        }

        $branchCodes = self::driveBranchCodes($drive);
        if ($branchCodes === []) {
            return true;
        }

        if ($deptCode !== '' && in_array($deptCode, $branchCodes, true)) {
            return true;
        }

        $deptModel = new DepartmentModel();
        foreach ($branchCodes as $code) {
            $row = $deptModel->findByCode($code);
            if ($row === null) {
                continue;
            }
            $rowCode = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($deptCode !== '' && $rowCode === $deptCode) {
                return true;
            }
            if ((string) ($row['_id'] ?? '') === $deptOid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mongo filter for drives visible to a department-scoped role (placement officer / staff).
     *
     * @return array<string, mixed>|null null when the role has no department (show nothing)
     */
    public static function driveCollectionFilter(array $ctx): ?array
    {
        if (!empty($ctx['isAdmin'])) {
            return [];
        }

        $deptOid = Security::toObjectId($ctx['departmentId'] ?? '');
        $deptCode = strtoupper(trim((string) ($ctx['department']['code'] ?? '')));
        $or = [
            ['branches' => []],
            ['branches' => ['$size' => 0]],
        ];
        if ($deptOid !== null) {
            $or[] = ['departmentId' => $deptOid];
        }
        if ($deptCode !== '') {
            $or[] = ['branches' => $deptCode];
        }

        return ['$or' => $or];
    }

    /**
     * @param array<string, mixed> $input drive create payload
     * @return array<string, mixed>
     */
    public static function applyDepartmentToDriveInput(array $input, array $ctx): array
    {
        if ($ctx['isAdmin'] || empty($ctx['departmentId'])) {
            return $input;
        }

        // Visibility comes from selected branches:
        // - one / few programme codes → only those students see the drive
        // - empty list → open to all eligible final-year students college-wide
        $branches = $input['branches'] ?? [];
        if (is_string($branches)) {
            $raw = trim($branches);
            $branches = $raw === '' ? [] : (preg_split('/[,|]+/', $raw) ?: []);
        }
        if (!is_array($branches)) {
            $branches = [];
        }
        $normalized = [];
        foreach ($branches as $branch) {
            $code = strtoupper(trim((string) $branch));
            if ($code === '' || $code === 'ALL' || preg_match('/^\d+$/', $code)) {
                continue;
            }
            $normalized[$code] = $code;
        }
        $input['branches'] = array_values($normalized);
        // Creator ownership for auditing / duplicate scope; student visibility uses branches.
        $input['departmentId'] = $ctx['departmentId'];
        return $input;
    }
}
