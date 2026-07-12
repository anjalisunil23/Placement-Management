<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Input validation helpers.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules  field => rule string (required|email|min:N|max:N|in:a,b)
     * @return array<string, string> errors
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $ruleList = explode('|', $ruleStr);

            foreach ($ruleList as $rule) {
                if ($rule === 'required') {
                    if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
                        break;
                    }
                } elseif ($rule === 'email') {
                    if ($value !== null && $value !== '' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = 'Invalid email format.';
                        break;
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) < $min) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must be at least {$min} characters.";
                        break;
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$max} characters.";
                        break;
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value !== null && !in_array((string) $value, $allowed, true)) {
                        $errors[$field] = 'Invalid value for ' . str_replace('_', ' ', $field) . '.';
                        break;
                    }
                } elseif ($rule === 'phone') {
                    if ($value !== null && $value !== '' && !self::isValidPhone((string) $value)) {
                        $errors[$field] = 'Enter a valid phone number (7–15 digits).';
                        break;
                    }
                } elseif ($rule === 'numeric') {
                    if ($value !== null && $value !== '' && !is_numeric($value)) {
                        $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' must be numeric.';
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    public static function sanitizeString(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return trim(strip_tags($value));
    }

    /** International phone: 7–15 digits (E.164); allows +, spaces, dashes, parentheses. */
    public static function isValidPhone(string $phone): bool
    {
        $trimmed = trim($phone);
        if ($trimmed === '' || preg_match('/^[+\d][\d\s().-]*$/', $trimmed) !== 1) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        $len = strlen($digits);

        return $len >= 7 && $len <= 15;
    }

    /**
     * Digits-only form of a valid phone, or empty string if invalid.
     */
    public static function normalizePhone(string $phone): string
    {
        if (!self::isValidPhone($phone)) {
            return '';
        }
        return preg_replace('/\D+/', '', trim($phone)) ?? '';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitizeArray(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $out[$key] = self::sanitizeString($value);
            } elseif (is_array($value)) {
                $out[$key] = self::sanitizeArray($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
