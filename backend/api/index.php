<?php

declare(strict_types=1);

/**
 * REST API entry point and router.
 * inline API router — no ApiExceptionHandler dependency (Linux/cPanel safe).
 */

$root = dirname(__DIR__, 2);

$emitJsonError = static function (string $message, int $status = 500): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(
        ['success' => false, 'message' => $message, 'data' => null],
        JSON_UNESCAPED_UNICODE
    );
    exit;
};

register_shutdown_function(static function () use ($emitJsonError): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    $emitJsonError('Server error: ' . ($err['message'] ?? 'fatal error'));
});

$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    $emitJsonError('Server is missing PHP dependencies. Run composer install in the site root.');
}

require_once $autoload;

// Linux/cPanel: PSR-4 may not resolve backend/utils (lowercase) — load utils explicitly.
$utilsDir = dirname(__DIR__) . '/utils';
foreach (['Response.php', 'DocumentHelper.php', 'Security.php', 'Validator.php', 'JwtHelper.php', 'OwnershipHelper.php', 'ApiExceptionHandler.php'] as $utilFile) {
    $path = $utilsDir . '/' . $utilFile;
    if (is_readable($path)) {
        require_once $path;
    }
}

// Linux/cPanel: load all services explicitly when PSR-4 case differs from backend/services/.
$servicesDir = dirname(__DIR__) . '/services';
foreach (glob($servicesDir . '/*.php') ?: [] as $serviceFile) {
    require_once $serviceFile;
}
if (!class_exists(\PMS\Utils\Response::class, false)) {
    $emitJsonError('Server autoload error: Response utility missing. Redeploy from latest main.');
}

try {
    $config = require dirname(__DIR__) . '/config/app.php';
} catch (\Throwable $e) {
    $emitJsonError('Configuration failed: ' . $e->getMessage());
}

// Start session before any output headers (login stores session user).
\PMS\Utils\Security::startSession();

