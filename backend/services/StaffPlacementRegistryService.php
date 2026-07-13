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

        $filterOptions = $this->buildFilterOptions($staffCtx);
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
                'joinDate'         => (string) ($placement['joinDate'] ?? ''),
                'endDate'          => (string) ($placement['endDate'] ?? ''),
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
     * @param array<string, mixed> $staffCtx
     * @return array{programs: string[], branches: string[], batches: string[], departments: array<int, array{id:string,code:string,name:string}>}
     */
    private function buildFilterOptions(array $staffCtx): array
    {
        $departments = $this->loadScopedDepartments($staffCtx);
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
        foreach ($this->loadScopedBatchOptions($staffCtx) as $batch) {
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

    /**
     * Staff update of a student's current placement registry fields.
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePlacement(array $staffCtx, string $studentId, array $input): array
    {
        $officerCtx = StaffContext::officerCompatible($staffCtx);
        $student = $this->officerData->resolveStudentRef($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        StaffContext::assertStudentInScope($student, $staffCtx);

        $employer = trim((string) ($input['employer'] ?? $input['companyName'] ?? $input['company'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $package = trim((string) ($input['package'] ?? ''));
        $address = trim((string) ($input['address'] ?? $input['companyAddress'] ?? ''));
        $employerContact = trim((string) ($input['employerContact'] ?? ''));
        $joinDate = trim((string) ($input['joinDate'] ?? ''));
        $endDate = trim((string) ($input['endDate'] ?? ''));
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
     * Staff upload of placement documents for a student.
     *
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    public function uploadPlacementDocuments(array $staffCtx, string $studentId): array
    {
        $officerCtx = StaffContext::officerCompatible($staffCtx);
        $student = $this->officerData->resolveStudentRef($studentId);
        if (!$student) {
            Response::notFound('Student not found.');
        }
        StaffContext::assertStudentInScope($student, $staffCtx);

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
