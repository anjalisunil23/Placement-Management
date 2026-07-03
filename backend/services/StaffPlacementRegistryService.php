<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;

/**
 * Department-scoped placement and higher-education registry for staff.
 */
final class StaffPlacementRegistryService
{
    private OfficerDataService $officerData;

    public function __construct()
    {
        $this->officerData = new OfficerDataService();
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function list(array $staffCtx, array $filters = []): array
    {
        $officerCtx = StaffContext::officerCompatible($staffCtx);
        // Campus-wide student list so program/branch filters work across all departments.
        $officerCtx['departmentId'] = '';
        $studentRows = $this->officerData->listStudents($officerCtx);

        $registry = [];
        foreach ($studentRows as $row) {
            foreach ($this->extractRegistryRows($row) as $entry) {
                $registry[] = $entry;
            }
        }

        usort($registry, static function (array $a, array $b): int {
            $name = strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? ''));
            if ($name !== 0) {
                return $name;
            }

            return strcasecmp((string) ($a['employer'] ?? ''), (string) ($b['employer'] ?? ''));
        });

        $filterOptions = $this->buildFilterOptions();
        $filtered = $this->applyFilters($registry, $filters);

        $placementCount = 0;
        $higherCount = 0;
        foreach ($filtered as $row) {
            if (($row['type'] ?? '') === 'Higher Education') {
                $higherCount++;
            } else {
                $placementCount++;
            }
        }

