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
        echo "\nstaff_rank:\n";
        print_r(\PMS\Services\StaffRank::pickAesStaffRank(array_merge($payload, $service->collectAesDetails($payload))));
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
    $rawNextHint = trim((string) ($_POST['ph_auth_next'] ?? ''));
    if ($rawNextHint === '' && !empty($_COOKIE['ph_auth_next'])) {
        $rawNextHint = trim((string) $_COOKIE['ph_auth_next']);
    }
    if ($rawNextHint !== '') {
        setcookie('ph_auth_next', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
        if (!str_contains(strtolower($rawNextHint), 'login.html')) {
            $next = ltrim($rawNextHint, '/');
        }
    }

    // Only allow same-site app pages (no open redirect). Preserve ?query for deep links (e.g. shared test URLs).
    $rawNext = ltrim(str_replace('\\', '/', $next), '/');
    $hash = '';
    if (($hashPos = strpos($rawNext, '#')) !== false) {
        $hash = substr($rawNext, $hashPos);
        $rawNext = substr($rawNext, 0, $hashPos);
    }
    $query = '';
    if (($qPos = strpos($rawNext, '?')) !== false) {
        $query = substr($rawNext, $qPos);
        $rawNext = substr($rawNext, 0, $qPos);
    }
    $next = $rawNext;
    if ($next === '' || str_contains($next, '..') || str_contains($next, '://')) {
        $next = ltrim($target, '/');
        $query = '';
        $hash = '';
    }
    if (!preg_match('/^[A-Za-z0-9._\/-]+\.html?$/i', $next) && !preg_match('/^[A-Za-z0-9._\/-]+$/i', $next)) {
        $next = 'dashboard.html';
        $query = '';
        $hash = '';
    }
    if ($query !== '' && !preg_match('/^\?[A-Za-z0-9._%-=&]+$/', $query)) {
        $query = '';
    }
    if ($hash !== '' && !preg_match('/^#[A-Za-z0-9._%-]+$/', $hash)) {
        $hash = '';
    }
    if ($query !== '' && str_contains($query, 'test=') && str_ends_with($next, 'mock-aptitude.html') && $hash === '') {
        $hash = '#take';
    }
    $next = $next . $query . $hash;

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
