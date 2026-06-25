<?php

declare(strict_types=1);

/**
 * Load backend service classes explicitly (Linux/cPanel safe when PSR-4 path case differs).
 */
function pms_load_backend_services(string $backendDir): void
{
    $servicesDir = rtrim($backendDir, '/\\') . '/services';
    if (!is_dir($servicesDir)) {
        return;
    }

    foreach (glob($servicesDir . '/*.php') ?: [] as $serviceFile) {
        require_once $serviceFile;
    }

    foreach (['AesApiService.php', 'AesLoginService.php', 'OfficerDataService.php'] as $serviceFile) {
        $class = 'PMS\\Services\\' . basename($serviceFile, '.php');
        if (class_exists($class, false)) {
            continue;
        }
        $path = $servicesDir . '/' . $serviceFile;
        if (is_readable($path)) {
            require_once $path;
        }
    }
}
