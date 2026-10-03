<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Per-question boilerplate from input format + sample I/O.
 */
final class CodingStarterTemplates
{
    /**
     * @return array<string, string>
     */
    public static function defaultStarters(string $pythonBody): array
    {
        return [
            'Python' => $pythonBody,
            'Java' => "import java.util.*;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    // Write your logic below\n  }\n}\n",
            'C' => "#include <stdio.h>\nint main() {\n  // Write your logic below\n  return 0;\n}\n",
            'C++' => "#include <bits/stdc++.h>\nusing namespace std;\nint main() {\n  ios::sync_with_stdio(false);\n  cin.tie(nullptr);\n  // Write your logic below\n  return 0;\n}\n",
            'JavaScript' => "const input = require('fs').readFileSync(0, 'utf8').trim();\nconst lines = input.split(/\\r?\\n/);\n// Write your logic below\n",
        ];
    }

    /**
     * @param array<string, mixed> $problem
     */
    public static function pythonFromProblem(array $problem): string
    {
        $sample = self::resolveSampleInput($problem);
        $format = trim((string) ($problem['inputFormat'] ?? ''));

        $fromFormat = self::pythonFromInputFormat($format, $sample);
        if ($fromFormat !== null && self::formatStarterFitsSample($fromFormat, $sample)) {
            return $fromFormat;
        }

        return self::pythonFromSampleInput($sample, $format);
    }

