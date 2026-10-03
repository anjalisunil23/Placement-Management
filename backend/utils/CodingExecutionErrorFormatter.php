<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * User-facing execution error summaries (full stderr preserved separately).
 */
final class CodingExecutionErrorFormatter
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function enrich(array $result, string $language = ''): array
    {
        $lang = strtolower(trim($language));
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $status = (string) ($result['status'] ?? '');

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
            $detail = self::firstNonEmptyLine($stderr);
        }

        $result['stderrTrace'] = $stderr;
        $result['errorDetail'] = $detail;
        $result['errorSummary'] = self::buildSummary($status, $detail, $stderr, $lang);

        return $result;
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

    private static function firstNonEmptyLine(string $text): string
    {
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }

    private static function buildSummary(string $status, string $detail, string $stderr, string $lang): string
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
