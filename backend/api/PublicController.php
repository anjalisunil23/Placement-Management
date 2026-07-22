<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Config\Database;
use PMS\Models\DepartmentModel;
use PMS\Schemas\Collections;
use PMS\Models\PlacementNewsModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Middleware\RBACMiddleware;
use PMS\Services\AnalyticsService;
use PMS\Services\ObjectStorageService;
use PMS\Services\OfficerDataService;
use PMS\Services\PlacementOfficerContext;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Public and analytics API endpoints.
 */
final class PublicController
{
    /** GET /api/health — database connectivity check */
    public function health(): void
    {
        $db = Database::status();
        $tables = [];
        if ($db['ok']) {
            try {
                $tables = Database::pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable) {
                $tables = [];
            }
        }
        $root = dirname(__DIR__, 2);
        $deployVersion = '';
        $deployFile = $root . '/deploy-version.txt';
        if (is_readable($deployFile)) {
            $deployVersion = trim((string) file_get_contents($deployFile));
        }
        $eligibilityFile = $root . '/backend/services/EligibilityEngine.php';
        $legacyPolicyRules = is_readable($eligibilityFile)
            && strpos((string) file_get_contents($eligibilityFile), 'Placement policy not accepted') !== false;

        $requiredTables = [
            Collections::USERS,
            Collections::STUDENTS,
            Collections::STAFF,
            Collections::PLACEMENT_OFFICERS,
            Collections::COMPANIES,
            Collections::ALUMNI,
            Collections::DEPARTMENTS,
            Collections::DRIVES,
            Collections::APPLICATIONS,
            Collections::JOBS,
            Collections::NOTIFICATIONS,
            Collections::RESUMES,
            Collections::BLACKLIST,
            Collections::RULES,
            Collections::REPORTS,
            Collections::RECOMMENDATIONS,
            Collections::ALUMNI_REFERRALS,
            Collections::ALUMNI_JOB_POSTS,
            Collections::RECRUITMENT_RESULTS,
            Collections::SYSTEM_SETTINGS,
            Collections::PUBLIC_PAGE_CONTENT,
            Collections::PLACEMENT_NEWS,
            Collections::SUCCESS_STORIES,
        ];
        $missingTables = array_values(array_diff($requiredTables, $tables));
        $storage = new ObjectStorageService();

        Response::success([
            'status'   => $db['ok'] ? 'ok' : 'error',
            'database' => [
                'connected' => $db['ok'],
                'driver'    => 'mariadb',
                'version'   => $db['version'],
                'database'  => $_ENV['DB_DATABASE'] ?? null,
                'tables'    => count($tables),
                'missingTables' => $missingTables,
                'error'     => $db['error'],
            ],
            's3' => [
                'configured' => $storage->isConfigured(),
                'bucket'     => $storage->bucket(),
            ],
            'build' => [
                'deployVersion'    => $deployVersion,
                'eligibilityRules' => $legacyPolicyRules ? 'legacy-policy-required' : 'v2-resume-only',
            ],
        ], $db['ok'] ? 'OK' : 'Database unavailable', $db['ok'] ? 200 : 503);
    }

    /** GET /api/public/placement-stats */
    public function placementStats(): void
    {
        $service = new AnalyticsService();
        Response::success(DocumentHelper::jsonSafe($service->getPublicStats()));
    }

    /** GET /api/public/site-content — landing page stats + news (no auth) */
    public function siteContent(): void
    {
        $system = (new SystemSettingsModel())->get();
        $editorial = (new PublicPageContentModel())->get();
        $live = (new AnalyticsService())->getPublicStats();
        $salary = $live['salaryHighlights'];

        $pick = static function (float|int $liveVal, float|int $editorialVal): float|int {
            return $liveVal > 0 ? $liveVal : $editorialVal;
        };
        $pickMax = static function (float|int $liveVal, float|int $editorialVal): float|int {
            return max($liveVal, $editorialVal);
        };

        $public = array_merge($editorial, [
            'placed'          => $pick($live['totalPlaced'], (int) ($editorial['placed'] ?? 0)),
            'companies'       => $pickMax($live['totalCompanies'], (int) ($editorial['companies'] ?? 0)),
            'highestPkg'      => $pickMax($salary['highest'], (float) ($editorial['highestPkg'] ?? 0)),
            'avgPkg'          => $pick($salary['average'], (float) ($editorial['avgPkg'] ?? 0)) ?: (float) ($editorial['avgPkg'] ?? 0),
            'medianPkg'       => $pick($salary['median'], (float) ($editorial['medianPkg'] ?? 0)) ?: (float) ($editorial['medianPkg'] ?? 0),
            'lowestPkg'       => $pick($salary['lowest'], (float) ($editorial['lowestPkg'] ?? 0)),
            'placementRate'   => $pick($live['placementPercentage'], (float) ($editorial['placementRate'] ?? 0)),
        ]);
        if (empty($public['season']) && !empty($system['placementYear'])) {
            $public['season'] = $system['placementYear'];
        }

        $news = DocumentHelper::serializeMany((new PlacementNewsModel())->published(50));
        Response::success(DocumentHelper::jsonSafe([
            'system'     => $system,
            'publicPage' => $public,
            'liveStats'  => $live,
            'news'       => $news,
        ]));
    }

