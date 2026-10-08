<?php

declare(strict_types=1);

namespace PMS\Utils;

use PMS\Services\CodeExecutionService;
use PMS\Services\CodingTestCaseChecker;

/**
 * Trusted expected output for custom stdin: stored test cases, optional
 * server-only referenceSolution (same executor), or an oracle that matches
 * every stored test case. Never sent to the client as source.
 */
final class CodingExpectedOracle
{
    /**
     * @param array<string, mixed> $problem
     * @param array<string, mixed>|null $parsed
     * @return array{expected: ?string, source: string, error: string}
     */
    public static function resolve(
        array $problem,
        string $stdin,
        ?array $parsed,
        ?CodeExecutionService $executor = null
    ): array {
        $fromCase = self::fromStoredCase($problem, $stdin);
        if ($fromCase !== null) {
            return ['expected' => $fromCase, 'source' => 'testcase', 'error' => ''];
        }

        $fromOracle = self::fromMatchingOracle($problem, $stdin, $parsed);
        if ($fromOracle !== null) {
            return ['expected' => $fromOracle, 'source' => 'oracle', 'error' => ''];
        }

        $ref = trim((string) ($problem['referenceSolution'] ?? $problem['officialSolution'] ?? ''));
        if ($ref !== '' && $executor instanceof CodeExecutionService) {
            $lang = trim((string) ($problem['referenceLanguage'] ?? 'Python')) ?: 'Python';
            $exec = $executor->run($lang, $ref, $stdin, 3000);
            if (!CodingTestCaseChecker::executionSucceeded($exec)) {
                return [
                    'expected' => null,
                    'source' => 'reference',
                    'error' => 'The reference solution could not produce expected output for this input.',
                ];
            }

            return [
                'expected' => (string) ($exec['stdout'] ?? ''),
                'source' => 'reference',
                'error' => '',
            ];
        }

        return ['expected' => null, 'source' => 'none', 'error' => ''];
    }

