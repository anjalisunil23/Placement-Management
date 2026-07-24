<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;
use PMS\Services\AnalyticsService;

/**
 * User model — core authentication entity.
 */
class UserModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::USERS;
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findOne(['email' => strtolower(trim($email))]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createUser(array $data): string
    {
        $role = (string) ($data['role'] ?? '');
        $approved = $data['approved'] ?? false;
        $status = $data['status'] ?? 'pending';
        if (in_array($role, ['admin', 'placement_officer', 'staff'], true)) {
            $approved = true;
            $status = 'active';
        }

        $doc = [
            'name'      => $data['name'],
            'email'     => strtolower(trim((string) $data['email'])),
            'password'  => Security::hashPassword((string) $data['password']),
            'role'      => $role,
            'status'    => $status,
            'approved'  => $approved,
        ];
        return $this->insert($doc);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateUser(string $id, array $data): bool
    {
        if (isset($data['password']) && $data['password'] !== '') {
            $data['password'] = Security::hashPassword($data['password']);
        } else {
            unset($data['password']);
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }
        return $this->update($id, $data);
    }

    public function blockUser(string $id): bool
    {
        return $this->update($id, ['status' => 'blocked']);
    }

    public function unblockUser(string $id): bool
    {
        return $this->update($id, ['status' => 'active']);
    }

    public function approveUser(string $id): bool
    {
        return $this->update($id, ['approved' => true, 'status' => 'active']);
    }

    /**
     * Whether this account may sign in (password, AES, or session restore).
     *
     * @param array<string, mixed> $user
     */
    public function canLogin(array $user): bool
    {
        if (($user['status'] ?? '') === 'blocked') {
            return false;
        }

        $role = (string) ($user['role'] ?? '');
        if ($role === 'admin') {
            return true;
        }

        if (($user['status'] ?? '') !== 'active') {
            return false;
        }

        if (in_array($role, ['placement_officer', 'staff'], true)) {
            return true;
        }

        return (bool) ($user['approved'] ?? false);
    }

    /**
     * Normalize admin-provisioned campus accounts so login is not blocked by stale flags.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function ensureLoginReady(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['placement_officer', 'staff'], true)) {
            return $user;
        }

        $patch = [];
        if (($user['status'] ?? '') !== 'active') {
            $patch['status'] = 'active';
        }
        if (!($user['approved'] ?? false)) {
            $patch['approved'] = true;
        }

        if ($patch === []) {
            return $user;
        }

        $this->updateUser((string) $user['_id'], $patch);

        return $this->findById((string) $user['_id']) ?? $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByRole(string $role, int $limit = 100, int $skip = 0): array
    {
        return $this->findAll(['role' => $role], $limit, $skip);
    }

    public function getDashboardStats(bool $includeExtended = true): array
    {
        if (!$includeExtended) {
            $cacheKey = 'admin_dash_lite';
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_' . $cacheKey . '.json';
            if (is_file($path)) {
                $mtime = @filemtime($path);
                if ($mtime !== false && (time() - $mtime) <= 45) {
                    $raw = @file_get_contents($path);
                    $decoded = is_string($raw) ? json_decode($raw, true) : null;
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        $studentModel = new StudentModel();
        $companyModel = new CompanyModel();
        $driveModel = new DriveModel();
        $totalStudents = $studentModel->count([]);
        $placedStudents = $studentModel->count(['placed' => true]);

        $base = [
            'totalStudents'       => $totalStudents,
            'totalCompanies'      => $companyModel->count([]),
            'placedStudents'      => $placedStudents,
            'placementPercentage' => $totalStudents > 0
                ? round(($placedStudents / $totalStudents) * 100, 1)
                : 0,
            'pendingApprovals'    => $this->count(['role' => 'student', 'approved' => false]),
            'blockedUsers'        => $this->count(['status' => 'blocked']),
            'totalStaff'          => $this->count(['role' => 'staff']),
            'totalAlumni'         => $this->count(['role' => 'alumni']),
            'activeDrives'        => $driveModel->count(['status' => ['$ne' => 'closed']]),
            'salaryAnalytics'     => ['highest' => 0, 'lowest' => 0, 'average' => 0, 'median' => 0],
            'branchStatistics'    => [],
            'companyStatistics'   => [],
            'hiringTrend'         => null,
            'hiringTrendLastYear' => null,
        ];

        if (!$includeExtended) {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_admin_dash_lite.json';
            @file_put_contents($path, json_encode($base), LOCK_EX);
            return $base;
        }

        // getExtendedAnalytics already includes getDashboardAnalytics — call once.
        $extended = (new AnalyticsService())->getExtendedAnalytics(null);

        return array_merge($base, [
            'salaryAnalytics'     => $extended['salaryAnalytics'] ?? $base['salaryAnalytics'],
            'branchStatistics'    => $extended['branchStatistics'] ?? [],
            'companyStatistics'   => $extended['companyStatistics'] ?? [],
            'hiringTrend'         => $extended['hiringTrend'] ?? null,
            'hiringTrendLastYear' => $extended['hiringTrendLastYear'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null safe user (no password)
     */
    public function safeUser(?array $user): ?array
    {
        return DocumentHelper::serialize($user);
    }
}
