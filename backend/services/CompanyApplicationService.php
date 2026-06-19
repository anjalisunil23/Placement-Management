<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\ApplicationModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

final class CompanyApplicationService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(string $companyId, array $filters = []): array
    {
        $apps = (new ApplicationModel())->findByCompany($companyId);
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $apps = array_values(array_filter(
                $apps,
                static fn (array $a) => ($a['status'] ?? '') === $filters['status']
            ));
        }
        if (!empty($filters['driveId'])) {
            $driveId = (string) $filters['driveId'];
            $apps = array_values(array_filter(
                $apps,
                static fn (array $a) => (string) ($a['driveId'] ?? '') === $driveId
            ));
        }

        $studentModel = new StudentModel();
        $userModel = new UserModel();
        $driveModel = new DriveModel();
        $jobModel = new JobModel();
        $deptModel = new DepartmentModel();
        $deptCache = [];

        $minCgpa = isset($filters['minCgpa']) ? (float) $filters['minCgpa'] : null;

        $rows = [];
        foreach ($apps as $app) {
            $student = $studentModel->findById((string) $app['studentId']);
            if (!$student) {
                continue;
            }
            $cgpa = (float) ($student['academic']['cgpa'] ?? 0);
            if ($minCgpa !== null && $cgpa < $minCgpa) {
                continue;
            }
            if (!empty($filters['branch'])) {
                $deptCode = $this->departmentCode($student, $deptModel, $deptCache);
                if (strcasecmp($deptCode, (string) $filters['branch']) !== 0) {
                    continue;
                }
            }

            $user = $userModel->findById((string) $student['userId']);
            $drive = $driveModel->findById((string) $app['driveId']);
            $job = !empty($app['jobId']) ? $jobModel->findById((string) $app['jobId']) : null;

            $rows[] = $this->serializeRow($app, $student, $user, $drive, $job, $deptModel, $deptCache);
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(string $companyId): array
    {
        $apps = (new ApplicationModel())->findByCompany($companyId);
        $counts = [
            'total'        => count($apps),
            'applied'      => 0,
            'under_review' => 0,
            'shortlisted'  => 0,
            'interview'    => 0,
            'offered'      => 0,
            'rejected'     => 0,
        ];
        foreach ($apps as $app) {
            $bucket = $this->uiStatus($app['status'] ?? 'applied');
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
        }
        return $counts;
    }

    public function uiStatus(string $status): string
    {
        return match ($status) {
            'applied', 'resume_pending', 'resume_verified' => 'applied',
            'officer_approved', 'company_review' => 'under_review',
            'shortlisted' => 'shortlisted',
            'selected' => 'offered',
            'rejected', 'withdrawn' => 'rejected',
            default => 'applied',
        };
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, string> $deptCache
     */
    private function departmentCode(array $student, DepartmentModel $deptModel, array &$deptCache): string
    {
        $deptId = (string) ($student['departmentId'] ?? '');
        if ($deptId === '') {
            return '';
        }
        if (!isset($deptCache[$deptId])) {
            $dept = $deptModel->findById($deptId);
            $deptCache[$deptId] = $dept ? (string) ($dept['code'] ?? $dept['name'] ?? '') : '';
        }
        return $deptCache[$deptId];
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed>|null $student
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $drive
     * @param array<string, mixed>|null $job
     * @param array<string, string> $deptCache
     * @return array<string, mixed>
     */
    private function serializeRow(
        array $app,
        ?array $student,
        ?array $user,
        ?array $drive,
        ?array $job,
        DepartmentModel $deptModel,
        array &$deptCache
    ): array {
        $serialized = DocumentHelper::serialize($app);
        $dept = $student ? $this->departmentCode($student, $deptModel, $deptCache) : '';
        $serialized['student'] = [
            'name'           => $user['name'] ?? 'Student',
            'registerNumber' => $student['registerNumber'] ?? '',
            'department'     => $dept,
            'cgpa'           => (float) ($student['academic']['cgpa'] ?? 0),
            'backlogs'       => (int) ($student['academic']['backlogs'] ?? 0),
            'hasResume'      => !empty($student['resume']['filename']),
        ];
        $serialized['drive'] = $drive ? [
            'title'   => $drive['title'] ?? '',
            'package' => $drive['tier'] ?? '',
            'status'  => $drive['status'] ?? '',
        ] : null;
        $serialized['job'] = $job ? [
            'title'   => $job['title'] ?? '',
            'package' => $job['package'] ?? '',
            'location'=> $job['location'] ?? '',
        ] : null;
        $serialized['uiStatus'] = $this->uiStatus($app['status'] ?? 'applied');
        return $serialized;
    }
}
