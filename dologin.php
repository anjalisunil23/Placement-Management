<?php

declare(strict_types=1);

$root = __DIR__;

require $root . '/backend/bootstrap-aes.php';
pms_bootstrap_aes_callback($root);

use PMS\Middleware\AuthMiddleware;
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

    $info = AuthMiddleware::userResponse($user);
    $target = (string) ($info['dashboard'] ?? '/dashboard.html');
    if (($info['role'] ?? '') === 'admin') {
        $target = '/dashboard.html';
    } elseif (($info['role'] ?? '') === 'placement_officer') {
        $target = '/drives.html';
    }
    if ($target === '' || $target[0] !== '/') {
        $target = '/' . ltrim($target, '/');
    }

    if (($user['role'] ?? '') === 'alumni' && array_key_exists('isWorking', $info) && $info['isWorking'] === false) {
        $target = '/drives.html';
    }

    $next = ltrim($target, '/');
    if (!empty($_COOKIE['ph_auth_next'])) {
        $cookieNext = trim((string) $_COOKIE['ph_auth_next']);
        setcookie('ph_auth_next', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
        if ($cookieNext !== '' && !str_contains(strtolower($cookieNext), 'login.html')) {
            $next = ltrim($cookieNext, '/');
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Location: /aes-complete.html?next=' . rawurlencode($next));
    exit;
} catch (Throwable $e) {
    $fail($e->getMessage());
}
