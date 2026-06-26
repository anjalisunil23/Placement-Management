<?php

declare(strict_types=1);

/**
 * AES login credentials (login.aesajce.in).
 * Reads .env via Dotenv, direct file parse, and getenv — with production host fallback.
 */

$rootPath = dirname(__DIR__, 2);

if (file_exists($rootPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable($rootPath);
    $dotenv->safeLoad();
}
if (file_exists($rootPath . '/.env.local')) {
    $dotenv = Dotenv\Dotenv::createMutable($rootPath, '.env.local');
    $dotenv->safeLoad();
}

$readEnv = static function (string $key) use ($rootPath): string {
    $candidates = [];
    if (isset($_ENV[$key])) {
        $candidates[] = $_ENV[$key];
    }
    if (isset($_SERVER[$key])) {
        $candidates[] = $_SERVER[$key];
    }
    $fromGetenv = getenv($key);
    if ($fromGetenv !== false) {
        $candidates[] = $fromGetenv;
    }

    foreach ($candidates as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value, " \t\"'");
        }
    }

    $envPath = $rootPath . '/.env';
    if (is_readable($envPath)) {
        $content = file_get_contents($envPath);
        if (is_string($content) && preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*)$/m', $content, $matches)) {
            $parsed = trim($matches[1]);
            $parsed = trim($parsed, "\"'");
            if ($parsed !== '' && !str_starts_with($parsed, 'your_') && !str_starts_with($parsed, 'replace-')) {
                return $parsed;
            }
        }
    }

    return '';
};

$authKey = $readEnv('AES_AUTH_KEY');
$refHost = $readEnv('AES_REF_HOST');

if ($refHost === '') {
    $appUrl = $readEnv('APP_URL');
    if ($appUrl !== '') {
        $refHost = (string) parse_url($appUrl, PHP_URL_HOST);
    }
}
if ($refHost === '') {
    $refHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// Public portal already ships this key in HTML/JS; use when production .env was never patched.
if ($authKey === '' && $refHost === 'placements.amaljyothi.ac.in') {
    $authKey = '17f7e7bf8d3ecf54364107279801e88ee6509a09';
}

return [
    'auth_key' => $authKey,
    'ref_host' => $refHost,
    'api_url' => $readEnv('AES_API_URL') ?: 'https://api.aesajce.in/',
    'api_origin' => $readEnv('AES_API_ORIGIN') ?: 'https://www.aesajce.in',
    'api_referer' => $readEnv('AES_API_REFERER') ?: 'https://www.aesajce.in/',
    'ssl_verify' => filter_var($readEnv('AES_SSL_VERIFY') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];
