<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Custom-input checks inferred from stored sample/hidden I/O, not from English statements.
 */
final class CodingInputValidator
{
    /**
     * @param array<string, mixed> $problem
     * @return array{ok: bool, status: string, summary: string, detail: string, parsed: ?array, schema: ?string}
     */
    public static function validate(array $problem, string $stdin): array
    {
        $primary = self::inferSchema($problem);
        $schemas = self::collectSchemas($problem);
        if ($primary === null && $schemas === []) {
            return [
                'ok' => true,
                'status' => '',
                'summary' => '',
                'detail' => '',
                'parsed' => self::parseGeneric($stdin),
                'schema' => null,
            ];
        }

        $try = $primary !== null ? array_values(array_unique(array_merge([$primary], $schemas))) : $schemas;
        $primaryError = null;
        foreach ($try as $schema) {
            $parsed = self::parse($schema, $stdin);
            if (!empty($parsed['ok'])) {
                return [
                    'ok' => true,
                    'status' => '',
                    'summary' => '',
                    'detail' => '',
                    'parsed' => $parsed,
                    'schema' => $schema,
                ];
            }
            if ($primaryError === null) {
                $primaryError = $parsed;
            }
        }

        return [
            'ok' => false,
            'status' => 'Custom Input Error',
            'summary' => (string) ($primaryError['summary'] ?? 'Custom Input Error'),
            'detail' => (string) ($primaryError['detail'] ?? ''),
            'parsed' => null,
            'schema' => $primary,
        ];
    }

