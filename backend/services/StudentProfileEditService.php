<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Student self-service may fill empty profile fields once.
 * Class teacher / co-class teacher may overwrite those fields afterward.
 */
final class StudentProfileEditService
{
    public const PERSONAL_KEYS = ['phone', 'personalEmail'];
    public const ACADEMIC_KEYS = ['cgpa', 'backlogs', 'marks10th', 'marks12th', 'ugMarks', 'mcaMarks'];

    private StudentModel $students;
    private OfficerDataService $officerData;

    public function __construct(?StudentModel $students = null, ?OfficerDataService $officerData = null)
    {
        $this->students = $students ?? new StudentModel();
        $this->officerData = $officerData ?? new OfficerDataService();
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{lockedFields: list<string>, editableFields: list<string>}
     */
    public function fieldStateForStudent(array $profile): array
    {
        $locked = [];
        $editable = [];
        foreach (self::PERSONAL_KEYS as $key) {
            $path = 'personal.' . $key;
            if ($this->isFieldLockedForStudent($profile, $path)) {
                $locked[] = $path;
            } else {
                $editable[] = $path;
            }
        }
        foreach (self::ACADEMIC_KEYS as $key) {
            $path = 'academic.' . $key;
            // Students may fill marks/CGPA when empty; backlogs stay staff/AES-owned unless empty null.
            if ($key === 'backlogs') {
                continue;
            }
            if ($this->isFieldLockedForStudent($profile, $path)) {
                $locked[] = $path;
            } else {
                $editable[] = $path;
            }
        }

        return [
            'lockedFields'   => $locked,
            'editableFields' => $editable,
        ];
    }

    /**
     * Apply a student self-edit: only empty (unlocked) fields may be written.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $input
     * @return array<string, mixed> updated profile document
     */
    public function applyStudentUpdate(array $profile, array $input): array
    {
        $personalPatch = $this->sanitizePersonal(is_array($input['personal'] ?? null) ? $input['personal'] : []);
        $academicPatch = $this->sanitizeAcademic(is_array($input['academic'] ?? null) ? $input['academic'] : [], false);

        $rejected = [];
        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $locks = is_array($profile['profileFieldLocks'] ?? null) ? $profile['profileFieldLocks'] : [];
        $now = gmdate('c');

        foreach ($personalPatch as $key => $value) {
            $path = 'personal.' . $key;
            $existingVal = $personal[$key] ?? null;
            if ($this->valuesEqual($path, $existingVal, $value)) {
                continue;
            }
            if ($this->isFieldLockedForStudent($profile, $path)) {
                $rejected[] = $path;
                continue;
            }
            if ($this->isEmptyValue($path, $value)) {
                continue;
            }
            $personal[$key] = $value;
            $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'student'];
        }

        foreach ($academicPatch as $key => $value) {
            $path = 'academic.' . $key;
            if ($key === 'backlogs') {
                $rejected[] = $path;
                continue;
            }
            $existingVal = $academic[$key] ?? null;
            if ($this->valuesEqual($path, $existingVal, $value)) {
                continue;
            }
            if ($this->isFieldLockedForStudent($profile, $path)) {
                $rejected[] = $path;
                continue;
            }
            if ($this->isEmptyValue($path, $value)) {
                continue;
            }
            $academic[$key] = $value;
            $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'student'];
        }

        if ($rejected !== []) {
            Response::error(
                'Some profile fields are already set and can only be changed by your class teacher or co-class teacher.',
                422,
                ['lockedFields' => array_values(array_unique($rejected))]
            );
        }

        $update = [];
        if ($personal !== (is_array($profile['personal'] ?? null) ? $profile['personal'] : [])) {
            $update['personal'] = $personal;
        }
        if ($academic !== (is_array($profile['academic'] ?? null) ? $profile['academic'] : [])) {
            $update['academic'] = $academic;
        }
        if ($locks !== (is_array($profile['profileFieldLocks'] ?? null) ? $profile['profileFieldLocks'] : [])) {
            $update['profileFieldLocks'] = $locks;
        }

