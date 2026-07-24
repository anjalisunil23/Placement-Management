<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Resolves staff profile and department scope.
 */
final class StaffContext
{
    /**
     * @return array{
     *   profile: array<string, mixed>,
     *   departmentId: string|null,
     *   department: array<string, mixed>|null
     * }
     */
    public static function resolve(array $user): array
    {
        $profile = self::ensureProfile($user);

        $departmentId = !empty($profile['departmentId']) ? (string) $profile['departmentId'] : null;
        $department = $departmentId ? (new DepartmentModel())->findById($departmentId) : null;

        return [
            'profile'      => $profile,
            'departmentId' => $departmentId,
            'department'   => $department,
            'user'         => $user,
        ];
    }

    /**
     * Staff must have a department before accessing student or placement data.
     *
     * @param array<string, mixed> $ctx
     */
    public static function requireDepartmentScope(array $ctx): void
    {
        if (empty($ctx['departmentId'])) {
            Response::forbidden('Your staff profile has no department assigned. Contact the placement cell.');
        }
    }

    /**
     * Class-incharge batches for this staff member.
     * For MCA / Integrated MCA (and other mapped cohorts), only CT/CoCT registry
     * matches count — never trust a stale department-wide assignedClassBatches dump.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public static function assignedClassBatches(array $ctx): array
    {
        $fromRegistry = ClassInchargeRegistry::batchesForStaff($ctx);
        if ($fromRegistry !== []) {
            return array_values(array_unique($fromRegistry));
        }

        $profile = is_array($ctx['profile'] ?? null) ? $ctx['profile'] : [];
        $raw = $profile['assignedClassBatches'] ?? [];
        if (!is_array($raw)) {
            if (is_string($raw) && trim($raw) !== '') {
                $raw = preg_split('/\s*,\s*/', trim($raw)) ?: [];
            } else {
                $raw = [];
            }
        }

        $fromProfile = array_values(array_filter(array_map(
            static fn ($batch) => trim((string) $batch),
            $raw
        ), static fn ($batch) => $batch !== ''));

        $fromSession = [];
        $aesProfile = Security::getSessionAesProfile();
        if (is_array($aesProfile) && $aesProfile !== []) {
            $fromSession = (new AesLoginService())->resolveAssignedClassBatches([], $aesProfile);
        }

        $merged = array_values(array_unique(array_merge($fromSession, $fromProfile)));
        // Strip any batch that belongs to a mapped CT/CoCT cohort — this staff is
        // not that incharge (registry miss), so they must not inherit edit rights.
        $merged = array_values(array_filter(
            $merged,
            static fn (string $batch): bool => !ClassInchargeRegistry::isMappedCohort($batch)
        ));

        // Drop leftover department-wide dumps.
        if (count($merged) > 12) {
            return [];
        }

        return $merged;
    }

    /**
     * Whether this staff member is class teacher or co-class teacher for the batch.
     */
    public static function canEditClassBatch(array $ctx, string $batch): bool
    {
        $batch = trim($batch);
        if ($batch === '') {
            return false;
        }

        $cohort = ClassInchargeRegistry::cohortKey($batch);
        $knownInRegistry = false;
        foreach (array_keys(ClassInchargeRegistry::assignments()) as $key) {
            if (strcasecmp(ClassInchargeRegistry::cohortKey((string) $key), $cohort) === 0) {
                $knownInRegistry = true;
                break;
            }
        }
        // For mapped MCA / Integrated MCA classes, only the directory CT/CoCT may edit.
        if ($knownInRegistry) {
            return ClassInchargeRegistry::staffIsInchargeOfBatch($ctx, $batch);
        }

        foreach (self::assignedClassBatches($ctx) as $assigned) {
            $assigned = trim((string) $assigned);
            if ($assigned === '') {
                continue;
            }
            if (strcasecmp($assigned, $batch) === 0) {
                return true;
            }
            if (strcasecmp(ClassInchargeRegistry::cohortKey($assigned), $cohort) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Placement / higher-education details may only be added by the class
     * teacher or co-class teacher (class incharge) of the student's class batch.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $ctx
     */
    public static function assertCanEditClassPlacement(array $student, array $ctx): void
    {
        self::requireDepartmentScope($ctx);
        $batch = trim((string) (
            $student['classBatch']
            ?? $student['stud_class']
            ?? $student['batch']
            ?? ''
        ));
        if ($batch === '') {
            Response::forbidden('This student has no class batch; only the class incharge can edit placement details.');
        }
        if (!self::canEditClassBatch($ctx, $batch)) {
            Response::forbidden(
                'Only the class teacher or co-class teacher (class incharge) of '
                . $batch
                . ' can add or edit placement / higher-education details.'
            );
        }
    }

    /**
     * @param array<string, mixed> $student
     * @param array<string, mixed> $ctx
     */
    public static function assertStudentInScope(array $student, array $ctx): void
    {
        self::requireDepartmentScope($ctx);
        if (!self::studentMatchesScope($student, $ctx)) {
            Response::forbidden('This student is outside your assigned class.');
        }
    }

    /**
     * Ensure a staff profile exists (AES sign-in may create the user before the profile row).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function ensureProfile(array $user): array
    {
        $staffModel = new StaffModel();
        $userId = (string) ($user['_id'] ?? '');
        if ($userId === '') {
            Response::notFound('Staff profile not found.');
        }

        $profile = $staffModel->findByUserId($userId);
        if ($profile) {
            return $profile;
        }

        $aes = new AesLoginService();
        $merged = $aes->applyAesSessionToUserFields([
            'name'  => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ]);

        $deptId = self::resolveDepartmentIdFromHints(
            (string) ($merged['department'] ?? ''),
            (string) ($merged['departmentName'] ?? '')
        );

        $designation = trim((string) ($merged['designation'] ?? ''));
        if ($designation === '') {
            $aesProfile = \PMS\Utils\Security::getSessionAesProfile();
            if (is_array($aesProfile)) {
                $designation = HodDetection::pickDesignation($aesProfile);
            }
        }
        $aesProfile = \PMS\Utils\Security::getSessionAesProfile();
        $isHod = HodDetection::designationLooksLikeHod($designation)
            || (is_array($aesProfile) && HodDetection::payloadIndicatesHod($aesProfile));
        $designation = HodDetection::normalizeDesignationForHod($designation, $isHod);
        $staffModel->createProfile($userId, [
            'departmentId' => $deptId,
            'designation'  => $designation !== '' ? $designation : 'Faculty',
            'phone'        => trim((string) ($merged['phone'] ?? '')),
        ]);

        $profile = $staffModel->findByUserId($userId);
        if (!$profile) {
            Response::notFound('Staff profile not found.');
        }

        return $profile;
    }

    private static function resolveDepartmentIdFromHints(string $code, string $name): ?string
    {
        $deptModel = new DepartmentModel();
        foreach (array_filter([$code, $name]) as $hint) {
            $hint = trim($hint);
            if ($hint === '') {
                continue;
            }
            $dept = $deptModel->findByCode($hint)
                ?? $deptModel->findByCode(strtoupper($hint));
            if ($dept) {
                return (string) ($dept['_id'] ?? '');
            }
        }

        return null;
    }

    /**
     * Officer-compatible context for reusing department-scoped services.
     *
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    public static function officerCompatible(array $staffCtx): array
    {
        return [
            'isAdmin'      => false,
            'departmentId' => $staffCtx['departmentId'] ?? '',
            'department'   => $staffCtx['department'],
            'profile'      => $staffCtx['profile'],
            'user'         => $staffCtx['user'] ?? null,
            'staffScope'   => true,
        ];
    }

    /**
     * Whether a student classBatch / stud_class belongs to the staff member's
     * assigned CT/CoCT classes (exact or cohort match, e.g. MCAINT2023-28-S7).
     *
     * @param list<string> $assignedBatches
     */
    public static function classBatchMatchesAssigned(string $studentBatch, array $assignedBatches): bool
    {
        $studentBatch = trim($studentBatch);
        if ($studentBatch === '' || $assignedBatches === []) {
            return false;
        }
        $wantCohort = ClassInchargeRegistry::cohortKey($studentBatch);
        foreach ($assignedBatches as $assigned) {
            $assigned = trim((string) $assigned);
            if ($assigned === '') {
                continue;
            }
            if (strcasecmp($assigned, $studentBatch) === 0) {
                return true;
            }
            if (strcasecmp(ClassInchargeRegistry::cohortKey($assigned), $wantCohort) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the display/AES class label from a student or list row.
     *
     * @param array<string, mixed> $student
     */
    public static function studentClassBatch(array $student): string
    {
        return trim((string) (
            $student['classBatch']
            ?? $student['stud_class']
            ?? $student['batch']
            ?? ''
        ));
    }

    /**
     * Staff: department + assigned class batches (CT/CoCT). Empty assignments ⇒ no students.
     * Placement officer paths do not use this (no staffScope).
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $ctx
     */
    public static function studentMatchesScope(array $student, array $ctx): bool
    {
        $scopeDept = (string) ($ctx['departmentId'] ?? '');
        $studentDept = trim((string) ($student['departmentId'] ?? ''));
        // AES-only rows may omit departmentId; allow when unset, otherwise require match.
        if ($scopeDept !== '' && $studentDept !== '' && $studentDept !== $scopeDept) {
            return false;
        }

        $batches = self::assignedClassBatches($ctx);
        // Class teachers / co-class teachers only see their own classes.
        if ($batches === []) {
            return false;
        }

        return self::classBatchMatchesAssigned(self::studentClassBatch($student), $batches);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public static function studentCollectionFilter(array $ctx): array
    {
        self::requireDepartmentScope($ctx);
        $oid = Security::toObjectId((string) $ctx['departmentId']);

        return $oid ? ['departmentId' => $oid] : ['registerNumber' => '__staff_scope_none__'];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return string[]
     */
    public static function studentIdsInDepartment(array $ctx): array
    {
        $students = (new StudentModel())->findAll(self::studentCollectionFilter($ctx), 5000);

        return array_map(static fn ($s) => (string) $s['_id'], $students);
    }
}
