<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Central API exception handling for consistent JSON error responses.
 */
final class ApiExceptionHandler
{
    public static function run(callable $callback): void
    {
        try {
            $callback();
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
            Response::error($e->getMessage(), $status);
        } catch (\Throwable $e) {
            $message = 'An unexpected server error occurred.';
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $message = $e->getMessage();
            }
            Response::error($message, 500);
        }
    }
}
