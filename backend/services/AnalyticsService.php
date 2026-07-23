<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Config\Database;
use PMS\Models\AlumniModel;
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

        // One student scan for branch totals instead of 2 count() queries per department.
        $branchTotals = [];
        $branchPlaced = [];
        foreach ($studentModel->findAll($studentFilter, 10000) as $s) {
            $did = (string) ($s['departmentId'] ?? '');
            if ($did === '') {
                continue;
            }
            $branchTotals[$did] = ($branchTotals[$did] ?? 0) + 1;
            if (($s['placed'] ?? false) === true) {
                $branchPlaced[$did] = ($branchPlaced[$did] ?? 0) + 1;
            }
        }
        foreach ($departments as $dept) {
            if (!$dept) {
                continue;
            }
            $deptId = (string) $dept['_id'];
            $total = (int) ($branchTotals[$deptId] ?? 0);
            $placed = (int) ($branchPlaced[$deptId] ?? 0);
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
        $companyStatsMap = [];
        foreach ($companies as $c) {
            $cid = (string) ($c['_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $companyStatsMap[$cid] = [
                'name'         => $c['companyName'],
                'tier'         => $c['tier'],
                'applications' => 0,
                'selected'     => 0,
            ];
        }

        $appScanFilter = [];
        if ($deptOid && $studentIds !== []) {
            $appScanFilter['studentId'] = ['$in' => $studentIds];
        } elseif ($deptOid) {
            $appScanFilter['studentId'] = ['$in' => []];
        }
        // One pass over applications instead of 2 count() queries per company.
        foreach ($applicationModel->findAll($appScanFilter, 8000) as $app) {
            $cid = (string) ($app['companyId'] ?? '');
            if ($cid === '' || !isset($companyStatsMap[$cid])) {
                continue;
            }
            $companyStatsMap[$cid]['applications']++;
            if (($app['status'] ?? '') === 'selected') {
                $companyStatsMap[$cid]['selected']++;
            }
        }
        $companyStats = array_values($companyStatsMap);

        $appCountFilter = $appScanFilter;

        $companyCount = $companyModel->count([]);
        if ($deptOid) {
            // Department dashboards: companies that actually received applications from this dept.
            $companyIds = [];
            if ($studentIds !== []) {
                foreach ($applicationModel->findAll(['studentId' => ['$in' => $studentIds]], 8000) as $app) {
                    $cid = (string) ($app['companyId'] ?? '');
                    if ($cid !== '') {
                        $companyIds[$cid] = true;
                    }
                }
            }
            $companyCount = count($companyIds);
        }

        $salaries = $this->extractSalariesFromSources(
            $jobModel->findAll([], 500),
            (new DriveModel())->findAll([], 500)
        );

        return [
            'totals' => [
                'students'            => $totalStudents,
                'placedStudents'      => $placedStudents,
                'placementPercentage' => $placementPct,
                'companies'           => $companyCount,
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
            'placedMembers'       => $this->listPlacedMembers(),
        ];
    }

    /**
     * Unique currently placed students + working alumni (for public/admin name lists).
     *
     * @return list<array{name: string, type: string, roll: string, company: string, role: string}>
     */
    public function listPlacedMembers(): array
    {
        $userModel = new UserModel();
        $rows = [];
        $seen = [];

        foreach ((new StudentModel())->findAll(['placed' => true], 5000) as $student) {
            $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
            $company = trim((string) ($placement['company'] ?? $placement['companyName'] ?? ''));
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $name = trim((string) ($user['name'] ?? 'Student'));
            $roll = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            $key = $roll !== '' ? 's:' . $roll : 's:' . strtolower($name) . '|' . (string) ($student['_id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'name'    => $name !== '' ? $name : 'Student',
                'type'    => 'student',
                'roll'    => $roll,
                'company' => $company !== '' ? $company : '—',
                'role'    => trim((string) ($placement['role'] ?? '')),
            ];
        }

        try {
            foreach ((new AlumniModel())->findAll(['isWorking' => true], 5000) as $alumni) {
                $company = trim((string) ($alumni['company'] ?? ''));
                if ($company === '') {
                    continue;
                }
                $user = $userModel->findById((string) ($alumni['userId'] ?? ''));
                $name = trim((string) ($user['name'] ?? $alumni['name'] ?? 'Alumni'));
                $email = strtolower(trim((string) ($user['email'] ?? '')));
                $key = $email !== '' ? 'a:' . $email : 'a:' . strtolower($name) . '|' . strtolower($company);
                if (isset($seen[$key])) {
                    continue;
                }
                // Also skip if same person already listed as placed student (by name).
                $nameKey = 's:' . strtolower($name);
                if (isset($seen[$nameKey])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'name'    => $name !== '' ? $name : 'Alumni',
                    'type'    => 'alumni',
                    'roll'    => '',
                    'company' => $company,
                    'role'    => trim((string) ($alumni['role'] ?? $alumni['jobRole'] ?? $alumni['alumniRole'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            // Alumni table may be empty / unavailable.
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
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
        try {
            foreach ((new SuccessStoryModel())->published($limit) as $row) {
                $stories[] = SuccessStoryModel::toPublicCard($row);
            }
        } catch (\Throwable) {
            $stories = [];
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
        $driveModel = new DriveModel();
        $companyModel = new CompanyModel();
        $ranked = [];

        foreach ($jobModel->findAll([], 500) as $job) {
            $pkg = $this->parsePackageValue($job['package'] ?? '');
            if ($pkg <= 0) {
                continue;
            }
            $company = $companyModel->findById((string) ($job['companyId'] ?? ''));
            $ranked[] = [
                'package' => $pkg,
                'company' => (string) ($company['companyName'] ?? ''),
                'role'    => (string) ($job['title'] ?? ''),
            ];
        }

        foreach ($driveModel->findAll([], 500) as $drive) {
            $elig = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
            $pkg = $this->parsePackageValue($elig['package'] ?? ($drive['tier'] ?? ''));
            if ($pkg <= 0) {
                continue;
            }
            $company = $companyModel->findById((string) ($drive['companyId'] ?? ''));
            $ranked[] = [
                'package' => $pkg,
                'company' => (string) ($company['companyName'] ?? ($drive['companyName'] ?? '')),
                'role'    => (string) ($drive['title'] ?? ''),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['package'] <=> $a['package']);

        $seen = [];
        $unique = [];
        foreach ($ranked as $row) {
            $key = $row['company'] . '|' . $row['role'] . '|' . $row['package'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return array_slice($unique, 0, $limit);
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
     * @param array<int, array<string, mixed>> $drives
     * @return array{highest: float, lowest: float, average: float, median: float, count: int}
     */
    private function extractSalariesFromSources(array $jobs, array $drives = []): array
    {
        $values = [];
        foreach ($jobs as $job) {
            $pkg = $this->parsePackageValue($job['package'] ?? '');
            if ($pkg > 0) {
                $values[] = $pkg;
            }
        }
        foreach ($drives as $drive) {
            $elig = is_array($drive['eligibility'] ?? null) ? $drive['eligibility'] : [];
            $pkg = $this->parsePackageValue($elig['package'] ?? ($drive['tier'] ?? ''));
            if ($pkg > 0) {
                $values[] = $pkg;
            }
        }

        $values = array_values(array_unique($values));
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

    private function parsePackageValue(mixed $package): float
    {
        if (is_numeric($package)) {
            return (float) $package;
        }
        if (preg_match('/[\d.]+/', (string) $package, $m)) {
            return (float) $m[0];
        }

        return 0.0;
    }

    /**
     * Extended analytics for analytics.html charts.
     *
     * @return array<string, mixed>
     */
    public function getExtendedAnalytics(?string $departmentId = null): array
    {
        $base = $this->getDashboardAnalytics($departmentId);
        $jobModel = new JobModel();
        $jobs = $jobModel->findAll([], 500);

        // Load selected apps once for both this-year and last-year trends.
        $selectedApps = (new ApplicationModel())->findAll(['status' => 'selected'], 5000);
        $studentIds = $this->studentIdsForDepartment($departmentId);

        return [
            'totals'           => $base['totals'],
            'branchStatistics' => $base['branchStatistics'],
            'companyStatistics'=> $base['companyStatistics'],
            'salaryAnalytics'  => $base['salaryAnalytics'],
            'hiringTrend'      => $this->getHiringTrendFromApps($selectedApps, $studentIds, 'this'),
            'hiringTrendLastYear' => $this->getHiringTrendFromApps($selectedApps, $studentIds, 'last'),
            'branchPlacement'  => [
                'labels' => array_map(
                    static fn (array $b): string => (string) ($b['code'] ?: $b['department']),
                    $base['branchStatistics']
                ),
                'values' => array_map(
                    static fn (array $b): int => (int) ($b['placed'] ?? 0),
                    $base['branchStatistics']
                ),
            ],
            'packageBands'     => $this->getPackageBands($jobs),
            'jobTypeSplit'     => $this->getJobTypeSplit($jobs),
            'topCompanyOffers' => $this->getTopCompanyOffers($base['companyStatistics']),
        ];
    }

    /**
     * Department pipeline stats for placement-console.html.
     *
     * @return array<string, mixed>
     */
    public function getPlacementConsole(?string $departmentId = null): array
    {
        $tracking = (new TrackingService())->getOverview($departmentId, 50);
        $recruiting = (new RecruitingService())->getCampusOverview($departmentId);
        $departments = $this->getDepartmentPipeline($departmentId);
        $driveModel = new DriveModel();
        $activeDriveStatuses = ['scheduled', 'ongoing', 'open', 'reviewing'];
        $jobCount = $driveModel->count(['status' => ['$in' => $activeDriveStatuses]]);

        return [
            'summary' => [
                'applicants'   => $tracking['summary']['applied'] ?? 0,
                'shortlisted'  => $tracking['summary']['shortlisted'] ?? 0,
                'selected'     => $tracking['summary']['offered'] ?? 0,
                'placed'       => $tracking['summary']['joined'] ?? 0,
                'companies'    => $recruiting['stats']['activeCompanies'] ?? 0,
                'jobPosts'     => $jobCount,
            ],
            'departments' => $departments,
            'tracking'    => $tracking,
            'recruiting'  => $recruiting,
        ];
    }

    /**
     * @param 'rolling'|'this'|'last'|null $yearMode
     * @return array{labels: array<int, string>, series: array<int, array{label: string, data: array<int, int>}> , year?: int}
     */
    private function getHiringTrend(?string $departmentId, ?string $yearMode = 'rolling'): array
    {
        $apps = (new ApplicationModel())->findAll(['status' => 'selected'], 5000);
        $studentIds = $this->studentIdsForDepartment($departmentId);

        return $this->getHiringTrendFromApps($apps, $studentIds, $yearMode);
    }

    /**
     * @param array<int, array<string, mixed>> $apps
     * @param array<string, true>|null $studentIds
     * @param 'rolling'|'this'|'last'|null $yearMode
     * @return array{labels: array<int, string>, series: array<int, array{label: string, data: array<int, int>}> , year?: int}
     */
    private function getHiringTrendFromApps(array $apps, ?array $studentIds, ?string $yearMode = 'rolling'): array
    {
        $yearMode = $yearMode === null || $yearMode === '' ? 'rolling' : $yearMode;
        $months = [];
        $year = null;

        if ($yearMode === 'this' || $yearMode === 'last') {
            $year = (int) date('Y');
            if ($yearMode === 'last') {
                $year--;
            }
            for ($m = 1; $m <= 12; $m++) {
                $months[] = sprintf('%04d-%02d', $year, $m);
            }
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $months[] = date('Y-m', strtotime("-{$i} months"));
            }
        }

        $counts = array_fill_keys($months, 0);

        foreach ($apps as $app) {
            if ($studentIds !== null) {
                $sid = (string) ($app['studentId'] ?? '');
                if (!isset($studentIds[$sid])) {
                    continue;
                }
            }
            $timeline = $app['timeline'] ?? [];
            $selectedAt = (string) ($app['updatedAt'] ?? $app['createdAt'] ?? '');
            foreach ($timeline as $entry) {
                if (($entry['status'] ?? '') === 'selected' && !empty($entry['at'])) {
                    $selectedAt = (string) $entry['at'];
                    break;
                }
            }
            $monthKey = date('Y-m', strtotime($selectedAt) ?: time());
            if (isset($counts[$monthKey])) {
                $counts[$monthKey]++;
            }
        }

        $result = [
            'labels' => array_map(
                static fn (string $ym): string => date('M', strtotime($ym . '-01')),
                $months
            ),
            'series' => [[
                'label' => 'Offers',
                'data'  => array_values($counts),
            ]],
        ];
        if ($year !== null) {
            $result['year'] = $year;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function getPackageBands(array $jobs): array
    {
        $bands = [
            '3-6'   => 0,
            '6-10'  => 0,
            '10-20' => 0,
            '20-40' => 0,
            '40+'   => 0,
        ];

        foreach ($jobs as $job) {
            $pkg = $job['package'] ?? '';
            if (!preg_match('/[\d.]+/', (string) $pkg, $m)) {
                continue;
            }
            $value = (float) $m[0];
            if ($value < 6) {
                $bands['3-6']++;
            } elseif ($value < 10) {
                $bands['6-10']++;
            } elseif ($value < 20) {
                $bands['10-20']++;
            } elseif ($value < 40) {
                $bands['20-40']++;
            } else {
                $bands['40+']++;
            }
        }

        return [
            'labels' => array_keys($bands),
            'values' => array_values($bands),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function getJobTypeSplit(array $jobs): array
    {
        $types = [];
        foreach ($jobs as $job) {
            $type = trim((string) ($job['jobType'] ?? $job['type'] ?? 'Full-time'));
            if ($type === '') {
                $type = 'Full-time';
            }
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        if ($types === []) {
            return ['labels' => ['Full-time'], 'values' => [0]];
        }

        return [
            'labels' => array_keys($types),
            'values' => array_values($types),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $companyStats
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function getTopCompanyOffers(array $companyStats): array
    {
        $sorted = $companyStats;
        usort($sorted, static fn (array $a, array $b): int => ($b['selected'] ?? 0) <=> ($a['selected'] ?? 0));
        $top = array_slice($sorted, 0, 10);

        return [
            'labels' => array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $top),
            'values' => array_map(static fn (array $c): int => (int) ($c['selected'] ?? 0), $top),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDepartmentPipeline(?string $departmentId): array
    {
        $departmentModel = new DepartmentModel();
        $studentModel = new StudentModel();
        $applicationModel = new ApplicationModel();
        $jobModel = new JobModel();

        $departments = $departmentId
            ? array_filter([$departmentModel->findById($departmentId)])
            : $departmentModel->findAll([], 50);

        $rows = [];
        foreach ($departments as $dept) {
            if (!$dept) {
                continue;
            }
            $deptId = $dept['_id'];
            $studentFilter = ['departmentId' => $deptId];
            $students = $studentModel->findAll($studentFilter, 5000);
            $studentIds = array_map(static fn (array $s) => $s['_id'], $students);
            $studentIdStrings = array_map(static fn ($oid) => (string) $oid, $studentIds);

            $applicants = 0;
            $shortlisted = 0;
            $selected = 0;
            $placed = $studentModel->count(array_merge($studentFilter, ['placed' => true]));

            if ($studentIds !== []) {
                $apps = $applicationModel->findAll(['studentId' => ['$in' => $studentIds]], 5000);
                foreach ($apps as $app) {
                    $status = (string) ($app['status'] ?? '');
                    if (in_array($status, ['rejected', 'withdrawn'], true)) {
                        continue;
                    }
                    $applicants++;
                    if (in_array($status, ['officer_approved', 'company_review', 'shortlisted', 'selected'], true)) {
                        $shortlisted++;
                    }
                    if ($status === 'selected') {
                        $selected++;
                    }
                }
            }

            $avgPackage = $this->averagePackageForStudents($studentIdStrings, $jobModel);

            $total = count($students);
            $rows[] = [
                'department'   => (string) ($dept['name'] ?? ''),
                'code'         => (string) ($dept['code'] ?? ''),
                'students'     => $total,
                'applicants'   => $applicants,
                'shortlisted'  => $shortlisted,
                'selected'     => $selected,
                'placed'       => $placed,
                'placementPct' => $total > 0 ? round(($placed / $total) * 100, 1) : 0,
                'avgPackage'   => $avgPackage,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, string> $studentIdStrings
     */
    private function averagePackageForStudents(array $studentIdStrings, JobModel $jobModel): float
    {
        if ($studentIdStrings === []) {
            return 0;
        }

        $lookup = array_flip($studentIdStrings);
        $values = [];
        foreach ((new ApplicationModel())->findAll(['status' => 'selected'], 5000) as $app) {
            $sid = (string) ($app['studentId'] ?? '');
            if (!isset($lookup[$sid]) || empty($app['jobId'])) {
                continue;
            }
            $job = $jobModel->findById((string) $app['jobId']);
            $pkg = $job['package'] ?? '';
            if (preg_match('/[\d.]+/', (string) $pkg, $m)) {
                $values[] = (float) $m[0];
            }
        }

        return $values === [] ? 0 : round(array_sum($values) / count($values), 2);
    }

    /**
     * @return array<string, true>|null
     */
    private function studentIdsForDepartment(?string $departmentId): ?array
    {
        if ($departmentId === null || $departmentId === '') {
            return null;
        }
        $deptOid = Security::toObjectId($departmentId);
        if ($deptOid === null) {
            return [];
        }
        $map = [];
        foreach ((new StudentModel())->findAll(['departmentId' => $deptOid], 5000) as $student) {
            $map[(string) $student['_id']] = true;
        }
        return $map;
    }
}
