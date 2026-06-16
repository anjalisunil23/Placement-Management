<?php

declare(strict_types=1);

namespace PMS\Utils;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT token generation and validation.
 */
final class JwtHelper
{
    private static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__) . '/config/app.php';
        }
        return $config['jwt'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        $cfg = self::config();
        $payload['iat'] = time();
        $payload['exp'] = time() + $cfg['expiry'];

        return JWT::encode($payload, $cfg['secret'], 'HS256');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $token): ?array
    {
        try {
            $cfg = self::config();
            $decoded = JWT::decode($token, new Key($cfg['secret'], 'HS256'));
            return (array) $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function extractFromHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
