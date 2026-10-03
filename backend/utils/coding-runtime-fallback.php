<?php

declare(strict_types=1);

/**
 * Safe fallbacks when cPanel has a partial deploy (new services, old bootstrap-services.php).
 */
if (!function_exists('pms_coding_exec_debug_log')) {
    /** @param array<string, mixed> $context */
    function pms_coding_exec_debug_log(string $phase, array $context): void
    {
    }
}

if (!function_exists('pms_coding_exec_debug_trace')) {
    /** @param array<string, mixed> $trace */
    function pms_coding_exec_debug_trace(array $trace): ?array
    {
        return null;
    }
}
