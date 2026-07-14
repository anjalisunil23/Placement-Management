<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
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
    public function getCampusOverview(?string $departmentId = null, ?array $filterCtx = null): array
    {
        $activeCompanies = $this->activeRecruitingCompanies($departmentId);
        $applicants = $this->campusApplicants($departmentId);
        $byDept = $this->applicantsByDepartment($applicants);
        $batchOptions = $this->campusBatchOptions($departmentId, $filterCtx);
        $placements = $this->listCampusPlacements($departmentId);

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
            'batchOptions'     => $batchOptions,
            'placements'       => $placements,
        ];
    }

    /**
     * Placed students for year-filtered placement lists (AES classBatch + offer dates).
     *
     * @return list<array<string, mixed>>
     */
    public function listCampusPlacements(?string $departmentId = null): array
    {
        return $this->campusPlacements($departmentId);
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
            'batchOptions'     => [],
            'placements'       => [],
        ];
    }

    /**
     * Distinct AES class batches (stud_class + assigned previous years) for filters.
     *
     * @param array<string, mixed>|null $filterCtx staff/officer-compatible PlacementFilterService ctx
     * @return list<string>
     */
    private function campusBatchOptions(?string $departmentId, ?array $filterCtx = null): array
    {
        $batches = [];

        if (is_array($filterCtx) && !empty($filterCtx['departmentId'])) {
            try {
                foreach ((new PlacementFilterService())->fetchBatchOptions($filterCtx, '', '') as $batch) {
                    $batch = trim((string) $batch);
                    if ($batch !== '') {
                        $batches[$batch] = true;
                    }
                }
            } catch (\Throwable) {
                // Fall through to Mongo / live AES stud_class backfill.
            }
        }

        $filter = [];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                $list = array_keys($batches);
                sort($list, SORT_NATURAL | SORT_FLAG_CASE);

                return $list;
            }
            $filter['departmentId'] = $deptOid;
        }

        $studentModel = new StudentModel();
        $aes = new AesApiService();
        $aesCalls = 0;
        $aesLimit = 80;

        foreach ($studentModel->findAll($filter, 8000) as $student) {
            $batch = $this->resolveStudentStudClass($student, $studentModel, $aes, $aesCalls, $aesLimit);
            if ($batch !== '') {
                $batches[$batch] = true;
            }
        }

        $list = array_keys($batches);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * Prefer stored classBatch; else fetch stud_class from getStudInfo4Placement and persist.
     *
     * @param array<string, mixed> $student
     */
    private function resolveStudentStudClass(
        array $student,
        StudentModel $studentModel,
        AesApiService $aes,
        int &$aesCalls,
        int $aesLimit
    ): string {
        $batch = trim((string) ($student['classBatch'] ?? ''));
        if ($batch !== '') {
            return $batch;
        }
        if ($aesCalls >= $aesLimit) {
            return '';
        }

        $admno = trim((string) ($student['registerNumber'] ?? ''));
        if ($admno === '') {
            return '';
        }

        $aesCalls++;
        $batch = $aes->studClassFromPlacementInfo($admno);
        if ($batch === '') {
            return '';
        }

        $id = (string) ($student['_id'] ?? '');
        if ($id !== '') {
            try {
                $studentModel->update($id, ['classBatch' => $batch]);
            } catch (\Throwable) {
                // Still expose the live AES label even if persist fails.
            }
        }

        return $batch;
    }

    /**
     * Placed students for year-filtered placement lists (AES classBatch + offer dates).
     *
     * @return list<array<string, mixed>>
     */
    private function campusPlacements(?string $departmentId): array
    {
        $filter = ['placed' => true];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return [];
            }
            $filter['departmentId'] = $deptOid;
        }

        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $appModel = new ApplicationModel();
        $companyModel = new CompanyModel();
        $studentModel = new StudentModel();
        $aes = new AesApiService();
        $deptCache = [];
        $rows = [];
        $aesCalls = 0;
        $aesLimit = 80;

        foreach ($studentModel->findAll($filter, 5000) as $student) {
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $deptId = (string) ($student['departmentId'] ?? '');
            if ($deptId !== '' && !isset($deptCache[$deptId])) {
                $dept = $deptModel->findById($deptId);
                $deptCache[$deptId] = $dept
                    ? (string) ($dept['code'] ?? $dept['name'] ?? '')
                    : '';
            }
            $deptCode = $deptCache[$deptId] ?? '';
            $classBatch = $this->resolveStudentStudClass($student, $studentModel, $aes, $aesCalls, $aesLimit);
            $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
            $company = trim((string) ($placement['company'] ?? ''));
            $role = trim((string) ($placement['role'] ?? ''));
            $package = trim((string) ($placement['package'] ?? ''));
            $joinDate = trim((string) ($placement['joinDate'] ?? ''));

            $selectedAt = '';
            foreach ($appModel->findByStudent((string) ($student['_id'] ?? '')) as $app) {
                if (($app['status'] ?? '') !== 'selected') {
                    continue;
                }
                $selectedAt = (string) ($app['updatedAt'] ?? $app['createdAt'] ?? '');
                foreach (($app['timeline'] ?? []) as $entry) {
                    if (($entry['status'] ?? '') === 'selected' && !empty($entry['at'])) {
                        $selectedAt = (string) $entry['at'];
                        break;
                    }
                }
                if ($company === '') {
                    $co = $companyModel->findById((string) ($app['companyId'] ?? ''));
                    $company = is_array($co) ? trim((string) ($co['companyName'] ?? '')) : '';
                }
                break;
            }

            $year = $this->placementYear($selectedAt, $joinDate, $classBatch);
            $rows[] = [
                'name'       => (string) ($user['name'] ?? 'Student'),
                'roll'       => (string) ($student['registerNumber'] ?? ''),
                'dept'       => $deptCode,
                'classBatch' => $classBatch,
                'company'    => $company !== '' ? $company : '—',
                'role'       => $role !== '' ? $role : '—',
                'package'    => $package !== '' ? $package : '—',
                'year'       => $year,
                'placedAt'   => $selectedAt !== '' ? $selectedAt : $joinDate,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $yearCmp = ((int) ($b['year'] ?? 0)) <=> ((int) ($a['year'] ?? 0));
            if ($yearCmp !== 0) {
                return $yearCmp;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }

    private function placementYear(string $selectedAt, string $joinDate, string $classBatch): int
    {
        foreach ([$selectedAt, $joinDate] as $date) {
            $date = trim($date);
            if ($date === '') {
                continue;
            }
            $ts = strtotime($date);
            if ($ts !== false) {
                return (int) date('Y', $ts);
            }
        }

        if (preg_match('/(\d{4})\s*[-–]\s*(\d{2,4})/', $classBatch, $m)) {
            $end = $m[2];
            if (strlen($end) === 2) {
                $end = substr($m[1], 0, 2) . $end;
            }

            return (int) $end;
        }
        if (preg_match('/(20\d{2})/', $classBatch, $m)) {
            return (int) $m[1];
        }

        return (int) date('Y');
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
        $companyModel = new CompanyModel();
        $rows = [];
        foreach ($byCompany as $companyId => $appIds) {
            $company = $companyModel->findById($companyId);
            $companyName = (string) ($company['companyName'] ?? 'Company');
            $lookup = array_flip($appIds);
            foreach ($appService->listEnriched($companyId) as $row) {
                $id = (string) ($row['id'] ?? $row['_id'] ?? '');
                if ($id !== '' && isset($lookup[$id])) {
                    $row['companyName'] = $companyName;
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
