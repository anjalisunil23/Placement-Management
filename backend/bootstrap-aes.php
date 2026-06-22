<?php

declare(strict_types=1);

/**
 * Bootstrap for AES callback scripts (Linux/cPanel case-safe autoload).
 */
function pms_bootstrap_aes_callback(string $root): void
{
    $autoload = $root . '/vendor/autoload.php';
    if (!is_readable($autoload)) {
        http_response_code(500);
        echo 'Server is missing PHP dependencies.';
        exit;
    }

    require_once $autoload;

    $backend = $root . '/backend';
    $utilsDir = $backend . '/utils';
    foreach (['Security.php'] as $utilFile) {
        $path = $utilsDir . '/' . $utilFile;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    $servicesDir = $backend . '/services';
    $serviceFile = $servicesDir . '/AesLoginService.php';
    if (is_readable($serviceFile)) {
        require_once $serviceFile;
    }

    if (!class_exists(\PMS\Services\AesLoginService::class, false)
        && !class_exists(\PMS\Services\AesLoginService::class)) {
        http_response_code(500);
        echo 'AES login service is missing on this server. Redeploy from latest main.';
        exit;
    }
}