// CORS — allow configured origins (supports localhost and 127.0.0.1 in dev)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['cors']['allowed_origins'] ?? ['http://localhost:8080', 'http://127.0.0.1:8080'];
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '' && !empty($config['url']) && rtrim($origin, '/') === rtrim((string) $config['url'], '/')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} elseif ($origin !== '' && preg_match('#^https?://(192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)(:\d+)?$#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

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
    ['POST', '/auth/change-password', [AuthController::class, 'changePassword']],

    // Admin
    ['GET',    '/admin/dashboard',              [AdminController::class, 'dashboard']],
    ['GET',    '/admin/users',                  [AdminController::class, 'listUsers']],
    ['POST',   '/admin/users',                  [AdminController::class, 'createUser']],
    ['PUT',    '/admin/users/{id}',             [AdminController::class, 'updateUser']],
    ['DELETE', '/admin/users/{id}',             [AdminController::class, 'deleteUser']],
    ['POST',   '/admin/users/{id}/block',       [AdminController::class, 'blockUser']],
    ['POST',   '/admin/users/{id}/unblock',     [AdminController::class, 'unblockUser']],
    ['POST',   '/admin/users/{id}/approve',     [AdminController::class, 'approveUser']],
    ['POST',   '/admin/users/{id}/promote-to-officer', [AdminController::class, 'promoteStaffToPlacementOfficer']],
    ['POST',   '/admin/users/{id}/demote-from-officer', [AdminController::class, 'demotePlacementOfficer']],
    ['GET',    '/admin/placement-officers',     [AdminController::class, 'listPlacementOfficers']],
    ['GET',    '/admin/departments',            [AdminController::class, 'listDepartments']],
    ['POST',   '/admin/departments',            [AdminController::class, 'createDepartment']],
    ['PUT',    '/admin/departments/{id}',       [AdminController::class, 'updateDepartment']],
    ['PUT',    '/admin/departments/{id}/placement-officer', [AdminController::class, 'assignPlacementOfficer']],
    ['DELETE', '/admin/departments/{id}/placement-officer', [AdminController::class, 'unassignPlacementOfficer']],
    ['DELETE', '/admin/departments/{id}',       [AdminController::class, 'deleteDepartment']],
    ['GET',    '/admin/rules',                  [AdminController::class, 'listRules']],
    ['POST',   '/admin/rules',                  [AdminController::class, 'createRule']],
    ['GET',    '/admin/rules/active',           [AdminController::class, 'getActiveRule']],
    ['PUT',    '/admin/rules/active',           [AdminController::class, 'saveActiveRule']],
    // Admin drives & application pipeline (Mongo-backed)
    ['GET',    '/admin/drives',                 [AdminController::class, 'listDrives']],
    ['POST',   '/admin/drives',                 [AdminController::class, 'createDrive']],
    ['PUT',    '/admin/drives/{id}',            [AdminController::class, 'updateDrive']],
    ['DELETE', '/admin/drives/{id}',            [AdminController::class, 'deleteDrive']],
    ['GET',    '/admin/applications',           [AdminController::class, 'listApplications']],
    ['POST',   '/admin/applications/{id}/transition', [AdminController::class, 'transitionApplication']],
    ['POST',   '/admin/students/{id}/verify-resume', [AdminController::class, 'verifyResume']],
    ['POST',   '/admin/students/{id}/blacklist',     [AdminController::class, 'blacklistStudent']],
    ['POST',   '/admin/students/{id}/unblacklist',   [AdminController::class, 'unblacklistStudent']],
    ['GET',    '/admin/students',                    [AdminController::class, 'listStudents']],
    ['GET',    '/admin/blacklist',                   [AdminController::class, 'listBlacklist']],
    ['GET',    '/admin/results',                     [AdminController::class, 'listResults']],
    ['POST',   '/admin/results',                     [AdminController::class, 'upsertResult']],
    ['DELETE', '/admin/results/{id}',                [AdminController::class, 'deleteResult']],
    ['GET',    '/admin/companies',                   [AdminController::class, 'listCompanies']],
    ['POST',   '/admin/companies',                   [AdminController::class, 'createCompany']],
    ['PUT',    '/admin/companies/{id}',              [AdminController::class, 'updateCompany']],
    ['DELETE', '/admin/companies/{id}',              [AdminController::class, 'deleteCompany']],
    ['POST',   '/admin/companies/register',         [AdminController::class, 'registerCompany']],
    ['GET',    '/admin/recommendations',            [AdminController::class, 'listRecommendations']],
    ['PUT',    '/admin/recommendations/{id}/status', [AdminController::class, 'updateRecommendationStatus']],
    ['PUT',    '/admin/recommendations/{id}',       [AdminController::class, 'updateRecommendation']],
    ['DELETE', '/admin/recommendations/{id}',       [AdminController::class, 'deleteRecommendation']],
    ['GET',    '/admin/alumni-referrals',           [AdminController::class, 'listAlumniReferrals']],
    ['PUT',    '/admin/alumni-referrals/{id}/status', [AdminController::class, 'updateAlumniReferralStatus']],
    ['PUT',    '/admin/alumni-referrals/{id}',      [AdminController::class, 'updateAlumniReferral']],
    ['DELETE', '/admin/alumni-referrals/{id}',      [AdminController::class, 'deleteAlumniReferral']],
    ['GET',    '/admin/resumes/pending',            [AdminController::class, 'listPendingResumes']],
    ['GET',    '/admin/resumes',                    [AdminController::class, 'listResumes']],
    ['GET',    '/admin/applications/{id}/resume',   [AdminController::class, 'downloadApplicationResume']],
    ['GET',    '/admin/students/{id}/resume',       [AdminController::class, 'downloadStudentResume']],
    ['POST',   '/admin/blacklist',                  [AdminController::class, 'addBlacklist']],
    ['DELETE', '/admin/blacklist/{id}',             [AdminController::class, 'removeBlacklistEntry']],
    ['GET',    '/admin/reports',                    [AdminController::class, 'listReports']],
    ['POST',   '/admin/reports/{type}',              [AdminController::class, 'generateReport']],
    ['GET',    '/admin/reports/download/{filename}', [AdminController::class, 'downloadReport']],
    ['GET',    '/admin/settings/system',             [AdminController::class, 'getSystemSettings']],
    ['PUT',    '/admin/settings/system',             [AdminController::class, 'updateSystemSettings']],
    ['GET',    '/admin/settings/public',             [AdminController::class, 'getPublicPageSettings']],
    ['PUT',    '/admin/settings/public',             [AdminController::class, 'updatePublicPageSettings']],
    ['GET',    '/admin/placement-news',              [AdminController::class, 'listPlacementNews']],
    ['POST',   '/admin/placement-news',              [AdminController::class, 'createPlacementNews']],
    ['PUT',    '/admin/placement-news/{id}',         [AdminController::class, 'updatePlacementNews']],
    ['DELETE', '/admin/placement-news/{id}',         [AdminController::class, 'deletePlacementNews']],
    ['GET',    '/admin/notifications',               [AdminController::class, 'notifications']],
    ['POST',   '/admin/notifications/read-all',      [AdminController::class, 'markAllNotificationsRead']],
    ['POST',   '/admin/notifications/{id}/read',     [AdminController::class, 'markNotificationRead']],
    ['POST',   '/admin/broadcast',                   [AdminController::class, 'broadcast']],
    ['GET',    '/admin/broadcasts',                  [AdminController::class, 'listBroadcasts']],
    ['GET',    '/admin/tracking',                    [AdminController::class, 'placementTracking']],
    ['GET',    '/admin/analytics/extended',          [AdminController::class, 'extendedAnalytics']],
    ['GET',    '/admin/placement-console',           [AdminController::class, 'placementConsole']],
    ['GET',    '/admin/recruiting',                  [AdminController::class, 'recruitingOverview']],

    // Student
    ['GET',  '/student/dashboard',         [StudentController::class, 'dashboard']],
    ['GET',  '/student/profile',           [StudentController::class, 'getProfile']],
    ['PUT',  '/student/profile',           [StudentController::class, 'updateProfile']],
    ['POST', '/student/policy/accept',     [StudentController::class, 'acceptPolicy']],
    ['POST', '/student/resume',            [StudentController::class, 'uploadResume']],
    ['POST', '/student/photo',            [StudentController::class, 'uploadPhoto']],
    ['POST', '/student/photo/remove',     [StudentController::class, 'removePhoto']],
    ['GET',  '/student/resumes',          [StudentController::class, 'listResumes']],
    ['POST', '/student/resumes/upload',   [StudentController::class, 'uploadResumeToLibrary']],
    ['GET',  '/student/resumes/{id}/view',[StudentController::class, 'viewResume']],
    ['POST', '/student/resumes/{id}/delete',[StudentController::class, 'deleteResumeFromLibrary']],
    ['POST', '/student/resumes/{id}/default',[StudentController::class, 'setDefaultResume']],
    ['GET',  '/student/jobs',              [StudentController::class, 'listJobs']],
    ['GET',  '/student/drives',            [StudentController::class, 'listDrives']],
    ['GET',  '/student/open-drives',       [StudentController::class, 'openDrives']],
    ['GET',  '/student/drives/{id}',       [StudentController::class, 'driveDetails']],
    ['POST', '/student/apply',             [StudentController::class, 'apply']],
    ['GET',  '/student/applications',      [StudentController::class, 'myApplications']],
    ['GET',  '/student/results',         [StudentController::class, 'myResults']],
    ['GET',  '/student/notifications',     [StudentController::class, 'notifications']],
    ['POST', '/student/notifications/read-all', [StudentController::class, 'markAllNotificationsRead']],
    ['GET',  '/student/placement-history', [StudentController::class, 'placementHistory']],
    ['POST', '/student/signed-report',     [StudentController::class, 'uploadSignedReport']],
    ['POST', '/student/applications/{id}/withdraw', [StudentController::class, 'withdrawApplication']],
    ['POST', '/student/notifications/{id}/read',    [StudentController::class, 'markNotificationRead']],

    // Company
    ['GET',  '/company/profile',                  [CompanyController::class, 'profile']],
    ['PUT',  '/company/profile',                  [CompanyController::class, 'updateProfile']],
    ['GET',  '/company/dashboard',                [CompanyController::class, 'dashboard']],
    ['GET',  '/company/drives',                   [CompanyController::class, 'listDrives']],
    ['PUT',  '/company/drives/{id}/eligibility',  [CompanyController::class, 'updateDriveEligibility']],
    ['GET',  '/company/eligibility/preview',      [CompanyController::class, 'eligibilityPreview']],
    ['POST', '/company/jobs',                     [CompanyController::class, 'createJob']],
    ['GET',  '/company/jobs',                     [CompanyController::class, 'listJobs']],
    ['PUT',  '/company/jobs/{id}',                [CompanyController::class, 'updateJob']],
    ['GET',  '/company/applications',             [CompanyController::class, 'applications']],
    ['GET',  '/company/applications/filter',      [CompanyController::class, 'filterApplicants']],
    ['POST', '/company/applications/upload-results', [CompanyController::class, 'uploadResults']],
    ['POST', '/company/applications/{id}/review',   [CompanyController::class, 'startReview']],
    ['POST', '/company/applications/{id}/shortlist', [CompanyController::class, 'shortlist']],
    ['POST', '/company/applications/{id}/result', [CompanyController::class, 'updateResult']],
    ['GET',  '/company/notifications',              [CompanyController::class, 'notifications']],
    ['POST', '/company/notifications/read-all',     [CompanyController::class, 'markAllNotificationsRead']],
    ['POST', '/company/notifications/{id}/read',    [CompanyController::class, 'markNotificationRead']],
    ['GET',  '/company/recruiting',                   [CompanyController::class, 'recruitingOverview']],

    // Staff
    ['GET',  '/staff/profile',                    [StaffController::class, 'profile']],
    ['PUT',  '/staff/profile',                    [StaffController::class, 'updateProfile']],
    ['GET',  '/staff/dashboard',                  [StaffController::class, 'dashboard']],
    ['GET',  '/staff/recommendations',            [StaffController::class, 'listRecommendations']],
    ['POST', '/staff/recommendations',            [StaffController::class, 'createRecommendation']],
    ['GET',  '/staff/drives',                     [StaffController::class, 'listDrives']],
    ['GET',  '/staff/students',                   [StaffController::class, 'listStudents']],
    ['GET',  '/staff/students/{id}/pipeline',     [StaffController::class, 'studentPipeline']],
    ['GET',  '/staff/hiring-overview',            [StaffController::class, 'hiringOverview']],
    ['GET',  '/staff/notifications',              [StaffController::class, 'notifications']],
    ['POST', '/staff/notifications/read-all',     [StaffController::class, 'markAllNotificationsRead']],
    ['POST', '/staff/notifications/{id}/read',    [StaffController::class, 'markNotificationRead']],

    // Alumni
    ['GET',  '/alumni/profile',     [AlumniController::class, 'getProfile']],
    ['PUT',  '/alumni/profile',     [AlumniController::class, 'updateProfile']],
    ['GET',  '/alumni/dashboard',   [AlumniController::class, 'dashboard']],
    ['GET',  '/alumni/job-posts',   [AlumniController::class, 'listJobPosts']],
    ['POST', '/alumni/job-posts',   [AlumniController::class, 'createJobPost']],
    ['GET',  '/alumni/jobs',        [AlumniController::class, 'listJobs']],
    ['GET',  '/alumni/referrals',   [AlumniController::class, 'listReferrals']],
    ['POST', '/alumni/jobs/refer',  [AlumniController::class, 'referJob']],
    ['GET',  '/alumni/drives',      [AlumniController::class, 'listDrives']],
    ['GET',  '/alumni/resumes',     [AlumniController::class, 'listResumes']],
    ['POST', '/alumni/apply',       [AlumniController::class, 'apply']],
    ['GET',  '/alumni/applications', [AlumniController::class, 'myApplications']],
    ['GET',  '/alumni/notifications', [AlumniController::class, 'notifications']],
    ['POST', '/alumni/notifications/read-all', [AlumniController::class, 'markAllNotificationsRead']],
    ['POST', '/alumni/notifications/{id}/read', [AlumniController::class, 'markNotificationRead']],
    ['GET',  '/alumni/success-stories', [AlumniController::class, 'listSuccessStories']],
    ['POST', '/alumni/success-stories', [AlumniController::class, 'createSuccessStory']],
    ['PUT',  '/alumni/success-stories/{id}', [AlumniController::class, 'updateSuccessStory']],
    ['DELETE', '/alumni/success-stories/{id}', [AlumniController::class, 'deleteSuccessStory']],

    // Placement Officer
    ['GET',  '/officer/profile',                   [OfficerController::class, 'profile']],
    ['GET',  '/officer/dashboard',                 [OfficerController::class, 'dashboard']],
    ['GET',  '/officer/students',                  [OfficerController::class, 'listStudents']],
    ['GET',  '/officer/students/pending',          [OfficerController::class, 'pendingStudents']],
    ['POST', '/officer/users/{id}/approve',        [OfficerController::class, 'approveStudent']],
    ['GET',  '/officer/applications',              [OfficerController::class, 'listApplications']],
    ['GET',  '/officer/applications/pending',     [OfficerController::class, 'pendingApplications']],
    ['POST', '/officer/applications/{id}/approve', [OfficerController::class, 'approveApplication']],
    ['POST', '/officer/applications/{id}/reject',  [OfficerController::class, 'rejectApplication']],
    ['GET',  '/officer/resumes/pending',           [OfficerController::class, 'listPendingResumes']],
    ['GET',  '/officer/resumes',                   [OfficerController::class, 'listResumes']],
    ['GET',  '/officer/applications/{id}/resume', [OfficerController::class, 'downloadApplicationResume']],
    ['GET',  '/officer/students/{id}/resume',      [OfficerController::class, 'downloadStudentResume']],
    ['POST', '/officer/students/{id}/verify-resume', [OfficerController::class, 'verifyResume']],
    ['GET',  '/officer/results',                   [OfficerController::class, 'listResults']],
    ['POST', '/officer/results',                   [OfficerController::class, 'upsertResult']],
    ['DELETE','/officer/results/{id}',             [OfficerController::class, 'deleteResult']],
    ['GET',  '/officer/analytics',                 [OfficerController::class, 'analytics']],
    ['POST', '/officer/drives',                    [OfficerController::class, 'createDrive']],
    ['GET',  '/officer/drives',                    [OfficerController::class, 'listDrives']],
    ['PUT',  '/officer/drives/{id}',               [OfficerController::class, 'updateDrive']],
    ['DELETE','/officer/drives/{id}',              [OfficerController::class, 'deleteDrive']],
    ['POST', '/officer/drives/{id}/attendance',    [OfficerController::class, 'markAttendance']],
    ['GET',  '/officer/tracking',                    [OfficerController::class, 'placementTracking']],
    ['GET',  '/officer/analytics/extended',          [OfficerController::class, 'extendedAnalytics']],
    ['GET',  '/officer/placement-console',           [OfficerController::class, 'placementConsole']],
    ['GET',  '/officer/recruiting',                  [OfficerController::class, 'recruitingOverview']],
    ['GET',  '/officer/notifications',               [OfficerController::class, 'notifications']],
    ['POST', '/officer/notifications/read-all',      [OfficerController::class, 'markAllNotificationsRead']],
    ['POST', '/officer/notifications/{id}/read',     [OfficerController::class, 'markNotificationRead']],

    // Health & public
    ['GET', '/health',                  [PublicController::class, 'health']],
    ['GET', '/public/departments',      [PublicController::class, 'listDepartments']],
    ['GET', '/public/placement-stats',  [PublicController::class, 'placementStats']],
    ['GET', '/public/site-content',    [PublicController::class, 'siteContent']],
    ['GET', '/analytics/dashboard',    [PublicController::class, 'analyticsDashboard']],
    ['GET', '/analytics/extended',     [PublicController::class, 'extendedAnalytics']],
    ['GET', '/analytics/placement-console', [PublicController::class, 'placementConsole']],
];

try {
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
} catch (\InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (\RuntimeException $e) {
    $code = $e->getCode();
    $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
    Response::error($e->getMessage(), $status);
} catch (\Throwable $e) {
    $message = 'An unexpected server error occurred.';
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        $message = $e->getMessage();
    }
    Response::error($message, 500);
}
