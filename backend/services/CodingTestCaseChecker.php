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
     * @param array<string, mixed> $execResult from CodeExecutionService
     */
    public static function verdictFromExecution(array $execResult, string $expected): string
    {
        $status = (string) ($execResult['status'] ?? '');
        if ($status === 'Compilation Error') {
            return 'Compilation Error';
        }
        if ($status === 'Time Limit Exceeded' || !empty($execResult['timedOut'])) {
            return 'Time Limit Exceeded';
        }
        if ($status === 'Memory Limit Exceeded') {
            return 'Memory Limit Exceeded';
        }
        if ($status === 'Output Limit Exceeded') {
            return 'Output Limit Exceeded';
        }
        if ($status !== 'OK' || empty($execResult['ok'])) {
            return 'Runtime Error';
        }
        if (!self::matches((string) ($execResult['stdout'] ?? ''), $expected)) {
            return 'Wrong Answer';
        }

        return 'Accepted';
    }
}
