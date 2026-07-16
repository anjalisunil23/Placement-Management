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
     * Class-incharge batches for this staff member (AES session + CT/CoCT registry).
     * Empty means the staff is not class teacher / co-class teacher for any batch.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public static function assignedClassBatches(array $ctx): array
    {
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

        // Drop polluted department-wide backfills.
        if (count($fromProfile) > 12) {
            $fromProfile = [];
        }

        $fromSession = [];
        $aesProfile = Security::getSessionAesProfile();
        if (is_array($aesProfile) && $aesProfile !== []) {
            $fromSession = (new AesLoginService())->resolveAssignedClassBatches([], $aesProfile);
        }

        $fromRegistry = ClassInchargeRegistry::batchesForStaff($ctx);

        // Registry (CT/CoCT directory) is authoritative for edit rights when matched.
        // Merge AES session / profile labels for the same cohorts when present.
        $merged = array_merge($fromRegistry, $fromSession, $fromProfile);
        if ($fromRegistry !== []) {
            // Keep only labels that belong to registry cohorts (or exact registry keys).
            $allowedCohorts = array_map(
                static fn (string $b) => ClassInchargeRegistry::cohortKey($b),
                $fromRegistry
            );
            $merged = array_values(array_filter(
                $merged,
                static function (string $batch) use ($allowedCohorts): bool {
                    $cohort = ClassInchargeRegistry::cohortKey($batch);
                    foreach ($allowedCohorts as $allowed) {
                        if (strcasecmp($cohort, $allowed) === 0) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
            // Always include canonical cohort keys from registry.
            $merged = array_merge($merged, $fromRegistry);
        } elseif ($fromSession !== []) {
            $merged = $fromSession;
        } else {
            $merged = $fromProfile;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($batch) => trim((string) $batch),
            $merged
        ), static fn ($batch) => $batch !== '')));
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
    public static function studentMatchesScope(array $student, array $ctx): bool
    {
        $scopeDept = (string) ($ctx['departmentId'] ?? '');
        if ($scopeDept === '' || (string) ($student['departmentId'] ?? '') !== $scopeDept) {
            return false;
        }

        $batches = self::assignedClassBatches($ctx);
        if ($batches === []) {
            return true;
        }

        $studentBatch = strtoupper(trim((string) ($student['classBatch'] ?? '')));
        if ($studentBatch === '') {
            return false;
        }

        foreach ($batches as $batch) {
            if (strcasecmp($studentBatch, trim((string) $batch)) === 0) {
                return true;
            }
        }

        return false;
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
            'staffScope'   => true,
        ];
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
        return array_map(fn ($s) => (string) $s['_id'], $students);
    }
}
