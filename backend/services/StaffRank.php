<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * AES staff_rank for PlaceHub access rules.
 *
 * Admin view-only access requires a real AES staff_rank in 1..5 (rank < 6).
 * Never invent a rank from designation text.
 */
final class StaffRank
{
    /** Exclusive upper bound: eligible when 1 <= staff_rank < MAX_VIEW_RANK */
    public const MAX_VIEW_RANK = 6;

    /** Missing AES staff_rank */
    public const UNKNOWN = 99;

    /**
     * Resolve from AES staff_rank only (or previously synced staffRank).
     *
     * @param array<string, mixed> $source AES payload and/or staff profile fields
     */
    public static function resolve(array $source): int
    {
        $fromAes = self::pickAesStaffRank($source);
        if ($fromAes !== null) {
            return $fromAes;
        }

        // Previously synced PlaceHub profile value (must already be AES-sourced).
        foreach (['staffRank', 'staff_rank'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $parsed = self::parseRankValue($source[$key]);
            if ($parsed !== null && $parsed >= 1 && $parsed < 10) {
                return $parsed;
            }
        }

        return self::UNKNOWN;
    }

    public static function canViewPlacementAdminData(int $rank): bool
    {
        return $rank >= 1 && $rank < self::MAX_VIEW_RANK;
    }

    /**
     * Read AES staff_rank field only (not pay level, not designation, not generic "rank").
     *
     * @param array<string, mixed> $source
     */
    public static function pickAesStaffRank(array $source): ?int
    {
        $keys = [
            'staff_rank', 'staffRank', 'staf_rank', 'stafRank',
            'Staff_Rank', 'STAFF_RANK', 'StaffRank',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $parsed = self::parseRankValue($source[$key]);
            if ($parsed === null || $parsed >= 10) {
                continue;
            }
            return $parsed;
        }

        foreach (['staff', 'employee', 'faculty', 'profile', 'user', 'data', 'details'] as $bag) {
            $nested = $source[$bag] ?? null;
            if (!is_array($nested)) {
                continue;
            }
            $parsed = self::pickAesStaffRank($nested);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach ($source as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? '');
            if (!in_array($norm, ['staffrank', 'stafrank'], true)) {
                continue;
            }
            $parsed = self::parseRankValue($value);
            if ($parsed !== null && $parsed >= 1 && $parsed < 10) {
                return $parsed;
            }
        }

        return null;
    }

    /** @deprecated Use pickAesStaffRank */
    public static function pickNumeric(array $source): ?int
    {
        return self::pickAesStaffRank($source);
    }

    private static function parseRankValue(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $n = (int) $value;
            return ($n >= 1 && $n <= 50) ? $n : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d{1,2}$/', $text)) {
            $n = (int) $text;
            return ($n >= 1 && $n <= 50) ? $n : null;
        }
        return null;
    }
}
