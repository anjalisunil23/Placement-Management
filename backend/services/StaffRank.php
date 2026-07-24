<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Numeric staff seniority rank for PlaceHub access rules.
 * Lower number = more senior. Ranks below 6 may view placement-admin data (read-only).
 *
 * Prefer an AES numeric seniority field when present (1–9). Ignore 7th-CPC pay levels (10+).
 * Otherwise derive from designation. Generic teaching titles default to rank 5 (eligible).
 */
final class StaffRank
{
    /** Exclusive upper bound: eligible when 1 <= rank < MAX_VIEW_RANK */
    public const MAX_VIEW_RANK = 6;

    /** Default for teaching faculty when designation is empty / generic */
    public const DEFAULT_TEACHING_RANK = 5;

    /** Explicitly unknown non-teaching / unsupported */
    public const UNKNOWN = 99;

    /**
     * @param array<string, mixed> $source AES payload and/or staff profile fields
     */
    public static function resolve(array $source, string $designation = ''): int
    {
        $fromAes = self::pickNumeric($source);
        if ($fromAes !== null) {
            return $fromAes;
        }
        $desig = trim($designation);
        if ($desig === '') {
            $desig = HodDetection::pickDesignation($source);
        }
        if ($desig === '' && isset($source['designation'])) {
            $desig = trim((string) $source['designation']);
        }
        return self::fromDesignation($desig);
    }

    public static function canViewPlacementAdminData(int $rank): bool
    {
        return $rank >= 1 && $rank < self::MAX_VIEW_RANK;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function pickNumeric(array $source): ?int
    {
        // Do NOT include staff_level / pay Level 10–14 — those are 7th CPC grades, not ranks.
        $keys = [
            'staff_rank', 'staffRank', 'staf_rank', 'stafRank',
            'emp_rank', 'empRank', 'employee_rank', 'employeeRank',
            'faculty_rank', 'facultyRank', 'desig_rank', 'desigRank',
            'designation_rank', 'designationRank', 'rank_id', 'rankId',
            'rank_no', 'rankNo', 'rank',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $parsed = self::parseRankValue($source[$key]);
            if ($parsed === null) {
                continue;
            }
            // Pay levels are typically 10–14; never treat them as seniority ranks.
            if ($parsed >= 10) {
                continue;
            }
            return $parsed;
        }

        foreach (['staff', 'employee', 'faculty', 'profile', 'user'] as $bag) {
            $nested = $source[$bag] ?? null;
            if (!is_array($nested)) {
                continue;
            }
            $parsed = self::pickNumeric($nested);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    public static function fromDesignation(string $designation): int
    {
        $raw = trim($designation);
        $d = strtoupper($raw);
        $d = preg_replace('/[,\/;|]+/', ' ', $d) ?? $d;
        $d = preg_replace('/\s+/', ' ', $d) ?? $d;
        $d = trim($d);

        // Empty / generic teaching labels → eligible (rank 5).
        if ($d === '' || preg_match('/^(FACULTY|STAFF|TEACHER|TEACHING\s*STAFF|MEMBER)$/', $d)) {
            return self::DEFAULT_TEACHING_RANK;
        }

        if (preg_match('/\b(PRINCIPAL|DIRECTOR|VICE\s*PRINCIPAL|DEAN)\b/', $d)) {
            return 1;
        }
        if (HodDetection::designationLooksLikeHod($raw)
            || preg_match('/\bH\.?\s*O\.?\s*D\.?\b/', $d)
            || preg_match('/\bHEAD\s+OF\s+(THE\s+)?(DEPT\.?|DEPARTMENT)\b/', $d)
            || preg_match('/\b(DEPT\.?|DEPARTMENT)\s+HEAD\b/', $d)) {
            return 2;
        }
        if (preg_match('/\bPROFESSOR\b/', $d)
            && !preg_match('/\b(ASSOCIATE|ASSISTANT|ADJUNCT)\b/', $d)
            && !preg_match('/\bOF\s+PRACTICE\b/', $d)) {
            return 3;
        }
        if (preg_match('/\bASSOCIATE\s+PROFESSOR\b/', $d)) {
            return 4;
        }
        if (preg_match('/\bASSISTANT\s+PROFESSOR\b/', $d)) {
            return 5;
        }
        // Junior / non-teaching — not eligible for placement-admin view.
        if (preg_match('/\b(LECTURER|INSTRUCTOR|DEMONSTRATOR|TECHNICAL|LAB\s*ASSISTANT|ADJUNCT|PROFESSOR\s+OF\s+PRACTICE|ATTENDER|CLERK|OFFICE\s*ASSISTANT|ACCOUNTANT)\b/', $d)) {
            return 6;
        }

        // Unknown academic-looking titles still default to teaching rank 5.
        if (preg_match('/\b(PROF|FACULTY|LECTUR|TEACH|DEPARTMENT)\b/', $d)) {
            return self::DEFAULT_TEACHING_RANK;
        }

        return self::DEFAULT_TEACHING_RANK;
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
        if (preg_match('/\brank\s*[=:]?\s*(\d{1,2})\b/i', $text, $m)) {
            $n = (int) $m[1];
            return ($n >= 1 && $n <= 50) ? $n : null;
        }
        return null;
    }
}
