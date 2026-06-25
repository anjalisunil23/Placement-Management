<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\RecommendationModel;
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
        $allBranchStats = $analytics->getDashboardAnalytics(null)['branchStatistics'];
        $scopedBranchStats = $deptId
            ? $analytics->getDashboardAnalytics($deptId)['branchStatistics']
            : $allBranchStats;
        $deptCode = (string) ($ctx['department']['code'] ?? '');
        $deptRow = null;
        foreach ($scopedBranchStats as $row) {
            if ($row['code'] === $deptCode) {
                $deptRow = $row;
                break;
            }
        }
        if ($deptRow === null) {
            foreach ($allBranchStats as $row) {
                if ($row['code'] === $deptCode) {
                    $deptRow = $row;
                    break;
                }
            }
        }

        $hiring = $this->hiringOverview($ctx['departmentId']);

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
            'branchStatistics' => $allBranchStats,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hiringOverview(?string $departmentId): array
    {
        $studentOids = $this->studentObjectIdsForDepartment($departmentId);
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
            $students = $studentModel->findAll(['departmentId' => Security::toObjectId($departmentId)], 500);
            foreach ($students as $student) {
                $user = $userModel->findById((string) ($student['userId'] ?? ''));
                $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
                $row = $officerData->enrichStudentListRow([], $student, $user);
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
                    'name'    => $displayName !== '' ? $displayName : 'Student',
                    'roll'    => (string) ($student['registerNumber'] ?? ''),
                    'dept'    => $deptCode !== '' ? $deptCode : $deptName,
                    'company' => $companyName !== '' ? $companyName : '—',
                    'role'    => $roleTitle !== '' ? $roleTitle : '—',
                    'status'  => $this->candidatePipelineStatus($student, $latest),
                ];
            }
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
            'companies'  => $companies,
            'candidates' => $candidates,
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
            $row = (new OfficerDataService())->enrichStudentListRow([], $student, $user);
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
     * @return array<int, string>
     */
    private function studentObjectIdsForDepartment(?string $departmentId): array
    {
        if ($departmentId === null) {
            return [];
        }
        $id = Security::toObjectId($departmentId);
        if ($id === null) {
            return [];
        }
        $students = (new StudentModel())->findAll(['departmentId' => $id], 5000);
        $ids = [];
        foreach ($students as $student) {
            if (!empty($student['_id'])) {
                $ids[] = (string) $student['_id'];
            }
        }
        return $ids;
    }
}
