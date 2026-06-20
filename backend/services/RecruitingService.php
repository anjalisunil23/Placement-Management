<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Utils\Security;

/**
 * Campus recruiting overview — active companies, department breakdown, applicants.
 */
final class RecruitingService
{
    /**
     * Company-scoped recruiting snapshot (recruiting.html).
     *
     * @return array<string, mixed>
     */
    public function getCompanyOverview(string $companyId): array
    {
        $company = (new CompanyModel())->findById($companyId);
        if (!$company) {
            return $this->emptyOverview();
        }

        $campusCompanies = $this->activeRecruitingCompanies(null);
        $appService = new CompanyApplicationService();
        $applicants = $appService->listEnriched($companyId);
        $byDept = $this->applicantsByDepartment($applicants);
        $counts = $appService->statusCounts($companyId);

        return [
            'scope'            => 'company',
            'companyName'      => (string) ($company['companyName'] ?? ''),
            'stats'            => [
                'activeCompanies' => count($campusCompanies),
                'applicants'      => count($applicants),
                'departments'     => count($byDept),
            ],
            'activeCompanies'  => $campusCompanies,
            'applicantsByDept' => $byDept,
            'applicants'       => $applicants,
            'statusCounts'     => $counts,
        ];
    }

    /**
     * Admin/officer campus recruiting overview.
     *
     * @return array<string, mixed>
     */
    public function getCampusOverview(?string $departmentId = null): array
    {
        $activeCompanies = $this->activeRecruitingCompanies($departmentId);
        $applicants = $this->campusApplicants($departmentId);
        $byDept = $this->applicantsByDepartment($applicants);

        return [
            'scope'            => 'campus',
            'stats'            => [
                'activeCompanies' => count($activeCompanies),
                'applicants'      => count($applicants),
                'departments'     => count($byDept),
            ],
            'activeCompanies'  => $activeCompanies,
            'applicantsByDept' => $byDept,
            'applicants'       => $applicants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyOverview(): array
    {
        return [
            'scope'            => 'company',
            'companyName'      => '',
            'stats'            => ['activeCompanies' => 0, 'applicants' => 0, 'departments' => 0],
            'activeCompanies'  => [],
            'applicantsByDept' => [],
            'applicants'       => [],
            'statusCounts'     => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeRecruitingCompanies(?string $departmentId): array
    {
        $driveModel = new DriveModel();
        $jobModel = new JobModel();
        $companyModel = new CompanyModel();
        $appModel = new ApplicationModel();

        $activeStatuses = ['scheduled', 'open', 'ongoing', 'reviewing'];
        $drives = $driveModel->findAll(['status' => ['$in' => $activeStatuses]], 500);

        if ($departmentId !== null && $departmentId !== '') {
            $dept = (new DepartmentModel())->findById($departmentId);
            $deptCode = $dept ? (string) ($dept['code'] ?? '') : '';
            $deptOid = Security::toObjectId($departmentId);
            $drives = array_values(array_filter($drives, static function (array $drive) use ($deptCode, $deptOid): bool {
                $branches = $drive['branches'] ?? [];
                if ($branches === [] || $branches === null) {
                    return true;
                }
                if ($deptCode !== '' && in_array($deptCode, (array) $branches, true)) {
                    return true;
                }
                $driveDept = (string) ($drive['departmentId'] ?? '');
                return $deptOid !== null && $driveDept === (string) $deptOid;
            }));
        }

        $byCompany = [];
        foreach ($drives as $drive) {
            $companyId = (string) ($drive['companyId'] ?? '');
            if ($companyId === '') {
                continue;
            }
            if (!isset($byCompany[$companyId])) {
                $company = $companyModel->findById($companyId);
                $byCompany[$companyId] = [
                    'companyId'    => $companyId,
                    'company'      => (string) ($company['companyName'] ?? 'Company'),
                    'openRoles'    => 0,
                    'package'      => (string) ($drive['tier'] ?? ''),
                    'applicants'   => 0,
                    'status'       => (string) ($drive['status'] ?? 'open'),
                ];
            }
            $byCompany[$companyId]['openRoles']++;
            if ($byCompany[$companyId]['package'] === '' && !empty($drive['tier'])) {
                $byCompany[$companyId]['package'] = (string) $drive['tier'];
            }
        }

        foreach ($jobModel->findAll(['status' => ['$in' => ['open', 'ongoing', 'reviewing']]], 500) as $job) {
            $companyId = (string) ($job['companyId'] ?? '');
            if ($companyId === '' || !isset($byCompany[$companyId])) {
                continue;
            }
            $byCompany[$companyId]['openRoles']++;
            if ($byCompany[$companyId]['package'] === '' && !empty($job['package'])) {
                $byCompany[$companyId]['package'] = (string) $job['package'];
            }
        }

        foreach (array_keys($byCompany) as $companyId) {
            $byCompany[$companyId]['applicants'] = $appModel->count(['companyId' => Security::toObjectId($companyId)]);
        }

        return array_values($byCompany);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function campusApplicants(?string $departmentId): array
    {
        $filter = [];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return [];
            }
            $studentIds = [];
            foreach ((new StudentModel())->findAll(['departmentId' => $deptOid], 5000) as $student) {
                $studentIds[] = $student['_id'];
            }
            if ($studentIds === []) {
                return [];
            }
            $filter['studentId'] = ['$in' => $studentIds];
        }

        $apps = (new ApplicationModel())->findAll($filter, 5000);
        $byCompany = [];
        foreach ($apps as $app) {
            $companyId = (string) ($app['companyId'] ?? '');
            if ($companyId === '') {
                continue;
            }
            $byCompany[$companyId][] = (string) ($app['_id'] ?? '');
        }

        $appService = new CompanyApplicationService();
        $rows = [];
        foreach ($byCompany as $companyId => $appIds) {
            $lookup = array_flip($appIds);
            foreach ($appService->listEnriched($companyId) as $row) {
                $id = (string) ($row['id'] ?? $row['_id'] ?? '');
                if ($id !== '' && isset($lookup[$id])) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $applicants
     * @return array<int, array<string, mixed>>
     */
    private function applicantsByDepartment(array $applicants): array
    {
        $totals = [];
        foreach ($applicants as $row) {
            $dept = trim((string) ($row['student']['department'] ?? ''));
            if ($dept === '') {
                $dept = 'Unknown';
            }
            $totals[$dept] = ($totals[$dept] ?? 0) + 1;
        }

        $grand = array_sum($totals) ?: 1;
        $result = [];
        foreach ($totals as $dept => $count) {
            $result[] = [
                'department' => $dept,
                'applicants' => $count,
                'share'      => round(($count / $grand) * 100, 1),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['applicants'] <=> $a['applicants']);

        return $result;
    }
}
