<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Optional execution tracing (enable with CODING_EXEC_DEBUG=true).
 */
final class CodingExecutionDebug
{
    public static function enabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['CODING_EXEC_DEBUG'] ?? 'false')));

        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $phase, array $context): void
    {
        if (!self::enabled()) {
            return;
        }
        $line = '[CODING_EXEC] ' . $phase . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log($line);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function traceOrNull(array $trace): ?array
    {
        return self::enabled() ? $trace : null;
    }
}
