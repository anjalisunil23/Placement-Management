<?php

declare(strict_types=1);

$root = __DIR__;

require $root . '/backend/bootstrap-aes.php';
pms_bootstrap_aes_callback($root);

use PMS\Middleware\AuthMiddleware;
use PMS\Services\AesLoginService;

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
        exit;
    }

    $payload = array_merge($_POST, $userData);
    $service = new AesLoginService();
    $user = $service->loginFromAesPayload($payload);

    $info = AuthMiddleware::userResponse($user);
    $target = (string) ($info['dashboard'] ?? '/dashboard.html');
    if ($target === '' || $target[0] !== '/') {
        $target = '/' . ltrim($target, '/');
    }

    if (($user['role'] ?? '') === 'alumni' && array_key_exists('isWorking', $info) && $info['isWorking'] === false) {
        $target = '/drives.html';
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Location: ' . $target);
    exit;
} catch (Throwable $e) {
    $fail($e->getMessage());
}
