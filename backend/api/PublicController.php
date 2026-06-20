<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Config\Database;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementNewsModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Middleware\RBACMiddleware;
use PMS\Services\AnalyticsService;
use PMS\Services\PlacementOfficerContext;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;

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
        Response::success([
            'status'   => $db['ok'] ? 'ok' : 'error',
            'database' => [
                'connected' => $db['ok'],
                'driver'    => 'mariadb',
                'version'   => $db['version'],
                'database'  => $_ENV['DB_DATABASE'] ?? null,
                'tables'    => count($tables),
                'error'     => $db['error'],
            ],
        ], $db['ok'] ? 'OK' : 'Database unavailable', $db['ok'] ? 200 : 503);
    }

    /** GET /api/public/placement-stats */
    public function placementStats(): void
    {
        $service = new AnalyticsService();
        Response::success($service->getPublicStats());
    }

    /** GET /api/public/site-content — landing page stats + news (no auth) */
    public function siteContent(): void
    {
        $system = (new SystemSettingsModel())->get();
        $editorial = (new PublicPageContentModel())->get();
        $live = (new AnalyticsService())->getPublicStats();
        $salary = $live['salaryHighlights'];

        $public = array_merge($editorial, [
            'placed'          => $live['totalPlaced'],
            'companies'       => $live['totalCompanies'],
            'highestPkg'      => $salary['highest'],
            'avgPkg'          => $salary['average'],
            'medianPkg'       => $salary['median'],
            'lowestPkg'       => $salary['lowest'],
            'placementRate'   => $live['placementPercentage'],
        ]);
        if (empty($public['season']) && !empty($system['placementYear'])) {
            $public['season'] = $system['placementYear'];
        }

        $news = DocumentHelper::serializeMany((new PlacementNewsModel())->published(50));
        Response::success([
            'system'     => $system,
            'publicPage' => $public,
            'liveStats'  => $live,
            'news'       => $news,
        ]);
    }

    /** GET /api/public/departments — for registration and forms (no auth) */
    public function listDepartments(): void
    {
        $departments = (new DepartmentModel())->findAll([], 200);
        $assignedDeptIds = [];
        foreach ((new PlacementOfficerModel())->findAll([], 200) as $profile) {
            $deptId = (string) ($profile['departmentId'] ?? '');
            if ($deptId !== '') {
                $assignedDeptIds[$deptId] = true;
            }
        }

        $rows = array_map(static function (array $dept) use ($assignedDeptIds) {
            $serialized = DocumentHelper::serialize($dept);
            $id = $serialized['id'] ?? $serialized['_id'] ?? '';
            return [
                'id'         => $id,
                'name'       => $serialized['name'] ?? '',
                'code'       => $serialized['code'] ?? '',
                'hasOfficer' => isset($assignedDeptIds[$id]),
            ];
        }, $departments);
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
}
