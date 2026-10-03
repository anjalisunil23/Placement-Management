<?php

declare(strict_types=1);

namespace PMS\Utils;

use PMS\Services\CodingTestCaseChecker;

/**
 * User-facing execution error summaries (full stderr preserved separately).
 */
final class CodingExecutionErrorFormatter
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function enrich(array $result, string $language = '', string $stdin = '', string $source = ''): array
    {
        $lang = strtolower(trim($language));
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $status = (string) ($result['status'] ?? '');
        $stdinTrim = trim($stdin);
        $sourceText = (string) $source;

        if (CodingTestCaseChecker::executionSucceeded($result)) {
            unset($result['errorSummary'], $result['errorDetail']);
            if ($stderr === '') {
                unset($result['stderrTrace']);
            }

            return $result;
        }

        if ($lang === 'python' && $status === 'Runtime Error' && self::isPythonSyntaxFailure($stderr)) {
            $result['status'] = 'Compilation Error';
            $status = 'Compilation Error';
            $result['ok'] = false;
        }

        if (!self::isErrorStatus($status) && $stderr === '') {
            return $result;
        }

        $detail = self::primaryErrorLine($stderr);
        if ($detail === '' && $stderr !== '') {
            $detail = self::firstMeaningfulLine($stderr);
        }
        if (self::isGenericStatusLabel($detail)) {
            $detail = '';
        }

        $result['stderrTrace'] = $stderr;
        $result['errorDetail'] = $detail;
        $result['errorSummary'] = self::buildSummary($status, $detail, $stderr, $lang, $stdinTrim);

        if ($status === 'Runtime Error' && $lang === 'python') {
            $needsInputHelp = $detail === ''
                || self::isGenericStatusLabel($stderr)
                || str_contains((string) $result['errorSummary'], 'exited with an error');
            if ($needsInputHelp || preg_match('/EOFError:/i', $detail)) {
                $inferred = self::inferPythonInputHelp($sourceText, $stdin);
                if ($inferred !== null) {
                    $result['errorSummary'] = $inferred['summary'];
                    $result['errorDetail'] = $inferred['detail'];
                }
            }
        }

        return $result;
    }

    /**
     * @return array{summary:string,detail:string}|null
     */
    private static function inferPythonInputHelp(string $source, string $stdin): ?array
    {
        if (!preg_match_all('/\binput\s*\(/', $source, $m)) {
            return null;
        }
        $reads = count($m[0]);
        if ($reads < 1) {
            return null;
        }
        $lines = self::stdinLineCount($stdin);
        if ($lines >= $reads) {
            return null;
        }

        $detail = 'EOFError: EOF when reading a line';
        if ($lines === 0) {
            return [
                'summary' => sprintf(
                    'Custom input is empty, but your program reads %d line(s) of input. Enter each line in Custom Input (this is a runtime error while reading stdin).',
                    $reads
                ),
                'detail' => $detail,
            ];
        }

        $missing = $reads - $lines;

        return [
            'summary' => sprintf(
                'Your program reads %1$d line(s) of input, but Custom Input has only %2$d. Add %3$d more line(s) below (runtime error while reading stdin).',
                $reads,
                $lines,
                $missing
            ),
            'detail' => $detail,
        ];
    }

    private static function stdinLineCount(string $stdin): int
    {
        $norm = str_replace("\r\n", "\n", trim($stdin));
        if ($norm === '') {
            return 0;
        }

        return substr_count($norm, "\n") + 1;
    }

    private static function isGenericStatusLabel(string $text): bool
    {
        $t = trim($text);

        return in_array($t, [
            'Runtime Error',
            'Compilation Error',
            'Syntax Error',
            'Time Limit Exceeded',
            'Memory Limit Exceeded',
        ], true);
    }

    private static function isErrorStatus(string $status): bool
    {
        return in_array($status, [
            'Runtime Error',
            'Compilation Error',
            'Syntax Error',
            'Time Limit Exceeded',
            'Memory Limit Exceeded',
        ], true);
    }

    private static function isPythonSyntaxFailure(string $stderr): bool
    {
        if ($stderr === '') {
            return false;
        }

        return (bool) preg_match('/\b(SyntaxError|IndentationError|TabError)\b/', $stderr);
    }

    private static function primaryErrorLine(string $stderr): string
    {
        if ($stderr === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\n|\r/', $stderr) ?: [];
        $found = '';
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^(\w+(?:Error|Exception)):\s*(.+)$/u', $t, $m)) {
                $found = $m[1] . ': ' . $m[2];
            }
        }

        return $found;
    }

    private static function firstMeaningfulLine(string $text): string
    {
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t === '' || self::isGenericStatusLabel($t)) {
                continue;
            }

            return $t;
        }

        return '';
    }

    private static function buildSummary(string $status, string $detail, string $stderr, string $lang, string $stdinTrim = ''): string
    {
        if ($status === 'Time Limit Exceeded') {
            return 'The program exceeded the time limit.';
        }
        if ($status === 'Memory Limit Exceeded') {
            return 'The program exceeded the memory limit.';
        }
        if ($status === 'Compilation Error' || $status === 'Syntax Error') {
            if ($detail !== '') {
                return 'The program could not be compiled or parsed.' . ($lang === 'python' ? ' Fix the syntax error and try again.' : '');
            }

            return 'The program could not be compiled.';
        }

        if ($detail !== '') {
            $friendly = self::friendlyRuntimeMessage($detail);
            if ($friendly !== '') {
                return $friendly;
            }
        }

        if ($stderr !== '' && str_contains($stderr, 'Traceback')) {
            return 'The program stopped with a runtime error while processing input.';
        }

        if ($status === 'Runtime Error') {
            if ($stdinTrim === '' && $lang === 'python') {
                return 'Custom input is empty, but your program tried to read input (stdin). Enter values in the Custom Input box.';
            }
            if (self::isGenericStatusLabel($stderr)) {
                return 'The program exited with an error. Check your logic and input format.';
            }

            return 'The program stopped with a runtime error.';
        }

        return $detail !== '' ? $detail : $status;
    }

    private static function friendlyRuntimeMessage(string $detail): string
    {
        if (preg_match('/ValueError:\s*not enough values to unpack \(expected (\d+), got (\d+)\)/i', $detail, $m)) {
            $exp = (int) $m[1];
            $got = (int) $m[2];
            $expWord = $exp === 1 ? 'value' : 'values';
            $gotWord = $got === 1 ? 'only ' . $got : (string) $got;

            return "Program expected {$exp} input {$expWord}, but received {$gotWord}.";
        }
        if (preg_match('/ValueError:\s*invalid literal for int\(\)/i', $detail)) {
            return 'Program expected a numeric input value, but the input was not valid.';
        }
        if (preg_match('/EOFError:/i', $detail)) {
            return 'Program tried to read input, but no more input was available.';
        }
        if (preg_match('/ZeroDivisionError:/i', $detail)) {
            return 'Program attempted to divide by zero.';
        }
        if (preg_match('/IndexError:/i', $detail)) {
            return 'Program accessed an invalid index in a list or sequence.';
        }
        if (preg_match('/TypeError:/i', $detail)) {
            return 'Program used a value in an unsupported way (type error).';
        }
        if (preg_match('/NameError:/i', $detail)) {
            return 'Program referenced a variable or name that is not defined.';
        }

        return '';
    }
}
