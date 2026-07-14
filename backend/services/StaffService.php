<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\RecommendationModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Staff dashboard, students, and hiring analytics.
 */
final class StaffService
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function getDashboard(array $user): array
    {
        $ctx = StaffContext::resolve($user);
        StaffContext::requireDepartmentScope($ctx);
        $userId = (string) $user['_id'];
        $recModel = new RecommendationModel();
        $recs = $recModel->findByStaffUserId($userId);

        $statusCounts = ['pending' => 0, 'contacted' => 0, 'registered' => 0, 'rejected' => 0];
        foreach ($recs as $rec) {
            $status = (string) ($rec['status'] ?? 'pending');
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $analytics = new AnalyticsService();
        $deptId = $ctx['departmentId'] ?? null;
        $scopedBranchStats = $deptId
            ? $analytics->getDashboardAnalytics($deptId)['branchStatistics']
            : [];
        $deptCode = (string) ($ctx['department']['code'] ?? '');
        $deptRow = null;
        foreach ($scopedBranchStats as $row) {
            if ($row['code'] === $deptCode) {
                $deptRow = $row;
                break;
            }
        }

        $hiring = $this->hiringOverview($ctx);

        return [
            'recommendations' => [
                'total'      => count($recs),
                'pending'    => $statusCounts['pending'],
                'contacted'  => $statusCounts['contacted'],
                'registered' => $statusCounts['registered'],
                'rejected'   => $statusCounts['rejected'],
                'recent'     => array_map(
                    static fn (array $rec) => RecommendationModel::serializeForStaff($rec, $user),
                    array_slice($recs, 0, 5)
                ),
            ],
            'department' => [
                'code'       => $deptCode,
                'name'       => (string) ($ctx['department']['name'] ?? ''),
                'placementPct' => $deptRow['percentage'] ?? 0,
                'students'   => $deptRow['total'] ?? 0,
                'placed'     => $deptRow['placed'] ?? 0,
            ],
            'hiring' => $hiring['totals'],
            'branchStatistics' => $deptRow ? [$deptRow] : [],
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function hiringOverview(array $ctx, ?string $batchFilter = null, ?string $branchFilter = null): array
    {
        $departmentId = $ctx['departmentId'] ?? null;
        $batchFilter = trim((string) ($batchFilter ?? ''));
        $branchFilter = trim((string) ($branchFilter ?? ''));
        $studentOids = $this->studentObjectIdsInScope($ctx, $batchFilter !== '' ? $batchFilter : null, $branchFilter);
        $appModel = new ApplicationModel();
        $companyModel = new CompanyModel();
        $userModel = new UserModel();
        $studentModel = new StudentModel();
        $deptModel = new DepartmentModel();

        $appFilter = $studentOids !== [] ? ['studentId' => ['$in' => $studentOids]] : [];
        $applications = $appModel->findAll($appFilter, 5000);

        $companyIds = [];
        $shortlisted = 0;
        $offered = 0;
        $pipeline = ['applied' => 0, 'shortlisted' => 0, 'interview' => 0, 'offered' => 0, 'hired' => 0];

        foreach ($applications as $app) {
            $cid = (string) ($app['companyId'] ?? '');
            if ($cid !== '') {
                $companyIds[$cid] = true;
            }
            $status = (string) ($app['status'] ?? 'applied');
            if (in_array($status, ['shortlisted', 'company_review', 'officer_approved'], true)) {
                $shortlisted++;
            }
            if ($status === 'selected') {
                $offered++;
            }
            $bucket = match ($status) {
                'shortlisted', 'company_review' => 'shortlisted',
                'selected' => 'offered',
                'rejected', 'withdrawn' => 'applied',
                default => 'applied',
            };
            if (isset($pipeline[$bucket])) {
                $pipeline[$bucket]++;
            }
        }
        $pipeline['hired'] = $offered;

        $companies = [];
        foreach (array_keys($companyIds) as $companyId) {
            $company = $companyModel->findById($companyId);
            if (!$company) {
                continue;
            }
            $apps = $appModel->findAll(['companyId' => Security::toObjectId($companyId)], 500);
            if ($departmentId !== null) {
                $apps = array_values(array_filter($apps, function (array $app) use ($studentOids) {
                    $sid = (string) ($app['studentId'] ?? '');
                    return $sid !== '' && in_array($sid, $studentOids, true);
                }));
            }
            if ($apps === []) {
                continue;
            }
            $roles = [];
            foreach ($apps as $app) {
                $job = !empty($app['jobId']) ? (new JobModel())->findById((string) $app['jobId']) : null;
                $drive = !empty($app['driveId']) ? (new DriveModel())->findById((string) $app['driveId']) : null;
                $title = trim((string) ($job['title'] ?? $drive['title'] ?? $drive['role'] ?? ''));
                if ($title !== '') {
                    $roles[] = $title;
                }
            }
            $companies[] = [
                'company'     => $company['companyName'] ?? '',
                'roles'       => array_values(array_unique($roles)),
                'applicants'  => count($apps),
                'shortlisted' => count(array_filter($apps, fn ($a) => in_array($a['status'] ?? '', ['shortlisted', 'company_review', 'officer_approved'], true))),
                'selected'    => count(array_filter($apps, fn ($a) => ($a['status'] ?? '') === 'selected')),
                'status'      => 'Active',
                'statusCls'   => 'info',
            ];
        }

        $candidates = [];
        if ($departmentId !== null) {
            $officerData = new OfficerDataService();
            $students = $studentModel->findAll(StaffContext::studentCollectionFilter($ctx), 5000);
            foreach ($students as $student) {
                if (!StaffContext::studentMatchesScope($student, $ctx)) {
                    continue;
                }
                $user = $userModel->findById((string) ($student['userId'] ?? ''));
                $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
                if (!$this->studentMatchesBranchFilter($student, $user, $dept, $branchFilter)) {
                    continue;
                }
                if ($batchFilter !== '') {
                    $studentBatch = trim((string) ($student['classBatch'] ?? ''));
                    if ($studentBatch === '' || strcasecmp($studentBatch, $batchFilter) !== 0) {
                        continue;
                    }
                }
                $row = $officerData->enrichStudentListRow([], $student, $user, false);
                $apps = $appModel->findByStudent((string) $student['_id']);
                $latest = $apps[0] ?? null;
                $companyName = '';
                $roleTitle = '';
                if ($latest) {
                    $co = $companyModel->findById((string) ($latest['companyId'] ?? ''));
                    $companyName = is_array($co) ? (string) ($co['companyName'] ?? '') : '';
                    $job = !empty($latest['jobId']) ? (new JobModel())->findById((string) $latest['jobId']) : null;
                    $drive = !empty($latest['driveId']) ? (new DriveModel())->findById((string) $latest['driveId']) : null;
                    $roleTitle = trim((string) ($job['title'] ?? $drive['title'] ?? $drive['role'] ?? ''));
                }
                $displayName = trim((string) ($row['displayName'] ?? ($user['name'] ?? '')));
                $deptCode = trim((string) ($row['departmentCode'] ?? $dept['code'] ?? ''));
                $deptName = trim((string) ($row['departmentName'] ?? $dept['name'] ?? ''));
                $candidates[] = [
                    'name'       => $displayName !== '' ? $displayName : 'Student',
                    'roll'       => (string) ($student['registerNumber'] ?? ''),
                    'dept'       => $deptCode !== '' ? $deptCode : $deptName,
                    'classBatch' => (string) ($student['classBatch'] ?? ''),
                    'company'    => $companyName !== '' ? $companyName : '—',
                    'role'       => $roleTitle !== '' ? $roleTitle : '—',
                    'status'     => $this->candidatePipelineStatus($student, $latest),
                ];
            }
        }

        $extended = (new AnalyticsService())->getExtendedAnalytics($departmentId);
        $placements = (new RecruitingService())->listCampusPlacements($departmentId);
        if ($batchFilter !== '') {
            $placements = array_values(array_filter(
                $placements,
                static fn (array $row): bool => strcasecmp((string) ($row['classBatch'] ?? ''), $batchFilter) === 0
            ));
        }
        if ($branchFilter !== '') {
            $placements = array_values(array_filter(
                $placements,
                function (array $row) use ($branchFilter): bool {
                    $batch = (string) ($row['classBatch'] ?? '');
                    $dept = (string) ($row['dept'] ?? '');
                    if ($batch !== '' && $this->batchProgrammeFromLabel($batch) !== '') {
                        $targets = $this->branchFilterTargets($branchFilter);
                        $prog = $this->normalizeBranchTargetCode($this->batchProgrammeFromLabel($batch));
                        foreach ($targets as $target) {
                            if ($prog === $this->normalizeBranchTargetCode($target)) {
                                return true;
                            }
                        }
                    }
                    $targets = $this->branchFilterTargets($branchFilter);
                    $deptUp = strtoupper($dept);
                    foreach ($targets as $target) {
                        $resolved = $this->normalizeBranchTargetCode($target);
                        if ($deptUp === strtoupper($target) || $deptUp === $resolved) {
                            return true;
                        }
                    }

                    return $targets === [];
                }
            ));
        }

        return [
            'totals' => [
                'companiesHiring' => count($companies),
                'applicants'      => count($applications),
                'shortlisted'     => $shortlisted,
                'offers'          => $offered,
                'hired'           => $offered,
            ],
            'pipeline' => [
                ['label' => 'Applied', 'value' => $pipeline['applied']],
                ['label' => 'Shortlisted', 'value' => $pipeline['shortlisted']],
                ['label' => 'Interview', 'value' => $pipeline['interview']],
                ['label' => 'Offered', 'value' => $pipeline['offered']],
                ['label' => 'Hired', 'value' => $pipeline['hired']],
            ],
            'companies'    => $companies,
            'candidates'   => $candidates,
            'batchOptions' => $this->batchOptionsForScope($ctx, $branchFilter),
            'hiringTrend'  => $extended['hiringTrend'] ?? null,
            'hiringTrendLastYear' => $extended['hiringTrendLastYear'] ?? null,
            'placements'   => $placements,
        ];
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $latestApp
     */
    private function candidatePipelineStatus(array $student, ?array $latestApp): string
    {
        if (!empty($student['placed'])) {
            return 'placed';
        }
        if ($latestApp === null) {
            return 'applied';
        }
        $status = (string) ($latestApp['status'] ?? 'applied');
        if ($status === 'selected') {
            return 'selected';
        }
        if (in_array($status, ['shortlisted', 'company_review', 'officer_approved'], true)) {
            return 'shortlisted';
        }

        return 'applied';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(?string $departmentId, ?string $query = null): array
    {
        $filter = [];
        if ($departmentId !== null) {
            $oid = Security::toObjectId($departmentId);
            if ($oid) {
                $filter['departmentId'] = $oid;
            }
        }

        $students = (new StudentModel())->findAll($filter, 500);
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $rows = [];

        foreach ($students as $student) {
            $user = $userModel->findById((string) $student['userId']);
            $dept = $deptModel->findById((string) $student['departmentId']);
            $row = (new OfficerDataService())->enrichStudentListRow([], $student, $user, false);
            $displayName = (string) ($row['displayName'] ?? ($user['name'] ?? 'Student'));
            $photoUrl = (string) ($row['photoUrl'] ?? '');
            $academic = is_array($row['academic'] ?? null) ? $row['academic'] : (is_array($student['academic'] ?? null) ? $student['academic'] : []);
            $hasSelfPlacement = is_array($student['selfPlacement'] ?? null)
                && (string) ($student['selfPlacement']['companyName'] ?? '') !== '';
            $selfStatus = $hasSelfPlacement ? (string) ($student['selfPlacement']['status'] ?? '') : '';
            $isPlaced = !empty($student['placed']);
            $placementStatus = $isPlaced
                ? 'placed'
                : ($selfStatus === 'pending' ? 'pending_placement' : 'seeking');
            $collegeEmail = (string) ($row['collegeEmail'] ?? '');
            $personalEmail = (string) ($row['personalEmail'] ?? '');
            $userEmail = strtolower(trim((string) ($user['email'] ?? '')));
            $rows[] = [
                'id'              => (string) $student['_id'],
                'name'            => $displayName !== '' ? $displayName : 'Student',
                'email'           => $collegeEmail !== '' ? $collegeEmail : ($personalEmail !== '' ? $personalEmail : $userEmail),
                'collegeEmail'    => $collegeEmail,
                'personalEmail'   => $personalEmail,
                'phone'           => (string) ($row['phone'] ?? ''),
                'registerNumber'  => $student['registerNumber'] ?? '',
                'department'      => (string) ($row['departmentCode'] ?? $dept['code'] ?? ''),
                'departmentName'  => (string) ($row['departmentName'] ?? $dept['name'] ?? ''),
                'classBatch'      => (string) ($row['classBatch'] ?? $student['classBatch'] ?? ''),
                'cgpa'            => (float) ($academic['cgpa'] ?? 0) ?: null,
                'marks10th'       => (float) ($academic['marks10th'] ?? 0) ?: null,
                'marks12th'       => (float) ($academic['marks12th'] ?? $student['academic']['ugMarks'] ?? 0) ?: null,
                'ugMarks'         => (float) ($academic['ugMarks'] ?? $academic['marks12th'] ?? 0) ?: null,
                'backlogs'        => (int) ($academic['backlogs'] ?? 0),
                'placementStatus' => $placementStatus,
                'photoUrl'        => $photoUrl,
                'photo'           => $row['photo'] ?? null,
                'status'          => $user['status'] ?? 'active',
                'blacklisted'     => false,
                'blocked'         => ($user['status'] ?? '') === 'blocked',
            ];
        }

        return $this->filterStudentRows($rows, $query);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterStudentRows(array $rows, ?string $query): array
    {
        $query = trim((string) ($query ?? ''));
        if ($query === '') {
            return $rows;
        }
        $tokens = preg_split('/\s+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($tokens): bool {
                $hay = strtolower(implode(' ', array_filter([
                    (string) ($row['registerNumber'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    (string) ($row['email'] ?? ''),
                    (string) ($row['department'] ?? ''),
                    (string) ($row['departmentName'] ?? ''),
                    (string) ($row['classBatch'] ?? ''),
                ], static fn (string $v): bool => $v !== '')));

                foreach ($tokens as $token) {
                    if (!str_contains($hay, $token)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function studentPipeline(string $studentId): array
    {
        $service = new OfficerDataService();
        $student = $service->resolveStudentRef($studentId);
        if (!$student) {
            return [];
        }

        return $service->buildStudentPipeline($student);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function batchOptionsForScope(array $ctx, string $branchFilter = ''): array
    {
        $branchFilter = trim($branchFilter);
        $assigned = StaffContext::assignedClassBatches($ctx);
        $aesBatches = $this->fetchAesClassBatchesForScope($ctx, $branchFilter);
        $merged = array_values(array_unique(array_merge($assigned, $aesBatches)));

        if ($merged !== []) {
            $filtered = $this->filterBatchLabelsForBranch($merged, $branchFilter);
            sort($filtered, SORT_NATURAL | SORT_FLAG_CASE);

            return $filtered;
        }

        $batches = [];
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $studentModel = new StudentModel();
        $aes = new AesApiService();
        $aesCalls = 0;
        $aesLimit = 80;
        foreach ($studentModel->findAll(StaffContext::studentCollectionFilter($ctx), 5000) as $student) {
            if (!StaffContext::studentMatchesScope($student, $ctx)) {
                continue;
            }
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
            if (!$this->studentMatchesBranchFilter($student, $user, $dept, $branchFilter)) {
                continue;
            }
            $batch = trim((string) ($student['classBatch'] ?? ''));
            if ($batch === '' && $aesCalls < $aesLimit) {
                $admno = trim((string) ($student['registerNumber'] ?? ''));
                if ($admno !== '') {
                    $aesCalls++;
                    $batch = $aes->studClassFromPlacementInfo($admno);
                    if ($batch !== '') {
                        $id = (string) ($student['_id'] ?? '');
                        if ($id !== '') {
                            try {
                                $studentModel->update($id, ['classBatch' => $batch]);
                            } catch (\Throwable) {
                            }
                        }
                    }
                }
            }
            if ($batch !== '') {
                $batches[$batch] = true;
            }
        }

        $list = array_keys($batches);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @return list<string>
     */
    private function branchFilterTargets(string $branchFilter): array
    {
        $branchFilter = trim($branchFilter);
        if ($branchFilter === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($part) => strtoupper(trim((string) $part)),
            preg_split('/\|/', $branchFilter) ?: []
        ), static fn ($part) => $part !== ''));
    }

    /**
     * @param list<string> $batches
     * @return list<string>
     */
    private function filterBatchLabelsForBranch(array $batches, string $branchFilter): array
    {
        $targets = $this->branchFilterTargets($branchFilter);
        if ($targets === []) {
            return $batches;
        }

        $resolvedTargets = array_values(array_unique(array_filter(array_map(
            fn (string $target) => $this->normalizeBranchTargetCode($target),
            $targets
        ), static fn (string $code) => $code !== '')));

        return array_values(array_filter($batches, function (string $batch) use ($resolvedTargets): bool {
            $batchProg = $this->batchProgrammeFromLabel($batch);
            if ($batchProg === '') {
                if (count($resolvedTargets) === 1 && (
                    preg_match('/^\d{4}/', trim($batch)) === 1
                    || preg_match('/\d{4}\s*[-–/]\s*\d{2,4}/', $batch) === 1
                )) {
                    return true;
                }

                return false;
            }
            foreach ($resolvedTargets as $target) {
                if ($batchProg === $target) {
                    return true;
                }
            }

            return false;
        }));
    }

  /**
   * Map a batch label (e.g. MCA2025-27-S3) to its programme code (MCA, BCA, INMCA).
   */
    private function batchProgrammeFromLabel(string $batch): string
    {
        $label = strtoupper(preg_replace('/[^A-Z0-9]/', '', $batch) ?? '');
        if ($label === '') {
            return '';
        }

        $programmes = [];
        foreach (DepartmentProgrammeCatalog::groups() as $group) {
            foreach ($group['programmes'] as $programme) {
                $programmes[] = [
                    DepartmentProgrammeCatalog::normalizeCode($programme['code']),
                    array_map(
                        static fn (string $alias) => DepartmentProgrammeCatalog::normalizeCode($alias),
                        $programme['aliases']
                    ),
                ];
            }
        }
        usort($programmes, static fn (array $a, array $b) => strlen($b[0]) <=> strlen($a[0]));

        foreach ($programmes as [$code, $aliases]) {
            $codes = array_merge([$code], $aliases);
            usort($codes, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
            foreach ($codes as $candidate) {
                if ($candidate !== '' && ($label === $candidate || str_starts_with($label, $candidate))) {
                    return $code;
                }
            }
        }

        if (preg_match('/^\d{4}/', trim($batch)) === 1 || preg_match('/\d{4}\s*[-–/]\s*\d{2,4}/', $batch) === 1) {
            return '';
        }

        return DepartmentProgrammeCatalog::resolveProgrammeCode($label);
    }

    private function normalizeBranchTargetCode(string $branch): string
    {
        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($branch);

        return $resolved !== '' ? $resolved : strtoupper(preg_replace('/[^A-Z0-9]/', '', $branch) ?? '');
    }

    /**
     * Load class batches from AES for the staff department scope.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function refreshAssignedClassBatchesFromAes(array $ctx): array
    {
        $batches = (new PlacementFilterService())->fetchBatchOptions($ctx, '', '');
        if ($batches === []) {
            return [];
        }

        $profileId = (string) ($ctx['profile']['_id'] ?? '');
        if ($profileId !== '') {
            (new StaffModel())->updateProfile($profileId, ['assignedClassBatches' => $batches]);
        }

        return $batches;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function fetchAesClassBatchesForScope(array $ctx, string $branchFilter): array
    {
        return (new PlacementFilterService())->fetchBatchOptions($ctx, $branchFilter, '');
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $dept
     */
    private function studentMatchesBranchFilter(array $student, ?array $user, ?array $dept, string $branchFilter): bool
    {
        $targets = $this->branchFilterTargets($branchFilter);
        if ($targets === []) {
            return true;
        }

        $row = (new OfficerDataService())->enrichStudentListRow([], $student, $user, false);
        $deptCode = strtoupper(trim((string) ($row['departmentCode'] ?? $dept['code'] ?? '')));
        $deptName = strtoupper(trim((string) ($row['departmentName'] ?? $dept['name'] ?? '')));
        $batch = strtoupper(trim((string) ($student['classBatch'] ?? $row['classBatch'] ?? '')));

        foreach ($targets as $target) {
            $resolvedTarget = $this->normalizeBranchTargetCode($target);
            if ($deptCode === $target || $deptName === $target || $deptCode === $resolvedTarget || $deptName === $resolvedTarget) {
                return true;
            }
            if ($batch !== '') {
                $batchProg = $this->batchProgrammeFromLabel($batch);
                if ($batchProg !== '' && $batchProg === $resolvedTarget) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, string>
     */
    private function studentObjectIdsInScope(array $ctx, ?string $batchFilter, string $branchFilter = ''): array
    {
        $students = (new StudentModel())->findAll(StaffContext::studentCollectionFilter($ctx), 5000);
        $ids = [];
        $batchFilter = trim((string) ($batchFilter ?? ''));
        $branchFilter = trim($branchFilter);
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();

        foreach ($students as $student) {
            if (!StaffContext::studentMatchesScope($student, $ctx)) {
                continue;
            }
            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
            if (!$this->studentMatchesBranchFilter($student, $user, $dept, $branchFilter)) {
                continue;
            }
            if ($batchFilter !== '') {
                $studentBatch = trim((string) ($student['classBatch'] ?? ''));
                if ($studentBatch === '' || strcasecmp($studentBatch, $batchFilter) !== 0) {
                    continue;
                }
            }
            if (!empty($student['_id'])) {
                $ids[] = (string) $student['_id'];
            }
        }

        return $ids;
    }
}
