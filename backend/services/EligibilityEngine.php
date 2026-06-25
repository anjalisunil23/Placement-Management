<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\BlacklistModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\ResumeModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Utils\Security;

/**
 * Automatic eligibility checker for placement drives and jobs.
 *
 * IF student.cgpa >= required AND department matches AND backlogs OK
 * AND not blacklisted AND placement chances remain
 * THEN allow application ELSE reject.
 */
final class EligibilityEngine
{
    private StudentModel $studentModel;
    private RuleModel $ruleModel;
    private BlacklistModel $blacklistModel;
    private DepartmentModel $departmentModel;

    public function __construct()
    {
        $this->studentModel    = new StudentModel();
        $this->ruleModel       = new RuleModel();
        $this->blacklistModel  = new BlacklistModel();
        $this->departmentModel = new DepartmentModel();
    }

    /**
     * @return array{eligible: bool, reasons: string[]}
     */
    public function checkForDrive(string $studentId, string $driveId, ?string $resumeId = null): array
    {
        $student = $this->studentModel->findById($studentId);
        $driveModel = new DriveModel();
        $drive = $driveModel->findById($driveId);

        if (!$student || !$drive) {
            return ['eligible' => false, 'reasons' => ['Student or drive not found.']];
        }

        $student = $this->enrichStudentForEligibility($student);

        if (($drive['status'] ?? '') === 'completed') {
            return ['eligible' => false, 'reasons' => ['This drive has been completed.']];
        }

        $criteria = $this->toPlainArray($drive['eligibility'] ?? []);
        $branches = $this->toPlainArray($drive['branches'] ?? []);
        $tier = $drive['tier'] ?? 'Tier 2';
        return $this->evaluate($student, $criteria, $branches, (string) $tier, $resumeId);
    }

