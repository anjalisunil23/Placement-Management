<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\BlacklistModel;
use PMS\Models\DepartmentModel;
use PMS\Models\DriveModel;
use PMS\Models\JobModel;
use PMS\Models\RuleModel;
use PMS\Models\StudentModel;
use PMS\Utils\Security;

/**
 * Automatic eligibility checker for placement drives and jobs.
 *
 * IF student.cgpa >= required AND department matches AND backlogs OK
 * AND not blacklisted AND placement chances remain AND policy accepted
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
    public function checkForDrive(string $studentId, string $driveId): array
    {
        $student = $this->studentModel->findById($studentId);
        $driveModel = new DriveModel();
        $drive = $driveModel->findById($driveId);

        if (!$student || !$drive) {
            return ['eligible' => false, 'reasons' => ['Student or drive not found.']];
        }

        if (($drive['status'] ?? '') === 'completed') {
            return ['eligible' => false, 'reasons' => ['This drive has been completed.']];
        }

        $criteria = $this->toPlainArray($drive['eligibility'] ?? []);
        $branches = $this->toPlainArray($drive['branches'] ?? []);
        $tier = $drive['tier'] ?? 'Tier 2';
        return $this->evaluate($student, $criteria, $branches, (string) $tier);
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
    private function evaluate(array $student, array $criteria, array $allowedBranches, string $driveTier = 'Tier 2'): array
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
            $deptCode = $dept['code'] ?? '';
            if ($deptCode && !in_array($deptCode, $allowedBranches, true)) {
                $reasons[] = "Department {$deptCode} is not eligible for this drive.";
            }
        }

        // Blacklist
        if ($this->blacklistModel->isBlacklisted($studentId)) {
            $reasons[] = 'Student is blacklisted.';
        }

        // Policy acceptance
        if (!($student['policyAccepted'] ?? false)) {
            $reasons[] = 'Placement policy not accepted.';
        }

        // Resume must be uploaded (verification happens in workflow after apply)
        $resume = $student['resume'] ?? null;
        if (!$resume || empty($resume['path'])) {
            $reasons[] = 'Resume not uploaded.';
        }

        // Signed placement report required
        if (empty($student['signedReport'])) {
            $reasons[] = 'Signed placement report not uploaded.';
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
