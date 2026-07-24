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
    public const PERSONAL_KEYS = ['phone', 'personalEmail', 'maritalStatus'];
    public const ACADEMIC_KEYS = ['cgpa', 'backlogs', 'marks10th', 'marks12th', 'ugMarks', 'mcaMarks'];
    public const MARITAL_STATUSES = ['Single', 'Married', 'Other'];

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
            // Students may always edit phone, personal email, and marital status.
            $editable[] = $path;
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
        $profile = $this->sanitizeProfileDocument($profile);
        $personalPatch = $this->sanitizePersonal(is_array($input['personal'] ?? null) ? $input['personal'] : []);
        $academicIn = is_array($input['academic'] ?? null) ? $input['academic'] : [];
        unset($academicIn['qualifications']);
        $academicPatch = $this->sanitizeAcademic($academicIn, false);

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
            if (!in_array($key, self::PERSONAL_KEYS, true) && $this->isFieldLockedForStudent($profile, $path)) {
                $rejected[] = $path;
                continue;
            }
            $personal[$key] = $value;
            if ($this->isEmptyValue($path, $value)) {
                unset($locks[$path]);
            } else {
                $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'student'];
            }
        }

        foreach ($academicPatch as $key => $value) {
            $path = 'academic.' . $key;
            if ($key === 'backlogs') {
                $rejected[] = $path;
                continue;
            }
            if ($key === 'qualifications') {
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
            // Mirror CGPA into an empty "Current CGPA" qualification row mark.
            if ($key === 'cgpa' && $this->isValidCgpa($value)) {
                $rows = is_array($academic['qualifications'] ?? null) ? $academic['qualifications'] : [];
                foreach ($rows as $qi => $qrow) {
                    if (!is_array($qrow)) {
                        continue;
                    }
                    $label = strtoupper((string) ($qrow['qualification'] ?? ''));
                    $mark = isset($qrow['mark']) && is_numeric($qrow['mark']) ? (float) $qrow['mark'] : 0.0;
                    if ($mark > 0) {
                        continue;
                    }
                    if (preg_match('/\b(CGPA|CURRENT)\b/', $label) !== 1) {
                        continue;
                    }
                    $rows[$qi]['mark'] = (float) $value;
                    $locks['academic.qualifications.' . $qi . '.mark'] = ['lockedAt' => $now, 'lockedBy' => 'student'];
                    $academic['qualifications'] = $rows;
                    break;
                }
            }
        }

        $qualPatch = is_array($input['academic']['qualifications'] ?? null)
            ? $input['academic']['qualifications']
            : (is_array($input['qualifications'] ?? null) ? $input['qualifications'] : null);
        if (is_array($qualPatch)) {
            $mergedQuals = $this->mergeStudentQualificationFills(
                is_array($academic['qualifications'] ?? null) ? $academic['qualifications'] : [],
                $qualPatch,
                $locks,
                $now,
                $rejected
            );
            $academic['qualifications'] = $mergedQuals['rows'];
            $locks = $mergedQuals['locks'];

            // If student filled Current CGPA mark, mirror into academic.cgpa when empty.
            if (!$this->isValidCgpa($academic['cgpa'] ?? null)) {
                foreach ($mergedQuals['rows'] as $qrow) {
                    if (!is_array($qrow)) {
                        continue;
                    }
                    $label = strtoupper((string) ($qrow['qualification'] ?? ''));
                    $mark = isset($qrow['mark']) && is_numeric($qrow['mark']) ? (float) $qrow['mark'] : 0.0;
                    if ($mark > 0 && $mark <= 10 && preg_match('/\b(CGPA|CURRENT)\b/', $label) === 1) {
                        $academic['cgpa'] = $mark;
                        $locks['academic.cgpa'] = ['lockedAt' => $now, 'lockedBy' => 'student'];
                        break;
                    }
                }
            }
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
        // Always persist academic when a qualifications patch was supplied (even if merge looks equal).
        if (
            is_array($qualPatch)
            || $academic !== (is_array($profile['academic'] ?? null) ? $profile['academic'] : [])
        ) {
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
        $academicIn = is_array($input['academic'] ?? null) ? $input['academic'] : [];
        $qualIn = is_array($academicIn['qualifications'] ?? null) ? $academicIn['qualifications'] : null;
        unset($academicIn['qualifications']);
        $academicPatch = $this->sanitizeAcademic($academicIn, true);

        if ($personalPatch === [] && $academicPatch === [] && !is_array($qualIn)) {
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
        if (is_array($qualIn)) {
            $existingRows = is_array($academic['qualifications'] ?? null) ? $academic['qualifications'] : [];
            $academic['qualifications'] = $this->mergeStaffQualificationRows($existingRows, $qualIn, $locks, $now);
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
        $value = $this->readPath($profile, $path);
        // Invalid CGPA (e.g. admission number mis-mapped as CGPA) is treated as unset.
        if ($path === 'academic.cgpa' && !$this->isValidCgpa($value)) {
            return false;
        }

        $locks = is_array($profile['profileFieldLocks'] ?? null) ? $profile['profileFieldLocks'] : [];
        if (isset($locks[$path]) && is_array($locks[$path]) && !$this->isEmptyValue($path, $value)) {
            return true;
        }

        return !$this->isEmptyValue($path, $value);
    }

    /**
     * Normalize profile academic values before lock-state / API responses.
     * Clears bogus CGPA values (e.g. register numbers > 10).
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function sanitizeProfileDocument(array $profile): array
    {
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $changed = false;
        if (array_key_exists('cgpa', $academic) && !$this->isValidCgpa($academic['cgpa'])) {
            unset($academic['cgpa']);
            $changed = true;
        }
        if ($changed) {
            $profile['academic'] = $academic;
            $id = (string) ($profile['_id'] ?? '');
            if ($id !== '' && Security::isValidId($id)) {
                $this->students->update($id, ['academic' => $academic]);
            }
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<string>
     */
    public function missingFieldsForStudent(array $profile): array
    {
        $profile = $this->sanitizeProfileDocument($profile);
        $personal = is_array($profile['personal'] ?? null) ? $profile['personal'] : [];
        $academic = is_array($profile['academic'] ?? null) ? $profile['academic'] : [];
        $missing = [];

        if (trim((string) ($personal['phone'] ?? '')) === '') {
            $missing[] = 'Phone number';
        }
        if (trim((string) ($personal['personalEmail'] ?? '')) === '') {
            $missing[] = 'Personal email';
        }
        if (!$this->isValidCgpa($academic['cgpa'] ?? null)) {
            $missing[] = 'CGPA';
        }
        if (!is_numeric($academic['marks10th'] ?? null) || (float) ($academic['marks10th'] ?? 0) <= 0) {
            $missing[] = '10th marks';
        }
        if (!is_numeric($academic['marks12th'] ?? null) || (float) ($academic['marks12th'] ?? 0) <= 0) {
            $missing[] = '12th marks';
        }

        $quals = is_array($academic['qualifications'] ?? null) ? $academic['qualifications'] : [];
        foreach ($quals as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['qualification'] ?? 'Qualification'));
            foreach (['institution' => 'institution', 'registerNumber' => 'register no.', 'monthYear' => 'month/year'] as $field => $suffix) {
                $value = trim((string) ($row[$field] ?? ''));
                if ($value === '') {
                    $missing[] = $label . ' ' . $suffix;
                }
            }
            $mark = isset($row['mark']) && is_numeric($row['mark']) ? (float) $row['mark'] : 0.0;
            $maxMark = isset($row['maxMark']) && is_numeric($row['maxMark']) ? (float) $row['maxMark'] : (isset($row['maxmark']) && is_numeric($row['maxmark']) ? (float) $row['maxmark'] : 0.0);
            if ($mark <= 0) {
                $missing[] = $label . ' mark';
            }
            if ($maxMark <= 0) {
                $missing[] = $label . ' max mark';
            }
        }

        return array_values(array_unique($missing));
    }

    public function isValidCgpa(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0 && (float) $value <= 10;
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
        if (array_key_exists('maritalStatus', $personal)) {
            $raw = trim((string) $personal['maritalStatus']);
            if ($raw === '') {
                $out['maritalStatus'] = '';
            } else {
                $normalized = (new AesLoginService())->normalizeMaritalStatus($raw);
                if (in_array($normalized, self::MARITAL_STATUSES, true)) {
                    $out['maritalStatus'] = $normalized;
                }
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
            if ($cgpa > 0 && $cgpa <= 10) {
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
        if ($path === 'academic.cgpa') {
            return !$this->isValidCgpa($value);
        }
        if (str_starts_with($path, 'academic.') && $path !== 'academic.backlogs') {
            return !is_numeric($value) || (float) $value <= 0;
        }
        if ($path === 'academic.backlogs') {
            return $value === null || $value === '';
        }

        return $value === '' || $value === [];
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<mixed> $patch
     * @param array<string, mixed> $locks
     * @param list<string> $rejected
     * @return array{rows: list<array<string, mixed>>, locks: array<string, mixed>}
     */
    private function mergeStudentQualificationFills(
        array $existing,
        array $patch,
        array $locks,
        string $now,
        array &$rejected
    ): array {
        $rows = $existing;
        foreach ($patch as $idx => $rowPatch) {
            if (!is_array($rowPatch) || !isset($rows[(int) $idx]) || !is_array($rows[(int) $idx])) {
                continue;
            }
            $i = (int) $idx;
            $row = $rows[$i];
            foreach (['mark', 'maxMark', 'percentage', 'institution', 'registerNumber', 'monthYear'] as $field) {
                if (!array_key_exists($field, $rowPatch)) {
                    continue;
                }
                $path = 'academic.qualifications.' . $i . '.' . $field;
                $newVal = $rowPatch[$field];
                if ($field === 'institution' || $field === 'registerNumber' || $field === 'monthYear') {
                    $newVal = trim((string) $newVal);
                    $existingVal = trim((string) ($row[$field] ?? $row[strtolower($field)] ?? ''));
                    if ($newVal === '' || strcasecmp($existingVal, $newVal) === 0) {
                        continue;
                    }
                    if ($existingVal !== '' || (isset($locks[$path]) && is_array($locks[$path]))) {
                        $rejected[] = $path;
                        continue;
                    }
                    $row[$field] = $newVal;
                    $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'student'];
                    continue;
                }
                if ($newVal === '' || $newVal === null || !is_numeric($newVal)) {
                    continue;
                }
                $num = (float) $newVal;
                if ($num <= 0) {
                    continue;
                }
                if ($field === 'mark' && $num > 1000) {
                    continue;
                }
                if (($field === 'maxMark' || $field === 'percentage') && $num > 1000) {
                    continue;
                }
                $existingNum = isset($row[$field]) && is_numeric($row[$field]) ? (float) $row[$field] : 0.0;
                if ($existingNum > 0) {
                    if (abs($existingNum - $num) < 0.0001) {
                        continue;
                    }
                    $rejected[] = $path;
                    continue;
                }
                if (isset($locks[$path]) && is_array($locks[$path])) {
                    $rejected[] = $path;
                    continue;
                }
                $row[$field] = $num;
                if ($field === 'maxMark') {
                    $row['maxmark'] = $num;
                }
                $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'student'];
            }
            // Auto % when mark + max present and percentage still empty.
            $mark = isset($row['mark']) && is_numeric($row['mark']) ? (float) $row['mark'] : 0.0;
            $max = isset($row['maxMark']) && is_numeric($row['maxMark'])
                ? (float) $row['maxMark']
                : (isset($row['maxmark']) && is_numeric($row['maxmark']) ? (float) $row['maxmark'] : 0.0);
            $pct = isset($row['percentage']) && is_numeric($row['percentage']) ? (float) $row['percentage'] : 0.0;
            if ($pct <= 0 && $mark > 0 && $max > 0) {
                $row['percentage'] = round(($mark / $max) * 100, 2);
                $locks['academic.qualifications.' . $i . '.percentage'] = ['lockedAt' => $now, 'lockedBy' => 'student'];
            }
            $rows[$i] = $row;
        }

        return ['rows' => $rows, 'locks' => $locks];
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param list<mixed> $patch
     * @param array<string, mixed> $locks
     * @return list<array<string, mixed>>
     */
    private function mergeStaffQualificationRows(array $existing, array $patch, array &$locks, string $now): array
    {
        $rows = $existing;
        foreach ($patch as $idx => $rowPatch) {
            if (!is_array($rowPatch) || !isset($rows[(int) $idx]) || !is_array($rows[(int) $idx])) {
                continue;
            }
            $i = (int) $idx;
            $row = $rows[$i];
            foreach (['mark', 'maxMark', 'percentage', 'institution', 'registerNumber', 'monthYear'] as $field) {
                if (!array_key_exists($field, $rowPatch)) {
                    continue;
                }
                $path = 'academic.qualifications.' . $i . '.' . $field;
                if (in_array($field, ['institution', 'registerNumber', 'monthYear'], true)) {
                    $row[$field] = trim((string) $rowPatch[$field]);
                } elseif ($rowPatch[$field] === '' || $rowPatch[$field] === null) {
                    continue;
                } elseif (is_numeric($rowPatch[$field])) {
                    $row[$field] = (float) $rowPatch[$field];
                    if ($field === 'maxMark') {
                        $row['maxmark'] = (float) $rowPatch[$field];
                    }
                } else {
                    continue;
                }
                $locks[$path] = ['lockedAt' => $now, 'lockedBy' => 'staff'];
            }
            $rows[$i] = $row;
        }

        return $rows;
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
