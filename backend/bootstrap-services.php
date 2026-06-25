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

    foreach (['AesApiService.php', 'AesLoginService.php', 'OfficerDataService.php', 'StaffContext.php', 'StaffService.php', 'StaffDataService.php'] as $serviceFile) {
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

/**
 * Load module controllers explicitly (Linux/cPanel safe when PSR-4 path case differs).
 */
function pms_load_module_controllers(string $backendDir): void
{
    $backendDir = rtrim($backendDir, '/\\');
    $modules = [
        'PMS\\Staff\\StaffController'   => 'staff/StaffController.php',
        'PMS\\Officer\\OfficerController' => 'officer/OfficerController.php',
        'PMS\\Admin\\AdminController'   => 'admin/AdminController.php',
        'PMS\\Student\\StudentController' => 'student/StudentController.php',
        'PMS\\Alumni\\AlumniController' => 'alumni/AlumniController.php',
        'PMS\\Company\\CompanyController' => 'company/CompanyController.php',
        'PMS\\Auth\\AuthController'     => 'auth/AuthController.php',
        'PMS\\Api\\PublicController'     => 'api/PublicController.php',
    ];

    foreach ($modules as $class => $rel) {
        if (class_exists($class, false) || class_exists($class)) {
            continue;
        }
        $path = $backendDir . '/' . $rel;
        if (is_readable($path)) {
            require_once $path;
        }
    }
}
