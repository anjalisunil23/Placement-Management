<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Schemas\Collections;

/**
 * Dashboard analytics aggregation.
 */
final class AnalyticsService
{
    public function getDashboardAnalytics(): array
    {
        $studentModel = new StudentModel();
        $companyModel = new CompanyModel();
        $applicationModel = new ApplicationModel();
        $departmentModel = new DepartmentModel();
        $jobModel = new JobModel();

        $totalStudents = $studentModel->count([]);
        $placedStudents = $studentModel->count(['placed' => true]);
        $placementPct = $totalStudents > 0
            ? round(($placedStudents / $totalStudents) * 100, 2)
            : 0;

        // Branch statistics
        $branchStats = [];
        $departments = $departmentModel->findAll([], 50);
        foreach ($departments as $dept) {
            $deptId = (string) $dept['_id'];
            $total = $studentModel->count(['departmentId' => $dept['_id']]);
            $placed = $studentModel->count(['departmentId' => $dept['_id'], 'placed' => true]);
            $branchStats[] = [
                'department' => $dept['name'],
                'code'       => $dept['code'],
                'total'      => $total,
                'placed'     => $placed,
                'percentage' => $total > 0 ? round(($placed / $total) * 100, 2) : 0,
            ];
        }

        // Company statistics
        $companies = $companyModel->findAll([], 100);
        $companyStats = array_map(function ($c) use ($applicationModel) {
            $companyId = (string) $c['_id'];
            return [
                'name'         => $c['companyName'],
                'tier'         => $c['tier'],
                'applications' => $applicationModel->count(['companyId' => $c['_id']]),
                'selected'     => $applicationModel->count(['companyId' => $c['_id'], 'status' => 'selected']),
            ];
        }, $companies);

        // Salary analytics from jobs
        $salaries = $this->extractSalaries($jobModel->findAll([], 500));

        return [
            'totals' => [
                'students'           => $totalStudents,
                'placedStudents'     => $placedStudents,
                'placementPercentage'=> $placementPct,
                'companies'          => $companyModel->count([]),
                'applications'       => $applicationModel->count([]),
            ],
            'branchStatistics'  => $branchStats,
            'companyStatistics' => $companyStats,
            'salaryAnalytics'   => $salaries,
        ];
    }

    /** Public placement page stats */
    public function getPublicStats(): array
    {
        $analytics = $this->getDashboardAnalytics();
        $companyModel = new CompanyModel();

        $topCompanies = array_slice(
            array_values(array_filter($analytics['companyStatistics'], fn ($c) => $c['selected'] > 0)),
            0,
            10
        );

        return [
            'placementPercentage' => $analytics['totals']['placementPercentage'],
            'totalPlaced'         => $analytics['totals']['placedStudents'],
            'totalStudents'       => $analytics['totals']['students'],
            'totalCompanies'      => $analytics['totals']['companies'],
            'salaryHighlights'    => $analytics['salaryAnalytics'],
            'topCompanies'        => $topCompanies,
            'branchStats'         => $analytics['branchStatistics'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array{highest: float, lowest: float, average: float, median: float, count: int}
     */
    private function extractSalaries(array $jobs): array
    {
        $values = [];
        foreach ($jobs as $job) {
            $pkg = $job['package'] ?? '';
            if (preg_match('/[\d.]+/', (string) $pkg, $m)) {
                $values[] = (float) $m[0];
            }
        }
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return ['highest' => 0, 'lowest' => 0, 'average' => 0, 'median' => 0, 'count' => 0];
        }
        $mid = (int) floor($count / 2);
        $median = $count % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];

        return [
            'highest' => max($values),
            'lowest'  => min($values),
            'average' => round(array_sum($values) / $count, 2),
            'median'  => round($median, 2),
            'count'   => $count,
        ];
    }
}
