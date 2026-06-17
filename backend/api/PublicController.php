<?php

declare(strict_types=1);

namespace PMS\Api;

use PMS\Models\PlacementNewsModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\SystemSettingsModel;
use PMS\Services\AnalyticsService;
use PMS\Utils\DocumentHelper;
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

    /** GET /api/public/site-content — landing page stats + news (no auth) */
    public function siteContent(): void
    {
        $system = (new SystemSettingsModel())->get();
        $public = (new PublicPageContentModel())->get();
        if (empty($public['season']) && !empty($system['placementYear'])) {
            $public['season'] = $system['placementYear'];
        }
        $news = DocumentHelper::serializeMany((new PlacementNewsModel())->published(50));
        Response::success([
            'system'     => $system,
            'publicPage' => $public,
            'news'       => $news,
        ]);
    }

    /** GET /api/analytics/dashboard */
    public function analyticsDashboard(): void
    {
        \PMS\Middleware\RBACMiddleware::requireRoles(['admin', 'placement_officer']);
        $service = new AnalyticsService();
        Response::success($service->getDashboardAnalytics());
    }
}
