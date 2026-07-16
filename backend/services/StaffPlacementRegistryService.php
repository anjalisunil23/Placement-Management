<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

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
        StaffContext::requireDepartmentScope($staffCtx);
        $officerCtx = StaffContext::officerCompatible($staffCtx);
        $studentRows = $this->officerData->listStudents($officerCtx);
        $program = trim((string) ($filters['program'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $aesClassRows = [];
        if ($program !== '' && $batch !== '') {
            $aesClassRows = $this->officerData->listAesClassStudents($officerCtx, $program, $batch);
        }

        $registry = [];
        foreach ($studentRows as $row) {
            foreach ($this->extractRegistryRows($row) as $entry) {
                $registry[] = $entry;
            }
        }
        $registry = $this->deduplicateStudentRows($registry);

        usort($registry, static function (array $a, array $b): int {
            $name = strcasecmp((string) ($a['studentName'] ?? ''), (string) ($b['studentName'] ?? ''));
            if ($name !== 0) {
                return $name;
            }

            return strcasecmp((string) ($a['employer'] ?? ''), (string) ($b['employer'] ?? ''));
        });

        $filterOptions = $this->buildFilterOptions($staffCtx, $filters);
        $filtered = $this->applyFilters($registry, $filters);
        if ($aesClassRows !== []) {
            $aesRegistry = [];
            foreach ($aesClassRows as $row) {
                foreach ($this->extractRegistryRows($row, false) as $entry) {
                    $aesRegistry[] = $entry;
                }
            }
            $filtered = $this->mergeCompleteClassRoster(
                $filtered,
                $this->applyFilters($aesRegistry, $filters)
            );
            $filtered = $this->deduplicateStudentRows($filtered);
            usort($filtered, static fn (array $a, array $b): int => strcasecmp(
                (string) ($a['studentName'] ?? ''),
                (string) ($b['studentName'] ?? '')
            ));
        }

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
     * Existing local/enriched rows win; AES-only rows fill any missing students.
     *
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $aesClass
     * @return array<int, array<string, mixed>>
     */
    private function mergeCompleteClassRoster(array $existing, array $aesClass): array
    {
        $byKey = [];
        $unkeyed = [];
        foreach ($existing as $row) {
            $key = $this->studentRowKey($row);
            if ($key === '') {
                $unkeyed[] = $row;
                continue;
            }
            $byKey[$key] = $row;
        }
        foreach ($aesClass as $row) {
            $key = $this->studentRowKey($row);
            if ($key === '') {
                continue;
            }
            if (!isset($byKey[$key])) {
                $byKey[$key] = $row;
                continue;
            }
            // Prefer the row that already has placement / HE details filled in.
            $existingEmployer = trim((string) ($byKey[$key]['employer'] ?? ''));
            $newEmployer = trim((string) ($row['employer'] ?? ''));
            if ($existingEmployer === '' && $newEmployer !== '') {
                $byKey[$key] = $row;
            }
        }

        return array_merge(array_values($byKey), $unkeyed);
    }

    /**
     * The class registry is one editable row per student. Keep the first,
     * authoritative placement row and discard duplicate history/AES rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateStudentRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $key = $this->studentRowKey($row);
            if ($key === '') {
                $unique[] = $row;
                continue;
            }
            if (!isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }
            // Prefer the row that already has placement / HE details filled in.
            $existingEmployer = trim((string) ($unique[$key]['employer'] ?? ''));
            $newEmployer = trim((string) ($row['employer'] ?? ''));
            if ($existingEmployer === '' && $newEmployer !== '') {
                $unique[$key] = $row;
            }
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function studentRowKey(array $row): string
    {
        foreach (['admissionNo', 'admno', 'registerNumber', 'studentId', 'id'] as $field) {
            $value = strtoupper(trim((string) ($row[$field] ?? '')));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function extractRegistryRows(array $row, bool $includeRecruitmentResults = true): array
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
        $ids = $this->resolveCourseBranchIds($row);

        $meta = [
            'studentId'       => $studentId,
            'studentName'     => $studentName,
            'registerNumber'  => $register,
            'admissionNo'     => $admissionNo,
            'courseId'        => $ids['courseId'],
            'branchId'        => $ids['branchId'],
            'phone'           => $phone,
            'email'           => $email,
            'contact'         => $contact,
            'departmentId'    => $deptFields['departmentId'],
            'departmentCode'  => $deptFields['departmentCode'],
            'departmentName'  => $deptFields['departmentName'],
            'program'         => $program,
            'branch'          => $branch,
            'batch'           => $batch,
        ];

        $entries = [];
        $seen = [];

        $placement = is_array($row['placement'] ?? null) ? $row['placement'] : [];
        if ((string) ($placement['company'] ?? '') !== '') {
            $entries[] = $this->buildEntry($meta, [
                'id'               => $studentId . ':placement',
                'employer'         => (string) $placement['company'],
                'role'             => (string) ($placement['role'] ?? ''),
                'address'          => (string) ($placement['address'] ?? ''),
                'package'          => $this->normalizePackage($placement['package'] ?? ''),
                'employerContact'  => (string) ($placement['employerContact'] ?? $placement['contact'] ?? ''),
                'joinDate'         => (string) ($placement['joinDate'] ?? ''),
                'endDate'          => (string) ($placement['endDate'] ?? ''),
                'academicDuration' => (string) ($placement['academicDuration'] ?? ''),
                'internshipDetails'=> (string) ($placement['internshipDetails'] ?? ''),
                'natureOfJob'      => (string) ($placement['natureOfJob'] ?? ''),
                'monthlySalary'    => (string) ($placement['monthlySalary'] ?? ''),
                'placementStatus'  => (string) ($placement['placementStatus'] ?? ''),
                'offerLetterVerified' => (bool) ($placement['offerLetterVerified'] ?? false),
                'verificationDate' => (string) ($placement['verificationDate'] ?? ''),
                'fordvv'           => $this->normalizeFlag01($placement['fordvv'] ?? 1),
                'includedvv'       => $this->normalizeFlag01($placement['includedvv'] ?? 1),
                'type'             => $this->resolveRecordType($placement),
                'source'           => (string) ($placement['source'] ?? 'placement'),
                'hasOfferLetter'   => (string) ($placement['offerLetter'] ?? '') !== ''
                    || (is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['offerLetter'] ?? '') !== ''),
                'hasJoiningLetter' => (string) ($placement['joiningLetter'] ?? '') !== ''
                    || (is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['joiningLetter'] ?? '') !== ''),
                'hasCompanyIdDoc'  => (string) ($placement['companyIdDoc'] ?? '') !== ''
                    || (is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['companyIdDoc'] ?? '') !== ''),
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
                    'joinDate'         => (string) ($self['joinDate'] ?? ''),
                    'endDate'          => (string) ($self['endDate'] ?? ''),
                    'academicDuration' => (string) ($self['academicDuration'] ?? ''),
                    'internshipDetails'=> (string) ($self['internshipDetails'] ?? ''),
                    'natureOfJob'      => (string) ($self['natureOfJob'] ?? ''),
                    'monthlySalary'    => (string) ($self['monthlySalary'] ?? ''),
                    'placementStatus'  => (string) ($self['placementStatus'] ?? ''),
                    'offerLetterVerified' => (bool) ($self['offerLetterVerified'] ?? false),
                    'verificationDate' => (string) ($self['verificationDate'] ?? ''),
                    'fordvv'           => $this->normalizeFlag01($self['fordvv'] ?? $placement['fordvv'] ?? 1),
                    'includedvv'       => $this->normalizeFlag01($self['includedvv'] ?? $placement['includedvv'] ?? 1),
                    'type'             => $this->resolveRecordType($self, 'Placement'),
                    'source'           => 'self_placement',
                    'hasOfferLetter'   => (string) ($self['offerLetter'] ?? '') !== '',
                    'hasJoiningLetter' => (string) ($self['joiningLetter'] ?? '') !== '',
                    'hasCompanyIdDoc'  => (string) ($self['companyIdDoc'] ?? '') !== '',
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

        if ($includeRecruitmentResults && $register !== '') {
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
        $entries = array_values(array_filter($entries, 'is_array'));

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

        // Class roster row so staff can add 5.2.1 details for every student in the batch.
        if ($entries === []) {
            $blank = $this->buildRosterEntry($meta, [
                'id'               => $studentId . ':roster',
                'employer'         => '',
                'role'             => '',
                'address'          => '',
                'package'          => '',
                'employerContact'  => '',
                'joinDate'         => '',
                'endDate'          => '',
                'academicDuration' => '',
                'internshipDetails'=> '',
                'natureOfJob'      => '',
                'monthlySalary'    => '',
                'placementStatus'  => '',
                'offerLetterVerified' => false,
                'verificationDate' => '',
                'fordvv'           => $this->normalizeFlag01($placement['fordvv'] ?? 1),
                'includedvv'       => $this->normalizeFlag01($placement['includedvv'] ?? 1),
                'type'             => 'Placement',
                'source'           => 'class_roster',
                'hasOfferLetter'   => false,
                'hasJoiningLetter' => false,
                'hasCompanyIdDoc'  => false,
                'canVerify'        => false,
            ]);
            if ($blank !== null) {
                $entries[] = $blank;
            }
        }

        return array_values(array_filter($entries, 'is_array'));
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

        $data['fordvv'] = $this->normalizeFlag01($data['fordvv'] ?? 1);
        $data['includedvv'] = $this->normalizeFlag01($data['includedvv'] ?? 1);

        return array_merge($meta, $data);
    }

    /**
     * Empty placement / higher-education shell for a student in the selected class.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildRosterEntry(array $meta, array $data): ?array
    {
        if (trim((string) ($meta['studentId'] ?? '')) === '') {
            return null;
        }

        $data['fordvv'] = $this->normalizeFlag01($data['fordvv'] ?? 1);
        $data['includedvv'] = $this->normalizeFlag01($data['includedvv'] ?? 1);

        return array_merge($meta, $data);
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array{programs: string[], branches: string[], batches: string[], departments: array<int, array{id:string,code:string,name:string}>}
     */
    private function buildFilterOptions(array $staffCtx, array $filters = []): array
    {
        $filterSvc = new PlacementFilterService();
        $program = trim((string) ($filters['program'] ?? ''));
        $branch = trim((string) ($filters['branch'] ?? ''));

        $programs = $filterSvc->fetchProgramOptions($staffCtx);
        $branches = $program !== '' ? $filterSvc->fetchBranchOptions($staffCtx, $program) : [];
        $batches = $program !== ''
            ? $filterSvc->fetchBatchOptions($staffCtx, $program, $branch)
            : [];

        return [
            'programs'     => $programs,
            'branches'     => $branches,
            'batches'      => $batches,
            'departments'  => $this->loadScopedDepartments($staffCtx),
        ];
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @return array<int, array{id:string,code:string,name:string}>
     */
    private function loadScopedDepartments(array $staffCtx): array
    {
        $dept = is_array($staffCtx['department'] ?? null) ? $staffCtx['department'] : null;
        if (!$dept) {
            return [];
        }
        $code = strtoupper(trim((string) ($dept['code'] ?? '')));
        $name = trim((string) ($dept['name'] ?? ''));
        if ($code === '' || $name === '') {
            return [];
        }

        return [[
            'id'   => (string) ($dept['_id'] ?? $staffCtx['departmentId'] ?? ''),
            'code' => $code,
            'name' => $name,
        ]];
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
     * @param array<string, mixed> $staffCtx
     * @return string[]
     */
    private function loadScopedBatchOptions(array $staffCtx): array
    {
        $filter = StaffContext::studentCollectionFilter($staffCtx);
        $batches = [];
        foreach ((new StudentModel())->findAll($filter, 5000) as $student) {
            if (!StaffContext::studentMatchesScope($student, $staffCtx)) {
                continue;
            }
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
            if ($program !== '') {
                $rowProgram = (string) ($row['program'] ?? '');
                $match = strcasecmp($rowProgram, $program) === 0
                    || strcasecmp(
                        DepartmentProgrammeCatalog::resolveProgrammeCode($rowProgram),
                        DepartmentProgrammeCatalog::resolveProgrammeCode($program)
                    ) === 0
                    || strcasecmp(
                        DepartmentProgrammeCatalog::normalizeCode($rowProgram),
                        DepartmentProgrammeCatalog::normalizeCode($program)
                    ) === 0;
                if (!$match) {
                    return false;
                }
            }
            if ($branch !== '' && strcasecmp((string) ($row['branch'] ?? ''), $branch) !== 0) {
                return false;
            }
            if ($batch !== '' && strcasecmp((string) ($row['batch'] ?? ''), $batch) !== 0) {
                return false;
            }
            if ($type !== '') {
                $want = $type === 'higher_education' ? 'Higher Education' : 'Placement';
                $employer = trim((string) ($row['employer'] ?? ''));
                // Keep unfilled class-roster rows visible so staff can add details.
                if ($employer !== '' && (string) ($row['type'] ?? '') !== $want) {
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
                (string) ($row['courseId'] ?? ''),
                (string) ($row['branchId'] ?? ''),
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
     * @return array{departmentId:string,departmentCode:string,departmentName:string,program:string,branch:string}
     */
    private function resolveDepartmentFields(array $row, ?array $dept): array
    {
        $aesProgram = DepartmentProgrammeCatalog::resolveProgrammeCode((string) (
            $row['stud_course']
            ?? $row['stud_cource_short']
            ?? $row['programme']
            ?? $row['course']
            ?? ''
        ));
        $aesBranch = trim((string) (
            $row['stud_branch']
            ?? $row['branchName']
            ?? $row['branch_name']
            ?? ''
        ));
        if ($aesProgram !== '') {
            return [
                'departmentId' => is_array($dept) ? (string) ($dept['id'] ?? '') : trim((string) ($row['departmentId'] ?? '')),
                'departmentCode' => is_array($dept) ? (string) ($dept['code'] ?? '') : trim((string) ($row['departmentCode'] ?? '')),
                'departmentName' => is_array($dept) ? (string) ($dept['name'] ?? '') : trim((string) ($row['departmentName'] ?? '')),
                'program'      => $aesProgram,
                'branch'       => $aesBranch !== '' ? $aesBranch : 'Regular',
            ];
        }

        $departmentId = '';
        if (is_array($dept)) {
            $departmentId = (string) ($dept['id'] ?? '');
            $code = strtoupper(trim((string) ($dept['code'] ?? '')));
            $name = trim((string) ($dept['name'] ?? ''));
            if ($code !== '' || $name !== '') {
                return [
                    'departmentId' => $departmentId,
                    'departmentCode' => $code,
                    'departmentName' => $name,
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
                'departmentCode' => $code,
                'departmentName' => $name,
                'program'      => $code,
                'branch'       => $name !== '' ? $name : $code,
            ];
        }

        if ($departmentId !== '') {
            $deptDoc = (new DepartmentModel())->findById($departmentId);
            if (is_array($deptDoc)) {
                return [
                    'departmentId' => $departmentId,
                    'departmentCode' => strtoupper(trim((string) ($deptDoc['code'] ?? ''))),
                    'departmentName' => trim((string) ($deptDoc['name'] ?? '')),
                    'program'      => strtoupper(trim((string) ($deptDoc['code'] ?? ''))),
                    'branch'       => trim((string) ($deptDoc['name'] ?? '')),
                ];
            }
        }

        return [
            'departmentId' => $departmentId,
            'departmentCode' => '',
            'departmentName' => '',
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

    /**
     * @param array<string, mixed> $row
     * @return array{courseId:string,branchId:string}
     */
    private function resolveCourseBranchIds(array $row): array
    {
        $courseId = '';
        foreach ([
            'courseId', 'course_id', 'CourseId', 'courseid',
            'stud_courseid', 'stud_course_id', 'stud_courseId',
            // AES getStudInfo4Placement does not return courseId; stud_deptcode is
            // the numeric course/department key available on every student row.
            'stud_deptcode', 'deptCode', 'dept_code', 'parentDepartmentCode',
        ] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $courseId = $value;
                break;
            }
        }

        $branchId = '';
        foreach ([
            'branchId', 'branch_id', 'BranchId', 'branchid',
            'stud_branchid', 'stud_branch_id', 'stud_branchId',
        ] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $branchId = $value;
                break;
            }
        }

        // Also inspect nested AES/local department payloads.
        $dept = is_array($row['department'] ?? null) ? $row['department'] : [];
        if ($courseId === '') {
            $courseId = trim((string) ($dept['aesId'] ?? $dept['code'] ?? ''));
            if ($courseId !== '' && !ctype_digit($courseId)) {
                $courseId = '';
            }
        }

        return [
            'courseId' => $courseId,
            'branchId' => $branchId,
        ];
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

    /** NAAC-style 0/1 flag; defaults to 1 when missing or invalid. */
    private function normalizeFlag01(mixed $value, int $default = 1): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            $n = (int) $value;

            return ($n === 0 || $n === 1) ? $n : $default;
        }
        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'y'], true)) {
            return 1;
        }
        if (in_array($raw, ['0', 'false', 'no', 'n'], true)) {
            return 0;
        }

        return $default;
    }

    /**
     * Staff update of a student's current placement registry fields.
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePlacement(array $staffCtx, string $studentId, array $input): array
    {
        $student = $this->officerData->resolveStudentRef($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        $this->assertRegistryStudentInDepartment($student, $staffCtx);
        $student = $this->ensureLocalStudentForStaffEdit($student, $staffCtx);

        $employer = trim((string) ($input['employer'] ?? $input['companyName'] ?? $input['company'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $package = trim((string) ($input['package'] ?? ''));
        $address = trim((string) ($input['address'] ?? $input['companyAddress'] ?? ''));
        $employerContact = trim((string) ($input['employerContact'] ?? ''));
        $joinDate = trim((string) ($input['joinDate'] ?? ''));
        $endDate = trim((string) ($input['endDate'] ?? ''));
        $academicDuration = trim((string) ($input['academicDuration'] ?? ''));
        $internshipDetails = trim((string) ($input['internshipDetails'] ?? ''));
        $natureOfJob = trim((string) ($input['natureOfJob'] ?? ''));
        $monthlySalary = trim((string) ($input['monthlySalary'] ?? ''));
        $placementStatus = trim((string) ($input['placementStatus'] ?? ''));
        $offerLetterVerified = filter_var(
            $input['offerLetterVerified'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $verificationDate = trim((string) ($input['verificationDate'] ?? ''));
        $fordvv = $this->normalizeFlag01($input['fordvv'] ?? 1);
        $includedvv = $this->normalizeFlag01($input['includedvv'] ?? 1);
        $typeRaw = trim((string) ($input['type'] ?? 'Placement'));
        $recordType = stripos($typeRaw, 'higher') !== false ? 'Higher Education' : 'Placement';

        if ($employer === '') {
            Response::error('Employer / institution name is required.', 422);
        }

        $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
        $placement = array_merge($placement, [
            'company'         => $employer,
            'role'            => $role,
            'package'         => $package,
            'address'         => $address,
            'employerContact' => $employerContact,
            'joinDate'        => $joinDate,
            'endDate'         => $endDate,
            'academicDuration'=> $academicDuration,
            'internshipDetails' => $internshipDetails,
            'natureOfJob'     => $natureOfJob,
            'monthlySalary'   => $monthlySalary,
            'placementStatus' => $placementStatus,
            'offerLetterVerified' => $offerLetterVerified,
            'verificationDate'=> $verificationDate,
            'fordvv'          => $fordvv,
            'includedvv'      => $includedvv,
            'recordType'      => $recordType,
            'updatedAt'       => DocumentHelper::now(),
        ]);

        $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : null;
        if (is_array($self) && in_array((string) ($self['status'] ?? ''), ['approved', 'placed', ''], true)) {
            $self['companyName'] = $employer;
            $self['role'] = $role;
            $self['package'] = $package;
            $self['companyAddress'] = $address;
            $self['joinDate'] = $joinDate;
            $self['endDate'] = $endDate;
            $self['academicDuration'] = $academicDuration;
            $self['internshipDetails'] = $internshipDetails;
            $self['natureOfJob'] = $natureOfJob;
            $self['monthlySalary'] = $monthlySalary;
            $self['placementStatus'] = $placementStatus;
            $self['offerLetterVerified'] = $offerLetterVerified;
            $self['verificationDate'] = $verificationDate;
            $self['fordvv'] = $fordvv;
            $self['includedvv'] = $includedvv;
            $self['recordType'] = $recordType;
        }

        $patch = [
            'placed'    => true,
            'placement' => $placement,
        ];
        if (is_array($self)) {
            $patch['selfPlacement'] = $self;
        }

        (new StudentModel())->update((string) $student['_id'], $patch);

        return [
            'studentId' => (string) $student['_id'],
            'placement' => DocumentHelper::serialize($placement),
        ];
    }

    /**
     * Registry entry is department-scoped, not limited to the staff member's
     * assigned teaching classes. This allows staff to maintain all displayed
     * final-year BCA/MCA/INMCA rows in their parent department.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $staffCtx
     */
    private function assertRegistryStudentInDepartment(array $student, array $staffCtx): void
    {
        StaffContext::requireDepartmentScope($staffCtx);
        $scopeId = (string) ($staffCtx['departmentId'] ?? '');
        $studentDeptId = (string) ($student['departmentId'] ?? '');
        if ($scopeId !== '' && $studentDeptId === $scopeId) {
            return;
        }

        $scopeDept = is_array($staffCtx['department'] ?? null) ? $staffCtx['department'] : [];
        $studentDept = $studentDeptId !== ''
            ? ((new DepartmentModel())->findById($studentDeptId) ?? [])
            : (is_array($student['department'] ?? null) ? $student['department'] : []);
        $scopeGroup = DepartmentProgrammeCatalog::findGroupForDepartment(
            (string) ($scopeDept['code'] ?? ''),
            (string) ($scopeDept['name'] ?? '')
        );

        $studentLabels = [
            (string) ($studentDept['code'] ?? ''),
            (string) ($studentDept['name'] ?? ''),
            (string) ($student['stud_course'] ?? ''),
            (string) ($student['programme'] ?? ''),
            (string) ($student['branch'] ?? ''),
        ];
        if ($scopeGroup !== null) {
            foreach ($studentLabels as $label) {
                $studentGroup = DepartmentProgrammeCatalog::findGroupForDepartment($label, $label);
                if ($studentGroup !== null && strcasecmp($studentGroup['parent'], $scopeGroup['parent']) === 0) {
                    return;
                }
            }
        }

        $scopeAesId = (new PlacementFilterService())->resolveParentDeptAesId($staffCtx);
        $studentAesId = trim((string) (
            $student['stud_deptcode']
            ?? $student['parentDepartmentCode']
            ?? $studentDept['aesId']
            ?? ''
        ));
        if ($scopeAesId !== '' && $studentAesId !== '' && strcasecmp($scopeAesId, $studentAesId) === 0) {
            return;
        }

        // Older local profiles may have a missing/programme-level departmentId.
        // Re-check the authoritative AES placement row before denying an entry
        // that was displayed in this department's class roster.
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));
        if ($register !== '') {
            try {
                $aesRow = (new AesApiService())->fetchStudInfoPlacementRow($register);
            } catch (\Throwable) {
                $aesRow = [];
            }
            if ($aesRow !== []) {
                $aesDeptId = trim((string) ($aesRow['stud_deptcode'] ?? $aesRow['parentDepartmentCode'] ?? ''));
                if ($scopeAesId !== '' && $aesDeptId !== '' && strcasecmp($scopeAesId, $aesDeptId) === 0) {
                    return;
                }

                if ($scopeGroup !== null) {
                    $aesLabels = [
                        (string) ($aesRow['stud_course'] ?? ''),
                        (string) ($aesRow['stud_cource_short'] ?? ''),
                        (string) ($aesRow['stud_branch'] ?? ''),
                        (string) ($aesRow['programme'] ?? ''),
                        (string) ($aesRow['stud_class'] ?? ''),
                    ];
                    foreach ($aesLabels as $label) {
                        $aesGroup = DepartmentProgrammeCatalog::findGroupForDepartment($label, $label);
                        if ($aesGroup !== null && strcasecmp($aesGroup['parent'], $scopeGroup['parent']) === 0) {
                            return;
                        }
                    }
                }
            }
        }

        Response::forbidden('This student is outside your department.');
    }

    /**
     * Materialize an AES-directory-only student into PlaceHub so staff can save 5.2.1 details.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    private function ensureLocalStudentForStaffEdit(array $student, array $staffCtx): array
    {
        $model = new StudentModel();
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));
        if ($register !== '') {
            $existing = $model->findByRegisterNumber($register);
            if ($existing) {
                $existing = $this->backfillStudentClassFields($model, $existing, $student);
                return $this->alignStudentDepartment($model, $existing, $staffCtx);
            }
        }

        if (empty($student['aesOnly']) && !empty($student['_id']) && Security::isValidId((string) $student['_id'])) {
            $byId = $model->findById((string) $student['_id']);
            if ($byId) {
                $byId = $this->backfillStudentClassFields($model, $byId, $student);
                return $this->alignStudentDepartment($model, $byId, $staffCtx);
            }
        }

        if ($register === '') {
            Response::error('Student admission number is missing; cannot save placement details.', 422);
        }

        $deptId = (string) ($staffCtx['departmentId'] ?? '');
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $name = trim((string) (
            $personal['fullName']
            ?? $student['displayName']
            ?? $student['stud_name']
            ?? ''
        ));
        $id = $model->insert([
            'registerNumber' => $register,
            'admno'          => $register,
            'departmentId'   => $deptId !== '' ? Security::toObjectId($deptId) : null,
            'classBatch'     => trim((string) ($student['classBatch'] ?? $student['stud_class'] ?? '')),
            'programme'      => DepartmentProgrammeCatalog::resolveProgrammeCode((string) (
                $student['stud_course']
                ?? $student['programme']
                ?? ''
            )),
            'branch'         => trim((string) ($student['stud_branch'] ?? $student['branch'] ?? 'Regular')),
            'courseId'       => trim((string) ($student['courseId'] ?? $student['course_id'] ?? '')),
            'branchId'       => trim((string) ($student['branchId'] ?? $student['branch_id'] ?? '')),
            'personal'       => [
                'fullName'       => $name,
                'phone'          => trim((string) ($personal['phone'] ?? $student['phone'] ?? '')),
                'personalEmail'  => trim((string) ($personal['personalEmail'] ?? $student['personalEmail'] ?? '')),
                'collegeEmail'   => trim((string) ($personal['collegeEmail'] ?? $student['collegeEmail'] ?? '')),
            ],
            'academic'       => is_array($student['academic'] ?? null) ? $student['academic'] : [
                'cgpa' => 0.0,
                'backlogs' => 0,
            ],
            'placementChances' => ['used' => 0, 'remaining' => 3, 'total' => 3],
            'placed'           => false,
            'placementHistory' => [],
            'source'           => 'staff_registry',
        ]);

        $created = $model->findById($id);
        if (!$created) {
            Response::serverError('Could not create local student profile for this admission number.');
        }

        return $created;
    }

    /**
     * Preserve AES programme/class identity on existing profiles for drive eligibility.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $aesStudent
     * @return array<string, mixed>
     */
    private function backfillStudentClassFields(StudentModel $model, array $existing, array $aesStudent): array
    {
        $programme = DepartmentProgrammeCatalog::resolveProgrammeCode((string) (
            $aesStudent['stud_course']
            ?? $aesStudent['programme']
            ?? ''
        ));
        $branch = trim((string) ($aesStudent['stud_branch'] ?? $aesStudent['branch'] ?? ''));
        $batch = trim((string) ($aesStudent['classBatch'] ?? $aesStudent['stud_class'] ?? ''));
        $courseId = trim((string) ($aesStudent['courseId'] ?? $aesStudent['course_id'] ?? ''));
        $branchId = trim((string) ($aesStudent['branchId'] ?? $aesStudent['branch_id'] ?? ''));
        $patch = [];
        if ($programme !== '' && trim((string) ($existing['programme'] ?? '')) === '') {
            $patch['programme'] = $programme;
        }
        if ($branch !== '' && trim((string) ($existing['branch'] ?? '')) === '') {
            $patch['branch'] = $branch;
        }
        if ($batch !== '') {
            $patch['classBatch'] = $batch;
        }
        if ($courseId !== '' && trim((string) ($existing['courseId'] ?? '')) === '') {
            $patch['courseId'] = $courseId;
        }
        if ($branchId !== '' && trim((string) ($existing['branchId'] ?? '')) === '') {
            $patch['branchId'] = $branchId;
        }
        // Align department to the AES/staff row when missing so reload merge indexes the student.
        $aesDeptId = trim((string) (
            $aesStudent['departmentId']
            ?? (is_array($aesStudent['department'] ?? null) ? ($aesStudent['department']['id'] ?? '') : '')
        ));
        if ($aesDeptId !== '' && Security::isValidId($aesDeptId) && trim((string) ($existing['departmentId'] ?? '')) === '') {
            $patch['departmentId'] = Security::toObjectId($aesDeptId);
        }
        if ($patch !== []) {
            $model->update((string) $existing['_id'], $patch);
            $existing = array_merge($existing, $patch);
        }

        return $existing;
    }

    /**
     * Keep materialized students under the staff department so reload merge indexes them.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    private function alignStudentDepartment(StudentModel $model, array $existing, array $staffCtx): array
    {
        $scopeId = trim((string) ($staffCtx['departmentId'] ?? ''));
        if ($scopeId === '' || !Security::isValidId($scopeId)) {
            return $existing;
        }
        $current = trim((string) ($existing['departmentId'] ?? ''));
        if ($current !== '') {
            return $existing;
        }
        $patch = ['departmentId' => Security::toObjectId($scopeId)];
        $model->update((string) $existing['_id'], $patch);
        return array_merge($existing, $patch);
    }

    /**
     * Staff upload of placement documents for a student.
     *
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    public function uploadPlacementDocuments(array $staffCtx, string $studentId): array
    {
        $student = $this->officerData->resolveStudentRef($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        $this->assertRegistryStudentInDepartment($student, $staffCtx);
        $student = $this->ensureLocalStudentForStaffEdit($student, $staffCtx);

        $hasOffer = isset($_FILES['offerLetter']) && (int) ($_FILES['offerLetter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasJoining = isset($_FILES['joiningLetter']) && (int) ($_FILES['joiningLetter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasCompanyId = isset($_FILES['companyIdDoc']) && (int) ($_FILES['companyIdDoc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasOffer && !$hasJoining && !$hasCompanyId) {
            Response::error('Upload at least one document: offer letter, joining letter, or company ID.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $registerNo = (string) ($student['registerNumber'] ?? 'student');
        $placement = is_array($student['placement'] ?? null) ? $student['placement'] : [];
        $self = is_array($student['selfPlacement'] ?? null) ? $student['selfPlacement'] : [];
        $company = (string) ($placement['company'] ?? $self['companyName'] ?? 'company');
        $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $company) ?: 'company';
        $savedPaths = [];

        $offerLetter = (string) ($placement['offerLetter'] ?? $self['offerLetter'] ?? '');
        $joiningLetter = (string) ($placement['joiningLetter'] ?? $self['joiningLetter'] ?? '');
        $companyIdDoc = (string) ($placement['companyIdDoc'] ?? $self['companyIdDoc'] ?? '');

        if ($hasOffer) {
            $error = Security::validateUploadedFile($_FILES['offerLetter'], $config['uploads']['max_resume'], ['pdf']);
            if ($error) {
                Response::error($error, 400);
            }
            $dir = $config['uploads']['offer_letter_dir'] ?? ($config['uploads']['reports_dir'] . '/offer_letters');
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                Response::error('Server upload folder is not writable.', 500);
            }
            $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_offer_' . time() . '.pdf';
            if (!move_uploaded_file($_FILES['offerLetter']['tmp_name'], $path)) {
                Response::error('Failed to save offer letter.', 500);
            }
            $savedPaths[] = $path;
            $offerLetter = basename($path);
        }

        $joiningLetter = $this->saveStaffDoc('joiningLetter', $registerNo, $safeCompany, 'joining_letter', ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'], $savedPaths) ?? $joiningLetter;
        $companyIdDoc = $this->saveStaffDoc('companyIdDoc', $registerNo, $safeCompany, 'company_id', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'], $savedPaths) ?? $companyIdDoc;

        $placement['offerLetter'] = $offerLetter;
        $placement['joiningLetter'] = $joiningLetter;
        $placement['companyIdDoc'] = $companyIdDoc;
        $placement['updatedAt'] = DocumentHelper::now();

        if ($self !== []) {
            if ($offerLetter !== '') {
                $self['offerLetter'] = $offerLetter;
            }
            if ($joiningLetter !== '') {
                $self['joiningLetter'] = $joiningLetter;
            }
            if ($companyIdDoc !== '') {
                $self['companyIdDoc'] = $companyIdDoc;
            }
        }

        $ok = (new StudentModel())->update((string) $student['_id'], [
            'placement'     => $placement,
            'selfPlacement' => $self !== [] ? $self : ($student['selfPlacement'] ?? null),
            'placed'        => true,
        ]);
        if (!$ok) {
            foreach ($savedPaths as $p) {
                @unlink($p);
            }
            Response::error('Could not save documents.', 500);
        }

        return [
            'studentId'        => (string) $student['_id'],
            'hasOfferLetter'   => $offerLetter !== '',
            'hasJoiningLetter' => $joiningLetter !== '',
            'hasCompanyIdDoc'  => $companyIdDoc !== '',
        ];
    }

    /**
     * @param string[] $savedPaths
     */
    private function saveStaffDoc(
        string $field,
        string $registerNo,
        string $safeCompany,
        string $prefix,
        array $extensions,
        array &$savedPaths
    ): ?string {
        if (!isset($_FILES[$field])) {
            return null;
        }
        $uploadError = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ' upload failed.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $error = Security::validateUploadedFile(
            $_FILES[$field],
            $config['uploads']['max_resume'],
            $extensions
        );
        if ($error) {
            Response::error(ucfirst(str_replace('_', ' ', $prefix)) . ': ' . $error, 400);
        }

        $dir = $config['uploads']['self_placement_dir'] ?? ($config['uploads']['offer_letter_dir'] . '/../self_placement');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Response::error('Server upload folder is not writable.', 500);
        }

        $ext = strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
        $path = $dir . '/' . $registerNo . '_' . $safeCompany . '_' . $prefix . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) {
            Response::error('Failed to save ' . str_replace('_', ' ', $prefix) . '.', 500);
        }
        $savedPaths[] = $path;

        return basename($path);
    }
}
