<?php

declare(strict_types=1);

/**
 * AES SSO return URL — login.aesajce.in POSTs token + user info here after authentication.
 *
 * Flow:
 *   placements.amaljyothi.ac.in  →  Login with AES
 *   login.aesajce.in             →  Authenticate
 *   callback.php                 →  aes-complete.html  →  PlaceHub session
 */

$root = __DIR__;

require $root . '/backend/bootstrap-aes.php';
pms_bootstrap_aes_callback($root);

use PMS\Services\AesLoginService;
use PMS\Utils\Security;

Security::startSession();

$redirectLogin = static function (string $message = ''): void {
    $qs = $message !== '' ? ('?aes_error=' . rawurlencode($message)) : '';
    header('Location: /login.html' . $qs);
    exit;
};

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$payload = $method === 'POST' ? $_POST : $_GET;

if ($payload === []) {
    header('Location: /login.html');
    exit;
}

try {
    $service = new AesLoginService();
    $target = $service->handleCallback($payload);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: /aes-complete.html?next=' . rawurlencode($target));
} catch (\Throwable $e) {
    $redirectLogin($e->getMessage());
}

exit;
