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
     * @param array<string, mixed>|null $filterCtx
     * @return array<string, mixed>
     */
    public function getCampusOverview(?string $departmentId = null, ?array $filterCtx = null, bool $lite = false): array
    {
        $activeCompanies = $this->activeRecruitingCompanies($departmentId);

        if ($lite) {
            $applicantCount = $this->countCampusApplicants($departmentId);
            $byDept = $this->applicantsByDepartmentCounts($departmentId);
            $placedCount = $this->countCampusPlacements($departmentId);

            return [
                'scope'            => ($departmentId !== null && $departmentId !== '') ? 'department' : 'campus',
                'stats'            => [
                    'activeCompanies' => count($activeCompanies),
                    'applicants'      => $applicantCount,
                    'departments'     => count($byDept),
                    'placedStudents'  => $placedCount,
                ],
                'activeCompanies'  => $activeCompanies,
                'applicantsByDept' => $byDept,
                'applicants'       => [],
                'batchOptions'     => $this->campusBatchOptionsLocal($departmentId),
                'placements'       => [],
                'lite'             => true,
            ];
        }

        $applicants = $this->campusApplicants($departmentId);
        $byDept = $this->applicantsByDepartment($applicants);
        // Local classBatch only — AES directory fetch is too slow for dashboard hydrate.
        $batchOptions = $this->campusBatchOptionsLocal($departmentId);
        $placements = $this->listCampusPlacements($departmentId);

        return [
            'scope'            => ($departmentId !== null && $departmentId !== '') ? 'department' : 'campus',
            'stats'            => [
                'activeCompanies' => count($activeCompanies),
                'applicants'      => count($applicants),
                'departments'     => count($byDept),
                'placedStudents'  => count($placements),
            ],
            'activeCompanies'  => $activeCompanies,
            'applicantsByDept' => $byDept,
            'applicants'       => $applicants,
            'batchOptions'     => $batchOptions,
            'placements'       => $placements,
            'lite'             => false,
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
        // Dashboard overview must stay fast — never fan out to AES for missing classBatch.
        $aes = new AesApiService();
        $aesCalls = 0;
        $aesLimit = 0;

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
     * Local classBatch labels only (no AES directory) — for dashboard first paint.
     *
     * @return list<string>
     */
    private function campusBatchOptionsLocal(?string $departmentId): array
    {
        $filter = [];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return [];
            }
            $filter['departmentId'] = $deptOid;
        }

        $batches = [];
        foreach ((new StudentModel())->findAll($filter, 8000) as $student) {
            $batch = trim((string) ($student['classBatch'] ?? ''));
            if ($batch !== '') {
                $batches[$batch] = true;
            }
        }
        $list = array_keys($batches);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    private function countCampusApplicants(?string $departmentId): int
    {
        $filter = $this->campusApplicantFilter($departmentId);
        if ($filter === null) {
            return 0;
        }

        return (new ApplicationModel())->count($filter);
    }

    private function countCampusPlacements(?string $departmentId): int
    {
        $filter = ['placed' => true];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return 0;
            }
            $filter['departmentId'] = $deptOid;
        }

        return (new StudentModel())->count($filter);
    }

    /**
     * Dept applicant counts without per-row enrichment (dashboard lite).
     *
     * @return array<int, array<string, mixed>>
     */
    private function applicantsByDepartmentCounts(?string $departmentId): array
    {
        $filter = $this->campusApplicantFilter($departmentId);
        if ($filter === null) {
            return [];
        }

        $apps = (new ApplicationModel())->findAll($filter, 5000);
        $studentIds = [];
        foreach ($apps as $app) {
            $sid = trim((string) ($app['studentId'] ?? ''));
            if ($sid !== '') {
                $studentIds[$sid] = true;
            }
        }
        $students = (new StudentModel())->findByIds(array_keys($studentIds));
        $deptModel = new DepartmentModel();
        $deptCache = [];
        $totals = [];
        foreach ($apps as $app) {
            $sid = trim((string) ($app['studentId'] ?? ''));
            $student = $sid !== '' ? ($students[$sid] ?? null) : null;
            $deptId = is_array($student) ? trim((string) ($student['departmentId'] ?? '')) : '';
            $dept = 'Unknown';
            if ($deptId !== '') {
                if (!isset($deptCache[$deptId])) {
                    $row = $deptModel->findById($deptId);
                    $deptCache[$deptId] = is_array($row)
                        ? trim((string) ($row['code'] ?? $row['name'] ?? ''))
                        : '';
                }
                $dept = $deptCache[$deptId] !== '' ? $deptCache[$deptId] : 'Unknown';
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
        usort($result, static fn (array $a, array $b): int => ($b['applicants'] <=> $a['applicants']));

        return $result;
    }

    /**
     * @return array<string, mixed>|null null when department filter is invalid
     */
    private function campusApplicantFilter(?string $departmentId): ?array
    {
        $filter = [];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOid = Security::toObjectId($departmentId);
            if ($deptOid === null) {
                return null;
            }
            $studentIds = [];
            foreach ((new StudentModel())->findAll(['departmentId' => $deptOid], 5000) as $student) {
                $studentIds[] = $student['_id'];
            }
            if ($studentIds === []) {
                return null;
            }
            $filter['studentId'] = ['$in' => $studentIds];
        }

        return $filter;
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

        $students = (new StudentModel())->findAll($filter, 5000);
        if ($students === []) {
            return [];
        }

        $userIds = [];
        $deptIds = [];
        foreach ($students as $student) {
            $uid = trim((string) ($student['userId'] ?? ''));
            $did = trim((string) ($student['departmentId'] ?? ''));
            if ($uid !== '') {
                $userIds[$uid] = true;
            }
            if ($did !== '') {
                $deptIds[$did] = true;
            }
        }
        $users = (new UserModel())->findByIds(array_keys($userIds));
        $departments = (new DepartmentModel())->findByIds(array_keys($deptIds));

        $rows = [];
        $seenRolls = [];

        foreach ($students as $student) {
            $roll = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
            $userId = (string) ($student['userId'] ?? '');

            if ($userId !== '' && isset($seenRolls['u:' . $userId])) {
                continue;
            }
            if ($roll !== '' && isset($seenRolls['r:' . $roll])) {
                continue;
            }

            $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
            $company = trim((string) ($placement['company'] ?? $placement['companyName'] ?? ''));
            $role = trim((string) ($placement['role'] ?? ''));
            $package = trim((string) ($placement['package'] ?? ''));
            $joinDate = trim((string) ($placement['joinDate'] ?? $placement['placedAt'] ?? $placement['date'] ?? ''));
            if ($company === '') {
                continue;
            }

            // Prefer dates on the current placement — skip per-student application scans.
            $placedAt = $joinDate;
            if ($placedAt === '') {
                $placedAt = trim((string) ($student['placedAt'] ?? $student['updatedAt'] ?? $student['createdAt'] ?? ''));
            }
            if ($placedAt === '') {
                continue;
            }
            $year = $this->placementYear($placedAt, $joinDate, '');
            if ($year <= 0) {
                continue;
            }

            $user = $userId !== '' ? ($users[$userId] ?? null) : null;
            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $deptId !== '' ? ($departments[$deptId] ?? null) : null;
            $deptCode = is_array($dept) ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '';
            $classBatch = trim((string) ($student['classBatch'] ?? ''));

            if ($userId !== '') {
                $seenRolls['u:' . $userId] = true;
            }
            if ($roll !== '') {
                $seenRolls['r:' . $roll] = true;
            }

            $rows[] = [
                'name'       => (string) ($user['name'] ?? 'Student'),
                'roll'       => (string) ($student['registerNumber'] ?? ''),
                'dept'       => $deptCode,
                'classBatch' => $classBatch,
                'company'    => $company,
                'role'       => $role !== '' ? $role : '—',
                'package'    => $package !== '' ? $package : '—',
                'year'       => $year,
                'placedAt'   => $placedAt,
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

        // Unknown year — exclude from year-scoped Hired totals (don't invent "this year").
        return 0;
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
                    'package'      => (string) ($drive['eligibility']['package'] ?? $drive['tier'] ?? ''),
                    'applicants'   => 0,
                    'status'       => (string) ($drive['status'] ?? 'open'),
                ];
            }
            $byCompany[$companyId]['openRoles']++;
            if ($byCompany[$companyId]['package'] === '' && !empty($drive['eligibility']['package'])) {
                $byCompany[$companyId]['package'] = (string) $drive['eligibility']['package'];
            }
        }

        foreach ($jobModel->findAll(['status' => ['$in' => ['open', 'ongoing', 'reviewing']]], 500) as $job) {
            $companyId = (string) ($job['companyId'] ?? '');
            if ($companyId === '') {
                continue;
            }
            if (!isset($byCompany[$companyId])) {
                $company = $companyModel->findById($companyId);
                $byCompany[$companyId] = [
                    'companyId'  => $companyId,
                    'company'    => (string) ($company['companyName'] ?? 'Company'),
                    'openRoles'  => 0,
                    'package'    => (string) ($job['package'] ?? ''),
                    'applicants' => 0,
                    'status'     => (string) ($job['status'] ?? 'open'),
                ];
            }
            $byCompany[$companyId]['openRoles']++;
            if ($byCompany[$companyId]['package'] === '' && !empty($job['package'])) {
                $byCompany[$companyId]['package'] = (string) $job['package'];
            }
        }

        $deptStudentOids = [];
        if ($departmentId !== null && $departmentId !== '') {
            $deptOidForApps = Security::toObjectId($departmentId);
            if ($deptOidForApps !== null) {
                foreach ((new StudentModel())->findAll(['departmentId' => $deptOidForApps], 5000) as $student) {
                    if (isset($student['_id'])) {
                        $deptStudentOids[] = $student['_id'];
                    }
                }
            }
        }

        foreach (array_keys($byCompany) as $companyId) {
            $appFilter = ['companyId' => Security::toObjectId($companyId)];
            if ($departmentId !== null && $departmentId !== '') {
                $appFilter['studentId'] = ['$in' => $deptStudentOids];
            }
            $byCompany[$companyId]['applicants'] = $appModel->count($appFilter);
        }

        return array_values($byCompany);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function campusApplicants(?string $departmentId): array
    {
        $filter = $this->campusApplicantFilter($departmentId);
        if ($filter === null) {
            return [];
        }

        $apps = (new ApplicationModel())->findAll($filter, 5000);
        if ($apps === []) {
            return [];
        }

        $companyIds = [];
        foreach ($apps as $app) {
            $cid = trim((string) ($app['companyId'] ?? ''));
            if ($cid !== '') {
                $companyIds[$cid] = true;
            }
        }
        $companies = (new CompanyModel())->findByIds(array_keys($companyIds));

        // One batched enrich for all apps — never call listEnriched per company.
        $rows = (new CompanyApplicationService())->enrichApplicationRows($apps, null, '', false);
        foreach ($rows as &$row) {
            $cid = trim((string) ($row['companyId'] ?? ''));
            $company = $cid !== '' ? ($companies[$cid] ?? null) : null;
            $row['companyName'] = is_array($company)
                ? (string) ($company['companyName'] ?? 'Company')
                : (string) ($row['companyName'] ?? 'Company');
        }
        unset($row);

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