    /**
     * @param array<string, mixed> $problem
     */
    private static function fromStoredCase(array $problem, string $stdin): ?string
    {
        $want = CodingTestCaseChecker::normalize($stdin);
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            if (CodingTestCaseChecker::normalize((string) ($tc['input'] ?? '')) !== $want) {
                continue;
            }
            $raw = CodingTestCaseChecker::expectedFromTestCase($tc);
            if ($raw !== null) {
                return $raw;
            }
        }
        foreach ((array) ($problem['examples'] ?? []) as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            if (CodingTestCaseChecker::normalize((string) ($ex['input'] ?? '')) === $want) {
                return (string) ($ex['output'] ?? $ex['expected'] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $problem
     * @param array<string, mixed>|null $parsed
     */
    private static function fromMatchingOracle(array $problem, string $stdin, ?array $parsed): ?string
    {
        $cases = [];
        foreach ((array) ($problem['testCases'] ?? []) as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $expected = CodingTestCaseChecker::expectedFromTestCase($tc);
            if ($expected === null) {
                continue;
            }
            $input = (string) ($tc['input'] ?? '');
            $schema = CodingInputValidator::schemaFromSample($input);
            $got = $schema !== null
                ? CodingInputValidator::parse($schema, $input)
                : ['ok' => false];
            if (empty($got['ok'])) {
                continue;
            }
            $cases[] = ['parsed' => $got, 'expected' => CodingTestCaseChecker::normalize($expected)];
        }
        if (count($cases) < 2) {
            return null;
        }

        $style = self::yesNoStyle($cases);
        $matched = [];
        foreach (self::oracleIds() as $id) {
            $all = true;
            foreach ($cases as $case) {
                $out = self::runOracle($id, $case['parsed'], $style);
                if ($out === null || CodingTestCaseChecker::normalize($out) !== $case['expected']) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $matched[] = $id;
            }
        }
        if (count($matched) !== 1) {
            return null;
        }
        $customParsed = $parsed;
        if (!is_array($customParsed) || empty($customParsed['ok'])) {
            $customSchema = CodingInputValidator::schemaFromSample($stdin)
                ?? CodingInputValidator::inferSchema($problem);
            $customParsed = $customSchema !== null
                ? CodingInputValidator::parse($customSchema, $stdin)
                : ['ok' => false];
        }
        if (empty($customParsed['ok'])) {
            return null;
        }

        return self::runOracle($matched[0], $customParsed, $style);
    }

    /**
     * @return list<string>
     */
    private static function oracleIds(): array
    {
        return [
            'array_has_duplicate',
            'array_all_unique',
            'array_reverse',
            'array_unique_first',
            'array_second_largest',
            'array_missing_1_to_n',
            'array_max',
            'array_min',
            'array_sum',
            'count_of_x',
            'pair_sum_exists',
            'two_sum',
            'two_abs_diff',
            'two_gcd',
            'three_max',
            'one_even_odd',
            'one_factorial',
            'one_fibonacci',
            'one_prime',
            'one_sum_digits',
            'one_triangle',
            'string_reverse',
            'string_vowels',
            'string_palindrome',
            'two_words_anagram',
            'two_lcm',
            'array_kadane',
            'array_next_greater',
            'array_mode',
            'array_partition_pairs',
            'array_rotate_right',
            'array_binary_search',
            'merge_sorted_arrays',
            'matrix_diagonal_sum',
            'string_balanced_parens',
            'words_longest',
        ];
    }

    /**
     * @param list<array{parsed: array<string, mixed>, expected: string}> $cases
     * @return array{yes: string, no: string}
     */
    private static function yesNoStyle(array $cases): array
    {
        foreach ($cases as $case) {
            $e = $case['expected'];
            if ($e === 'Yes' || $e === 'No') {
                return ['yes' => 'Yes', 'no' => 'No'];
            }
            if ($e === 'YES' || $e === 'NO') {
                return ['yes' => 'YES', 'no' => 'NO'];
            }
            if ($e === 'Even' || $e === 'Odd') {
                return ['yes' => 'Even', 'no' => 'Odd'];
            }
        }

        return ['yes' => 'Yes', 'no' => 'No'];
    }

    /**
     * @param array<string, mixed> $parsed
     * @param array{yes: string, no: string} $style
     */
    private static function runOracle(string $id, array $parsed, array $style): ?string
    {
        $arr = array_values(array_map('intval', (array) ($parsed['arr'] ?? $parsed['ints'] ?? [])));
        $n = (int) ($parsed['n'] ?? ($arr[0] ?? 0));
        $x = (int) ($parsed['x'] ?? 0);
        $s = (string) ($parsed['s'] ?? '');
        $words = array_values((array) ($parsed['words'] ?? []));
        $shape = (string) ($parsed['shape'] ?? '');

        return match ($id) {
            'array_has_duplicate' => self::needArr($shape) ? self::yn(count($arr) !== count(array_unique($arr)), $style) : null,
            'array_all_unique' => self::needArr($shape) ? self::yn(count($arr) === count(array_unique($arr)), $style) : null,
            'array_reverse' => self::needArr($shape) ? implode(' ', array_reverse($arr)) : null,
            'array_unique_first' => self::needArr($shape) ? implode(' ', array_values(array_unique($arr))) : null,
            'array_second_largest' => self::needArr($shape) ? self::secondLargest($arr) : null,
            'array_missing_1_to_n' => ($shape === 'n_then_n_minus_1_ints') ? self::missingNumber($n, $arr) : null,
            'array_max' => self::needArr($shape) && $arr !== [] ? (string) max($arr) : null,
            'array_min' => self::needArr($shape) && $arr !== [] ? (string) min($arr) : null,
            'array_sum' => self::needArr($shape) ? (string) array_sum($arr) : null,
            'count_of_x' => $shape === 'n_x_then_n_ints' ? (string) count(array_filter($arr, static fn (int $v): bool => $v === $x)) : null,
            'pair_sum_exists' => $shape === 'n_x_then_n_ints' ? self::yn(self::pairSum($arr, $x), $style) : null,
            'two_sum' => $shape === 'two_ints' && count($arr) === 2 ? (string) ($arr[0] + $arr[1]) : null,
            'two_abs_diff' => $shape === 'two_ints' && count($arr) === 2 ? (string) abs($arr[0] - $arr[1]) : null,
            'two_gcd' => $shape === 'two_ints' && count($arr) === 2 ? (string) self::gcd($arr[0], $arr[1]) : null,
            'three_max' => $shape === 'three_ints' && $arr !== [] ? (string) max($arr) : null,
            'one_even_odd' => $shape === 'one_int' ? (($n % 2 === 0) ? 'Even' : 'Odd') : null,
            'one_factorial' => $shape === 'one_int' && $n >= 0 && $n <= 12 ? (string) self::factorial($n) : null,
            'one_fibonacci' => $shape === 'one_int' && $n >= 1 && $n <= 40 ? (string) self::fib($n) : null,
            'one_prime' => $shape === 'one_int' ? self::yn(self::isPrime($n), ['yes' => 'YES', 'no' => 'NO']) : null,
            'one_sum_digits' => $shape === 'one_int' ? (string) array_sum(str_split((string) abs($n))) : null,
            'one_triangle' => $shape === 'one_int' && $n >= 0 ? (string) intdiv($n * ($n + 1), 2) : null,
            'string_reverse' => $shape === 'one_string' ? strrev($s) : null,
            'string_vowels' => $shape === 'one_string' ? (string) preg_match_all('/[aeiou]/i', $s) : null,
            'string_palindrome' => $shape === 'one_string' ? self::yn(strtolower($s) === strrev(strtolower($s)), ['yes' => 'YES', 'no' => 'NO']) : null,
            'two_words_anagram' => $shape === 'two_words' && count($words) === 2 ? self::yn(self::anagram($words[0], $words[1]), ['yes' => 'YES', 'no' => 'NO']) : null,
            'two_lcm' => $shape === 'two_ints' && count($arr) === 2 ? (string) self::lcm($arr[0], $arr[1]) : null,
            'array_kadane' => self::needArr($shape) && $arr !== [] ? (string) self::kadane($arr) : null,
            'array_next_greater' => self::needArr($shape) ? implode(' ', self::nextGreater($arr)) : null,
            'array_mode' => self::needArr($shape) && $arr !== [] ? (string) self::mode($arr) : null,
            'array_partition_pairs' => self::needArr($shape) && count($arr) >= 2 && count($arr) % 2 === 0 ? (string) self::arrayPartition($arr) : null,
            'array_rotate_right' => $shape === 'n_x_then_n_ints' ? implode(' ', self::rotateRight($arr, $x)) : null,
            'array_binary_search' => $shape === 'n_x_then_n_ints' ? (string) self::indexOf($arr, $x) : null,
            'merge_sorted_arrays' => $shape === 'n_m_two_arrays' ? implode(' ', self::mergeSorted($arr, array_map('intval', (array) ($parsed['arr2'] ?? [])))) : null,
            'matrix_diagonal_sum' => $shape === 'matrix_n' ? (string) self::diagonalSum((array) ($parsed['matrix'] ?? [])) : null,
            'string_balanced_parens' => $shape === 'one_string' ? self::yn(self::balanced($s), ['yes' => 'YES', 'no' => 'NO']) : null,
            'words_longest' => ($shape === 'words_line' || $shape === 'two_words') && $words !== [] ? self::longestWord($words) : null,
            default => null,
        };
    }

    private static function needArr(string $shape): bool
    {
        return in_array($shape, ['n_then_n_ints', 'n_then_n_minus_1_ints', 'n_then_2n_ints', 'n_x_then_n_ints'], true);
    }

    /**
     * @param array{yes: string, no: string} $style
     */
    private static function yn(bool $ok, array $style): string
    {
        return $ok ? $style['yes'] : $style['no'];
    }

    /**
     * @param list<int> $arr
     */
    private static function secondLargest(array $arr): string
    {
        $uniq = array_values(array_unique($arr));
        rsort($uniq);

        return isset($uniq[1]) ? (string) $uniq[1] : '-1';
    }

    /**
     * @param list<int> $arr
     */
    private static function missingNumber(int $n, array $arr): string
    {
        $have = array_fill_keys($arr, true);
        for ($i = 1; $i <= $n; $i++) {
            if (!isset($have[$i])) {
                return (string) $i;
            }
        }

        return '0';
    }

    /**
     * @param list<int> $arr
     */
    private static function pairSum(array $arr, int $target): bool
    {
        $seen = [];
        foreach ($arr as $v) {
            if (isset($seen[$target - $v])) {
                return true;
            }
            $seen[$v] = true;
        }

        return false;
    }

    /**
     * @param list<int> $arr
     */
    private static function indexOf(array $arr, int $x): int
    {
        $idx = array_search($x, $arr, true);

        return $idx === false ? -1 : (int) $idx;
    }

    private static function lcm(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return intdiv(abs($a * $b), self::gcd($a, $b));
    }

    /**
     * @param list<int> $arr
     */
    private static function kadane(array $arr): int
    {
        $best = $arr[0];
        $cur = $arr[0];
        $n = count($arr);
        for ($i = 1; $i < $n; $i++) {
            $cur = max($arr[$i], $cur + $arr[$i]);
            $best = max($best, $cur);
        }

        return $best;
    }

    /**
     * @param list<int> $arr
     * @return list<int>
     */
    private static function nextGreater(array $arr): array
    {
        $n = count($arr);
        $out = array_fill(0, $n, -1);
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            while ($stack !== [] && $arr[$i] > $arr[$stack[array_key_last($stack)]]) {
                $j = array_pop($stack);
                $out[$j] = $arr[$i];
            }
            $stack[] = $i;
        }

        return $out;
    }

    /**
     * @param list<int> $arr
     */
    private static function mode(array $arr): int
    {
        $freq = [];
        foreach ($arr as $v) {
            $freq[$v] = ($freq[$v] ?? 0) + 1;
        }
        $best = $arr[0];
        $bestC = $freq[$best];
        foreach ($freq as $v => $c) {
            if ($c > $bestC || ($c === $bestC && (int) $v < $best)) {
                $best = (int) $v;
                $bestC = $c;
            }
        }

        return $best;
    }

    /**
     * @param list<int> $arr
     */
    private static function arrayPartition(array $arr): int
    {
        sort($arr);
        $sum = 0;
        $n = count($arr);
        for ($i = 0; $i < $n; $i += 2) {
            $sum += $arr[$i];
        }

        return $sum;
    }

    /**
     * @param list<int> $arr
     * @return list<int>
     */
    private static function rotateRight(array $arr, int $k): array
    {
        $n = count($arr);
        if ($n === 0) {
            return [];
        }
        $k %= $n;
        if ($k === 0) {
            return $arr;
        }

        return array_merge(array_slice($arr, $n - $k), array_slice($arr, 0, $n - $k));
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function mergeSorted(array $a, array $b): array
    {
        $out = array_merge($a, $b);
        sort($out);

        return $out;
    }

    /**
     * @param list<list<int>> $matrix
     */
    private static function diagonalSum(array $matrix): int
    {
        $n = count($matrix);
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += (int) ($matrix[$i][$i] ?? 0);
            if ($i !== $n - 1 - $i) {
                $sum += (int) ($matrix[$i][$n - 1 - $i] ?? 0);
            }
        }

        return $sum;
    }

    private static function balanced(string $s): bool
    {
        $map = [')' => '(', ']' => '[', '}' => '{'];
        $stack = [];
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $stack[] = $ch;
                continue;
            }
            if (!isset($map[$ch])) {
                continue;
            }
            if ($stack === [] || array_pop($stack) !== $map[$ch]) {
                return false;
            }
        }

        return $stack === [];
    }

