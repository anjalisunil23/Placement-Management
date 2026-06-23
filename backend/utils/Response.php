<?php

declare(strict_types=1);

namespace PMS\Utils;

/**
 * Standardized JSON API responses.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($data, $flags);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $status = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, ?array $errors = null): void
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 404);
    }
}
