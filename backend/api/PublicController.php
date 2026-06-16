<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Services\AnalyticsService;
use PMS\Utils\Response;

/**
 * Public and analytics API endpoints.
 */
final class PublicController
{
    /** GET /api/public/placement-stats */
    public function placementStats(): void
    {
        $service = new AnalyticsService();
        Response::success($service->getPublicStats());
    }

    /** GET /api/analytics/dashboard */
    public function analyticsDashboard(): void
    {
        \PMS\Middleware\RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $service = new AnalyticsService();
        Response::success($service->getDashboardAnalytics());
    }
}
