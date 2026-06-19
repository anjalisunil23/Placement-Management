<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Models\SuccessStoryModel;
use PMS\Models\UserModel;
use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Dashboard analytics aggregation.
 */
final class AnalyticsService
{
    public function getDashboardAnalytics(?string $departmentId = null): array
    {
        $studentModel = new StudentModel();
        $companyModel = new CompanyModel();
        $applicationModel = new ApplicationModel();
        $departmentModel = new DepartmentModel();
        $jobModel = new JobModel();

        $studentFilter = [];
        $deptOid = $departmentId ? Security::toObjectId($departmentId) : null;
        if ($deptOid) {
            $studentFilter['departmentId'] = $deptOid;
        }

        $totalStudents = $studentModel->count($studentFilter);
        $placedStudents = $studentModel->count(array_merge($studentFilter, ['placed' => true]));
        $placementPct = $totalStudents > 0
            ? round(($placedStudents / $totalStudents) * 100, 2)
            : 0;

        $branchStats = [];
        $departments = $departmentId
            ? array_filter([$departmentModel->findById($departmentId)])
            : $departmentModel->findAll([], 50);
        foreach ($departments as $dept) {
            if (!$dept) {
                continue;
            }
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

        $studentIds = [];
        if ($deptOid) {
            foreach ($studentModel->findAll($studentFilter, 5000) as $s) {
                $studentIds[] = $s['_id'];
            }
        }

        $companies = $companyModel->findAll([], 100);
        $companyStats = array_map(function ($c) use ($applicationModel, $studentIds, $deptOid) {
            $appFilter = ['companyId' => $c['_id']];
            if ($deptOid && $studentIds !== []) {
                $appFilter['studentId'] = ['$in' => $studentIds];
            } elseif ($deptOid) {
                return [
                    'name'         => $c['companyName'],
                    'tier'         => $c['tier'],
                    'applications' => 0,
                    'selected'     => 0,
                ];
            }
            return [
                'name'         => $c['companyName'],
                'tier'         => $c['tier'],
                'applications' => $applicationModel->count($appFilter),
                'selected'     => $applicationModel->count(array_merge($appFilter, ['status' => 'selected'])),
            ];
        }, $companies);

        $appCountFilter = [];
        if ($deptOid && $studentIds !== []) {
            $appCountFilter['studentId'] = ['$in' => $studentIds];
        } elseif ($deptOid) {
            $appCountFilter['studentId'] = ['$in' => []];
        }

        $salaries = $this->extractSalaries($jobModel->findAll([], 500));

        return [
            'totals' => [
                'students'            => $totalStudents,
                'placedStudents'      => $placedStudents,
                'placementPercentage' => $placementPct,
                'companies'           => $companyModel->count([]),
                'applications'        => $applicationModel->count($appCountFilter),
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
        $salary = $analytics['salaryAnalytics'];

        $companyStats = array_values(array_filter(
            $analytics['companyStatistics'],
            static fn (array $c): bool => ($c['selected'] ?? 0) > 0
        ));
        usort($companyStats, static fn (array $a, array $b): int => ($b['selected'] ?? 0) <=> ($a['selected'] ?? 0));
        $topCompanies = array_slice($companyStats, 0, 12);

        $topRecruiters = array_map(
            static fn (array $c): string => (string) ($c['name'] ?? ''),
            $topCompanies
        );
        if ($topRecruiters === []) {
            $topRecruiters = array_map(
                static fn (array $c): string => (string) ($c['companyName'] ?? ''),
                (new CompanyModel())->findAll([], 12)
            );
        }
        $topRecruiters = array_values(array_unique(array_filter($topRecruiters)));

        $branchStats = $analytics['branchStatistics'];
        $offersByBranch = [
            'labels' => array_map(static fn (array $b): string => (string) ($b['code'] ?: $b['department']), $branchStats),
            'values' => array_map(static fn (array $b): int => (int) ($b['placed'] ?? 0), $branchStats),
        ];

        $topOffers = $this->getTopJobOffers(2);

        return [
            'placementPercentage' => $analytics['totals']['placementPercentage'],
            'totalPlaced'         => $analytics['totals']['placedStudents'],
            'totalStudents'       => $analytics['totals']['students'],
            'totalCompanies'      => $analytics['totals']['companies'],
            'salaryHighlights'    => $salary,
            'highestPkg'          => $salary['highest'],
            'avgPkg'              => $salary['average'],
            'medianPkg'           => $salary['median'],
            'lowestPkg'           => $salary['lowest'],
            'topCompanies'        => $topCompanies,
            'topRecruiters'       => array_values(array_filter($topRecruiters)),
            'branchStats'         => $branchStats,
            'offersByBranch'      => $offersByBranch,
            'sectorDistribution'  => $this->getSectorDistribution(),
            'successStories'      => $this->getSuccessStories(6),
            'topOffers'           => $topOffers,
        ];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function getSectorDistribution(): array
    {
        $companyModel = new CompanyModel();
        $applicationModel = new ApplicationModel();
        $sectors = [];

        foreach ($companyModel->findAll([], 200) as $company) {
            $category = trim((string) ($company['category'] ?? 'Other'));
            if ($category === '') {
                $category = 'Other';
            }
            $selected = $applicationModel->count([
                'companyId' => $company['_id'],
                'status'    => 'selected',
            ]);
            $sectors[$category] = ($sectors[$category] ?? 0) + $selected;
        }

        if (array_sum($sectors) === 0) {
            foreach ($companyModel->findAll([], 200) as $company) {
                $category = trim((string) ($company['category'] ?? 'Other'));
                if ($category === '') {
                    $category = 'Other';
                }
                $sectors[$category] = ($sectors[$category] ?? 0) + 1;
            }
        }

        arsort($sectors);

        return [
            'labels' => array_keys($sectors),
            'values' => array_values($sectors),
        ];
    }

    /**
     * @return array<int, array{name: string, role: string, package: string, quote: string}>
     */
    private function getSuccessStories(int $limit = 6): array
    {
        $stories = [];
        foreach ((new SuccessStoryModel())->published($limit) as $row) {
            $stories[] = SuccessStoryModel::toPublicCard($row);
        }
        if (count($stories) >= $limit) {
            return array_slice($stories, 0, $limit);
        }

        $remaining = $limit - count($stories);
        $placementStories = $this->getPlacementSuccessStories($remaining);
        return array_merge($stories, $placementStories);
    }

    /**
     * @return array<int, array{name: string, role: string, package: string, quote: string}>
     */
    private function getPlacementSuccessStories(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $applicationModel = new ApplicationModel();
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $companyModel = new CompanyModel();
        $jobModel = new JobModel();
        $driveModel = new DriveModel();

        $apps = $applicationModel->findAll(['status' => 'selected'], $limit, 0, ['updatedAt' => -1]);
        if ($apps === []) {
            foreach ($studentModel->findAll(['placed' => true], $limit) as $student) {
                $user = $userModel->findById((string) ($student['userId'] ?? ''));
                if (!$user) {
                    continue;
                }
                $apps[] = ['studentId' => $student['_id'], 'companyId' => null, 'jobId' => null, 'driveId' => null];
            }
        }

        $stories = [];
        foreach ($apps as $app) {
            $student = $studentModel->findById((string) ($app['studentId'] ?? ''));
            if (!$student) {
                continue;
            }
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $name = (string) ($user['name'] ?? 'Student');

            $companyName = 'Campus recruiter';
            if (!empty($app['companyId'])) {
                $company = $companyModel->findById((string) $app['companyId']);
                $companyName = (string) ($company['companyName'] ?? $companyName);
            }

            $roleTitle = '';
            $package = '';
            if (!empty($app['jobId'])) {
                $job = $jobModel->findById((string) $app['jobId']);
                $roleTitle = (string) ($job['title'] ?? '');
                $package = (string) ($job['package'] ?? '');
            } elseif (!empty($app['driveId'])) {
                $drive = $driveModel->findById((string) $app['driveId']);
                $roleTitle = (string) ($drive['title'] ?? '');
                $package = (string) ($drive['tier'] ?? '');
            }

            $stories[] = [
                'name'    => $name,
                'role'    => $roleTitle !== '' ? "{$companyName} · {$roleTitle}" : $companyName,
                'package' => $this->formatPackageLabel($package),
                'quote'   => 'Selected through the campus placement process on PlaceHub.',
            ];
            if (count($stories) >= $limit) {
                break;
            }
        }

        return $stories;
    }

    /**
     * @return array<int, array{package: float, company: string, role: string}>
     */
    private function getTopJobOffers(int $limit = 2): array
    {
        $jobModel = new JobModel();
        $companyModel = new CompanyModel();
        $ranked = [];

        foreach ($jobModel->findAll([], 500) as $job) {
            $pkg = $job['package'] ?? '';
            if (!preg_match('/[\d.]+/', (string) $pkg, $m)) {
                continue;
            }
            $company = $companyModel->findById((string) ($job['companyId'] ?? ''));
            $ranked[] = [
                'package' => (float) $m[0],
                'company' => (string) ($company['companyName'] ?? ''),
                'role'    => (string) ($job['title'] ?? ''),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['package'] <=> $a['package']);

        return array_slice($ranked, 0, $limit);
    }

    private function formatPackageLabel(string $package): string
    {
        if (preg_match('/[\d.]+/', $package, $m)) {
            return '₹' . $m[0] . ' LPA';
        }
        return $package !== '' ? $package : '—';
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
