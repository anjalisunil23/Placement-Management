<?php

declare(strict_types=1);

$root = __DIR__;

require $root . '/backend/bootstrap-aes.php';
pms_bootstrap_aes_callback($root);

use PMS\Services\AesLoginService;
use PMS\Utils\Security;

Security::startSession();

$fail = static function (string $message = ''): void {
    $qs = $message !== '' ? ('?aes_error=' . rawurlencode($message)) : '';
    header('Location: /public-stats.html' . $qs);
    exit;
};

$checksum = $_POST['checksum'] ?? $_REQUEST['checksum'] ?? null;
if ($checksum === null || $checksum === '') {
    $fail('AES login was incomplete.');
}

try {
    $_encKey  = substr((string) $checksum, -40);
    $_encData = substr((string) $checksum, 0, -40);
    $_encIV   = substr(sha1($_encKey), -16);
    $decryptedJson = openssl_decrypt(base64_decode($_encData), 'AES-256-CBC', $_encKey, 0, $_encIV);
    $userData = json_decode(is_string($decryptedJson) ? $decryptedJson : '', true);

    if (!is_array($userData)) {
        $fail('Could not read AES login data.');
    }

    if (!empty($_GET['debug'])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "POST:\n";
        print_r($_POST);
        echo "\nuserData:\n";
        print_r($userData);
        $payload = array_merge($_POST, $userData);
        $service = new AesLoginService();
        echo "\naesDetails:\n";
        print_r($service->collectAesDetails($payload));
        echo "\nmapped:\n";
        $debug = $service->debugAesPayload($payload);
        print_r($debug['mapped']);
        echo "\nprofileScan:\n";
        print_r($debug['profileScan']);
        exit;
    }

    $payload = array_merge($_POST, $userData);
    $service = new AesLoginService();
    $user = $service->loginFromAesPayload($payload);

    // Fast redirect: do not run full AuthMiddleware::userResponse (profile joins + AES) here.
    $config = require __DIR__ . '/backend/config/app.php';
    $role = \PMS\Middleware\AuthMiddleware::resolvedRole($user);
    $target = (string) ($config['role_dashboards'][$role] ?? '/dashboard.html');
    if ($target === '' || $target[0] !== '/') {
        $target = '/' . ltrim($target, '/');
    }

    $next = ltrim($target, '/');
    if (!empty($_COOKIE['ph_auth_next'])) {
        $cookieNext = trim((string) $_COOKIE['ph_auth_next']);
        setcookie('ph_auth_next', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
        if ($cookieNext !== '' && !str_contains(strtolower($cookieNext), 'login.html')) {
            $next = ltrim($cookieNext, '/');
        }
    }

    // Only allow same-site app pages (no open redirect).
    $next = preg_replace('/[#?].*$/', '', $next) ?? '';
    $next = ltrim(str_replace('\\', '/', $next), '/');
    if ($next === '' || str_contains($next, '..') || str_contains($next, '://')) {
        $next = ltrim($target, '/');
    }
    if (!preg_match('/^[A-Za-z0-9._\/-]+\.html?$/i', $next) && !preg_match('/^[A-Za-z0-9._\/-]+$/i', $next)) {
        $next = 'dashboard.html';
    }

    // First /auth/me after AES login stays local-only (no placement API round-trips).
    $_SESSION['ph_auth_fast_boot'] = 1;

    // Tell the destination page to clear stale localStorage and hard-bootstrap from the cookie session.
    setcookie('ph_aes_login', '1', [
        'expires'  => time() + 180,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Go straight to the portal — no intermediate "Signing you in" page.
    header('Location: /' . $next);
    exit;
} catch (Throwable $e) {
    $fail($e->getMessage());
}
