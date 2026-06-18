<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Middleware\RBACMiddleware;
use PMS\Models\ApplicationModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Department-scoped data access and enrichment for placement officers.
 */
final class OfficerDataService
{
    /**
     * @return array{user: array<string, mixed>, ctx: array<string, mixed>}
     */
    public function requireScope(): array
    {
        $user = RBACMiddleware::requirePlacementOfficer();
        $ctx = PlacementOfficerContext::resolve($user);
        return ['user' => $user, 'ctx' => $ctx];
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function applicationFilter(array $ctx, array $filter = []): array
    {
        if ($ctx['isAdmin']) {
            return $filter;
        }

        $studentIds = PlacementOfficerContext::studentIdsInDepartment($ctx);
        if ($studentIds === []) {
            return ['studentId' => ['$in' => []]];
        }

        $oids = array_values(array_filter(array_map(
            fn (string $id) => Security::toObjectId($id),
            $studentIds
        )));
        $filter['studentId'] = ['$in' => $oids];
        return $filter;
    }

    /**
     * @param array<int, array<string, mixed>> $apps
     * @return array<int, array<string, mixed>>
     */
    public function enrichApplications(array $apps): array
    {
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $companyModel = new CompanyModel();
        $deptModel = new DepartmentModel();
        $driveModel = new DriveModel();

        $rows = [];
        foreach ($apps as $app) {
            $student = $studentModel->findById((string) ($app['studentId'] ?? ''));
            $user = $student ? $userModel->findById((string) ($student['userId'] ?? '')) : null;
            $company = $companyModel->findById((string) ($app['companyId'] ?? ''));
            $drive = $driveModel->findById((string) ($app['driveId'] ?? ''));
            $dept = $student ? $deptModel->findById((string) ($student['departmentId'] ?? '')) : null;

            $status = $app['status'] ?? 'applied';
            $stage = match ($status) {
                'applied', 'resume_pending' => 'resume_verification',
                'resume_verified' => 'resume_verification',
                'officer_approved' => 'approval',
                'company_review', 'shortlisted' => 'company_selection',
                'selected' => 'company_selection',
                'rejected' => 'rejected',
                default => $status,
            };

            $row = DocumentHelper::serialize($app) ?? [];
            $row['studentName'] = $user['name'] ?? '';
            $row['registerNumber'] = $student['registerNumber'] ?? '';
            $row['department'] = $dept['code'] ?? $dept['name'] ?? '';
            $row['company'] = $company['companyName'] ?? '';
            $row['role'] = $drive['title'] ?? '';
            $row['stage'] = $stage;
            $row['appliedAt'] = $row['createdAt'] ?? null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function listApplications(array $ctx, array $filter = []): array
    {
        $filter = $this->applicationFilter($ctx, $filter);
        if (isset($filter['studentId']['$in']) && $filter['studentId']['$in'] === []) {
            return [];
        }

        $apps = (new ApplicationModel())->findAll($filter, 500);
        return $this->enrichApplications($apps);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listStudents(array $ctx): array
    {
        $studentModel = new StudentModel();
        $deptModel = new DepartmentModel();
        $userModel = new UserModel();

        $departments = [];
        foreach ($deptModel->findAll([], 200) as $d) {
            $departments[(string) $d['_id']] = $d;
        }

        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $rows = [];
        foreach ($studentModel->findAll($filter, 500) as $s) {
            $userId = (string) ($s['userId'] ?? '');
            $deptId = (string) ($s['departmentId'] ?? '');
            $u = $userId ? $userModel->findById($userId) : null;
            $dept = $departments[$deptId] ?? null;

            $row = DocumentHelper::serialize($s) ?? [];
            $row['user'] = $u ? DocumentHelper::serialize($u) : null;
            $row['department'] = $dept ? [
                'id'   => (string) $dept['_id'],
                'name' => $dept['name'] ?? '',
                'code' => $dept['code'] ?? '',
            ] : null;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    public function listPendingResumes(array $ctx): array
    {
        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $deptModel = new DepartmentModel();
        $filter = PlacementOfficerContext::studentCollectionFilter($ctx);

        $rows = [];
        foreach ($studentModel->findAll($filter, 500) as $student) {
            $resume = $student['resume'] ?? null;
            if (!$resume || empty($resume['path']) || !empty($resume['verified'])) {
                continue;
            }

            $user = $userModel->findById((string) ($student['userId'] ?? ''));
            $dept = $deptModel->findById((string) ($student['departmentId'] ?? ''));
            $rows[] = [
                'id'             => (string) $student['_id'],
                'studentId'      => (string) $student['_id'],
                'studentName'    => $user['name'] ?? '',
                'registerNumber' => $student['registerNumber'] ?? '',
                'department'     => $dept['code'] ?? $dept['name'] ?? '',
                'fileName'       => $resume['filename'] ?? basename((string) ($resume['path'] ?? '')),
                'validFormat'    => true,
                'status'         => 'pending',
                'submittedAt'    => $resume['uploadedAt'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function listResults(array $ctx, array $filter = []): array
    {
        $results = (new RecruitmentResultModel())->list($filter, 500);
        if ($ctx['isAdmin']) {
            return DocumentHelper::serializeMany($results);
        }

        $allowed = array_flip(PlacementOfficerContext::registerNumbersInDepartment($ctx));
        $filtered = array_values(array_filter(
            $results,
            fn (array $r) => isset($allowed[strtoupper((string) ($r['registerNumber'] ?? ''))])
        ));

        return DocumentHelper::serializeMany($filtered);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public function dashboardStats(array $ctx): array
    {
        $studentModel = new StudentModel();
        $applicationModel = new ApplicationModel();
        $driveModel = new DriveModel();

        $studentFilter = PlacementOfficerContext::studentCollectionFilter($ctx);
        $totalStudents = $studentModel->count($studentFilter);
        $placedStudents = $studentModel->count(array_merge($studentFilter, ['placed' => true]));
        $pendingStudents = 0;

        if ($ctx['isAdmin']) {
            $pendingStudents = (new UserModel())->count(['role' => 'student', 'approved' => false]);
        } else {
            $userIds = PlacementOfficerContext::userIdsInDepartment($ctx);
            if ($userIds !== []) {
                $oids = array_values(array_filter(array_map(
                    fn (string $id) => Security::toObjectId($id),
                    $userIds
                )));
                $pendingStudents = (new UserModel())->count([
                    'role'     => 'student',
                    'approved' => false,
                    '_id'      => ['$in' => $oids],
                ]);
            }
        }

        $appFilter = $this->applicationFilter($ctx, [
            'status' => ['$in' => ['applied', 'resume_verified', 'resume_pending']],
        ]);
        $pendingApplications = isset($appFilter['studentId']['$in']) && $appFilter['studentId']['$in'] === []
            ? 0
            : $applicationModel->count($appFilter);

        $pendingResumes = count($this->listPendingResumes($ctx));

        if ($ctx['isAdmin']) {
            $activeDrives = $driveModel->count(['status' => ['$ne' => 'closed']]);
        } else {
            $deptOid = Security::toObjectId($ctx['departmentId']);
            $deptCode = $ctx['department']['code'] ?? '';
            $or = [['departmentId' => $deptOid], ['branches' => []]];
            if ($deptCode !== '') {
                $or[] = ['branches' => $deptCode];
            }
            $activeDrives = $driveModel->count(['$or' => $or, 'status' => ['$ne' => 'closed']]);
        }

        return [
            'totalStudents'       => $totalStudents,
            'placedStudents'      => $placedStudents,
            'placementPercentage' => $totalStudents > 0
                ? round(($placedStudents / $totalStudents) * 100, 1)
                : 0,
            'pendingApprovals'    => $pendingStudents + $pendingResumes,
            'pendingStudents'     => $pendingStudents,
            'pendingApplications' => $pendingApplications,
            'pendingResumes'      => $pendingResumes,
            'activeDrives'        => $activeDrives,
            'department'          => $ctx['department']
                ? DocumentHelper::serialize($ctx['department'])
                : null,
        ];
    }

    public function assertApplicationInScope(string $appId, array $ctx): array
    {
        $app = (new ApplicationModel())->findById($appId);
        if (!$app) {
            Response::notFound('Application not found.');
        }
        PlacementOfficerContext::assertStudentInDepartment((string) ($app['studentId'] ?? ''), $ctx);
        return $app;
    }

    public function assertResultRegisterInScope(string $registerNumber, array $ctx): void
    {
        if ($ctx['isAdmin']) {
            return;
        }
        $student = (new StudentModel())->findByRegisterNumber($registerNumber);
        if (!$student || (string) ($student['departmentId'] ?? '') !== $ctx['departmentId']) {
            Response::forbidden('This student does not belong to your department.');
        }
    }
}