    /**
     * @return array{eligible: bool, reasons: string[]}
     */
    public function checkForJob(string $studentId, string $jobId): array
    {
        $student = $this->studentModel->findById($studentId);
        $jobModel = new JobModel();
        $job = $jobModel->findById($jobId);

        if (!$student || !$job) {
            return ['eligible' => false, 'reasons' => ['Student or job not found.']];
        }

        $student = $this->enrichStudentForEligibility($student);

        $criteria = $this->toPlainArray($job['eligibility'] ?? []);
        $branches = [];
        if (!empty($criteria['departments'])) {
            foreach ($criteria['departments'] as $deptId) {
                $dept = $this->departmentModel->findById((string) $deptId);
                if ($dept) {
                    $branches[] = $dept['code'];
                }
            }
        }

        return $this->evaluate($student, $criteria, $branches, 'Tier 2');
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $criteria
     * @param string[] $allowedBranches department codes
     * @param string $driveTier
     * @return array{eligible: bool, reasons: string[]}
     */
    private function evaluate(array $student, array $criteria, array $allowedBranches, string $driveTier = 'Tier 2', ?string $resumeId = null): array
    {
        $reasons = [];
        $studentId = (string) $student['_id'];
        $academic = $student['academic'] ?? [];
        $cgpa = (float) ($academic['cgpa'] ?? 0);
        $backlogs = (int) ($academic['backlogs'] ?? 0);

        // Global placement rules
        $rule = $this->ruleModel->getActiveRule();
        $minCgpa = (float) ($criteria['minCgpa'] ?? $rule['minCgpa'] ?? 0);
        $maxBacklogs = (int) ($criteria['maxBacklogs'] ?? $rule['maxBacklogs'] ?? 99);

        if ($cgpa < $minCgpa) {
            $reasons[] = "CGPA {$cgpa} is below required minimum {$minCgpa}.";
        }

        if ($backlogs > $maxBacklogs) {
            $reasons[] = "Backlogs ({$backlogs}) exceed maximum allowed ({$maxBacklogs}).";
        }

        // Department check
        if (!empty($allowedBranches)) {
            $deptId = (string) ($student['departmentId'] ?? '');
            $dept = $this->departmentModel->findById($deptId);
            $deptCode = is_array($dept) ? (string) ($dept['code'] ?? '') : '';
            if ($deptCode && !in_array($deptCode, $allowedBranches, true)) {
                $reasons[] = "Department {$deptCode} is not eligible for this drive.";
            }
        }

        // Blacklist
        if ($this->blacklistModel->isBlacklisted($studentId)) {
            $reasons[] = 'Student is blacklisted.';
        }

        // Resume must be uploaded (profile field or resume library)
        if (!$this->studentHasResume($student, $resumeId)) {
            $reasons[] = 'Resume not uploaded.';
        }

        // Placement chances — tier cost must be affordable
        $rule = $this->ruleModel->getActiveRule();
        $tierRules = $rule['tierRules'] ?? [];
        $tierCost = (int) ($tierRules[$driveTier]['chances'] ?? 1);
        $chances = $student['placementChances'] ?? ['remaining' => 0];
        if (($chances['remaining'] ?? 0) < $tierCost) {
            $reasons[] = "Insufficient placement chances for {$driveTier} (requires {$tierCost}).";
        }

        // Already placed with no chances
        if (($student['placed'] ?? false) && ($chances['remaining'] ?? 0) <= 0) {
            $reasons[] = 'Student already placed with no remaining chances.';
        }

        // Skills check
        if (!empty($criteria['skills'])) {
            $studentSkills = array_map('strtolower', array_merge(
                $academic['skills'] ?? [],
                array_column($student['certifications'] ?? [], 'name')
            ));
            foreach ($criteria['skills'] as $skill) {
                if (!in_array(strtolower((string) $skill), $studentSkills, true)) {
                    $reasons[] = "Missing required skill: {$skill}.";
                }
            }
        }

        return [
            'eligible' => empty($reasons),
            'reasons'  => $reasons,
        ];
    }

    /**
     * Whether a drive should appear in a student's drive list (eligible branch scope).
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $drive
     */
    public function driveVisibleToStudent(array $student, array $drive): bool
    {
        $branches = array_values(array_filter(array_map(
            static fn ($b) => strtoupper(trim((string) $b)),
            $this->toPlainArray($drive['branches'] ?? [])
        )));
        if ($branches === []) {
            return true;
        }

        foreach ($this->studentDepartmentCodes($student) as $code) {
            if (in_array($code, $branches, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $student
     * @return list<string>
     */
    private function studentDepartmentCodes(array $student): array
    {
        $codes = [];
        $deptId = (string) ($student['departmentId'] ?? '');
        if ($deptId !== '') {
            $dept = $this->departmentModel->findById($deptId);
            if (is_array($dept) && !empty($dept['code'])) {
                $codes[] = strtoupper(trim((string) $dept['code']));
            }
        }

        $reg = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($reg !== '' && preg_match('/\d{2}([A-Z]{2,10})\d+/i', $reg, $matches) === 1) {
            $codes[] = strtoupper($matches[1]);
        }

        $aesProfile = Security::getSessionAesProfile();
        if (is_array($aesProfile) && $aesProfile !== []) {
            $mapped = (new AesLoginService())->mapAesDetailsToUserFields($aesProfile);
            if (!empty($mapped['department'])) {
                $codes[] = strtoupper(trim((string) $mapped['department']));
            }
        }

        return array_values(array_unique(array_filter($codes)));
    }

    /**
     * @param array<string, mixed> $student
     */
    private function studentHasResume(array $student, ?string $resumeId = null): bool
    {
        $studentId = (string) ($student['_id'] ?? '');

        if ($resumeId !== null && $resumeId !== '') {
            $resume = (new ResumeModel())->findById($resumeId);
            if ($resume && (string) ($resume['studentId'] ?? '') === $studentId) {
                return true;
            }
        }

        $profileResume = $student['resume'] ?? null;
        if (is_array($profileResume) && !empty($profileResume['path'])) {
            return true;
        }

        return (new ResumeModel())->findByStudent($studentId, 1) !== [];
    }

    /**
     * Use AES session CGPA/backlogs when the stored student profile is still empty.
     *
     * @param array<string, mixed> $student
     * @return array<string, mixed>
     */
    private function enrichStudentForEligibility(array $student): array
    {
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        $cgpa = (float) ($academic['cgpa'] ?? 0);
        $needsCgpa = $cgpa <= 0;
        $needsBacklogs = !array_key_exists('backlogs', $academic);

        if (!$needsCgpa && !$needsBacklogs) {
            return $student;
        }

        $aesProfile = Security::getSessionAesProfile();
        if (!is_array($aesProfile) || $aesProfile === []) {
            return $student;
        }

        $mapped = (new AesLoginService())->mapAesDetailsToUserFields($aesProfile);
        $patch = [];

        if ($needsCgpa && !empty($mapped['cgpa']) && (float) $mapped['cgpa'] > 0) {
            $academic['cgpa'] = (float) $mapped['cgpa'];
            $patch['academic'] = $academic;
        }
        if ($needsBacklogs && isset($mapped['backlogs'])) {
            $academic = $patch['academic'] ?? $academic;
            $academic['backlogs'] = (int) $mapped['backlogs'];
            $patch['academic'] = $academic;
        }

        if ($patch !== []) {
            $this->studentModel->update((string) $student['_id'], $patch);
            $student = array_merge($student, $patch);
        }

        return $student;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPlainArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \ArrayObject || (is_object($value) && method_exists($value, 'getArrayCopy'))) {
            return (array) $value;
        }
        return [];
    }
}