    /** GET /api/public/departments — for registration and forms (no auth) */
    public function listDepartments(): void
    {
        $model = new DepartmentModel();
        $localCount = 0;
        try {
            $localCount = count($model->findAll([], 5));
        } catch (\Throwable) {
            $localCount = 0;
        }

        // AES department sync is expensive — refresh at most once per hour when local data exists.
        $stampFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms_dept_sync_stamp';
        $ttlSeconds = 3600;
        $stampAge = is_file($stampFile) ? (time() - (int) @file_get_contents($stampFile)) : PHP_INT_MAX;
        $shouldSync = $localCount === 0 || $stampAge >= $ttlSeconds;

        if ($shouldSync) {
            try {
                (new \PMS\Services\AesApiService())->syncDepartmentsToLocal();
                @file_put_contents($stampFile, (string) time());
            } catch (\Throwable) {
                // Serve local departments when AES API is unreachable.
            }
        }

        $departments = $model->findAll([], 200);
        $assignedDeptIds = [];
        foreach ((new PlacementOfficerModel())->findAll([], 200) as $profile) {
            $deptId = (string) ($profile['departmentId'] ?? '');
            if ($deptId !== '') {
                $assignedDeptIds[$deptId] = true;
            }
        }

        $rows = [];
        foreach ($departments as $dept) {
            $serialized = DocumentHelper::serialize($dept);
            $id = (string) ($serialized['id'] ?? $serialized['_id'] ?? '');
            $code = strtoupper(trim((string) ($serialized['code'] ?? '')));
            $name = trim((string) ($serialized['name'] ?? ''));
            if ($code === '' || $name === '' || !DepartmentModel::isStudentAcademicDepartment($code, $name)) {
                continue;
            }
            $aesId = trim((string) ($serialized['aesId'] ?? ''));
            $rows[] = [
                'id'         => $id,
                'name'       => $name,
                'code'       => $code,
                'aesId'      => preg_match('/^\d+$/', $aesId) === 1 ? $aesId : '',
                'hasOfficer' => isset($assignedDeptIds[$id]),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        Response::success($rows);
    }

    /** GET /api/analytics/dashboard */
    public function analyticsDashboard(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $departmentId = null;
        if (($user['role'] ?? '') === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            $departmentId = $ctx['departmentId'];
        }
        $service = new AnalyticsService();
        Response::success($service->getDashboardAnalytics($departmentId));
    }

    /** GET /api/analytics/extended */
    public function extendedAnalytics(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $departmentId = null;
        if (($user['role'] ?? '') === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            $departmentId = $ctx['departmentId'];
        }
        Response::success((new AnalyticsService())->getExtendedAnalytics($departmentId));
    }

    /** GET /api/analytics/placement-console */
    public function placementConsole(): void
    {
        $user = RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $departmentId = null;
        if (($user['role'] ?? '') === 'placement_officer') {
            $ctx = PlacementOfficerContext::resolve($user);
            $departmentId = $ctx['departmentId'];
        }
        Response::success((new AnalyticsService())->getPlacementConsole($departmentId));
    }

    /** GET /api/media/{folder}/{file} — stream public media (photos, job-posters) from S3 */
    public function serveMedia(string $folder, string $file): void
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $allowed = [
            ObjectStorageService::FOLDER_PHOTOS,
            ObjectStorageService::FOLDER_JOB_POSTERS,
        ];
        if (!in_array($folder, $allowed, true)) {
            Response::notFound('Media not found.');
        }

        $file = basename(str_replace('\\', '/', $file));
        if ($file === '' || $file === '.' || $file === '..') {
            Response::notFound('Media not found.');
        }

        $storage = new ObjectStorageService();
        $uri = $storage->uri($folder, $file);
        $mime = $storage->guessMime($file);
        try {
            $storage->streamWithFallback($uri, $file, $mime, true, $folder);
        } catch (\Throwable) {
            Response::notFound('Media not found.');
        }
    }

    /**
     * GET /api/public/report-resume/application/{id}/{exp}/{sig}
     * Also accepts ?exp=&sig= for older Excel links.
     * Time-limited link for Excel report resume cells (no login required).
     */
    public function serveReportApplicationResume(string $id, ?string $exp = null, ?string $sig = null): void
    {
        $exp = $exp ?? (string) ($_GET['exp'] ?? '');
        $sig = $sig ?? (string) ($_GET['sig'] ?? '');
        if (!Security::verifyDownload('application_resume', $id, $exp, $sig)) {
            Response::forbidden('This resume link is invalid or has expired. Generate the report again.');
        }
        (new OfficerDataService())->streamApplicationResumeSigned($id);
    }

    /**
     * GET /api/public/report-resume/student/{id}/{exp}/{sig}
     */
    public function serveReportStudentResume(string $id, ?string $exp = null, ?string $sig = null): void
    {
        $exp = $exp ?? (string) ($_GET['exp'] ?? '');
        $sig = $sig ?? (string) ($_GET['sig'] ?? '');
        if (!Security::verifyDownload('student_resume', $id, $exp, $sig)) {
            Response::forbidden('This resume link is invalid or has expired. Generate the report again.');
        }
        (new OfficerDataService())->streamStudentResumeSigned($id);
    }

    /**
     * POST /api/aes/check-login — proxy username/password or social login to AES.
     */
    public function aesCheckLogin(): void
    {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($raw)) {
            $raw = $_POST;
        }

        $data = $raw;
        if (isset($raw['data']) && is_array($raw['data'])) {
            $data = $raw['data'];
        }

        try {
            $service = new \PMS\Services\AesLoginService();
            $resp = $service->checkLogin($data);
            if (!($resp['status'] ?? false)) {
                $message = (string) ($resp['message'] ?? $resp['title'] ?? 'AES login failed');
                Response::error($message, 401, $resp);
                return;
            }
            Response::success($resp, 'AES login verified');
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
