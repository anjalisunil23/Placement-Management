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
        if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
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
        return self::requireRoles(['staff']);
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
     * @param string[] $roles
     */
    public static function canAccess(array $user, array $roles): bool
    {
        return in_array($user['role'] ?? '', $roles, true);
    }
}