        return [
            'filters' => $filterOptions,
            'rows'    => $filtered,
            'totals'  => [
                'all'               => count($filtered),
                'placement'         => $placementCount,
                'higher_education'  => $higherCount,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function extractRegistryRows(array $row): array
    {
        $studentId = (string) ($row['id'] ?? $row['studentId'] ?? '');
        if ($studentId === '') {
            return [];
        }

        $placed = ($row['placed'] ?? false) === true;
        $studentName = trim((string) ($row['displayName'] ?? $row['user']['name'] ?? ''));
        $register = strtoupper(trim((string) ($row['registerNumber'] ?? '')));
        $phone = trim((string) ($row['phone'] ?? $row['personal']['phone'] ?? ''));
        $email = trim((string) ($row['collegeEmail'] ?? $row['personalEmail'] ?? $row['email'] ?? ''));
        $contact = $this->formatContact($phone, $email);

        $deptObj = is_array($row['department'] ?? null) ? $row['department'] : null;
        $deptFields = $this->resolveDepartmentFields($row, $deptObj);
        $program = $deptFields['program'];
        $branch = $deptFields['branch'];
        $batch = trim((string) ($row['classBatch'] ?? ''));
        $admissionNo = $this->resolveAdmissionNo($row, $register);

        $meta = [
            'studentId'       => $studentId,
            'studentName'     => $studentName,
            'registerNumber'  => $register,
            'admissionNo'     => $admissionNo,
            'phone'           => $phone,
            'email'           => $email,
            'contact'         => $contact,
            'departmentId'    => $deptFields['departmentId'],
            'program'         => $program,
            'branch'          => $branch,
            'batch'           => $batch,
        ];

        $entries = [];
        $seen = [];

        $placement = is_array($row['placement'] ?? null) ? $row['placement'] : [];
        if ($placed && (string) ($placement['company'] ?? '') !== '') {
            $entries[] = $this->buildEntry($meta, [
                'id'               => $studentId . ':placement',
                'employer'         => (string) $placement['company'],
                'role'             => (string) ($placement['role'] ?? ''),
                'address'          => (string) ($placement['address'] ?? ''),
                'package'          => $this->normalizePackage($placement['package'] ?? ''),
                'employerContact'  => (string) ($placement['employerContact'] ?? $placement['contact'] ?? ''),
                'type'             => $this->resolveRecordType($placement),
                'source'           => (string) ($placement['source'] ?? 'placement'),
                'hasOfferLetter'   => false,
                'canVerify'        => false,
            ], $seen);
        }

        $self = is_array($row['selfPlacement'] ?? null) ? $row['selfPlacement'] : null;
        if ($self !== null && (string) ($self['companyName'] ?? '') !== '') {
            $status = (string) ($self['status'] ?? '');
            if ($status === 'approved' || ($placed && $status !== 'rejected')) {
                $entries[] = $this->buildEntry($meta, [
                    'id'               => $studentId . ':self',
                    'employer'         => (string) $self['companyName'],
                    'role'             => (string) ($self['role'] ?? ''),
                    'address'          => (string) ($self['companyAddress'] ?? ''),
                    'package'          => $this->normalizePackage($self['package'] ?? ''),
                    'employerContact'  => '',
                    'type'             => $this->resolveRecordType($self, 'Placement'),
                    'source'           => 'self_placement',
                    'hasOfferLetter'   => (string) ($self['offerLetter'] ?? '') !== '',
                    'canVerify'        => false,
                ], $seen);
            }
        }

        $history = is_array($row['placementHistory'] ?? null) ? $row['placementHistory'] : [];
        foreach ($history as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $company = trim((string) ($entry['company'] ?? ''));
            if ($company === '') {
                continue;
            }
            $status = strtolower((string) ($entry['status'] ?? ''));
            if (in_array($status, ['pending', 'rejected'], true)) {
                continue;
            }
            if (($entry['type'] ?? '') === 'self_reported' && is_array($self)) {
                continue;
            }
            $entries[] = $this->buildEntry($meta, [
                'id'               => $studentId . ':history:' . $idx,
                'employer'         => $company,
                'role'             => (string) ($entry['role'] ?? ''),
                'address'          => (string) ($entry['address'] ?? ''),
                'package'          => $this->normalizePackage($entry['package'] ?? ''),
                'employerContact'  => (string) ($entry['employerContact'] ?? $entry['contact'] ?? ''),
                'type'             => $this->resolveRecordType($entry),
                'source'           => (string) ($entry['type'] ?? 'history'),
                'hasOfferLetter'   => false,
                'canVerify'        => false,
            ], $seen);
        }

        if ($register !== '') {
            foreach ((new RecruitmentResultModel())->list(['registerNumber' => $register, 'status' => 'selected'], 20) as $result) {
                $company = trim((string) ($result['company'] ?? ''));
                if ($company === '') {
                    continue;
                }
                $entries[] = $this->buildEntry($meta, [
                    'id'               => $studentId . ':result:' . (string) ($result['_id'] ?? ''),
                    'employer'         => $company,
                    'role'             => (string) ($result['role'] ?? ''),
                    'address'          => '',
                    'package'          => $this->normalizePackage($result['package'] ?? ''),
                    'employerContact'  => '',
                    'type'             => 'Placement',
                    'source'           => 'recruitment_result',
                    'hasOfferLetter'   => false,
                    'canVerify'        => false,
                ], $seen);
            }
        }

        if ($entries === [] && $placed) {
            $fallbackEmployer = trim((string) ($placement['company'] ?? ($self['companyName'] ?? '')));
            if ($fallbackEmployer !== '') {
                $entries[] = $this->buildEntry($meta, [
                    'id'               => $studentId . ':placed',
                    'employer'         => $fallbackEmployer,
                    'role'             => (string) ($placement['role'] ?? $self['role'] ?? ''),
                    'address'          => (string) ($placement['address'] ?? $self['companyAddress'] ?? ''),
                    'package'          => $this->normalizePackage($placement['package'] ?? $self['package'] ?? ''),
                    'employerContact'  => '',
                    'type'             => 'Placement',
                    'source'           => 'placed',
                    'hasOfferLetter'   => is_array($self) && (string) ($self['offerLetter'] ?? '') !== '',
                    'canVerify'        => false,
                ], $seen);
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     * @param array<string, true> $seen
     * @return array<string, mixed>|null
     */
    private function buildEntry(array $meta, array $data, array &$seen): ?array
    {
        $employer = trim((string) ($data['employer'] ?? ''));
        $type = (string) ($data['type'] ?? 'Placement');
        $key = strtolower((string) ($meta['studentId'] ?? '') . '|' . $employer . '|' . $type);
        if ($employer === '' || isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;

        return array_merge($meta, $data);
    }

    /**
     * @return array{programs: string[], branches: string[], batches: string[], departments: array<int, array{id:string,code:string,name:string}>}
     */
    private function buildFilterOptions(): array
    {
        $departments = $this->loadAllDepartments();
        $programs = [];
        $branches = [];
        foreach ($departments as $dept) {
            $code = trim((string) ($dept['code'] ?? ''));
            $name = trim((string) ($dept['name'] ?? ''));
            if ($code !== '') {
                $programs[$code] = true;
            }
            if ($name !== '') {
                $branches[$name] = true;
            }
        }

        $batches = [];
        foreach ($this->loadAllBatchOptions() as $batch) {
            $batches[$batch] = true;
        }

        $sort = static function (array $keys): array {
            $list = array_keys($keys);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);

            return $list;
        };

        return [
            'programs'     => $sort($programs),
            'branches'     => $sort($branches),
            'batches'      => $sort($batches),
            'departments'  => $departments,
        ];
    }

    /**
     * @return array<int, array{id:string,code:string,name:string}>
     */
    private function loadAllDepartments(): array
    {
        try {
            (new AesApiService())->syncDepartmentsToLocal();
        } catch (\Throwable) {
            // Serve local departments when AES is unreachable.
        }

        $rows = [];
        foreach ((new DepartmentModel())->findAll([], 200) as $dept) {
            $code = strtoupper(trim((string) ($dept['code'] ?? '')));
            $name = trim((string) ($dept['name'] ?? ''));
            if ($code === '' || $name === '' || preg_match('/^\d+$/', $code) === 1) {
                continue;
            }
            $rows[] = [
                'id'   => (string) ($dept['_id'] ?? ''),
                'code' => $code,
                'name' => $name,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /**
     * @return string[]
     */
    private function loadAllBatchOptions(): array
    {
        $batches = [];
        foreach ((new StudentModel())->findAll([], 5000) as $student) {
            $batch = trim((string) ($student['classBatch'] ?? ''));
            if ($batch !== '') {
                $batches[$batch] = true;
            }
        }

        $list = array_keys($batches);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        $program = trim((string) ($filters['program'] ?? ''));
        $branch = trim((string) ($filters['branch'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $q = strtolower(trim((string) ($filters['q'] ?? $filters['search'] ?? '')));

        return array_values(array_filter($rows, static function (array $row) use ($program, $branch, $batch, $type, $q): bool {
            if ($program !== '' && strcasecmp((string) ($row['program'] ?? ''), $program) !== 0) {
                return false;
            }
            if ($branch !== '' && strcasecmp((string) ($row['branch'] ?? ''), $branch) !== 0) {
                return false;
            }
            if ($batch !== '' && strcasecmp((string) ($row['batch'] ?? ''), $batch) !== 0) {
                return false;
            }
            if ($type !== '') {
                $want = $type === 'higher_education' ? 'Higher Education' : 'Placement';
                if ((string) ($row['type'] ?? '') !== $want) {
                    return false;
                }
            }
            if ($q === '') {
                return true;
            }
            $hay = strtolower(implode(' ', [
                (string) ($row['studentName'] ?? ''),
                (string) ($row['registerNumber'] ?? ''),
                (string) ($row['admissionNo'] ?? ''),
                (string) ($row['employer'] ?? ''),
                (string) ($row['contact'] ?? ''),
                (string) ($row['address'] ?? ''),
                (string) ($row['package'] ?? ''),
            ]));

            return str_contains($hay, $q);
        }));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $dept
     * @return array{departmentId:string,program:string,branch:string}
     */
    private function resolveDepartmentFields(array $row, ?array $dept): array
    {
        $departmentId = '';
        if (is_array($dept)) {
            $departmentId = (string) ($dept['id'] ?? '');
            $code = strtoupper(trim((string) ($dept['code'] ?? '')));
            $name = trim((string) ($dept['name'] ?? ''));
            if ($code !== '' || $name !== '') {
                return [
                    'departmentId' => $departmentId,
                    'program'      => $code,
                    'branch'       => $name,
                ];
            }
        }

        $departmentId = trim((string) ($row['departmentId'] ?? ''));
        $code = strtoupper(trim((string) ($row['departmentCode'] ?? $row['department'] ?? '')));
        $name = trim((string) ($row['departmentName'] ?? ''));
        if ($code !== '' || $name !== '') {
            return [
                'departmentId' => $departmentId,
                'program'      => $code,
                'branch'       => $name !== '' ? $name : $code,
            ];
        }

        if ($departmentId !== '') {
            $deptDoc = (new DepartmentModel())->findById($departmentId);
            if (is_array($deptDoc)) {
                return [
                    'departmentId' => $departmentId,
                    'program'      => strtoupper(trim((string) ($deptDoc['code'] ?? ''))),
                    'branch'       => trim((string) ($deptDoc['name'] ?? '')),
                ];
            }
        }

        return [
            'departmentId' => $departmentId,
            'program'      => '',
            'branch'       => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAdmissionNo(array $row, string $register): string
    {
        $personal = is_array($row['personal'] ?? null) ? $row['personal'] : [];
        foreach (['admissionNo', 'admission_no', 'admno', 'stud_admno'] as $key) {
            $value = trim((string) ($personal[$key] ?? $row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        if ($register !== '' && ctype_digit($register)) {
            return $register;
        }

        return '';
    }

    private function formatContact(string $phone, string $email): string
    {
        $parts = array_filter([$phone, $email], static fn (string $v): bool => $v !== '');

        return implode(',', $parts);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolveRecordType(array $record, string $default = 'Placement'): string
    {
        $raw = strtolower(trim((string) (
            $record['recordType']
            ?? $record['placementType']
            ?? $record['category']
            ?? $record['type']
            ?? ''
        )));
        if (in_array($raw, ['higher_education', 'higher_ed', 'higher education', 'highereducation', 'education'], true)) {
            return 'Higher Education';
        }
        if ($raw === 'placement' || $raw === 'self_reported' || $raw === 'campus' || $raw === 'company_selection') {
            return 'Placement';
        }

        $employer = strtolower(trim((string) ($record['company'] ?? $record['companyName'] ?? $record['employer'] ?? $record['institution'] ?? '')));
        if ($employer !== '' && preg_match('/\b(university|college|institute|iit|iim|nit|iiit|school of)\b/i', $employer)) {
            return 'Higher Education';
        }

        return $default;
    }

    private function normalizePackage(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) (int) round((float) $value);
        }

        return trim((string) $value);
    }
}
