<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AlumniJobPostModel;
use PMS\Models\AlumniModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\JobModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Utils\DocumentHelper;

/**
 * Unified, role-scoped feed for company and alumni job posts.
 */
final class JobFeedService
{
    /** @return array<int, array<string, mixed>> */
    public function listForAdmin(): array
    {
        return $this->listPosts([], null, true, null);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForOfficer(array $user): array
    {
        $ctx = PlacementOfficerContext::resolve($user);
        if ($ctx['isAdmin']) {
            return $this->listForAdmin();
        }
        return $this->listPosts($this->departmentCodes($ctx['department']), null, false, null);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForStaff(array $user): array
    {
        $staff = (new StaffModel())->findByUserId((string) $user['_id']);
        if (!$staff || empty($staff['departmentId'])) {
            return [];
        }
        $department = (new DepartmentModel())->findById((string) $staff['departmentId']);
        return $this->listPosts($this->departmentCodes($department), null, false, null);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForStudent(array $user): array
    {
        $student = (new StudentModel())->findByUserId((string) $user['_id']);
        if (!$student) {
            return [];
        }
        return $this->listPosts($this->studentDepartmentCodes($student), 'student', false, $student);
    }

    /** @return array<int, array<string, mixed>> */
    public function listForSeekingAlumni(array $user): array
    {
        $alumni = (new AlumniModel())->findByUserId((string) $user['_id']);
        if (!$alumni || ($alumni['isWorking'] ?? false) === true) {
            return [];
        }

        $studentModel = new StudentModel();
        $student = $studentModel->findByUserId((string) $user['_id']);
        $registerNumber = trim((string) ($alumni['registerNumber'] ?? $user['registerNumber'] ?? ''));
        if (!$student && $registerNumber !== '') {
            $student = $studentModel->findByRegisterNumber($registerNumber);
        }
        if (!$student) {
            return [];
        }

        return $this->listPosts($this->studentDepartmentCodes($student), 'alumni', false, $student);
    }

    /**
     * @param string[] $viewerCodes
     * @return array<int, array<string, mixed>>
     */
    private function listPosts(array $viewerCodes, ?string $audience, bool $includeAllStatuses, ?array $student): array
    {
        if (!$includeAllStatuses && $viewerCodes === []) {
            return [];
        }

        $rows = [];
        $companyModel = new CompanyModel();
        foreach ((new JobModel())->findAll([], 500) as $job) {
            $company = $companyModel->findById((string) ($job['companyId'] ?? ''));
            $rows[] = $this->normalizeCompanyJob($job, (string) ($company['companyName'] ?? 'Company'));
        }
        foreach ((new AlumniJobPostModel())->findAll([], 500) as $post) {
            $rows[] = $this->normalizeAlumniPost($post);
        }

        $rows = array_values(array_filter($rows, function (array $row) use ($viewerCodes, $audience, $includeAllStatuses, $student): bool {
            // Pending/rejected posts are admin/officer review queues, not public feed items.
            if (!$includeAllStatuses && !in_array($row['status'], ['open', 'ongoing', 'reviewing'], true)) {
                return false;
            }
            if ($audience !== null && !in_array($row['audience'], [$audience, 'both'], true)) {
                return false;
            }
            if ($includeAllStatuses) {
                return true;
            }
            $targetBranches = $row['branches'];
            $branchMatches = $targetBranches === [] || array_intersect($viewerCodes, $targetBranches) !== [];
            return $branchMatches && ($student === null || $this->matchesAcademicCriteria($student, $row['eligibility']));
        }));

        usort($rows, static fn (array $a, array $b): int =>
            strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''))
        );
        return $rows;
    }

    /** @return array<string, mixed> */
    private function normalizeCompanyJob(array $job, string $companyName): array
    {
        $serialized = DocumentHelper::serialize($job);
        $eligibility = is_array($job['eligibility'] ?? null) ? $job['eligibility'] : [];
        return [
            'id'          => (string) ($job['_id'] ?? ''),
            'sourceType'  => 'company',
            'sourceLabel' => 'Company',
            'companyName' => $companyName,
            'title'       => trim((string) ($job['title'] ?? 'Job')),
            'description' => trim((string) ($job['description'] ?? '')),
            'imageUrl'    => trim((string) ($job['imageUrl'] ?? '')),
            'posterUrl'   => trim((string) ($job['posterUrl'] ?? $job['imageUrl'] ?? '')),
            'posterType'  => trim((string) ($job['posterType'] ?? (($job['imageUrl'] ?? '') !== '' ? 'image' : ''))),
            'jobType'     => trim((string) ($job['jobType'] ?? 'Full-time')),
            'package'     => trim((string) ($job['package'] ?? '')),
            'location'    => trim((string) ($job['location'] ?? '')),
            'status'      => strtolower(trim((string) ($job['status'] ?? 'open'))),
            'audience'    => $this->normalizeAudience($job['audience'] ?? $eligibility['audience'] ?? 'both'),
            'eligibility' => $eligibility,
            'branches'    => $this->targetBranches($job),
            'driveId'     => (string) ($job['driveId'] ?? ''),
            'createdAt'   => $serialized['createdAt'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeAlumniPost(array $post): array
    {
        $serialized = DocumentHelper::serialize($post);
        $eligibility = is_array($post['eligibility'] ?? null) ? $post['eligibility'] : [];
        $source = strtolower(trim((string) ($post['sourceType'] ?? 'alumni')));
        if ($source !== 'staff') {
            $source = 'alumni';
        }
        return [
            'id'           => (string) ($post['_id'] ?? ''),
            'sourceType'   => $source,
            'sourceLabel'  => $source === 'staff' ? 'Staff' : 'Alumni',
            'companyName'  => trim((string) ($post['company'] ?? 'Company')),
            'title'        => trim((string) ($post['title'] ?? 'Job')),
            'description'  => trim((string) ($post['description'] ?? '')),
            'imageUrl'     => trim((string) ($post['imageUrl'] ?? '')),
            'posterUrl'    => trim((string) ($post['posterUrl'] ?? $post['imageUrl'] ?? '')),
            'posterType'   => trim((string) ($post['posterType'] ?? (($post['imageUrl'] ?? '') !== '' ? 'image' : ''))),
            'jobType'      => trim((string) ($post['jobType'] ?? 'Full-time')),
            'package'      => trim((string) ($post['package'] ?? '')),
            'location'     => trim((string) ($post['location'] ?? '')),
            'status'       => strtolower(trim((string) ($post['status'] ?? 'pending'))),
            'audience'     => $this->normalizeAudience($post['audience'] ?? 'both'),
            'eligibility'  => $eligibility,
            'branches'     => $this->targetBranches($post),
            'departmentId' => (string) ($post['departmentId'] ?? ''),
            'driveId'      => (string) ($post['driveId'] ?? ''),
            'createdAt'    => $serialized['createdAt'] ?? null,
        ];
    }

    /** @return string[] */
    private function targetBranches(array $post): array
    {
        $eligibility = is_array($post['eligibility'] ?? null) ? $post['eligibility'] : [];
        $branches = $eligibility['branches'] ?? $post['branches'] ?? [];
        if (is_string($branches)) {
            $branches = preg_split('/[,;]+/', $branches) ?: [];
        }
        $codes = $this->normalizeCodes(is_array($branches) ? $branches : []);

        $departmentIds = $eligibility['departments'] ?? [];
        if (is_array($departmentIds)) {
            $departmentModel = new DepartmentModel();
            foreach ($departmentIds as $departmentId) {
                $department = $departmentModel->findById((string) $departmentId);
                $codes = array_merge($codes, $this->departmentCodes($department));
            }
        }
        return array_values(array_unique($codes));
    }

    /** @return string[] */
    private function studentDepartmentCodes(array $student): array
    {
        $department = null;
        if (!empty($student['departmentId'])) {
            $department = (new DepartmentModel())->findById((string) $student['departmentId']);
        }
        $codes = $this->departmentCodes($department);
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        return array_values(array_unique(array_merge($codes, $this->normalizeCodes([
            $student['departmentCode'] ?? '',
            $student['branch'] ?? '',
            $academic['branch'] ?? '',
            $academic['departmentCode'] ?? '',
        ]))));
    }

    /** @return string[] */
    private function departmentCodes(?array $department): array
    {
        if (!$department) {
            return [];
        }
        return $this->normalizeCodes([
            $department['code'] ?? '',
            $department['shortName'] ?? '',
        ]);
    }

    /** @param array<int, mixed> $values @return string[] */
    private function normalizeCodes(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            $values
        ))));
    }

