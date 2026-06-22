<?php

declare(strict_types=1);

/**
 * AES SSO callback — login.aesajce.in posts here after successful institute login.
 * Configure this URL with the AES team when registering the site API key.
 */

$root = __DIR__;

$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    http_response_code(500);
    echo 'Server is missing PHP dependencies.';
    exit;
}

require_once $autoload;

$utilsDir = $root . '/backend/utils';
foreach (['Security.php'] as $utilFile) {
    $path = $utilsDir . '/' . $utilFile;
    if (is_readable($path)) {
        require_once $path;
    }
}

use PMS\Services\AesLoginService;
use PMS\Utils\Security;

Security::startSession();

$redirectLogin = static function (string $message = ''): void {
    $qs = $message !== '' ? ('?aes_error=' . rawurlencode($message) . '&login=1') : '?login=1';
    header('Location: /public-stats.html' . $qs);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || $_POST === []) {
    header('Location: /');
    exit;
}

try {
    $target = (new AesLoginService())->handleCallback($_POST);
    header('Location: ' . $target);
} catch (\Throwable $e) {
    $redirectLogin($e->getMessage());
}

exit;
