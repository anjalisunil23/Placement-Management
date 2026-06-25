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
            $companies[] = [
                'company'     => $company['companyName'] ?? '',
                'applicants'  => count($apps),
                'shortlisted' => count(array_filter($apps, fn ($a) => in_array($a['status'] ?? '', ['shortlisted', 'company_review', 'officer_approved'], true))),
                'selected'    => count(array_filter($apps, fn ($a) => ($a['status'] ?? '') === 'selected')),
                'status'      => 'Active',
                'statusCls'   => 'info',
            ];
        }

        $candidates = [];
        if ($departmentId !== null) {
            $students = $studentModel->findAll(['departmentId' => Security::toObjectId($departmentId)], 200);
            foreach ($students as $student) {
                $user = $userModel->findById((string) $student['userId']);
                $dept = $deptModel->findById((string) $student['departmentId']);
                $apps = $appModel->findByStudent((string) $student['_id']);
                $latest = $apps[0] ?? null;
                $companyName = '';
                $role = '';
                if ($latest) {
                    $co = $companyModel->findById((string) ($latest['companyId'] ?? ''));
                    $companyName = $co['companyName'] ?? '';
                    $job = !empty($latest['jobId']) ? (new JobModel())->findById((string) $latest['jobId']) : null;
                    $drive = !empty($latest['driveId']) ? (new DriveModel())->findById((string) $latest['driveId']) : null;
                    $role = $job['title'] ?? $drive['title'] ?? '';
                }
                $candidates[] = [
                    'name'    => $user['name'] ?? 'Student',
                    'roll'    => $student['registerNumber'] ?? '',
                    'dept'    => $dept['code'] ?? '',
                    'company' => $companyName,
                    'role'    => $role,
                    'status'  => !empty($student['placed']) ? 'placed' : ($latest['status'] ?? 'applied'),
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
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(?string $departmentId): array
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
            $rows[] = [
                'id'              => (string) $student['_id'],
                'name'            => $user['name'] ?? 'Student',
                'email'           => $user['email'] ?? '',
                'registerNumber'  => $student['registerNumber'] ?? '',
                'department'      => $dept['code'] ?? '',
                'classBatch'      => (string) ($student['classBatch'] ?? $student['personal']['batch'] ?? ''),
                'cgpa'            => (float) ($student['academic']['cgpa'] ?? 0),
                'placementStatus' => !empty($student['placed']) ? 'placed' : 'seeking',
                'status'          => $user['status'] ?? 'active',
                'blacklisted'     => false,
                'blocked'         => ($user['status'] ?? '') === 'blocked',
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function studentPipeline(string $studentId): array
    {
        $apps = (new ApplicationModel())->findByStudent($studentId);
        $companyModel = new CompanyModel();
        $jobModel = new JobModel();
        $driveModel = new DriveModel();
        $rows = [];

        foreach ($apps as $app) {
            $company = $companyModel->findById((string) ($app['companyId'] ?? ''));
            $job = !empty($app['jobId']) ? $jobModel->findById((string) $app['jobId']) : null;
            $drive = !empty($app['driveId']) ? $driveModel->findById((string) $app['driveId']) : null;
            $status = (string) ($app['status'] ?? 'applied');
            $rows[] = [
                'company'        => $company['companyName'] ?? '',
                'role'           => $job['title'] ?? $drive['title'] ?? '',
                'stage'          => $status,
                'status'         => $status,
                'appliedAt'      => DocumentHelper::serialize($app)['createdAt'] ?? '',
                'registerNumber' => '',
            ];
        }

        return $rows;
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
