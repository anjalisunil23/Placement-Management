<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\DriveModel;
use PMS\Models\RecommendationModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Department-scoped read access and recommendations for faculty / staff.
 */
final class StaffDataService
{
    /**
     * @return array{user: array<string, mixed>, ctx: array<string, mixed>, officerCtx: array<string, mixed>}
     */
    public function requireScope(): array
    {
        $user = RBACMiddleware::requireStaff();
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        return [
            'user'        => $user,
            'ctx'         => $ctx,
            'officerCtx'  => StaffContext::officerCompatible($ctx),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMyRecommendations(string $staffUserId): array
    {
        return (new RecommendationModel())->listEnrichedForStaff($staffUserId);
    }

    /**
     * @param array<string, mixed> $officerCtx
     * @return array<string, mixed>
     */
    public function dashboardStats(array $officerCtx, string $staffUserId, bool $includeExtended = true): array
    {
        $stats = (new OfficerDataService())->dashboardStats($officerCtx, $includeExtended);
        if (!$includeExtended) {
            return array_merge($stats, [
                'recommendationCount' => 0,
                'pendingRecommendations' => 0,
                'registeredRecommendations' => 0,
            ]);
        }
        $recs = $this->listMyRecommendations($staffUserId);

        return array_merge($stats, [
            'recommendationCount' => count($recs),
            'pendingRecommendations' => count(array_filter(
                $recs,
                fn (array $r) => ($r['status'] ?? '') === 'pending'
            )),
            'registeredRecommendations' => count(array_filter(
                $recs,
                fn (array $r) => ($r['status'] ?? '') === 'registered'
            )),
        ]);
    }

    /**
     * @param array<string, mixed> $officerCtx
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(array $officerCtx): array
    {
        return (new OfficerDataService())->listStudents($officerCtx);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listDrives(array $ctx): array
    {
        if (empty($ctx['departmentId'])) {
            return [];
        }

        $officerCtx = [
            'isAdmin'      => false,
            'departmentId' => $ctx['departmentId'] ?? '',
            'department'   => $ctx['department'] ?? null,
            'profile'      => $ctx['profile'] ?? null,
        ];
        $filter = PlacementOfficerContext::driveCollectionFilter($officerCtx);
        $candidates = (new DriveModel())->findAll($filter, 100);

        return array_values(array_filter(
            $candidates,
            static fn (array $drive): bool => PlacementOfficerContext::driveMatchesDepartment($drive, $officerCtx)
        ));
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $officerCtx
     * @return array<string, mixed>
     */
    public function hiringOverview(array $ctx, array $officerCtx): array
    {
        $analytics = (new AnalyticsService())->getDashboardAnalytics($ctx['departmentId']);
        $applications = (new OfficerDataService())->listApplications($officerCtx);

        $shortlisted = 0;
        $offers = 0;
        foreach ($applications as $app) {
            $status = $app['status'] ?? '';
            if (in_array($status, ['shortlisted', 'company_review'], true)) {
                $shortlisted++;
            }
            if ($status === 'selected') {
                $offers++;
            }
        }

        $companiesHiring = count(array_filter(
            $analytics['companyStatistics'] ?? [],
            fn (array $c) => ($c['applications'] ?? 0) > 0
        ));

        return [
            'department'        => $ctx['department'] ? DocumentHelper::serialize($ctx['department']) : null,
            'companiesHiring'   => $companiesHiring,
            'applicants'        => $analytics['totals']['applications'] ?? 0,
            'shortlisted'       => $shortlisted,
            'offers'            => $offers,
            'hired'             => $analytics['totals']['placedStudents'] ?? 0,
            'placementPercentage' => $analytics['totals']['placementPercentage'] ?? 0,
            'branchStatistics'  => $analytics['branchStatistics'] ?? [],
            'companyStatistics' => $analytics['companyStatistics'] ?? [],
            'salaryAnalytics'   => $analytics['salaryAnalytics'] ?? [],
        ];
    }
}