    /**
     * @param array<string, mixed> $problem
     */
    private static function resolveSampleInput(array $problem): string
    {
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (is_array($ex) && trim((string) ($ex['input'] ?? '')) !== '') {
                return (string) $ex['input'];
            }
        }
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (is_array($tc) && !empty($tc['sample']) && trim((string) ($tc['input'] ?? '')) !== '') {
                return (string) $tc['input'];
            }
        }
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (is_array($tc) && trim((string) ($tc['input'] ?? '')) !== '') {
                return (string) $tc['input'];
            }
        }

        return '';
    }

    private static function formatStarterFitsSample(string $starter, string $sampleIn): bool
    {
        $raw = str_replace("\r\n", "\n", trim($sampleIn));
        if ($raw === '') {
            return true;
        }
        $first = trim(explode("\n", $raw)[0] ?? '');
        if (preg_match('/^n,\s*(?:x|t|k)\s*=\s*map/m', $starter)) {
            return (bool) preg_match('/^\d+\s+-?\d+$/', $first);
        }
        if (preg_match('/^n,\s*x,\s*_\s*=\s*map/m', $starter)) {
            return (bool) preg_match('/^\d+\s+-?\d+\s+-?\d+/', $first);
        }

        return true;
    }

    private static function pythonFromInputFormat(string $format, string $sampleIn): ?string
    {
        $f = strtolower($format);
        if ($f === '') {
            return null;
        }

        if (preg_match('/single line string|line string|string s\b|a string\b|one string/i', $format)) {
            return "s = input().strip()\n\n# Write your logic below\n";
        }
        if (preg_match('/brackets|line of brackets|parentheses/i', $format)) {
            return "s = input().strip()\n\n# Write your logic below\n";
        }
        if (preg_match('/single line of words|line of words|space-separated words|two space-separated words|two words/i', $format)) {
            if (preg_match('/two space-separated words|two words/i', $format)) {
                return "w1, w2 = input().split()\n\n# Write your logic below\n";
            }
            return "words = input().split()\n\n# Write your logic below\n";
        }
        if (preg_match('/three integers|3 integers|a b c/i', $format) && !preg_match('/first line|second line|n\b/i', $format)) {
            return "a, b, c = map(int, input().split())\n\n# Write your logic below\n";
        }
        if (preg_match('/two integers|2 integers|\ba and b\b/i', $format) && !preg_match('/first line|three|n integers/i', $format)) {
            return "a, b = map(int, input().split())\n\n# Write your logic below\n";
        }
        if (preg_match('/one integer|single integer|one non-negative integer|\bn\b.*integer/i', $format)
            && !preg_match('/first line|second line|then/i', $format)) {
            return "n = int(input())\n\n# Write your logic below\n";
        }
        if (preg_match('/then n lines|n lines of n|n×n|n x n matrix|matrix/i', $format)) {
            return "n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n";
        }
        if (preg_match('/line 1:\s*n\s*m|n m\b|n and m/i', $format) && preg_match('/line 2|line 3|second line|third line/i', $format)) {
            return "n, m = map(int, input().split())\narr1 = list(map(int, input().split()))\narr2 = list(map(int, input().split()))\n\n# Write your logic below\n";
        }
        if (preg_match('/first line n\. second line n-1|n-1 integers/i', $format)) {
            return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
        }
        if (preg_match('/first line n x\.|first line n t\.|n x\b|n t\b/i', $format)) {
            return "n, x = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
        }
        if (preg_match('/first line n k\.|n k\b/i', $format)) {
            return "n, k = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
        }
        if (preg_match('/first line n\. second line n integers|first line n\. second line/i', $format)) {
            return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
        }

        return null;
    }

    public static function pythonFromSampleInput(string $sampleIn, string $inputFormat = ''): string
    {
        $raw = str_replace("\r\n", "\n", trim($sampleIn));
        if ($raw === '') {
            return "# Write your logic below\n";
        }
        $lines = explode("\n", $raw);
        $first = trim($lines[0] ?? '');
        $fmt = strtolower($inputFormat);

        if (count($lines) >= 3 && preg_match('/^\d+\s+\d+$/', $first)) {
            $second = trim($lines[1] ?? '');
            $third = trim($lines[2] ?? '');
            if (preg_match('/^-?\d+(\s+-?\d+)*$/', $second) && preg_match('/^-?\d+(\s+-?\d+)*$/', $third)) {
                return "n, m = map(int, input().split())\narr1 = list(map(int, input().split()))\narr2 = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
        }

        if (count($lines) >= 2 && preg_match('/^\d+\s+-?\d+$/', $first)) {
            $second = trim($lines[1] ?? '');
            if (preg_match('/^-?\d+(\s+-?\d+)+$/', $second)) {
                $label = str_contains($fmt, ' k') ? 'k' : (str_contains($fmt, ' x') ? 'x' : 't');
                return "n, {$label} = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
        }

        if (count($lines) >= 2 && preg_match('/^\d+$/', $first)) {
            $n = (int) $first;
            $second = trim($lines[1] ?? '');
            $third = trim($lines[2] ?? '');

            $nums = preg_split('/\s+/', $second, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (str_contains($fmt, '2n') && $n > 0 && count($nums) === 2 * $n) {
                return "n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
            if ($n > 0 && count($nums) === 2 * $n) {
                return "n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
            if ($n > 0 && count($nums) === $n) {
                return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
            if ($n > 0 && count($nums) === max(1, $n - 1)) {
                return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
            }

            if (count($lines) >= 3 && preg_match('/^\d+$/', trim($lines[1] ?? ''))) {
                $m = (int) trim($lines[1]);
                $row = preg_split('/\s+/', $third, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($m > 0 && count($row) === $m) {
                    return "n, m = map(int, input().split())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
                }
                return "n, m = map(int, input().split())\n\n# Write your logic below\n";
            }

            if (count($lines) > 2 && preg_match('/^-?\d+(\s+-?\d+)+$/', $second)) {
                return "n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n";
            }

            if (count($lines) === 2) {
                return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
        }

        if (count($lines) === 1) {
            $line = $first;
            if (preg_match('/^-?\d+$/', $line)) {
                return "n = int(input())\n\n# Write your logic below\n";
            }
            if (preg_match('/^-?\d+\s+-?\d+$/', $line)) {
                return "a, b = map(int, input().split())\n\n# Write your logic below\n";
            }
            if (preg_match('/^-?\d+\s+-?\d+\s+-?\d+$/', $line)) {
                return "a, b, c = map(int, input().split())\n\n# Write your logic below\n";
            }
            if (preg_match('/^-?\d+\s+-?\d+\s+-?\d+\s+-?\d+$/', $line)) {
                return "a, b, c, d = map(int, input().split())\n\n# Write your logic below\n";
            }
            if (preg_match('/^[()\[\]{}]+$/', $line)) {
                return "s = input().strip()\n\n# Write your logic below\n";
            }
            if (preg_match('/^[A-Za-z]+(\s+[A-Za-z]+)+$/', $line) && !preg_match('/\d/', $line)) {
                $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (count($parts) === 2) {
                    return "w1, w2 = input().split()\n\n# Write your logic below\n";
                }
                return "words = input().split()\n\n# Write your logic below\n";
            }
            if (str_contains($line, ' ')) {
                if (preg_match('/^-?\d/', $line)) {
                    return "values = list(map(int, input().split()))\n\n# Write your logic below\n";
                }
                return "parts = input().split()\n\n# Write your logic below\n";
            }
            return "s = input().strip()\n\n# Write your logic below\n";
        }

        if (count($lines) >= 3 && preg_match('/^\d+$/', $first)) {
            $n = (int) $first;
            return "n = int(input())\nmatrix = [list(map(int, input().split())) for _ in range(n)]\n\n# Write your logic below\n";
        }

        return "# Write your logic below\n";
    }

    public static function onlyInputBoilerplate(string $py): bool
    {
        $lines = explode("\n", $py);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            if (preg_match(
                '/^(?:[a-z_,\s]+\s*=\s*)?(?:int\(input\(\)\)|input\(\)(?:\.strip\(\))?|map\(int,\s*input\(\)\.split\(\)\)|list\(map\(int,\s*input\(\)\.split\(\)\)\)|\[list\(map\(int,\s*input\(\)\.split\(\)\)\)\s+for\s+_\s+in\s+range\([a-z_]\w*\)\])$/i',
                $t
            )) {
                continue;
            }
            if (preg_match('/^[a-z_]\w*\s*=\s*input\(\)\.split\(\)$/i', $t)) {
                continue;
            }
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function enrichItem(array $item): array
    {
        if (!empty($item['starterCodeLocked'])) {
            return $item;
        }

        $starter = is_array($item['starterCode'] ?? null) ? $item['starterCode'] : [];
        $py = trim((string) ($starter['Python'] ?? ''));
        $generated = self::pythonFromProblem($item);

        $replacePython = $py === ''
            || preg_match('/^#\s*Write your solution\s*$/m', $py)
            || self::onlyInputBoilerplate($py);

        if ($replacePython) {
            $defaults = self::defaultStarters($generated);
            foreach ($defaults as $lang => $code) {
                $existing = trim((string) ($starter[$lang] ?? ''));
                if ($lang === 'Python' || $existing === '' || ($lang !== 'Python' && self::onlyInputBoilerplate($existing))) {
                    $starter[$lang] = $code;
                }
            }
            $item['starterCode'] = $starter;
        }

        return $item;
    }
}
