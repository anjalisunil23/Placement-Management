<?php

declare(strict_types=1);

namespace PMS\Services;

final class CodingTestCaseChecker
{
    public static function normalize(string $output): string
    {
        $text = str_replace("\r\n", "\n", $output);
        $text = rtrim($text, "\n");
        $lines = explode("\n", $text);
        $lines = array_map(static fn (string $line): string => rtrim($line, " \t"), $lines);

        return implode("\n", $lines);
    }

    public static function matches(string $actualStdout, string $expected): bool
    {
        return self::normalize($actualStdout) === self::normalize($expected);
    }

    /**
     * Whether the process finished normally (exit 0 / status OK). Never infer failure from empty stdout.
     *
     * @param array<string, mixed> $execResult
     */
    public static function executionSucceeded(array $execResult): bool
    {
        if (!empty($execResult['timedOut'])) {
            return false;
        }
        $status = (string) ($execResult['status'] ?? '');
        if (in_array($status, [
            'Runtime Error',
            'Compilation Error',
            'Syntax Error',
            'Time Limit Exceeded',
            'Memory Limit Exceeded',
        ], true)) {
            return false;
        }
        if ($status === 'OK') {
            return true;
        }
        if (array_key_exists('exit_code', $execResult)) {
            return (int) $execResult['exit_code'] === 0;
        }

        return self::coerceOkFlag($execResult['ok'] ?? false);
    }

    public static function coerceOkFlag(mixed $ok): bool
    {
        if ($ok === true || $ok === 1) {
            return true;
        }
        if (is_string($ok)) {
            $t = strtolower(trim($ok));

            return $t === 'true' || $t === '1';
        }

        return false;
    }

    /**
     * @param array<string, mixed> $execResult from CodeExecutionService
     */
    public static function verdictFromExecution(array $execResult, string $expected): string
    {
        $status = (string) ($execResult['status'] ?? '');
        if ($status === 'Compilation Error' || $status === 'Syntax Error') {
            return 'Compilation Error';
        }
        if ($status === 'Time Limit Exceeded' || !empty($execResult['timedOut'])) {
            return 'Time Limit Exceeded';
        }
        if ($status === 'Memory Limit Exceeded') {
            return 'Memory Limit Exceeded';
        }
        if (!self::executionSucceeded($execResult)) {
            return 'Runtime Error';
        }
        if (!self::matches((string) ($execResult['stdout'] ?? ''), $expected)) {
            return 'Wrong Answer';
        }

        return 'Accepted';
    }
}