        // certifications remain freely editable by students (list replace).
        if (isset($input['certifications']) && is_array($input['certifications'])) {
            $update['certifications'] = $input['certifications'];
        }

        if ($update === []) {
            Response::error('No valid fields to update.', 422);
        }

        $id = (string) ($profile['_id'] ?? '');
        $this->students->update($id, $update);
        $fresh = $this->students->findById($id);

        return is_array($fresh) ? $fresh : array_merge($profile, $update);
    }

    /**
     * Class teacher / co-class teacher profile edit.
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function applyStaffUpdate(array $staffCtx, string $studentRef, array $input): array
    {
        $resolved = $this->officerData->resolveStudentRef($studentRef);
        if (!$resolved) {
            Response::notFound('Student not found.');
        }

        StaffContext::assertCanEditClassPlacement($resolved, $staffCtx);
        $profile = $this->ensureLocalStudent($staffCtx, $resolved);

        $personalPatch = $this->sanitizePersonal(is_array($input['personal'] ?? null) ? $input['personal'] : []);
        $academicPatch = $this->sanitizeAcademic(is_array($input['academic'] ?? null) ? $input['academic'] : [], true);

        if ($personalPatch === [] && $academicPatch === []) {
            Response::error('No valid fields to update.', 422);
        }

        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $locks = is_array($profile['profileFieldLocks'] ?? null) ? $profile['profileFieldLocks'] : [];
        $now = gmdate('c');

        foreach ($personalPatch as $key => $value) {
            $personal[$key] = $value;
            $path = 'personal.' . $key;
            if (!$this->isEmptyValue($path, $value)) {
                $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'staff'];
            }
        }
        foreach ($academicPatch as $key => $value) {
            $academic[$key] = $value;
            $path = 'academic.' . $key;
            if ($key === 'backlogs' || !$this->isEmptyValue($path, $value)) {
                $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'staff'];
            }
        }

        $update = [
            'personal'           => $personal,
            'academic'           => $academic,
            'profileFieldLocks'  => $locks,
        ];
        $id = (string) ($profile['_id'] ?? '');
        $this->students->update($id, $update);
        $fresh = $this->students->findById($id);
        $out = is_array($fresh) ? $fresh : array_merge($profile, $update);
        $state = $this->fieldStateForStudent($out);

        return [
            'id'              => (string) ($out['_id'] ?? ''),
            'registerNumber'  => (string) ($out['registerNumber'] ?? ''),
            'personal'        => is_array($out['personal'] ?? null) ? $out['personal'] : [],
            'academic'        => is_array($out['academic'] ?? null) ? $out['academic'] : [],
            'lockedFields'    => $state['lockedFields'],
            'editableFields'  => $state['editableFields'],
            'canEditProfile'  => true,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function isFieldLockedForStudent(array $profile, string $path): bool
    {
        $locks = is_array($profile['profileFieldLocks'] ?? null) ? $profile['profileFieldLocks'] : [];
        if (isset($locks[$path]) && is_array($locks[$path])) {
            return true;
        }

        $value = $this->readPath($profile, $path);

        return !$this->isEmptyValue($path, $value);
    }

    /**
     * @param array<string, mixed> $personal
     * @return array<string, mixed>
     */
    public function sanitizePersonal(array $personal): array
    {
        $out = [];
        if (array_key_exists('phone', $personal)) {
            $out['phone'] = trim((string) $personal['phone']);
        }
        if (array_key_exists('personalEmail', $personal)) {
            $email = strtolower(trim((string) $personal['personalEmail']));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['personalEmail'] = $email;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $academic
     * @return array<string, mixed>
     */
    public function sanitizeAcademic(array $academic, bool $allowBacklogs): array
    {
        $out = [];
        if (array_key_exists('cgpa', $academic) && is_numeric($academic['cgpa'])) {
            $cgpa = (float) $academic['cgpa'];
            if ($cgpa >= 0 && $cgpa <= 10) {
                $out['cgpa'] = $cgpa;
            }
        }
        if ($allowBacklogs && array_key_exists('backlogs', $academic) && is_numeric($academic['backlogs'])) {
            $out['backlogs'] = max(0, (int) $academic['backlogs']);
        }
        foreach (['marks10th', 'marks12th', 'ugMarks', 'mcaMarks'] as $markKey) {
            if (!array_key_exists($markKey, $academic)) {
                continue;
            }
            $raw = $academic[$markKey];
            if ($raw === '' || $raw === null) {
                continue;
            }
            if (!is_numeric($raw)) {
                continue;
            }
            $mark = (float) $raw;
            if ($mark > 0 && $mark <= 100) {
                $out[$markKey] = $mark;
            }
        }

        return $out;
    }

    private function isEmptyValue(string $path, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (str_starts_with($path, 'academic.') && $path !== 'academic.backlogs') {
            return !is_numeric($value) || (float) $value <= 0;
        }
        if ($path === 'academic.backlogs') {
            return $value === null || $value === '';
        }

        return $value === '' || $value === [];
    }

    private function valuesEqual(string $path, mixed $a, mixed $b): bool
    {
        if (str_starts_with($path, 'personal.')) {
            return strtolower(trim((string) ($a ?? ''))) === strtolower(trim((string) ($b ?? '')));
        }
        if ($path === 'academic.backlogs') {
            return (int) ($a ?? -1) === (int) ($b ?? -2);
        }
        if (str_starts_with($path, 'academic.')) {
            $af = is_numeric($a) ? (float) $a : 0.0;
            $bf = is_numeric($b) ? (float) $b : 0.0;
            return abs($af - $bf) < 0.0001;
        }

        return $a === $b;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function readPath(array $profile, string $path): mixed
    {
        $parts = explode('.', $path);
        $cur = $profile;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }

        return $cur;
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, mixed> $student
     * @return array<string, mixed>
     */
    private function ensureLocalStudent(array $staffCtx, array $student): array
    {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));
        if ($register !== '') {
            $existing = $this->students->findByRegisterNumber($register);
            if ($existing) {
                return $existing;
            }
        }

        if (empty($student['aesOnly']) && !empty($student['_id']) && Security::isValidId((string) $student['_id'])) {
            $byId = $this->students->findById((string) $student['_id']);
            if ($byId) {
                return $byId;
            }
        }

        if ($register === '') {
            Response::error('Student admission number is missing; cannot update profile.', 422);
        }

        $deptId = (string) ($staffCtx['departmentId'] ?? '');
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) (
            $personal['fullName']
            ?? $student['displayName']
            ?? $student['stud_name']
            ?? ''
        ));
        $id = $this->students->insert([
            'registerNumber' => $register,
            'admno'          => $register,
            'departmentId'   => $deptId !== '' ? Security::toObjectId($deptId) : null,
            'classBatch'     => trim((string) ($student['classBatch'] ?? $student['stud_class'] ?? '')),
            'personal'       => [
                'fullName'      => $name,
                'phone'         => trim((string) ($personal['phone'] ?? $student['phone'] ?? '')),
                'personalEmail' => trim((string) ($personal['personalEmail'] ?? $student['personalEmail'] ?? '')),
            ],
            'academic'       => is_array($student['academic'] ?? null) ? $student['academic'] : [
                'cgpa' => 0.0,
                'backlogs' => 0,
            ],
            'placementChances' => ['used' => 0, 'remaining' => 3, 'total' => 3],
            'placed'           => false,
            'placementHistory' => [],
            'source'           => 'staff_profile_edit',
        ]);

        $created = $this->students->findById($id);
        if (!$created) {
            Response::serverError('Could not create local student profile.');
        }

        return $created;
    }
}
