<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

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

    /**
     * @param array<string, mixed> $data
     */
    public function createDepartment(array $data): string
    {
        return $this->insert([
            'name' => $data['name'],
            'code' => strtoupper(trim($data['code'])),
        ]);
    }

    /**
     * True for academic / student programme departments (MCA, CSE, …).
     * False for staff/teacher role buckets and non-academic units.
     */
    public static function isStudentAcademicDepartment(string $code, string $name = ''): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));
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
}