    private function normalizeAudience(mixed $audience): string
    {
        $value = strtolower(trim((string) $audience));
        return in_array($value, ['student', 'alumni', 'both'], true) ? $value : 'both';
    }

    private function matchesAcademicCriteria(array $student, array $criteria): bool
    {
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $checks = [
            'minCgpa' => (float) ($academic['cgpa'] ?? $student['cgpa'] ?? 0),
            'min10th' => (float) ($academic['marks10th'] ?? $student['marks10th'] ?? 0),
            'min12th' => (float) ($academic['marks12th'] ?? $student['marks12th'] ?? 0),
        ];
        foreach ($checks as $criterion => $actual) {
            $minimum = (float) ($criteria[$criterion] ?? 0);
            if ($minimum > 0 && $actual < $minimum) {
                return false;
            }
        }
        if (array_key_exists('maxBacklogs', $criteria)) {
            $backlogs = (int) ($academic['backlogs'] ?? $student['backlogs'] ?? 0);
            if ($backlogs > (int) $criteria['maxBacklogs']) {
                return false;
            }
        }
        $requiredGender = strtolower(trim((string) ($criteria['gender'] ?? 'any')));
        if (in_array($requiredGender, ['male', 'female'], true)) {
            $gender = strtolower(trim((string) ($personal['gender'] ?? $student['gender'] ?? '')));
            if ($gender === '' || $gender !== $requiredGender) {
                return false;
            }
        }
        return true;
    }
}
