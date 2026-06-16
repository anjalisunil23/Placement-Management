<?php

declare(strict_types=1);

/**
 * REST API entry point and router.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/app.php';

// CORS — allow configured origins (supports localhost and 127.0.0.1 in dev)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['cors']['allowed_origins'] ?? ['http://localhost:8080', 'http://127.0.0.1:8080'];
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

use PMS\Admin\AdminController;
use PMS\Alumni\AlumniController;
use PMS\Api\PublicController;
use PMS\Auth\AuthController;
use PMS\Company\CompanyController;
use PMS\Officer\OfficerController;
use PMS\Staff\StaffController;
use PMS\Student\StudentController;
use PMS\Utils\Response;

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Support /backend/api/* (PHP dev server) and /api/* (Apache/nginx)
if (preg_match('#^/backend/api(?:/index\.php)?(.*)$#', $uri, $matches)) {
    $uri = $matches[1] ?: '/';
} elseif (preg_match('#^/api(.*)$#', $uri, $matches)) {
    $uri = $matches[1] ?: '/';
}

$uri    = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

/** Simple route matcher */
$routes = [
    // Auth
    ['POST', '/auth/register', [AuthController::class, 'register']],
    ['POST', '/auth/login',    [AuthController::class, 'login']],
    ['POST', '/auth/logout',   [AuthController::class, 'logout']],
    ['GET',  '/auth/me',       [AuthController::class, 'me']],

    // Admin
    ['GET',    '/admin/dashboard',              [AdminController::class, 'dashboard']],
    ['GET',    '/admin/users',                  [AdminController::class, 'listUsers']],
    ['POST',   '/admin/users',                  [AdminController::class, 'createUser']],
    ['PUT',    '/admin/users/{id}',             [AdminController::class, 'updateUser']],
    ['DELETE', '/admin/users/{id}',             [AdminController::class, 'deleteUser']],
    ['POST',   '/admin/users/{id}/block',       [AdminController::class, 'blockUser']],
    ['POST',   '/admin/users/{id}/unblock',     [AdminController::class, 'unblockUser']],
    ['POST',   '/admin/users/{id}/approve',     [AdminController::class, 'approveUser']],
    ['GET',    '/admin/placement-officers',     [AdminController::class, 'listPlacementOfficers']],
    ['GET',    '/admin/departments',            [AdminController::class, 'listDepartments']],
    ['POST',   '/admin/departments',            [AdminController::class, 'createDepartment']],
    ['PUT',    '/admin/departments/{id}',       [AdminController::class, 'updateDepartment']],
    ['DELETE', '/admin/departments/{id}',       [AdminController::class, 'deleteDepartment']],
    ['GET',    '/admin/rules',                  [AdminController::class, 'listRules']],
    ['POST',   '/admin/rules',                  [AdminController::class, 'createRule']],
    ['POST',   '/admin/students/{id}/verify-resume', [AdminController::class, 'verifyResume']],
    ['POST',   '/admin/students/{id}/blacklist',     [AdminController::class, 'blacklistStudent']],
    ['POST',   '/admin/students/{id}/unblacklist',   [AdminController::class, 'unblacklistStudent']],
    ['GET',    '/admin/students',                    [AdminController::class, 'listStudents']],
    ['GET',    '/admin/companies',                   [AdminController::class, 'listCompanies']],
    ['POST',   '/admin/companies',                   [AdminController::class, 'createCompany']],
    ['PUT',    '/admin/companies/{id}',              [AdminController::class, 'updateCompany']],
    ['DELETE', '/admin/companies/{id}',              [AdminController::class, 'deleteCompany']],
    ['POST',   '/admin/reports/{type}',              [AdminController::class, 'generateReport']],
    ['GET',    '/admin/reports/download/{filename}', [AdminController::class, 'downloadReport']],

    // Student
    ['GET',  '/student/profile',           [StudentController::class, 'getProfile']],
    ['PUT',  '/student/profile',           [StudentController::class, 'updateProfile']],
    ['POST', '/student/policy/accept',     [StudentController::class, 'acceptPolicy']],
    ['POST', '/student/resume',            [StudentController::class, 'uploadResume']],
    ['GET',  '/student/jobs',              [StudentController::class, 'listJobs']],
    ['GET',  '/student/drives',            [StudentController::class, 'listDrives']],
    ['POST', '/student/apply',             [StudentController::class, 'apply']],
    ['GET',  '/student/applications',      [StudentController::class, 'myApplications']],
    ['GET',  '/student/notifications',     [StudentController::class, 'notifications']],
    ['GET',  '/student/placement-history', [StudentController::class, 'placementHistory']],
    ['POST', '/student/signed-report',     [StudentController::class, 'uploadSignedReport']],
    ['POST', '/student/applications/{id}/withdraw', [StudentController::class, 'withdrawApplication']],
    ['POST', '/student/notifications/{id}/read',    [StudentController::class, 'markNotificationRead']],

    // Company
    ['GET',  '/company/profile',                  [CompanyController::class, 'profile']],
    ['PUT',  '/company/profile',                  [CompanyController::class, 'updateProfile']],
    ['POST', '/company/jobs',                     [CompanyController::class, 'createJob']],
    ['GET',  '/company/jobs',                     [CompanyController::class, 'listJobs']],
    ['GET',  '/company/applications',             [CompanyController::class, 'applications']],
    ['GET',  '/company/applications/filter',      [CompanyController::class, 'filterApplicants']],
    ['POST', '/company/applications/{id}/review',   [CompanyController::class, 'startReview']],
    ['POST', '/company/applications/{id}/shortlist', [CompanyController::class, 'shortlist']],
    ['POST', '/company/applications/{id}/result', [CompanyController::class, 'updateResult']],

    // Staff
    ['GET',  '/staff/recommendations', [StaffController::class, 'listRecommendations']],
    ['POST', '/staff/recommendations', [StaffController::class, 'createRecommendation']],

    // Alumni
    ['GET',  '/alumni/profile',     [AlumniController::class, 'getProfile']],
    ['PUT',  '/alumni/profile',     [AlumniController::class, 'updateProfile']],
    ['GET',  '/alumni/jobs',        [AlumniController::class, 'listJobs']],
    ['GET',  '/alumni/referrals',   [AlumniController::class, 'listReferrals']],
    ['POST', '/alumni/jobs/refer',  [AlumniController::class, 'referJob']],
    ['GET',  '/alumni/drives',      [AlumniController::class, 'listDrives']],
    ['POST', '/alumni/apply',       [AlumniController::class, 'apply']],

    // Placement Officer
    ['GET',  '/officer/profile',                   [OfficerController::class, 'profile']],
    ['POST', '/officer/drives',                    [OfficerController::class, 'createDrive']],
    ['GET',  '/officer/drives',                    [OfficerController::class, 'listDrives']],
    ['POST', '/officer/drives/{id}/attendance',    [OfficerController::class, 'markAttendance']],
    ['POST', '/officer/applications/{id}/approve', [OfficerController::class, 'approveApplication']],
    ['GET',  '/officer/applications/pending',      [OfficerController::class, 'pendingApplications']],
    ['GET',  '/officer/students/pending',          [OfficerController::class, 'pendingStudents']],
    ['POST', '/officer/users/{id}/approve',        [OfficerController::class, 'approveStudent']],

    // Public & Analytics
    ['GET', '/public/placement-stats', [PublicController::class, 'placementStats']],
    ['GET', '/analytics/dashboard',    [PublicController::class, 'analyticsDashboard']],
];

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method) {
        continue;
    }

    $regex = preg_replace('/\{([a-zA-Z]+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $uri, $matches)) {
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        [$class, $action] = $handler;
        $controller = new $class();

        if (empty($params)) {
            $controller->$action();
        } else {
            $controller->$action(...array_values($params));
        }
        exit;
    }
}

Response::notFound('API endpoint not found.');
