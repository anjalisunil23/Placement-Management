<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Boilerplate starter code from sample I/O so students only fill in logic.
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
            'JavaScript' => "const input = require('fs').readFileSync(0, 'utf8').trim();\n// Write your logic below\n",
        ];
    }

    /**
     * @param array<string, mixed> $problem
     */
    public static function pythonFromProblem(array $problem): string
    {
        $sample = '';
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (is_array($ex) && trim((string) ($ex['input'] ?? '')) !== '') {
                $sample = (string) $ex['input'];
                break;
            }
        }
        if ($sample === '') {
            foreach ((array) ($problem['testCases'] ?? []) as $tc) {
                if (is_array($tc) && !empty($tc['sample']) && trim((string) ($tc['input'] ?? '')) !== '') {
                    $sample = (string) $tc['input'];
                    break;
                }
            }
        }
        if ($sample === '') {
            foreach ((array) ($problem['testCases'] ?? []) as $tc) {
                if (is_array($tc) && trim((string) ($tc['input'] ?? '')) !== '') {
                    $sample = (string) $tc['input'];
                    break;
                }
            }
        }

        return self::pythonFromSampleInput($sample);
    }

    public static function pythonFromSampleInput(string $sampleIn): string
    {
        $raw = str_replace("\r\n", "\n", trim($sampleIn));
        if ($raw === '') {
            return "# Write your logic below\n";
        }
        $lines = explode("\n", $raw);
        $first = trim($lines[0] ?? '');

        if (count($lines) >= 2 && preg_match('/^\d+$/', $first)) {
            $n = (int) $first;
            $second = trim($lines[1] ?? '');
            $nums = preg_split('/\s+/', $second, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($n > 0 && count($nums) === $n) {
                return "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
            if ($n > 0 && count($nums) === 2 * $n) {
                return "n = int(input())\nnums = list(map(int, input().split()))\n\n# Write your logic below\n";
            }
            if (count($lines) >= 3 && preg_match('/^\d+$/', trim($lines[1] ?? ''))) {
                $m = (int) trim($lines[1]);
                $third = trim($lines[2] ?? '');
                $rowNums = preg_split('/\s+/', $third, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($m > 0 && count($rowNums) === $m) {
                    return "n = int(input())\nm = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
                }
                return "n = int(input())\nm = int(input())\n\n# Write your logic below\n";
            }
            if (count($lines) >= 3) {
                return "n = int(input())\n\n# Write your logic below\n";
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
            if (str_contains($line, ' ')) {
                return "parts = input().split()\n\n# Write your logic below\n";
            }
            return "s = input().strip()\n\n# Write your logic below\n";
        }

        return "# Write your logic below\n";
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function enrichItem(array $item): array
    {
        $starter = is_array($item['starterCode'] ?? null) ? $item['starterCode'] : [];
        $py = trim((string) ($starter['Python'] ?? ''));
        $needsTemplate = $py === ''
            || preg_match('/^#\s*Write your solution\s*$/m', $py)
            || !str_contains($py, 'input(');
        if ($needsTemplate) {
            $python = self::pythonFromProblem($item);
            $defaults = self::defaultStarters($python);
            foreach ($defaults as $lang => $code) {
                $existing = trim((string) ($starter[$lang] ?? ''));
                if ($existing === '' || ($lang === 'Python' && $needsTemplate)) {
                    $starter[$lang] = $code;
                }
            }
            $item['starterCode'] = $starter;
        }

        return $item;
    }
}
