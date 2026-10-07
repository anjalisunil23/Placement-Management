<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Canonical coding language labels and Wandbox routing keys.
 */
final class CodingLanguage
{
    /** @var array<string, string> normalized key → display label */
    private const LABELS = [
        'python' => 'Python',
        'javascript' => 'JavaScript',
        'java' => 'Java',
        'c' => 'C',
        'cpp' => 'C++',
    ];

    /**
     * Normalize UI / API language string to an internal key (python, cpp, …) or null.
     */
    public static function normalizeKey(string $language): ?string
    {
        $raw = self::fold(trim($language));
        if ($raw === '') {
            return null;
        }
        $compact = preg_replace('/\s+/', '', $raw) ?? $raw;

        return match ($compact) {
            'python', 'py', 'python3', 'python2' => 'python',
            'javascript', 'js', 'node', 'nodejs' => 'javascript',
            'java' => 'java',
            'c' => 'c',
            'c++', 'cpp', 'cxx', 'cplusplus' => 'cpp',
            default => null,
        };
    }

    /**
     * Display label for starter templates and API responses (defaults to Python when unknown).
     */
    public static function canonicalLabel(string $language): string
    {
        $key = self::normalizeKey($language);

        return $key !== null ? (self::LABELS[$key] ?? 'Python') : 'Python';
    }

    /**
     * Canonical display label when the language is supported, otherwise null.
     */
    public static function supportedLabel(string $language): ?string
    {
        $key = self::normalizeKey($language);

        return $key !== null ? (self::LABELS[$key] ?? null) : null;
    }

    /** @return list<string> */
    public static function supportedLabels(): array
    {
        return array_values(self::LABELS);
    }

    /**
     * When the client sends Python but the source is clearly C/C++, route to the C++ compiler.
     *
     * @return array{label:string,key:string,requested:string,override:bool}
     */
    public static function resolveForSource(string $language, string $source): array
    {
        $requested = trim($language);
        $key = self::normalizeKey($requested) ?? 'python';
        $label = self::LABELS[$key] ?? 'Python';
        $override = false;

        if ($key === 'python' && self::looksLikeCppOrC($source)) {
            $key = 'cpp';
            $label = 'C++';
            $override = true;
        }

        return [
            'label' => $label,
            'key' => $key,
            'requested' => $requested !== '' ? $requested : 'Python',
            'override' => $override,
        ];
    }

    private static function looksLikeCppOrC(string $source): bool
    {
        if (preg_match('/^\s*#\s*include\s*[<"]/m', $source) === 1) {
            return true;
        }
        if (preg_match('/^\s*using\s+namespace\s+\w+/m', $source) === 1) {
            return true;
        }
        if (preg_match('/\bstd\s*::\s*(cin|cout|endl)\b/', $source) === 1) {
            return true;
        }

        return false;
    }

    private static function fold(string $language): string
    {
        $language = str_replace("\u{FF0B}", '+', $language);
        $language = str_replace(['＋', '﹢', '⁺'], '+', $language);

        return strtolower($language);
    }
}
