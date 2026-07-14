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
 * Load model classes explicitly (Linux/cPanel safe when optimized classmap is stale).
 */
function pms_load_backend_models(string $backendDir): void
{
    $modelsDir = rtrim($backendDir, '/\\') . '/models';
    if (!is_dir($modelsDir)) {
        return;
    }

    // BaseModel first so subclasses can resolve when classmap/PSR-4 is stale.
    $base = $modelsDir . '/BaseModel.php';
    if (is_readable($base)) {
        require_once $base;
    }

    foreach (glob($modelsDir . '/*.php') ?: [] as $modelFile) {
        require_once $modelFile;
    }

    // Pin critical models used by alumni / public dashboard even if glob order differs.
    foreach ([
        'SuccessStoryModel.php',
        'AlumniModel.php',
        'AlumniJobPostModel.php',
        'AlumniReferralModel.php',
        'UserModel.php',
    ] as $modelFile) {
        $class = 'PMS\\Models\\' . basename($modelFile, '.php');
        if (class_exists($class, false)) {
            continue;
        }
        $path = $modelsDir . '/' . $modelFile;
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