    /**
     * @param list<string> $words
     */
    private static function longestWord(array $words): string
    {
        $best = $words[0] ?? '';
        foreach ($words as $w) {
            if (strlen($w) > strlen($best)) {
                $best = $w;
            }
        }

        return $best;
    }

    private static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);
        while ($b !== 0) {
            $t = $a % $b;
            $a = $b;
            $b = $t;
        }

        return $a;
    }

    private static function factorial(int $n): int
    {
        $v = 1;
        for ($i = 2; $i <= $n; $i++) {
            $v *= $i;
        }

        return $v;
    }

    private static function fib(int $n): int
    {
        if ($n <= 2) {
            return 1;
        }
        $a = 1;
        $b = 1;
        for ($i = 3; $i <= $n; $i++) {
            $c = $a + $b;
            $a = $b;
            $b = $c;
        }

        return $b;
    }

    private static function isPrime(int $n): bool
    {
        if ($n <= 1) {
            return false;
        }
        if ($n <= 3) {
            return true;
        }
        if ($n % 2 === 0 || $n % 3 === 0) {
            return false;
        }
        for ($i = 5; $i * $i <= $n; $i += 6) {
            if ($n % $i === 0 || $n % ($i + 2) === 0) {
                return false;
            }
        }

        return true;
    }

    private static function anagram(string $a, string $b): bool
    {
        $x = str_split(strtolower($a));
        $y = str_split(strtolower($b));
        sort($x);
        sort($y);

        return $x === $y;
    }
}