    /**
     * Sample/example shape is the custom-input schema. Hidden tests with a
     * different shape do not disable validation.
     *
     * @param array<string, mixed> $problem
     */
    public static function inferSchema(array $problem): ?string
    {
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc) || empty($tc['sample'])) {
                continue;
            }
            $schema = self::schemaFromSample((string) ($tc['input'] ?? ''));
            if ($schema !== null) {
                return $schema;
            }
        }
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $schema = self::schemaFromSample((string) ($ex['input'] ?? ''));
            if ($schema !== null) {
                return $schema;
            }
        }
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $schema = self::schemaFromSample((string) ($tc['input'] ?? ''));
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $problem
     * @return list<string>
     */
    public static function collectSchemas(array $problem): array
    {
        $out = [];
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $schema = self::schemaFromSample((string) ($tc['input'] ?? ''));
            if ($schema !== null) {
                $out[] = $schema;
            }
        }
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $schema = self::schemaFromSample((string) ($ex['input'] ?? ''));
            if ($schema !== null) {
                $out[] = $schema;
            }
        }

        return array_values(array_unique($out));
    }

    public static function schemaFromSample(string $sample): ?string
    {
        $lines = self::lines($sample);
        if ($lines === []) {
            return null;
        }
        $first = $lines[0];
        $firstToks = self::tokens($first);

        if (count($lines) >= 3 && count($firstToks) === 2 && self::allInts($firstToks)) {
            $n = (int) $firstToks[0];
            $m = (int) $firstToks[1];
            $second = self::tokens($lines[1] ?? '');
            $third = self::tokens($lines[2] ?? '');
            if ($n > 0 && $m > 0 && count($second) === $n && count($third) === $m && self::allInts($second) && self::allInts($third)) {
                return 'n_m_two_arrays';
            }
        }

        if (count($lines) >= 2 && count($firstToks) === 1 && self::isInt($firstToks[0])) {
            $n = (int) $firstToks[0];
            if ($n >= 2 && count($lines) >= $n + 1) {
                $matrix = true;
                for ($i = 1; $i <= $n; $i++) {
                    $row = self::tokens($lines[$i] ?? '');
                    if (count($row) !== $n || !self::allInts($row)) {
                        $matrix = false;
                        break;
                    }
                }
                if ($matrix) {
                    return 'matrix_n';
                }
            }
            $secondToks = self::tokens($lines[1] ?? '');
            if ($secondToks !== [] && self::allInts($secondToks)) {
                if ($n > 0 && count($secondToks) === $n) {
                    return 'n_then_n_ints';
                }
                if ($n > 1 && count($secondToks) === $n - 1) {
                    return 'n_then_n_minus_1_ints';
                }
                if ($n > 0 && count($secondToks) === 2 * $n) {
                    return 'n_then_2n_ints';
                }
            }
        }

        if (count($lines) >= 2 && count($firstToks) === 2 && self::allInts($firstToks)) {
            $n = (int) $firstToks[0];
            $secondToks = self::tokens($lines[1]);
            if ($n > 0 && count($secondToks) === $n && self::allInts($secondToks)) {
                return 'n_x_then_n_ints';
            }
        }

        if (count($lines) === 1) {
            if (count($firstToks) === 1 && self::isInt($firstToks[0])) {
                return 'one_int';
            }
            if (count($firstToks) === 2 && self::allInts($firstToks)) {
                return 'two_ints';
            }
            if (count($firstToks) === 3 && self::allInts($firstToks)) {
                return 'three_ints';
            }
            if (count($firstToks) === 2 && !self::allInts($firstToks)) {
                return 'two_words';
            }
            if (count($firstToks) >= 3 && !self::allInts($firstToks)) {
                return 'words_line';
            }
            if (count($firstToks) >= 1) {
                return 'one_string';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $schema, string $stdin): array
    {
        $lines = self::lines($stdin);
        return match ($schema) {
            'n_then_n_ints' => self::parseNThenKInts($lines, null, 'n_then_n_ints'),
            'n_then_n_minus_1_ints' => self::parseNThenKInts($lines, -1, 'n_then_n_minus_1_ints'),
            'n_then_2n_ints' => self::parseNThenKInts($lines, 2, 'n_then_2n_ints'),
            'n_x_then_n_ints' => self::parseNXThenN($lines),
            'n_m_two_arrays' => self::parseNMTwoArrays($lines),
            'matrix_n' => self::parseMatrix($lines),
            'one_int' => self::parseFixedInts($lines, 1, 'one_int'),
            'two_ints' => self::parseFixedInts($lines, 2, 'two_ints'),
            'three_ints' => self::parseFixedInts($lines, 3, 'three_ints'),
            'two_words' => self::parseTwoWords($lines),
            'words_line' => self::parseWordsLine($lines),
            'one_string' => self::parseOneString($lines),
            default => ['ok' => true, 'shape' => $schema],
        };
    }

    /**
     * @return list<string>
     */
    public static function lines(string $stdin): array
    {
        $text = str_replace("\r\n", "\n", str_replace("\r", "\n", $stdin));
        $text = rtrim($text, "\n");
        if (trim($text) === '') {
            return [];
        }

        return explode("\n", $text);
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $line): array
    {
        $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values($parts) : [];
    }

    public static function isInt(string $token): bool
    {
        return (bool) preg_match('/^-?\d+$/', $token);
    }

    /**
     * @param list<string> $tokens
     */
    public static function allInts(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (!self::isInt($token)) {
                return false;
            }
        }

        return $tokens !== [];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseNThenKInts(array $lines, ?int $factor, string $shape): array
    {
        if ($lines === [] || trim($lines[0] ?? '') === '') {
            return self::fail('Custom input is empty.', 'Enter the values required by the input format.');
        }
        $head = self::tokens($lines[0]);
        if (count($head) !== 1) {
            return self::fail('The first line must contain a single integer N.', 'Invalid value: ' . ($head[0] ?? ''));
        }
        if (!self::isInt($head[0])) {
            return self::fail('The first line must contain an integer N.', 'Invalid value: ' . $head[0]);
        }
        $n = (int) $head[0];
        if ($n < 0) {
            return self::fail('N must be a non-negative integer.', 'Invalid value: ' . $head[0]);
        }
        $want = $factor === null ? $n : ($factor < 0 ? max(0, $n - 1) : $n * $factor);
        $second = self::tokens($lines[1] ?? '');
        foreach ($second as $token) {
            if (!self::isInt($token)) {
                return self::fail('Array elements must be integers.', 'Invalid value: ' . $token);
            }
        }
        $got = count($second);
        if ($got !== $want) {
            return self::fail(
                'Expected ' . $want . ' integer' . ($want === 1 ? '' : 's') . '.',
                'Expected ' . $want . ' integers.' . "\n" . 'Received ' . $got . '.'
            );
        }
        $extra = array_slice($lines, 2);
        foreach ($extra as $line) {
            if (trim($line) !== '') {
                return self::fail('Unexpected extra input.', 'Remove extra lines after the array.');
            }
        }
        $arr = array_map(static fn (string $t): int => (int) $t, $second);

        return ['ok' => true, 'shape' => $shape, 'n' => $n, 'arr' => $arr, 'ints' => $arr];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseNXThenN(array $lines): array
    {
        if ($lines === []) {
            return self::fail('Custom input is empty.', 'Enter N and X, then N integers.');
        }
        $head = self::tokens($lines[0]);
        if (count($head) !== 2 || !self::allInts($head)) {
            $bad = $head[0] ?? '';
            foreach ($head as $token) {
                if (!self::isInt($token)) {
                    $bad = $token;
                    break;
                }
            }

            return self::fail('The first line must contain two integers.', 'Invalid value: ' . $bad);
        }
        $n = (int) $head[0];
        $x = (int) $head[1];
        $second = self::tokens($lines[1] ?? '');
        foreach ($second as $token) {
            if (!self::isInt($token)) {
                return self::fail('Array elements must be integers.', 'Invalid value: ' . $token);
            }
        }
        if (count($second) !== $n) {
            return self::fail(
                'Expected ' . $n . ' integers.',
                'Expected ' . $n . ' integers.' . "\n" . 'Received ' . count($second) . '.'
            );
        }
        $arr = array_map(static fn (string $t): int => (int) $t, $second);

        return ['ok' => true, 'shape' => 'n_x_then_n_ints', 'n' => $n, 'x' => $x, 'arr' => $arr, 'ints' => $arr];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseFixedInts(array $lines, int $count, string $shape): array
    {
        if ($lines === []) {
            return self::fail('Custom input is empty.', 'Enter ' . $count . ' integer' . ($count === 1 ? '' : 's') . '.');
        }
        $toks = self::tokens($lines[0]);
        foreach ($toks as $token) {
            if (!self::isInt($token)) {
                return self::fail('Values must be integers.', 'Invalid value: ' . $token);
            }
        }
        if (count($toks) !== $count) {
            return self::fail(
                'Expected ' . $count . ' integer' . ($count === 1 ? '' : 's') . '.',
                'Expected ' . $count . ' integers.' . "\n" . 'Received ' . count($toks) . '.'
            );
        }
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) !== '') {
                return self::fail('Unexpected extra input.', 'Remove extra lines.');
            }
        }
        $ints = array_map(static fn (string $t): int => (int) $t, $toks);

        return ['ok' => true, 'shape' => $shape, 'ints' => $ints, 'n' => $ints[0] ?? 0];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseNMTwoArrays(array $lines): array
    {
        if ($lines === []) {
            return self::fail('Custom input is empty.', 'Enter N M, then two arrays.');
        }
        $head = self::tokens($lines[0]);
        if (count($head) !== 2 || !self::allInts($head)) {
            return self::fail('The first line must contain two integers N and M.', 'Invalid first line.');
        }
        $n = (int) $head[0];
        $m = (int) $head[1];
        $a = self::tokens($lines[1] ?? '');
        $b = self::tokens($lines[2] ?? '');
        foreach (array_merge($a, $b) as $token) {
            if (!self::isInt($token)) {
                return self::fail('Array elements must be integers.', 'Invalid value: ' . $token);
            }
        }
        if (count($a) !== $n) {
            return self::fail('Expected ' . $n . ' integers.', 'Expected ' . $n . ' integers.' . "\n" . 'Received ' . count($a) . '.');
        }
        if (count($b) !== $m) {
            return self::fail('Expected ' . $m . ' integers.', 'Expected ' . $m . ' integers.' . "\n" . 'Received ' . count($b) . '.');
        }
        $arr1 = array_map(static fn (string $t): int => (int) $t, $a);
        $arr2 = array_map(static fn (string $t): int => (int) $t, $b);

        return ['ok' => true, 'shape' => 'n_m_two_arrays', 'n' => $n, 'm' => $m, 'arr' => $arr1, 'arr2' => $arr2, 'ints' => $arr1];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseMatrix(array $lines): array
    {
        if ($lines === []) {
            return self::fail('Custom input is empty.', 'Enter N, then an N x N matrix.');
        }
        $head = self::tokens($lines[0]);
        if (count($head) !== 1 || !self::isInt($head[0])) {
            return self::fail('The first line must contain integer N.', 'Invalid value: ' . ($head[0] ?? ''));
        }
        $n = (int) $head[0];
        $matrix = [];
        for ($i = 1; $i <= $n; $i++) {
            $row = self::tokens($lines[$i] ?? '');
            foreach ($row as $token) {
                if (!self::isInt($token)) {
                    return self::fail('Matrix values must be integers.', 'Invalid value: ' . $token);
                }
            }
            if (count($row) !== $n) {
                return self::fail('Expected ' . $n . ' integers.', 'Expected ' . $n . ' integers.' . "\n" . 'Received ' . count($row) . '.');
            }
            $matrix[] = array_map(static fn (string $t): int => (int) $t, $row);
        }

        return ['ok' => true, 'shape' => 'matrix_n', 'n' => $n, 'matrix' => $matrix, 'arr' => $matrix[0] ?? []];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseWordsLine(array $lines): array
    {
        $toks = self::tokens($lines[0] ?? '');
        if (count($toks) < 1) {
            return self::fail('Custom input is empty.', 'Enter one or more words.');
        }

        return ['ok' => true, 'shape' => 'words_line', 'words' => $toks, 's' => implode(' ', $toks)];
    }

    private static function parseTwoWords(array $lines): array
    {
        $toks = self::tokens($lines[0] ?? '');
        if (count($toks) !== 2) {
            return self::fail('Expected two words.', 'Received ' . count($toks) . '.');
        }

        return ['ok' => true, 'shape' => 'two_words', 'words' => $toks];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private static function parseOneString(array $lines): array
    {
        if ($lines === []) {
            return self::fail('Custom input is empty.', 'Enter a string.');
        }

        return ['ok' => true, 'shape' => 'one_string', 's' => $lines[0]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseGeneric(string $stdin): array
    {
        return ['ok' => true, 'shape' => null, 'raw' => $stdin];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fail(string $summary, string $detail): array
    {
        return ['ok' => false, 'summary' => $summary, 'detail' => $detail];
    }
}
