<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

/**
 * Assign / replace the department placement officer (used by admin and HOD).
 * HOD keeps PO dashboard via designation elevation even without a placement_officers row.
 */
final class DepartmentPoAssignmentService
{
    /**
     * @return array{departmentId: string, departmentName: string, officer: ?array<string, mixed>}
     */
    public function currentForDepartment(string $departmentId): array
    {
        $deptId = (string) (Security::toObjectId($departmentId) ?? '');
        $dept = $deptId !== '' ? (new \PMS\Models\DepartmentModel())->findById($deptId) : null;
        $profile = $deptId !== '' ? (new PlacementOfficerModel())->findByDepartment($deptId) : null;
        $officer = null;
        if ($profile) {
            $userId = (string) ($profile['userId'] ?? '');
            $user = $userId !== '' ? (new UserModel())->findById($userId) : null;
            $officer = [
                'userId'      => $userId,
                'name'        => (string) ($user['name'] ?? ''),
                'email'       => (string) ($user['email'] ?? ''),
                'designation' => (string) ($profile['designation'] ?? ''),
            ];
        }

        return [
            'departmentId'   => $deptId,
            'departmentName' => (string) ($dept['name'] ?? $dept['code'] ?? ''),
            'officer'        => $officer,
        ];
    }

    /**
     * Staff in a department eligible to become placement officer.
     *
     * @return list<array<string, mixed>>
     */
    public function listAssignableStaff(string $departmentId, string $excludeUserId = ''): array
    {
        $deptId = (string) (Security::toObjectId($departmentId) ?? '');
        if ($deptId === '') {
            return [];
        }

        $userModel = new UserModel();
        $exclude = (string) (Security::toObjectId($excludeUserId) ?? $excludeUserId);
        $rows = [];

        foreach ((new StaffModel())->findByDepartmentId($deptId, 500) as $staff) {
            $userId = (string) ($staff['userId'] ?? '');
            if ($userId === '') {
                continue;
            }
            if ($exclude !== '' && (string) Security::toObjectId($userId) === $exclude) {
                continue;
            }
            $user = $userModel->findById($userId);
            if (!$user) {
                continue;
            }
            $role = (string) ($user['role'] ?? '');
            if (!in_array($role, ['staff', 'placement_officer'], true)) {
                continue;
            }
            // Skip other HODs — they already have PO access via designation.
            if (AuthMiddleware::isHod($user) || HodDetection::designationLooksLikeHod((string) ($staff['designation'] ?? ''))) {
              continue;
            }

            $rows[] = [
                'userId'      => $userId,
                'name'        => (string) ($user['name'] ?? ''),
                'email'       => (string) ($user['email'] ?? ''),
                'designation' => (string) ($staff['designation'] ?? $user['designation'] ?? 'Staff'),
                'role'        => $role,
            ];
        }

        usort($rows, static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * Promote staff (or reassign existing PO) as the department placement officer.
     *
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public function assign(string $departmentId, string $userId, ?string $designation = null): void
    {
        $deptId = (string) (Security::toObjectId($departmentId) ?? '');
        $uid = (string) (Security::toObjectId($userId) ?? '');
        if ($deptId === '' || $uid === '') {
            throw new \InvalidArgumentException('Valid department and user are required.');
        }

        $dept = (new \PMS\Models\DepartmentModel())->findById($deptId);
        if (!$dept) {
            throw new \InvalidArgumentException('Department not found.');
        }

        $userModel = new UserModel();
        $user = $userModel->findById($uid);
        if (!$user) {
            throw new \InvalidArgumentException('User not found.');
        }

        $role = (string) ($user['role'] ?? '');
        $staffModel = new StaffModel();
        $staffProfile = $staffModel->findByUserId($uid);

        if ($role === 'staff') {
            if ($staffProfile === null) {
                throw new \InvalidArgumentException('Staff member must have a staff profile.');
            }
            $staffDept = (string) ($staffProfile['departmentId'] ?? '');
            if ($staffDept === '' || (string) Security::toObjectId($staffDept) !== $deptId) {
                throw new \InvalidArgumentException('Staff member must belong to this department.');
            }
            if (AuthMiddleware::isHod($user)) {
                throw new \InvalidArgumentException('HOD already has placement officer access. Assign another staff member.');
            }

            $userModel->updateUser($uid, [
                'role'     => 'placement_officer',
                'status'   => 'active',
                'approved' => true,
            ]);

            try {
                $this->replaceDepartmentOfficer($deptId, $uid, $designation ?? (string) ($staffProfile['designation'] ?? 'Placement Officer'));
            } catch (\Throwable $e) {
                $userModel->updateUser($uid, ['role' => 'staff']);
                throw $e;
            }

            return;
        }

        if ($role !== 'placement_officer') {
            throw new \InvalidArgumentException('User must be staff or a placement officer.');
        }

        $poModel = new PlacementOfficerModel();
        $existingUser = $poModel->findByUserId($uid);
        if ($existingUser && (string) Security::toObjectId((string) ($existingUser['departmentId'] ?? '')) !== $deptId) {
            throw new \RuntimeException('This placement officer is already assigned to another department.');
        }

        $this->replaceDepartmentOfficer(
            $deptId,
            $uid,
            $designation ?? (string) ($existingUser['designation'] ?? $staffProfile['designation'] ?? 'Placement Officer')
        );
    }

    public function unassign(string $departmentId): void
    {
        $deptId = (string) (Security::toObjectId($departmentId) ?? '');
        if ($deptId === '') {
            return;
        }
        $poModel = new PlacementOfficerModel();
        $existing = $poModel->findByDepartment($deptId);
        if (!$existing) {
            return;
        }
        $userId = (string) ($existing['userId'] ?? '');
        $poModel->deleteByDepartment($deptId);
        if ($userId !== '') {
            $this->demoteToStaff($userId, $deptId);
        }
    }

    private function replaceDepartmentOfficer(string $deptId, string $userId, string $designation): void
    {
        $poModel = new PlacementOfficerModel();
        $existingDept = $poModel->findByDepartment($deptId);
        if ($existingDept && (string) Security::toObjectId((string) ($existingDept['userId'] ?? '')) !== (string) Security::toObjectId($userId)) {
            $replacedUserId = (string) ($existingDept['userId'] ?? '');
            $poModel->deleteByDepartment($deptId);
            if ($replacedUserId !== '') {
                $this->demoteToStaff($replacedUserId, $deptId);
            }
        }

        if (!$poModel->findByUserId($userId)) {
            $poModel->createProfile($userId, [
                'departmentId' => $deptId,
                'designation'  => $designation !== '' ? $designation : 'Placement Officer',
            ]);
        }
    }

    private function demoteToStaff(string $userId, string $departmentId = ''): void
    {
        $userModel = new UserModel();
        $user = $userModel->findById($userId);
        if ($user === null) {
            return;
        }
        // HOD keeps staff role + PO dashboard via designation elevation.
        if (AuthMiddleware::isHod($user)) {
            (new PlacementOfficerModel())->deleteByUserId($userId);
            return;
        }
        if (($user['role'] ?? '') !== 'placement_officer') {
            return;
        }

        $poModel = new PlacementOfficerModel();
        $profile = $poModel->findByUserId($userId);
        $deptId = $departmentId !== ''
            ? $departmentId
            : (string) ($profile['departmentId'] ?? '');
        if ($profile !== null) {
            $poModel->deleteByUserId($userId);
        }

        $staffModel = new StaffModel();
        if ($staffModel->findByUserId($userId) === null && $deptId !== '') {
            try {
                $staffModel->createProfile($userId, [
                    'departmentId' => $deptId,
                    'designation'  => 'Staff',
                ]);
            } catch (\Throwable) {
                // Profile may already exist.
            }
        }

        $userModel->updateUser($userId, [
            'role'     => 'staff',
            'status'   => 'active',
            'approved' => true,
        ]);
    }
}
