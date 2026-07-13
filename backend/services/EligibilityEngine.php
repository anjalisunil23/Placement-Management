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

        $this->appendMarksCriteriaReasons($academic, $criteria, $reasons);
        $this->appendGenderCriteriaReasons($student, $criteria, $reasons);

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
     * Whether a drive should appear in a student's browse list.
     * Only branch targeting is used for visibility; CGPA/marks gate Apply via eligibilityCheck.
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

        $studentCodes = $this->studentDepartmentCodes($student);
        if ($studentCodes === []) {
            // Unknown branch — still show open drives so new listings are not invisible.
            return true;
        }

        foreach ($studentCodes as $code) {
            if ($this->branchCodesMatch($code, $branches)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedBranches
     */
    private function branchCodesMatch(string $studentCode, array $allowedBranches): bool
    {
        $studentCode = strtoupper(trim($studentCode));
        if ($studentCode === '') {
            return false;
        }
        foreach ($allowedBranches as $allowed) {
            $allowed = strtoupper(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($studentCode === $allowed) {
                return true;
            }
            if (str_contains($allowed, $studentCode) || str_contains($studentCode, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $academic
     * @param array<string, mixed> $criteria
     */
    private function passesMarksCriteria(array $academic, array $criteria): bool
    {
        $reasons = [];
        $this->appendMarksCriteriaReasons($academic, $criteria, $reasons);

        return $reasons === [];
    }

    /**
     * @param array<string, mixed> $academic
     * @param array<string, mixed> $criteria
     * @param list<string> $reasons
     */
    private function appendMarksCriteriaReasons(array $academic, array $criteria, array &$reasons): void
    {
        $marks10 = (float) ($academic['marks10th'] ?? 0);
        $marks12 = (float) ($academic['marks12th'] ?? 0);
        $ug = (float) ($academic['ugMarks'] ?? 0);
        $pg = $this->studentPgMarkPercent($academic);

        $minAll = $this->criteriaMinPercent(
            $criteria,
            'minPercentAllClasses',
            'minAllClassPercent',
            'minPercentAll'
        );

        $checks = [
            ['min10th', 'minMarks10th', $marks10, '10th'],
            ['min12th', 'minMarks12th', $marks12, '12th'],
            ['minUg', 'minUgMarks', $ug, 'UG'],
            ['minPg', 'minPgMarks', $pg, 'PG'],
        ];

        foreach ($checks as [$primary, $alternate, $actual, $label]) {
            $min = max($minAll, $this->criteriaMinPercent($criteria, $primary, $alternate));
            if ($min <= 0) {
                continue;
            }
            if ($actual < $min) {
                $reasons[] = "{$label} marks ({$actual}%) are below the required minimum ({$min}%).";
            }
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function criteriaMinPercent(array $criteria, string ...$keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $criteria)) {
                continue;
            }
            $value = $criteria[$key];
            if ($value === '' || $value === null || !is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($n > 0) {
                return $n;
            }
        }

        return 0.0;
    }

    /**
     * PG marks as % — prefer mcaMarks, else CGPA on a 10-point scale × 10.
     *
     * @param array<string, mixed> $academic
     */
    private function studentPgMarkPercent(array $academic): float
    {
        $mca = (float) ($academic['mcaMarks'] ?? 0);
        if ($mca > 0) {
            return $mca;
        }

        $cgpa = (float) ($academic['cgpa'] ?? 0);
        if ($cgpa > 0 && $cgpa <= 10) {
            return round($cgpa * 10, 2);
        }

        return 0.0;
    }

    /**
     * @param list<array<string, mixed>> $qualifications
     */
    private function ugMarkFromQualifications(array $qualifications): float
    {
        foreach ($qualifications as $q) {
            if (!is_array($q)) {
                continue;
            }
            $label = strtoupper(trim((string) ($q['qualification'] ?? '')));
            if (!preg_match('/\b(BCA|B\.?\s*SC|B\.?\s*TECH|BTECH|BE|BACHELOR|UG)\b/', $label)) {
                continue;
            }
            if (isset($q['percentage']) && is_numeric($q['percentage']) && (float) $q['percentage'] > 0) {
                return (float) $q['percentage'];
            }
            $mark = isset($q['mark']) && is_numeric($q['mark']) ? (float) $q['mark'] : null;
            $max = isset($q['maxMark']) && is_numeric($q['maxMark']) ? (float) $q['maxMark'] : null;
            if ($mark !== null && $max !== null && $max > 0) {
                return round(($mark / $max) * 100, 2);
            }
        }

        return 0.0;
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
            if (is_array($dept)) {
                if (!empty($dept['code'])) {
                    $codes[] = strtoupper(trim((string) $dept['code']));
                }
                if (!empty($dept['name'])) {
                    $codes[] = strtoupper(trim((string) $dept['name']));
                }
            }
        }

        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $academic = is_array($student['academic'] ?? null) ? $student['academic'] : [];
        foreach ([
            $personal['course'] ?? null,
            $personal['branch'] ?? null,
            $personal['programme'] ?? null,
            $academic['branch'] ?? null,
            $academic['programme'] ?? null,
            $academic['course'] ?? null,
            $student['branch'] ?? null,
            $student['programme'] ?? null,
            $student['department'] ?? null,
            $student['departmentCode'] ?? null,
            $student['departmentName'] ?? null,
        ] as $extra) {
            if (is_string($extra) || is_numeric($extra)) {
                $text = strtoupper(trim((string) $extra));
                if ($text !== '' && !preg_match('/^\d+$/', $text)) {
                    $codes[] = $text;
                }
            }
        }

        $reg = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($reg !== '' && preg_match('/\d{2}([A-Z]{2,10})\d+/i', $reg, $matches) === 1) {
            $codes[] = strtoupper($matches[1]);
        }

        $aesProfile = Security::getSessionAesProfile();
        if (is_array($aesProfile) && $aesProfile !== []) {
            $mapped = (new AesLoginService())->mapAesDetailsToUserFields($aesProfile);
            foreach (['department', 'departmentName', 'branch', 'programme', 'course'] as $key) {
                if (!empty($mapped[$key])) {
                    $text = strtoupper(trim((string) $mapped[$key]));
                    if ($text !== '' && !preg_match('/^\d+$/', $text)) {
                        $codes[] = $text;
                    }
                }
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
        $needsCgpa = (float) ($academic['cgpa'] ?? 0) <= 0;
        $needsBacklogs = !array_key_exists('backlogs', $academic);
        $needs10 = (float) ($academic['marks10th'] ?? 0) <= 0;
        $needs12 = (float) ($academic['marks12th'] ?? 0) <= 0;
        $needsUg = (float) ($academic['ugMarks'] ?? 0) <= 0;

        if (!$needsCgpa && !$needsBacklogs && !$needs10 && !$needs12 && !$needsUg) {
            return $student;
        }

        $patch = [];
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));

        if ($register !== '' && ($needsCgpa || $needs10 || $needs12 || $needsUg)) {
            $api = new AesApiService();
            $qualAdmno = $api->resolveQualificationAdmissionNumber([], $register);
            if ($qualAdmno !== '' && ctype_digit($qualAdmno)) {
                try {
                    $qual = $api->fetchStudentQualificationProfile([
                        'admno' => $qualAdmno,
                        'stud_admno' => $qualAdmno,
                    ]);
                } catch (\Throwable) {
                    $qual = [];
                }
                $qualMapped = (new AesLoginService())->mapAesDetailsToUserFields($qual);
                if ($needsCgpa && !empty($qualMapped['cgpa']) && (float) $qualMapped['cgpa'] > 0) {
                    $academic['cgpa'] = (float) $qualMapped['cgpa'];
                }
                if ($needs10 && !empty($qualMapped['marks10th']) && (float) $qualMapped['marks10th'] > 0) {
                    $academic['marks10th'] = (float) $qualMapped['marks10th'];
                }
                if ($needs12 && !empty($qualMapped['marks12th']) && (float) $qualMapped['marks12th'] > 0) {
                    $academic['marks12th'] = (float) $qualMapped['marks12th'];
                }
                if ($needsUg) {
                    $ug = !empty($qualMapped['ugMarks']) && (float) $qualMapped['ugMarks'] > 0
                        ? (float) $qualMapped['ugMarks']
                        : $this->ugMarkFromQualifications(
                            is_array($qual['qualifications'] ?? null) ? $qual['qualifications'] : []
                        );
                    if ($ug > 0) {
                        $academic['ugMarks'] = $ug;
                    }
                }
                if ($academic !== ($student['academic'] ?? [])) {
                    $patch['academic'] = $academic;
                }
            }
        }

        if ($needsBacklogs) {
            $aesProfile = Security::getSessionAesProfile();
            if (is_array($aesProfile) && $aesProfile !== []) {
                $mapped = (new AesLoginService())->mapAesDetailsToUserFields($aesProfile);
                if (isset($mapped['backlogs'])) {
                    $academic = $patch['academic'] ?? $academic;
                    $academic['backlogs'] = (int) $mapped['backlogs'];
                    $patch['academic'] = $academic;
                }
            }
        }

        if ($patch !== []) {
            $this->studentModel->update((string) $student['_id'], $patch);
            $student = array_merge($student, $patch);
        }

        return $student;
    }

    /**
     * Whether a student matches a drive's gender rule (if any).
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $criteria
     */
    public function studentMatchesGenderRule(array $student, array $criteria): bool
    {
        return $this->passesGenderCriteria($student, $criteria);
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $criteria
     */
    private function passesGenderCriteria(array $student, array $criteria): bool
    {
        $reasons = [];
        $this->appendGenderCriteriaReasons($student, $criteria, $reasons);

        return $reasons === [];
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $criteria
     * @param list<string> $reasons
     */
    private function appendGenderCriteriaReasons(array $student, array $criteria, array &$reasons): void
    {
        $required = $this->requiredGender($criteria);
        if ($required === null) {
            return;
        }

        $actual = $this->studentGender($student);
        if ($actual === null) {
            $reasons[] = 'Gender is not recorded on your profile.';
            return;
        }

        if ($actual !== $required) {
            $label = $required === 'female' ? 'Female' : 'Male';
            $reasons[] = "This drive is restricted to {$label} candidates only.";
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function requiredGender(array $criteria): ?string
    {
        $raw = strtolower(trim((string) ($criteria['gender'] ?? '')));
        if ($raw === '' || $raw === 'any' || $raw === 'all') {
            return null;
        }

        return match ($raw) {
            'male', 'm', 'boys', 'boy' => 'male',
            'female', 'f', 'girls', 'girl' => 'female',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $student
     */
    private function studentGender(array $student): ?string
    {
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $raw = trim((string) ($personal['gender'] ?? $student['gender'] ?? ''));

        return self::normalizeGender($raw);
    }

    private static function normalizeGender(string $raw): ?string
    {
        $g = strtolower(trim($raw));
        if ($g === '') {
            return null;
        }

        if (in_array($g, ['male', 'm', 'boy', 'boys', '1'], true) || str_starts_with($g, 'mal')) {
            return 'male';
        }

        if (in_array($g, ['female', 'f', 'girl', 'girls', '2'], true) || str_starts_with($g, 'fem')) {
            return 'female';
        }

        return null;
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
