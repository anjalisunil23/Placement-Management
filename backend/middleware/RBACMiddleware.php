<?php

declare(strict_types=1);

namespace PMS\Middleware;

use PMS\Utils\Response;

/**
 * Role-Based Access Control middleware.
 */
final class RBACMiddleware
{
    /**
     * Require authenticated user with one of the given roles.
     *
     * @param string[] $allowedRoles
     * @return array<string, mixed> authenticated user
     */
    public static function requireRoles(array $allowedRoles): array
    {
        $user = AuthMiddleware::authenticate();
        if (!in_array(AuthMiddleware::resolvedRole($user), $allowedRoles, true)) {
            Response::forbidden('You do not have permission to access this resource.');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        return self::requireRoles(['admin']);
    }

    public static function requireStudent(): array
    {
        return self::requireRoles(['student']);
    }

    public static function requireStaff(): array
    {
        $user = AuthMiddleware::authenticate();
        // HOD stays DB role=staff but resolvedRole elevates to placement_officer.
        // Staff APIs must still accept the DB staff account.
        $dbRole = trim((string) ($user['role'] ?? ''));
        if ($dbRole === 'staff' || AuthMiddleware::resolvedRole($user) === 'staff') {
            return $user;
        }
        Response::forbidden('You do not have permission to access this resource.');
    }

    public static function requireCompany(): array
    {
        return self::requireRoles(['company']);
    }

    public static function requireAlumni(): array
    {
        return self::requireRoles(['alumni']);
    }

    public static function requirePlacementOfficer(): array
    {
        return self::requireRoles(['placement_officer', 'admin']);
    }

    /**
     * Admin, placement officer, or senior staff (rank &lt; 6) with read-only placement-admin access.
     *
     * @return array<string, mixed>
     */
    public static function requirePlacementDataViewer(): array
    {
        $user = AuthMiddleware::authenticate();
        $resolved = AuthMiddleware::resolvedRole($user);
        if (in_array($resolved, ['admin', 'placement_officer'], true)) {
            return $user;
        }
        if (($user['role'] ?? '') === 'staff' && AuthMiddleware::canViewPlacementAdminData($user)) {
            return $user;
        }
        Response::forbidden('You do not have permission to access this resource.');
    }

    /**
     * @param string[] $roles
     */
    public static function canAccess(array $user, array $roles): bool
    {
        return in_array(AuthMiddleware::resolvedRole($user), $roles, true);
    }
}
