<?php

declare(strict_types=1);

$__pmsBackendRoot = __DIR__;
$__pmsRuntimeFallback = $__pmsBackendRoot . '/utils/coding-runtime-fallback.php';
if (is_readable($__pmsRuntimeFallback)) {
    require_once $__pmsRuntimeFallback;
}
unset($__pmsBackendRoot, $__pmsRuntimeFallback);

/**
 * Load backend/utils explicitly (cPanel often misses new PSR-4 files until classmap refresh).
 */
function pms_load_backend_utils(string $backendDir): void
{
    $utilsDir = rtrim($backendDir, '/\\') . '/utils';
    if (!is_dir($utilsDir)) {
        return;
    }

    foreach ([
        'Response.php',
        'DocumentHelper.php',
        'Security.php',
        'Validator.php',
        'JwtHelper.php',
        'OwnershipHelper.php',
        'ApiExceptionHandler.php',
        'CodingStarterTemplates.php',
        'coding-runtime-fallback.php',
        'CodingExecutionDebug.php',
        'CodingDeployInfo.php',
        'CodingExecutionErrorFormatter.php',
        'CodingLanguage.php',
        'CodingInputValidator.php',
        'CodingExpectedOracle.php',
    ] as $utilFile) {
        $path = $utilsDir . '/' . $utilFile;
        if (is_readable($path)) {
            require_once $path;
        }
    }

    foreach (glob($utilsDir . '/*.php') ?: [] as $utilFile) {
        require_once $utilFile;
    }
}

/**
 * @param array<string, mixed> $context
 */
function pms_coding_exec_debug_log(string $phase, array $context): void
{
    if (!class_exists(\PMS\Utils\CodingExecutionDebug::class, false)) {
        return;
    }
    \PMS\Utils\CodingExecutionDebug::log($phase, $context);
}

/**
 * @param array<string, mixed> $trace
 * @return array<string, mixed>|null
 */
function pms_coding_exec_debug_trace(array $trace): ?array
{
    if (!class_exists(\PMS\Utils\CodingExecutionDebug::class, false)) {
        return null;
    }

    return \PMS\Utils\CodingExecutionDebug::traceOrNull($trace);
}

/**
 * Load backend service classes explicitly (Linux/cPanel safe when PSR-4 path case differs).
 */
function pms_load_backend_services(string $backendDir): void
{
    pms_load_backend_utils($backendDir);

    $servicesDir = rtrim($backendDir, '/\\') . '/services';
    if (!is_dir($servicesDir)) {
        return;
    }

    foreach (glob($servicesDir . '/*.php') ?: [] as $serviceFile) {
        require_once $serviceFile;
    }

    foreach (['PistonExecutionClient.php', 'CodingExecutionConfig.php'] as $serviceFile) {
        $class = 'PMS\\Services\\' . basename($serviceFile, '.php');
        if (class_exists($class, false)) {
            continue;
        }
        $path = $servicesDir . '/' . $serviceFile;
        if (is_readable($path)) {
            require_once $path;
        }
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
        'PMS\\Student\\StudentPracticeController' => 'student/StudentPracticeController.php',
        'PMS\\Student\\ResumeBuilderController' => 'student/ResumeBuilderController.php',
        'PMS\\Alumni\\AlumniController' => 'alumni/AlumniController.php',
        'PMS\\Company\\CompanyController' => 'company/CompanyController.php',
        'PMS\\Auth\\AuthController'     => 'auth/AuthController.php',
        'PMS\\Api\\PublicController'     => 'api/PublicController.php',
        'PMS\\Api\\JobFeedController'    => 'api/JobFeedController.php',
        'PMS\\Api\\InternalJobController' => 'api/InternalJobController.php',
        'PMS\\Api\\CertificationController' => 'api/CertificationController.php',
        'PMS\\Api\\TutorialController'   => 'api/TutorialController.php',
        'PMS\\Api\\AptitudeController'   => 'api/AptitudeController.php',
        'PMS\\Api\\CodingController'     => 'api/CodingController.php',
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
