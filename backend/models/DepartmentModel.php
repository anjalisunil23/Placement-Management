<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Services\DepartmentProgrammeCatalog;

class DepartmentModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::DEPARTMENTS;
    }

    public function findByCode(string $code): ?array
    {
        return $this->findOne(['code' => strtoupper(trim($code))]);
    }

    public function findByAesId(string $aesId): ?array
    {
        $aesId = trim($aesId);
        if ($aesId === '' || preg_match('/^\d+$/', $aesId) !== 1) {
            return null;
        }
        return $this->findOne(['aesId' => $aesId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDepartment(array $data): string
    {
        $row = [
            'name' => $data['name'],
            'code' => strtoupper(trim($data['code'])),
        ];
        $aesId = trim((string) ($data['aesId'] ?? ''));
        if ($aesId !== '' && preg_match('/^\d+$/', $aesId) === 1) {
            $row['aesId'] = $aesId;
        }
        return $this->insert($row);
    }

    /**
     * True for academic / student programme departments (MCA, CSE, …).
     * False for staff/teacher role buckets and non-academic units.
     */
    public static function isStudentAcademicDepartment(string $code, string $name = ''): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));
        if ($code !== '' && preg_match('/^\d+$/', $code) === 1) {
            $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $name) ?? '');
        }
        if ($code === '' && $name !== '') {
            $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $name) ?? '');
        }
        if ($code === '' || preg_match('/^\d+$/', $code) === 1) {
            return false;
        }

        $codeKey = preg_replace('/[^A-Z0-9]/', '', $code) ?: $code;
        $roleCodes = [
            'STAFF', 'FACULTY', 'TEACHER', 'TEACHERS', 'EMPLOYEE', 'EMPLOYEES',
            'NONTEACHING', 'ADMINISTRATION', 'ADMIN', 'OFFICE', 'LIBRARY',
            'PRINCIPAL', 'HOSTEL', 'SECURITY', 'ACCOUNTS', 'ESTABLISHMENT',
            'HR', 'PLACEMENT', 'TRAINING', 'PHD',
        ];
        if (in_array($codeKey, $roleCodes, true)) {
            return false;
        }

        $blob = trim($code . ' ' . $name);
        if ($blob === '') {
            return false;
        }
        if (preg_match('/\b(PHD|DOCTOR OF PHILOSOPHY)\b/', $blob) === 1) {
            return false;
        }
        if (preg_match('/\b(STAFF|FACULTY|TEACHERS?|EMPLOYEES?|NON[-\s]?TEACHING|ADMINISTRATION)\b/', $blob) === 1) {
            return false;
        }

        return true;
    }

    /** Parent academic unit from AES getDepartments — excludes programme rows like BCA / BT. */
    public static function isPlacementParentDepartment(string $code, string $name = ''): bool
    {
        if (!self::isStudentAcademicDepartment($code, $name)) {
            return false;
        }

        return !DepartmentProgrammeCatalog::isProgrammeBranchDepartmentRow($code, $name);
    }

    /**
     * Dropdown label/code for placement filters (handles AES numeric codes).
     *
     * @param array<string, mixed> $dept
     * @return array{id:string,code:string,name:string}|null
     */
    public static function toPlacementFilterOption(array $dept): ?array
    {
        $name = trim((string) ($dept['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $rawCode = strtoupper(trim((string) ($dept['code'] ?? '')));
        if (!self::isPlacementParentDepartment($rawCode, $name)) {
            return null;
        }
        $id = trim((string) ($dept['_id'] ?? $dept['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $displayCode = $rawCode !== '' && preg_match('/^\d+$/', $rawCode) !== 1
            ? $rawCode
            : strtoupper(preg_replace('/[^A-Z0-9]/', '', $name) ?? '');
        if ($displayCode === '') {
            $displayCode = 'DEPT';
        }

        return [
            'id'   => $id,
            'code' => $displayCode,
            'name' => $name,
        ];
    }
}
