<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Detect Head of Department from AES / staff designation fields.
 * HOD remains role=staff in DB but is elevated to placement_officer at request time.
 */
final class HodDetection
{
    /**
     * @param list<string> $keys
     */
    public static function pickDesignation(array $source): string
    {
        $keys = [
            'designation', 'title', 'job_title', 'jobTitle', 'desig', 'Desig',
            'staff_desig', 'staffDesig', 'staff_designation', 'staffDesignation',
            'emp_desig', 'empDesig', 'emp_designation', 'empDesignation',
            'faculty_desig', 'facultyDesig', 'faculty_designation', 'facultyDesignation',
            'post', 'post_name', 'postName', 'position', 'staff_post', 'staffPost',
            'role_name', 'roleName', 'official_designation', 'officialDesignation',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    public static function designationLooksLikeHod(string $designation): bool
    {
        $d = strtoupper(trim($designation));
        if ($d === '') {
            return false;
        }

        // HOD / H.O.D / H O D
        if (preg_match('/\bH\.?\s*O\.?\s*D\.?\b/', $d) === 1) {
            return true;
        }
        if (preg_match('/\bHEAD\s+OF\s+(THE\s+)?(DEPT\.?|DEPARTMENT)\b/', $d) === 1) {
            return true;
        }
        if (preg_match('/\b(DEPT\.?|DEPARTMENT)\s+HEAD\b/', $d) === 1) {
            return true;
        }
        if (preg_match('/\bPROFESSOR\s*&\s*HEAD\b/', $d) === 1) {
            return true;
        }
        if (preg_match('/\bAND\s+HEAD\b/', $d) === 1) {
            return true;
        }
        // "Head - CSE", "Head, Department of ..."
        if (preg_match('/\bHEAD\b/', $d) === 1
            && preg_match('/\b(DEPT\.?|DEPARTMENT|BRANCH)\b/', $d) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function payloadIndicatesHod(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        $designation = self::pickDesignation($payload);
        if (self::designationLooksLikeHod($designation)) {
            return true;
        }

        foreach (['is_hod', 'isHod', 'ishod', 'hod', 'is_head', 'isHead', 'department_head', 'departmentHead'] as $flagKey) {
            if (!array_key_exists($flagKey, $payload)) {
                continue;
            }
            $v = $payload[$flagKey];
            if ($v === true || $v === 1 || $v === '1' || strtolower(trim((string) $v)) === 'yes'
                || strtolower(trim((string) $v)) === 'true' || strtolower(trim((string) $v)) === 'hod') {
                return true;
            }
        }

        $hintKeys = [
            'role', 'user_type', 'userType', 'category', 'type', 'usertype', 'user_role', 'userRole',
            'login_type', 'logintype', 'account_type', 'accounttype', 'designation_type', 'designationType',
            'portal', 'module', 'staff_type', 'staffType', 'emp_type', 'empType',
        ];
        foreach ($hintKeys as $key) {
            if (!array_key_exists($key, $payload) || !is_scalar($payload[$key])) {
                continue;
            }
            $text = strtolower(trim((string) $payload[$key]));
            if ($text === '') {
                continue;
            }
            if (preg_match('/\bhod\b|\bhead\s*of\s*(the\s*)?(dept|department)\b|\bdept\.?\s*head\b/', $text) === 1) {
                return true;
            }
        }

        // Shallow scan nested "data" / "user" / "staff" bags common in AES payloads.
        foreach (['data', 'user', 'staff', 'employee', 'faculty', 'profile', 'details'] as $bag) {
            if (!isset($payload[$bag]) || !is_array($payload[$bag])) {
                continue;
            }
            if (self::payloadIndicatesHod($payload[$bag])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure stored designation clearly marks HOD so future requests elevate correctly.
     */
    public static function normalizeDesignationForHod(string $designation, bool $isHod): string
    {
        $designation = trim($designation);
        if (!$isHod) {
            return $designation;
        }
        if (self::designationLooksLikeHod($designation)) {
            return $designation !== '' ? $designation : 'HOD';
        }
        if ($designation === '' || strcasecmp($designation, 'Faculty') === 0 || strcasecmp($designation, 'Staff') === 0) {
            return 'HOD';
        }

        return 'HOD — ' . $designation;
    }
}
