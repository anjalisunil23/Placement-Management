<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\RecruitmentResultModel;
use PMS\Models\StudentModel;
use PMS\Models\StudentPlacementModel;
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
    /**
     * @return list<array{id:string,code:string,name:string}>
     */
    public function departmentFilterOptions(): array
    {
        return $this->loadAllDepartments();
    }

    /**
     * AES / dropdown filter context for the selected department (or entire campus).
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function placementFilterContext(array $staffCtx, array $filters = []): array
    {
        return $this->placementFilterCtx($staffCtx, $filters);
    }

    public function list(array $staffCtx, array $filters = []): array
    {
        StaffContext::requireDepartmentScope($staffCtx);
        $listCtx = $this->resolveRegistryListContext($staffCtx, $filters);
        $batch = trim((string) ($filters['batch'] ?? ''));

        $filters['departmentId'] = trim((string) ($listCtx['departmentId'] ?? $filters['departmentId'] ?? ''));

        return $this->buildRegistryResponse(
            $staffCtx,
            $listCtx,
            $filters,
            $this->listFromStudentPlacements($listCtx, $filters)
        );
    }

    /**
     * Manual AES → student_placements sync (placement / higher-education registry only).
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function syncFromAes(array $staffCtx, array $filters = []): array
    {
        StaffContext::requireDepartmentScope($staffCtx);
        PlacementFilterService::clearScopedRowsCache();
        OfficerDataService::clearPlacementSyncCaches();

        $filters['studRole'] = 'all';
        $student = $this->syncFromAesForStudRole(
            $staffCtx,
            array_merge($filters, ['studRole' => 'student'])
        );
        $alumni = $this->syncFromAesForStudRole(
            $staffCtx,
            array_merge($filters, ['studRole' => 'alumni'])
        );
        $studyingCount = (int) ($student['studentsSynced'] ?? 0);
        $alumniCount = (int) ($alumni['studentsSynced'] ?? 0);
        $aesFetched = (int) ($student['aesRosterFetched'] ?? 0) + (int) ($alumni['aesRosterFetched'] ?? 0);

        return [
            'studentsSynced'   => $studyingCount + $alumniCount,
            'studyingSynced'   => $studyingCount,
            'alumniSynced'     => $alumniCount,
            'aesRosterFetched' => $aesFetched,
            'scope'            => array_merge($student['scope'] ?? [], ['studRole' => 'all']),
        ];
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function syncFromAesForStudRole(array $staffCtx, array $filters = []): array
    {
        @ini_set('memory_limit', trim((string) ($_ENV['AES_DIRECTORY_MEMORY_LIMIT'] ?? '512M')));
        @set_time_limit(max(120, (int) ($_ENV['AES_REGISTRY_SYNC_TIME_LIMIT'] ?? 300)));

        $listCtx = $this->resolveRegistryListContext($staffCtx, $filters);
        $officerCtx = StaffContext::officerCompatible($listCtx);
        $program = trim((string) ($filters['program'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $studRole = $this->normalizeRegistryStudRoleFilter((string) ($filters['studRole'] ?? 'student'));
        $isAlumni = $studRole === 'alumni';
        $deptId = trim((string) ($listCtx['departmentId'] ?? ''));
        $campusWide = $deptId === '' || !empty($listCtx['campusWide']);
        $roleTag = $isAlumni ? 'alumni' : 'student';
        $directoryCtx = array_merge($officerCtx, [
            'placementRegistryWide' => true,
            'placementStudRole'     => $roleTag,
            'campusWide'            => $campusWide,
            'placementForceLiveAes' => true,
        ]);
        // Class-scoped AES (getStudInfo4Placement per batch) — required for INMCA / MCA cohorts.
        $classCtx = array_merge($officerCtx, [
            'placementRegistryWide' => false,
            'placementStudRole'     => $roleTag,
            'campusWide'            => $campusWide,
            'placementForceLiveAes' => true,
        ]);

        $studentsSynced = 0;
        $aesRosterFetched = 0;

        if ($program !== '' && $batch !== '') {
            $aesClassRows = $isAlumni
                ? $this->officerData->listAesAlumniClassStudents($classCtx, $program, $batch, true)
                : $this->officerData->listAesClassStudents($classCtx, $program, $batch, true);
            $classRows = $this->filterRosterByPlacementStudRole($aesClassRows, $studRole);
            $aesRosterFetched += count($classRows);
            $studentsSynced += $this->syncClassRowsToStudentPlacements(
                $classRows,
                $deptId,
                $program,
                $batch,
                $roleTag
            );
        } else {
            if ($program !== '') {
                // Per-class AES only — dept directory pulls every programme and pollutes student_placements.
                $classBatchRows = $this->syncRosterViaProgrammeClassBatches($classCtx, $program, $isAlumni);
                $classRows = $this->filterRosterByPlacementStudRole($classBatchRows, $studRole);
            } else {
                $classRows = $this->filterRosterByPlacementStudRole(
                    $this->syncRosterViaDepartmentClassBatches($classCtx, $isAlumni),
                    $studRole
                );
                if ($classRows === []) {
                    $aesRows = $isAlumni
                        ? $this->officerData->listAlumniStudentsForPlacementRegistry($directoryCtx)
                        : $this->officerData->listStudyingStudentsForPlacementRegistry($directoryCtx);
                    $classRows = $this->filterRosterByPlacementStudRole($aesRows, $studRole);
                }
            }
            $aesRosterFetched += count($classRows);
            if ($classRows !== []) {
                $studentsSynced += $this->syncAesDirectoryRowsToStudentPlacements(
                    $classRows,
                    $deptId,
                    $roleTag
                );
            }
        }

        $dept = is_array($listCtx['department'] ?? null) ? $listCtx['department'] : null;

        return [
            'studentsSynced'   => $studentsSynced,
            'aesRosterFetched' => $aesRosterFetched,
            'scope'          => [
                'departmentId'   => $deptId,
                'departmentName' => trim((string) ($dept['name'] ?? $dept['code'] ?? '')),
                'program'        => $program,
                'batch'          => $batch,
                'studRole'       => $isAlumni ? 'alumni' : 'student',
            ],
        ];
    }

    /**
     * When dept-wide directory fetch is empty, pull each known class batch via AES class API.
     *
     * @param array<string, mixed> $classCtx
     * @return list<array<string, mixed>>
     */
    private function syncRosterViaProgrammeClassBatches(array $classCtx, string $program, bool $isAlumni): array
    {
        $program = trim($program);
        if ($program === '') {
            return [];
        }

        $filterCtx = array_merge($classCtx, [
            'filterMode'                    => true,
            'placementStaffRegistryFilters' => true,
            'placementStudRole'             => $isAlumni ? 'alumni' : 'student',
            'placementRegistryWide'         => true,
        ]);
        $filterSvc = new PlacementFilterService();
        $batches = $filterSvc->fetchBatchOptions($filterCtx, $program, '', false);
        $deptAesId = $filterSvc->resolveParentDeptAesId($classCtx);
        if ($batches === [] && $deptAesId !== '') {
            $batches = (new AesApiService())->fetchPlacementClassBatches($deptAesId, $program);
        }
        if ($batches === [] && $deptAesId !== '') {
            $deptWide = (new AesApiService())->fetchPlacementClassBatches($deptAesId, '');
            $scoped = array_values(array_filter(
                $deptWide,
                static fn (string $label): bool => DepartmentProgrammeCatalog::batchHintMatchesProgramme($label, $program)
            ));
            if ($scoped !== []) {
                $batches = $scoped;
            }
        } elseif ($program !== '') {
            $scoped = array_values(array_filter(
                $batches,
                static fn (string $label): bool => DepartmentProgrammeCatalog::batchHintMatchesProgramme($label, $program)
            ));
            if ($scoped !== []) {
                $batches = $scoped;
            }
        }
        $rows = [];
        $seen = [];

        foreach ($batches as $batchLabel) {
            $batchLabel = trim((string) $batchLabel);
            if ($batchLabel === '') {
                continue;
            }
            $chunk = $isAlumni
                ? $this->officerData->listAesAlumniClassStudents($classCtx, $program, $batchLabel, true)
                : $this->officerData->listAesClassStudents($classCtx, $program, $batchLabel, true);
            foreach ($chunk as $row) {
                $key = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     * @return list<array<string, mixed>>
     */
    private function mergeUniqueRosterRows(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $classCtx
     * @return list<array<string, mixed>>
     */
    private function syncRosterViaDepartmentClassBatches(array $classCtx, bool $isAlumni): array
    {
        $filterCtx = array_merge($classCtx, [
            'filterMode'                    => true,
            'placementStaffRegistryFilters' => true,
            'placementStudRole'             => $isAlumni ? 'alumni' : 'student',
        ]);
        $programs = (new PlacementFilterService())->fetchProgramOptions($filterCtx);
        $rows = [];
        $seen = [];

        foreach ($programs as $program) {
            $program = trim((string) $program);
            if ($program === '') {
                continue;
            }
            foreach ($this->syncRosterViaProgrammeClassBatches($classCtx, $program, $isAlumni) as $row) {
                $key = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            foreach ($this->syncRosterViaDepartmentWideClassLabels($classCtx, $isAlumni) as $row) {
                $key = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Dept-wide class list from AES stud_class labels (when programme dropdown is empty).
     *
     * @param array<string, mixed> $classCtx
     * @return list<array<string, mixed>>
     */
    private function syncRosterViaDepartmentWideClassLabels(array $classCtx, bool $isAlumni): array
    {
        $filterSvc = new PlacementFilterService();
        $deptAesId = $filterSvc->resolveParentDeptAesId($classCtx);
        if ($deptAesId === '') {
            return [];
        }

        $batches = (new AesApiService())->fetchPlacementClassBatches($deptAesId, '');
        $rows = [];
        $seen = [];

        foreach ($batches as $batchLabel) {
            $batchLabel = trim((string) $batchLabel);
            if ($batchLabel === '') {
                continue;
            }
            $program = $this->inferProgrammeCodeFromBatchLabel($batchLabel);
            if ($program === '') {
                continue;
            }
            $chunk = $isAlumni
                ? $this->officerData->listAesAlumniClassStudents($classCtx, $program, $batchLabel, true)
                : $this->officerData->listAesClassStudents($classCtx, $program, $batchLabel, true);
            foreach ($chunk as $row) {
                $key = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function inferProgrammeCodeFromBatchLabel(string $batchLabel): string
    {
        $batchLabel = trim($batchLabel);
        if ($batchLabel === '') {
            return '';
        }

        $fromBatch = DepartmentProgrammeCatalog::resolveProgrammeCode($batchLabel);
        if ($fromBatch !== '') {
            return $fromBatch;
        }

        $norm = DepartmentProgrammeCatalog::normalizeCode($batchLabel);
        if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
            return 'INMCA';
        }
        if (str_starts_with($norm, 'MCA')) {
            return 'MCA';
        }
        if (str_contains($norm, 'BCA')) {
            return 'BCA';
        }

        foreach (DepartmentProgrammeCatalog::groups() as $group) {
            foreach ($group['programmes'] as $programme) {
                $code = DepartmentProgrammeCatalog::normalizeCode((string) ($programme['code'] ?? ''));
                if ($code !== '' && str_contains($norm, $code)) {
                    return (string) $programme['code'];
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $listCtx
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function listFromStudentPlacements(array $listCtx, array $filters): array
    {
        $studRole = $this->normalizeRegistryStudRoleFilter((string) ($filters['studRole'] ?? 'all'));
        $allFromTable = !empty($filters['allFromTable']);
        $model = new StudentPlacementModel();

        if ($allFromTable) {
            $tableRows = $model->listAllRosterRows(StudentPlacementModel::REGISTRY_TABLE_LIST_MAX);
            $tableRows = $this->enrichRosterRowsFromLocalStudents($tableRows, false);
        } else {
            $departmentId = trim((string) ($filters['departmentId'] ?? $listCtx['departmentId'] ?? ''));
            $program = trim((string) ($filters['program'] ?? ''));
            $batch = trim((string) ($filters['batch'] ?? ''));
            $tableRows = $model->listRosterRowsForRegistryScope(
                $departmentId,
                $program,
                $batch,
                StudentPlacementModel::REGISTRY_TABLE_LIST_MAX,
                $batch !== ''
            );
            $tableRows = $this->enrichRosterRowsFromLocalStudents($tableRows);
        }

        if ($studRole !== 'all') {
            $tableRows = $this->filterRosterByPlacementStudRole($tableRows, $studRole);
        }

        return $this->registryEntriesFromRoster($tableRows, true);
    }

    /**
     * Fill snapshot gaps when student_placements has ids but AES keys were not normalized on save.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichRosterRowsFromLocalStudents(array $rows, bool $dropIncomplete = true): array
    {
        if ($rows === []) {
            return [];
        }

        $needIds = [];
        foreach ($rows as $idx => $row) {
            if ($this->rosterRowHasSnapshot($row)) {
                continue;
            }
            $id = trim((string) ($row['studentId'] ?? $row['id'] ?? $row['_id'] ?? ''));
            if ($id !== '' && Security::isValidId($id)) {
                $needIds[$id][] = $idx;
            }
        }
        if ($needIds === []) {
            return $rows;
        }

        $students = (new StudentModel())->findByIds(array_keys($needIds));
        foreach ($needIds as $id => $indexes) {
            $student = $students[$id] ?? null;
            if (!is_array($student)) {
                continue;
            }
            $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
            $patch = [
                'displayName'    => trim((string) ($student['displayName'] ?? $personal['fullName'] ?? $personal['name'] ?? '')),
                'studentName'    => trim((string) ($student['displayName'] ?? $personal['fullName'] ?? $personal['name'] ?? '')),
                'registerNumber' => strtoupper(trim((string) ($student['registerNumber'] ?? ''))),
                'classBatch'     => trim((string) ($student['classBatch'] ?? '')),
                'phone'          => trim((string) ($personal['phone'] ?? $student['phone'] ?? '')),
                'collegeEmail'   => trim((string) ($personal['collegeEmail'] ?? $student['collegeEmail'] ?? '')),
                'email'          => trim((string) ($personal['collegeEmail'] ?? $student['collegeEmail'] ?? '')),
            ];
            foreach ($indexes as $idx) {
                foreach ($patch as $key => $value) {
                    if ($value === '') {
                        continue;
                    }
                    $existing = trim((string) ($rows[$idx][$key] ?? ''));
                    if ($existing === '') {
                        $rows[$idx][$key] = $value;
                    }
                }
            }
        }

        if (!$dropIncomplete) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->rosterRowHasSnapshot($row)
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rosterRowHasSnapshot(array $row): bool
    {
        $name = trim((string) ($row['studentName'] ?? $row['displayName'] ?? ''));
        $register = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));

        return $name !== '' || $register !== '';
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, mixed> $listCtx
     * @param array<string, string> $filters
     * @param array<int, array<string, mixed>> $registry
     * @return array<string, mixed>
     */
    private function buildRegistryResponse(
        array $staffCtx,
        array $listCtx,
        array $filters,
        array $registry
    ): array {
        $batch = trim((string) ($filters['batch'] ?? ''));
        $filtered = $this->applyFilters($registry, $filters);
        $filterOptions = $this->buildLiteFilterOptions($listCtx, $filters, $filtered);

        $placementCount = 0;
        $higherCount = 0;
        $researchCount = 0;
        $filledCount = 0;
        foreach ($filtered as $row) {
            $employer = trim((string) ($row['employer'] ?? ''));
            if ($employer === '') {
                continue;
            }
            $filledCount++;
            $rowType = (string) ($row['type'] ?? '');
            if ($rowType === 'Higher Education') {
                $higherCount++;
            } elseif ($rowType === 'Research') {
                $researchCount++;
            } else {
                $placementCount++;
            }
        }

        return [
            'filters' => $filterOptions,
            'columns' => self::registryTableColumns(),
            'rows'    => $filtered,
            'allFromTable' => !empty($filters['allFromTable']),
            'staffDepartmentId' => trim((string) ($staffCtx['departmentId'] ?? '')),
            'canEditBatch' => $batch !== '' && StaffContext::canEditClassBatch($staffCtx, $batch),
            'assignedClassBatches' => StaffContext::assignedClassBatches($staffCtx),
            'totals'  => [
                'all'               => count($filtered),
                'students'          => count($filtered),
                'filled'            => $filledCount,
                'placement'         => $placementCount,
                'higher_education'  => $higherCount,
                'research'          => $researchCount,
            ],
        ];
    }

    /**
     * Registry grid — columns mirror student_placements JSON payload (+ Sl#).
     *
     * @return list<array{key:string,label:string,sortable:bool,sortKey?:string,source:string,group?:string,payloadField?:string}>
     */
    public static function registryTableColumns(): array
    {
        return [
            ['key' => '_sl', 'label' => 'Sl#', 'sortable' => true, 'sortKey' => 'registerNumber', 'source' => 'student_placements', 'group' => 'snapshot'],
            ['key' => 'studentName', 'label' => 'Student name', 'sortable' => true, 'sortKey' => 'studentName', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'studentName'],
            ['key' => 'registerNumber', 'label' => 'Register no.', 'sortable' => true, 'sortKey' => 'registerNumber', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'registerNumber'],
            ['key' => 'classBatch', 'label' => 'Class batch', 'sortable' => true, 'sortKey' => 'classBatch', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'classBatch'],
            ['key' => 'programme', 'label' => 'Programme', 'sortable' => true, 'sortKey' => 'programme', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'programme'],
            ['key' => 'phone', 'label' => 'Phone', 'sortable' => true, 'sortKey' => 'phone', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'phone'],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true, 'sortKey' => 'email', 'source' => 'student_placements', 'group' => 'snapshot', 'payloadField' => 'email'],
            ['key' => 'company', 'label' => 'Company / institution', 'sortable' => true, 'sortKey' => 'company', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'company'],
            ['key' => 'recordType', 'label' => 'Record type', 'sortable' => true, 'sortKey' => 'recordType', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'recordType'],
            ['key' => 'role', 'label' => 'Role', 'sortable' => true, 'sortKey' => 'role', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'role'],
            ['key' => 'employerContact', 'label' => 'Contact', 'sortable' => true, 'sortKey' => 'employerContact', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'employerContact'],
            ['key' => 'address', 'label' => 'Address', 'sortable' => true, 'sortKey' => 'address', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'address'],
            ['key' => 'package', 'label' => 'Package', 'sortable' => true, 'sortKey' => 'package', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'package'],
            ['key' => 'placementStatus', 'label' => 'Status', 'sortable' => true, 'sortKey' => 'placementStatus', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'placementStatus'],
            ['key' => 'fordvv', 'label' => 'For DVV', 'sortable' => true, 'sortKey' => 'fordvv', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'fordvv'],
            ['key' => 'includedvv', 'label' => 'Included DVV', 'sortable' => true, 'sortKey' => 'includedvv', 'source' => 'student_placements', 'group' => 'placement', 'payloadField' => 'includedvv'],
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
            // Keep PlaceHub identity/placement; fill AES class/programme gaps so
            // program filters do not drop classmates after the merge.
            $byKey[$key] = $this->mergeRosterStudentRows($byKey[$key], $row);
        }

        return array_merge(array_values($byKey), $unkeyed);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRegistryStudRoleFilter(string $studRole): string
    {
        $role = strtolower(trim($studRole));
        if ($role === '' || $role === 'all' || $role === 'both') {
            return 'all';
        }

        return $role === 'alumni' ? 'alumni' : 'student';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRosterByPlacementStudRole(array $rows, string $studRole): array
    {
        if ($this->normalizeRegistryStudRoleFilter($studRole) === 'all') {
            return $rows;
        }
        $want = strtolower(trim($studRole)) === 'alumni' ? 'alumni' : 'student';

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->rosterRowMatchesPlacementStudRole($row, $want)
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rosterRowMatchesPlacementStudRole(array $row, string $wantRole): bool
    {
        $role = $this->normalizeRosterStudRole($row);
        if ($role === 'alumni' || $role === 'student') {
            return $role === $wantRole;
        }

        // Legacy rows saved before studRole was stored — show under both filters until re-sync stamps role.
        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function registryRowMatchesStudRole(array $row, string $studRole): bool
    {
        if ($this->normalizeRegistryStudRoleFilter($studRole) === 'all') {
            return true;
        }

        return $this->rosterRowMatchesPlacementStudRole(
            $row,
            strtolower(trim($studRole)) === 'alumni' ? 'alumni' : 'student'
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function normalizeRosterStudRole(array $row): ?string
    {
        $probe = $row;
        if (!isset($probe['stud_role']) && isset($row['studRole'])) {
            $probe['stud_role'] = $row['studRole'];
        }

        return AesApiService::normalizeStudRole($probe);
    }

    /**
     * @param array<int, array<string, mixed>> $rosterSource
     * @return array<int, array<string, mixed>>
     */
    private function registryEntriesFromRoster(array $rosterSource, bool $studentPlacementsTableOnly = false): array
    {
        $registry = [];
        foreach ($rosterSource as $row) {
            foreach ($this->extractRegistryRows($row, false, $studentPlacementsTableOnly) as $entry) {
                $registry[] = $entry;
            }
        }
        $registry = $this->deduplicateStudentRows($registry);
        usort($registry, static fn (array $a, array $b): int => strcasecmp(
            (string) ($a['studentName'] ?? ''),
            (string) ($b['studentName'] ?? '')
        ));

        return $registry;
    }

    /**
     * Local row wins for ids/placement; AES fills empty class/programme fields.
     *
     * @param array<string, mixed> $local
     * @param array<string, mixed> $aes
     * @return array<string, mixed>
     */
    private function mergeRosterStudentRows(array $local, array $aes): array
    {
        foreach ([
            'displayName', 'classBatch', 'stud_class', 'stud_course', 'stud_branch',
            'programme', 'admno', 'collegeEmail', 'personalEmail', 'phone', 'photoUrl',
            'studRole', 'stud_role',
            'year', 'semester',
            // AES course/branch numeric ids — local placement shells often omit these.
            'courseId', 'course_id', 'stud_courseid', 'stud_course_id',
            'branchId', 'branch_id', 'stud_branchid', 'stud_branch_id',
            'stud_deptcode', 'parentDepartmentCode', 'deptCode',
        ] as $field) {
            $localVal = $local[$field] ?? null;
            $aesVal = $aes[$field] ?? null;
            $localEmpty = $localVal === null || $localVal === '' || $localVal === [];
            if ($localEmpty && $aesVal !== null && $aesVal !== '' && $aesVal !== []) {
                $local[$field] = $aesVal;
            }
        }

        // Prefer AES numeric course/branch ids when local values are blank or non-numeric.
        foreach (['courseId' => ['courseId', 'course_id', 'stud_courseid'], 'branchId' => ['branchId', 'branch_id', 'stud_branchid']] as $canon => $aliases) {
            $localId = '';
            foreach ($aliases as $alias) {
                $v = trim((string) ($local[$alias] ?? ''));
                if ($v !== '') {
                    $localId = $v;
                    break;
                }
            }
            $aesId = '';
            foreach ($aliases as $alias) {
                $v = trim((string) ($aes[$alias] ?? ''));
                if ($v !== '') {
                    $aesId = $v;
                    break;
                }
            }
            if ($aesId !== '' && ($localId === '' || !ctype_digit($localId))) {
                $local[$canon] = $aesId;
                foreach ($aliases as $alias) {
                    $local[$alias] = $aesId;
                }
            }
        }

        $localBatch = trim((string) ($local['classBatch'] ?? $local['stud_class'] ?? ''));
        $aesBatch = trim((string) ($aes['classBatch'] ?? $aes['stud_class'] ?? ''));
        // Prefer the more specific semester label when both belong to the same cohort.
        if ($aesBatch !== '' && (
            $localBatch === ''
            || (
                strcasecmp(ClassInchargeRegistry::cohortKey($localBatch), ClassInchargeRegistry::cohortKey($aesBatch)) === 0
                && preg_match('/-S\d+$/i', $aesBatch) === 1
                && preg_match('/-S\d+$/i', $localBatch) !== 1
            )
        )) {
            $local['classBatch'] = $aesBatch;
            $local['stud_class'] = $aesBatch;
        }

        return $local;
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
            $existingEmployer = trim((string) ($unique[$key]['employer'] ?? $unique[$key]['company'] ?? ''));
            $newEmployer = trim((string) ($row['employer'] ?? $row['company'] ?? ''));
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
            if ($value === '') {
                continue;
            }
            // Normalize numeric admission numbers so 014570 and 14570 collapse.
            if (preg_match('/^\d+$/', $value) === 1) {
                return ltrim($value, '0') ?: '0';
            }

            return $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function extractRegistryRows(array $row, bool $includeRecruitmentResults = true, bool $studentPlacementsTableOnly = false): array
    {
        $studentId = (string) ($row['id'] ?? $row['studentId'] ?? $row['_id'] ?? '');
        if ($studentId === '') {
            return [];
        }

        $placed = ($row['placed'] ?? false) === true;
        $studentName = trim((string) (
            $row['studentName']
            ?? $row['displayName']
            ?? $row['user']['name']
            ?? ''
        ));
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
            'programme'       => $program,
            'branch'          => $branch,
            'batch'           => $batch,
            'classBatch'      => $batch,
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
                'fordvv'           => $this->normalizeVvValue($placement['fordvv'] ?? '1'),
                'includedvv'       => $this->normalizeVvValue($placement['includedvv'] ?? '1'),
                'type'             => $this->resolveRecordType($placement),
                'source'           => $studentPlacementsTableOnly ? 'student_placements' : (string) ($placement['source'] ?? 'placement'),
                'hasOfferLetter'   => (string) ($placement['offerLetter'] ?? '') !== ''
                    || (!$studentPlacementsTableOnly && is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['offerLetter'] ?? '') !== ''),
                'hasJoiningLetter' => (string) ($placement['joiningLetter'] ?? '') !== ''
                    || (!$studentPlacementsTableOnly && is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['joiningLetter'] ?? '') !== ''),
                'hasCompanyIdDoc'  => (string) ($placement['companyIdDoc'] ?? '') !== ''
                    || (!$studentPlacementsTableOnly && is_array($row['selfPlacement'] ?? null) && (string) ($row['selfPlacement']['companyIdDoc'] ?? '') !== ''),
                'canVerify'        => false,
            ], $seen);
        }

        if ($studentPlacementsTableOnly) {
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
                    'fordvv'           => $this->normalizeVvValue($placement['fordvv'] ?? '1'),
                    'includedvv'       => $this->normalizeVvValue($placement['includedvv'] ?? '1'),
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
                    'fordvv'           => $this->normalizeVvValue($self['fordvv'] ?? $placement['fordvv'] ?? '1'),
                    'includedvv'       => $this->normalizeVvValue($self['includedvv'] ?? $placement['includedvv'] ?? '1'),
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
                'fordvv'           => $this->normalizeVvValue($placement['fordvv'] ?? '1'),
                'includedvv'       => $this->normalizeVvValue($placement['includedvv'] ?? '1'),
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

        $data['fordvv'] = $this->normalizeVvValue($data['fordvv'] ?? '1');
        $data['includedvv'] = $this->normalizeVvValue($data['includedvv'] ?? '1');

        return $this->presentRegistryRow(array_merge($meta, $data));
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

        $data['fordvv'] = $this->normalizeVvValue($data['fordvv'] ?? '1');
        $data['includedvv'] = $this->normalizeVvValue($data['includedvv'] ?? '1');

        return $this->presentRegistryRow(array_merge($meta, $data));
    }

    /**
     * Flatten registry row keys to match student_placements payload for the UI grid.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRegistryRow(array $row): array
    {
        $company = trim((string) ($row['company'] ?? $row['employer'] ?? ''));
        $row['company'] = $company;
        $row['employer'] = $company;

        $recordType = trim((string) ($row['recordType'] ?? $row['type'] ?? ''));
        if ($recordType === '' && $company !== '') {
            $recordType = 'Placement';
        }
        $row['recordType'] = $recordType;
        if ($recordType !== '') {
            $row['type'] = $recordType;
        }

        $row['studentName'] = trim((string) (
            $row['studentName']
            ?? $row['displayName']
            ?? ''
        ));
        $row['registerNumber'] = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admissionNo'] ?? '')));
        $row['classBatch'] = trim((string) ($row['classBatch'] ?? $row['batch'] ?? ''));
        $row['programme'] = trim((string) ($row['programme'] ?? $row['program'] ?? ''));
        $row['phone'] = trim((string) ($row['phone'] ?? ''));
        $row['email'] = trim((string) ($row['email'] ?? ''));
        $row['role'] = trim((string) ($row['role'] ?? ''));
        $row['placementStatus'] = trim((string) ($row['placementStatus'] ?? ''));

        return $row;
    }

    /**
     * Push fetched class roster (+ placement overlay) into student_placements only.
     *
     * @param array<int, array<string, mixed>> $classRows
     */
    /**
     * Materialize getAllStudInfo4Placement roster shells into student_placements (per class).
     *
     * @param array<int, array<string, mixed>> $classRows
     */
    private function syncAesDirectoryRowsToStudentPlacements(
        array $classRows,
        string $departmentId,
        string $placementStudRole = 'student'
    ): int {
        $grouped = [];
        foreach ($classRows as $row) {
            $batch = trim((string) ($row['classBatch'] ?? $row['stud_class'] ?? $row['batch'] ?? ''));
            if ($batch === '') {
                continue;
            }
            $program = DepartmentProgrammeCatalog::resolveProgrammeCode(trim((string) (
                $row['programme'] ?? $row['stud_course'] ?? $row['program'] ?? ''
            )));
            if ($program === '') {
                $norm = DepartmentProgrammeCatalog::normalizeCode($batch);
                if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
                    $program = 'INMCA';
                } elseif (str_starts_with($norm, 'MCA')) {
                    $program = 'MCA';
                } elseif (str_contains($norm, 'BCA')) {
                    $program = 'BCA';
                }
            }
            $key = strtoupper($program . '|' . $batch);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['program' => $program, 'batch' => $batch, 'rows' => []];
            }
            $grouped[$key]['rows'][] = $row;
        }

        $synced = 0;
        foreach ($grouped as $group) {
            $synced += $this->syncClassRowsToStudentPlacements(
                $group['rows'],
                $departmentId,
                (string) $group['program'],
                (string) $group['batch'],
                $placementStudRole
            );
        }

        return $synced;
    }

    private function syncClassRowsToStudentPlacements(
        array $classRows,
        string $departmentId,
        string $program,
        string $batch,
        string $placementStudRole = 'student'
    ): int {
        $model = new StudentPlacementModel();
        $deptId = trim($departmentId);
        $programCode = $program !== ''
            ? DepartmentProgrammeCatalog::resolveProgrammeCode($program)
            : '';
        $batchLabel = trim($batch);
        $synced = 0;

        foreach ($classRows as $row) {
            $studentId = $this->registryStudentId($row);
            $register = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
            if ($studentId === '' || $register === '') {
                continue;
            }

            $embedded = is_array($row['placement'] ?? null) ? $row['placement'] : [];
            $fromDirectory = (new AesApiService())->placementFieldsFromStudInfoDirectoryRecord(
                array_merge($row, $embedded)
            );
            $placement = $this->placementFieldsForAesSync(array_merge($embedded, $fromDirectory));
            $meta = StudentPlacementModel::normalizeRosterMeta(array_merge(
                $row,
                $this->rosterStudRoleFieldsForSync($row, $placementStudRole),
                [
                    'classBatch' => $batchLabel !== '' ? $batchLabel : ($row['classBatch'] ?? $row['stud_class'] ?? ''),
                    'programme'  => $programCode !== '' ? $programCode : ($row['programme'] ?? $row['stud_course'] ?? ''),
                ]
            ));

            try {
                $model->upsertForStudent(
                    $studentId,
                    $register,
                    $placement,
                    $deptId !== '' ? $deptId : null,
                    $meta
                );
                $synced++;
            } catch (\Throwable) {
                // Registry still falls back to in-memory class rows if the table is unavailable.
            }
        }

        return $synced;
    }

    /**
     * AES sync writes placement / higher-education payload fields only (not unrelated profile data).
     *
     * @param array<string, mixed> $placement
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array{studRole: string, stud_role: string}
     */
    private function rosterStudRoleFieldsForSync(array $row, string $scopeRole): array
    {
        $scopeRole = strtolower(trim($scopeRole)) === 'alumni' ? 'alumni' : 'student';
        $role = AesApiService::normalizeStudRole($row) ?? $scopeRole;

        return [
            'studRole'  => $role,
            'stud_role' => $role === 'alumni' ? 'Alumni' : 'Student',
        ];
    }

    private function placementFieldsForAesSync(array $placement): array
    {
        $allowed = [
            'company', 'companyName', 'role', 'address', 'package', 'employerContact', 'contact',
            'joinDate', 'endDate', 'academicDuration', 'internshipDetails', 'natureOfJob',
            'monthlySalary', 'placementStatus', 'recordType', 'type', 'source',
            'offerLetterVerified', 'verificationDate', 'fordvv', 'includedvv',
            'offerLetter', 'joiningLetter', 'companyIdDoc',
        ];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $placement)) {
                $out[$key] = $placement[$key];
            }
        }
        if (isset($out['companyName']) && !isset($out['company'])) {
            $out['company'] = $out['companyName'];
        }

        return $out;
    }

    /**
     * Prefer student_placements rows; keep any class member missing from the table (sync failure).
     *
     * @param array<int, array<string, mixed>> $tableRows
     * @param array<int, array<string, mixed>> $classRows
     * @return array<int, array<string, mixed>>
     */
    private function mergeTableRosterWithClass(array $tableRows, array $classRows): array
    {
        $byKey = [];
        foreach ($tableRows as $row) {
            $key = $this->studentRowKey($row);
            if ($key === '') {
                continue;
            }
            $byKey[$key] = $row;
        }

        foreach ($classRows as $row) {
            $key = $this->studentRowKey($row);
            if ($key === '' || isset($byKey[$key])) {
                continue;
            }
            $byKey[$key] = $row;
        }

        return array_values($byKey);
    }

    /**
     * Stable registry key for student_placements (PlaceHub id or AES register number).
     *
     * @param array<string, mixed> $row
     */
    private function registryStudentId(array $row): string
    {
        $id = trim((string) ($row['id'] ?? $row['_id'] ?? $row['studentId'] ?? ''));
        if ($id !== '' && Security::isValidId($id)) {
            return $id;
        }

        $register = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
        if ($register !== '') {
            return $register;
        }

        return $id;
    }

    /**
     * Lightweight filter options for a selected class — no second AES directory scan.
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @param array<int, array<string, mixed>> $rows
     * @return array{programs: string[], branches: string[], batches: string[], departments: array<int, array{id:string,code:string,name:string}>}
     */
    private function buildLiteFilterOptions(array $staffCtx, array $filters, array $rows): array
    {
        $program = trim((string) ($filters['program'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $programs = [];
        $batches = [];

        foreach (StaffContext::assignedClassBatches($staffCtx) as $assigned) {
            $assigned = ClassInchargeRegistry::batchLabelWithoutSemester(trim((string) $assigned));
            if ($assigned === '') {
                continue;
            }
            $batches[] = $assigned;
            $hint = DepartmentProgrammeCatalog::resolveProgrammeCode($assigned);
            if ($hint === '') {
                $norm = DepartmentProgrammeCatalog::normalizeCode($assigned);
                if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
                    $hint = 'INMCA';
                } elseif (str_starts_with($norm, 'MCA')) {
                    $hint = 'MCA';
                } elseif (str_contains($norm, 'BCA')) {
                    $hint = 'BCA';
                }
            }
            if ($hint !== '') {
                $programs[] = $hint;
            }
        }

        foreach ($rows as $row) {
            $p = trim((string) ($row['program'] ?? $row['programme'] ?? ''));
            $b = trim((string) ($row['batch'] ?? $row['classBatch'] ?? ''));
            if ($p !== '') {
                $programs[] = $p;
            }
            if ($b !== '') {
                $batches[] = $b;
            }
        }

        if ($program !== '') {
            $programs[] = $program;
        }
        if ($batch !== '') {
            $batches[] = $batch;
        }

        $filterSvc = new PlacementFilterService();
        $filterCtx = $this->placementFilterCtx($staffCtx, $filters);
        $branch = trim((string) ($filters['branch'] ?? ''));
        $programs = array_values(array_unique(array_filter(array_merge(
            $filterSvc->fetchProgramOptions($filterCtx),
            $programs
        ))));
        $batches = array_values(array_unique(array_filter(array_merge(
            $filterSvc->fetchBatchOptions($filterCtx, $program, $branch, false),
            $batches
        ))));

        sort($programs, SORT_STRING);
        sort($batches, SORT_STRING);

        return [
            'programs'    => $programs,
            'branches'    => $program !== '' ? $filterSvc->fetchBranchOptions($filterCtx, $program) : [],
            'batches'     => $batches,
            'departments' => $this->loadAllDepartments(),
        ];
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array{programs: string[], branches: string[], batches: string[], departments: array<int, array{id:string,name:string}>}
     */
    private function buildFilterOptions(array $staffCtx, array $filters = []): array
    {
        $filterSvc = new PlacementFilterService();
        $filterCtx = $this->placementFilterCtx($staffCtx, $filters);
        $program = trim((string) ($filters['program'] ?? ''));
        $branch = trim((string) ($filters['branch'] ?? ''));

        $programs = $filterSvc->fetchProgramOptions($filterCtx);
        $branches = $program !== '' ? $filterSvc->fetchBranchOptions($filterCtx, $program) : [];
        $batches = $filterSvc->fetchBatchOptions($filterCtx, $program, $branch, false);

        return [
            'programs'     => $programs,
            'branches'     => $branches,
            'batches'      => $batches,
            'departments'  => $this->loadAllDepartments(),
        ];
    }

    /**
     * Listing scope from UI department filter (empty = staff home department when assigned).
     *
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function resolveRegistryListContext(array $staffCtx, array $filters): array
    {
        $selectedDeptId = trim((string) ($filters['departmentId'] ?? ''));
        if ($selectedDeptId === '') {
            $staffDept = trim((string) ($staffCtx['departmentId'] ?? ''));
            if ($staffDept !== '' && is_array($staffCtx['department'] ?? null)) {
                return $staffCtx;
            }

            return array_merge($staffCtx, [
                'departmentId' => '',
                'department'   => null,
                'campusWide'   => true,
            ]);
        }

        if ($selectedDeptId === trim((string) ($staffCtx['departmentId'] ?? ''))) {
            return $staffCtx;
        }

        $dept = (new DepartmentModel())->findById($selectedDeptId);
        if (!is_array($dept) || $dept === []) {
            return $staffCtx;
        }

        return array_merge($staffCtx, [
            'departmentId' => $selectedDeptId,
            'department'   => $dept,
            'campusWide'   => false,
        ]);
    }

    /**
     * Fast AES-free filter context for dropdown endpoints.
     *
     * @param array<string, mixed> $staffCtx
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $staffCtx
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function placementFilterCtx(array $staffCtx, array $filters = []): array
    {
        $listCtx = $this->resolveRegistryListContext($staffCtx, $filters);
        $studRole = $this->normalizeRegistryStudRoleFilter((string) ($filters['studRole'] ?? 'all'));
        $deptId = trim((string) ($listCtx['departmentId'] ?? ''));

        return array_merge(StaffContext::officerCompatible($listCtx), [
            'filterMode' => true,
            'placementStudRole' => $studRole,
            'campusWide' => $deptId === '' || !empty($listCtx['campusWide']),
            'placementStaffRegistryFilters' => true,
        ]);
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
        $api = new AesApiService();
        try {
            $api->syncDepartmentsToLocal();
        } catch (\Throwable) {
            // Serve local departments when AES is unreachable.
        }

        $catalog = [];
        try {
            $catalog = $api->listDepartments();
        } catch (\Throwable) {
            $catalog = [];
        }

        $model = new DepartmentModel();
        $rows = [];

        if ($catalog !== []) {
            foreach ($catalog as $aesRow) {
                $code = strtoupper(trim((string) ($aesRow['code'] ?? '')));
                $name = trim((string) ($aesRow['name'] ?? ''));
                if ($code === '' || $name === '' || preg_match('/^\d+$/', $code) === 1) {
                    continue;
                }
                if (!DepartmentModel::isStudentAcademicDepartment($code, $name)) {
                    continue;
                }
                $local = $model->findByCode($code);
                if ($local === null) {
                    continue;
                }
                $rows[] = [
                    'id'   => (string) ($local['_id'] ?? ''),
                    'code' => $code,
                    'name' => $name,
                ];
            }
        } else {
            foreach ($model->findAll([], 200) as $dept) {
                $code = strtoupper(trim((string) ($dept['code'] ?? '')));
                $name = trim((string) ($dept['name'] ?? ''));
                if ($code === '' || $name === '' || preg_match('/^\d+$/', $code) === 1) {
                    continue;
                }
                if (!DepartmentModel::isStudentAcademicDepartment($code, $name)) {
                    continue;
                }
                if (empty($dept['aesAcademic']) && trim((string) ($dept['aesId'] ?? '')) === '') {
                    continue;
                }
                $rows[] = [
                    'id'   => (string) ($dept['_id'] ?? ''),
                    'code' => $code,
                    'name' => $name,
                ];
            }
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
     * Full student_placements table — optional type/search only (no dept/programme/batch scope).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyOptionalRegistrySearchFilters(array $rows, array $filters): array
    {
        $type = trim((string) ($filters['type'] ?? ''));
        $q = strtolower(trim((string) ($filters['q'] ?? $filters['search'] ?? '')));
        if ($type === '' && $q === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($type, $q): bool {
            if ($type !== '') {
                $want = match ($type) {
                    'higher_education' => 'Higher Education',
                    'research' => 'Research',
                    default => 'Placement',
                };
                $employer = trim((string) ($row['employer'] ?? $row['company'] ?? ''));
                if ($employer !== '' && (string) ($row['type'] ?? $row['recordType'] ?? '') !== $want) {
                    return false;
                }
            }
            if ($q === '') {
                return true;
            }
            $hay = strtolower(implode(' ', [
                (string) ($row['studentName'] ?? ''),
                (string) ($row['registerNumber'] ?? ''),
                (string) ($row['classBatch'] ?? ''),
                (string) ($row['programme'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['company'] ?? ''),
                (string) ($row['employer'] ?? ''),
                (string) ($row['recordType'] ?? ''),
            ]));

            return str_contains($hay, $q);
        }));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        if (!empty($filters['allFromTable'])) {
            return $this->applyOptionalRegistrySearchFilters($rows, $filters);
        }

        $program = trim((string) ($filters['program'] ?? ''));
        $branch = trim((string) ($filters['branch'] ?? ''));
        $batch = trim((string) ($filters['batch'] ?? ''));
        $type = trim((string) ($filters['type'] ?? ''));
        $departmentId = trim((string) ($filters['departmentId'] ?? ''));
        $studRole = $this->normalizeRegistryStudRoleFilter((string) ($filters['studRole'] ?? 'all'));
        $q = strtolower(trim((string) ($filters['q'] ?? $filters['search'] ?? '')));

        $wantCohort = $batch !== '' ? ClassInchargeRegistry::cohortKey($batch) : '';
        $wantProgram = $program !== ''
            ? DepartmentProgrammeCatalog::resolveProgrammeCode($program)
            : '';

        return array_values(array_filter($rows, function (array $row) use ($program, $wantProgram, $branch, $batch, $wantCohort, $type, $departmentId, $studRole, $q): bool {
            if (!$this->registryRowMatchesStudRole($row, $studRole)) {
                return false;
            }
            if ($departmentId !== '') {
                $rowDept = trim((string) ($row['departmentId'] ?? ''));
                if ($rowDept !== '' && strcasecmp($rowDept, $departmentId) !== 0) {
                    return false;
                }
                if ($rowDept === '' && $batch === '' && $program === '') {
                    return false;
                }
            }
            if ($batch !== '') {
                $rowBatch = trim((string) ($row['batch'] ?? $row['classBatch'] ?? ''));
                $batchOk = StudentPlacementModel::matchesClassBatchSelection($rowBatch, $batch);
                if (!$batchOk) {
                    return false;
                }
                // Class batch already scopes the roster; do not drop late-fee /
                // local classmates whose programme label is blank or dept-coded.
            } elseif ($program !== '') {
                $rowProgram = (string) ($row['program'] ?? $row['programme'] ?? '');
                $rowBatch = trim((string) ($row['batch'] ?? $row['classBatch'] ?? ''));
                $fromBatch = $rowBatch !== ''
                    ? DepartmentProgrammeCatalog::resolveProgrammeCode($rowBatch)
                    : '';
                if ($fromBatch === '' && $rowBatch !== '') {
                    $norm = DepartmentProgrammeCatalog::normalizeCode($rowBatch);
                    if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
                        $fromBatch = 'INMCA';
                    } elseif (str_starts_with($norm, 'MCA')) {
                        $fromBatch = 'MCA';
                    } elseif (str_contains($norm, 'BCA')) {
                        $fromBatch = 'BCA';
                    }
                }
                $match = strcasecmp($rowProgram, $program) === 0
                    || ($wantProgram !== '' && strcasecmp(
                        DepartmentProgrammeCatalog::resolveProgrammeCode($rowProgram),
                        $wantProgram
                    ) === 0)
                    || ($wantProgram !== '' && $fromBatch !== '' && strcasecmp($fromBatch, $wantProgram) === 0)
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
            if ($type !== '') {
                $want = match ($type) {
                    'higher_education' => 'Higher Education',
                    'research' => 'Research',
                    default => 'Placement',
                };
                $employer = trim((string) ($row['employer'] ?? $row['company'] ?? ''));
                // Keep unfilled class-roster rows visible so staff can add details.
                if ($employer !== '' && (string) ($row['type'] ?? $row['recordType'] ?? '') !== $want) {
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
                (string) ($row['classBatch'] ?? ''),
                (string) ($row['programme'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['company'] ?? ''),
                (string) ($row['employer'] ?? ''),
                (string) ($row['recordType'] ?? ''),
                (string) ($row['role'] ?? ''),
                (string) ($row['contact'] ?? ''),
                (string) ($row['address'] ?? ''),
                (string) ($row['package'] ?? ''),
                (string) ($row['placementStatus'] ?? ''),
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
        if ($aesProgram === '') {
            $batchHint = trim((string) ($row['classBatch'] ?? $row['stud_class'] ?? $row['batch'] ?? ''));
            if ($batchHint !== '') {
                $aesProgram = DepartmentProgrammeCatalog::resolveProgrammeCode($batchHint);
                if ($aesProgram === '') {
                    $norm = DepartmentProgrammeCatalog::normalizeCode($batchHint);
                    if (str_contains($norm, 'MCAINT') || str_contains($norm, 'INMCA')) {
                        $aesProgram = 'INMCA';
                    } elseif (str_starts_with($norm, 'MCA')) {
                        $aesProgram = 'MCA';
                    } elseif (str_contains($norm, 'BCA')) {
                        $aesProgram = 'BCA';
                    }
                }
            }
        }
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
            if ($value !== '' && ctype_digit($value)) {
                $courseId = $value;
                break;
            }
            if ($courseId === '' && $value !== '') {
                $courseId = $value;
            }
        }

        $branchId = '';
        foreach ([
            'branchId', 'branch_id', 'BranchId', 'branchid',
            'stud_branchid', 'stud_branch_id', 'stud_branchId',
        ] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                $branchId = $value;
                break;
            }
            if ($branchId === '' && $value !== '') {
                $branchId = $value;
            }
        }

        // Also inspect nested AES/local department payloads.
        $dept = is_array($row['department'] ?? null) ? $row['department'] : [];
        if ($courseId === '' || !ctype_digit($courseId)) {
            $fromDept = trim((string) ($dept['aesId'] ?? ''));
            if ($fromDept !== '' && ctype_digit($fromDept)) {
                $courseId = $fromDept;
            } elseif ($courseId === '') {
                $fromDept = trim((string) ($dept['code'] ?? ''));
                if ($fromDept !== '' && ctype_digit($fromDept)) {
                    $courseId = $fromDept;
                }
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
        if (in_array($raw, ['research', 'research scholar', 'phd', 'ph.d', 'fellowship'], true)) {
            return 'Research';
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
     * Free-text VV fields (kept editable for future AES / NAAC codes).
     */
    private function normalizeVvValue(mixed $value, string $default = '1'): string
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $raw = trim((string) $value);

        return $raw !== '' ? $raw : $default;
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
        StaffContext::assertCanEditClassPlacement($student, $staffCtx);
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
        $fordvv = $this->normalizeVvValue($input['fordvv'] ?? '1');
        $includedvv = $this->normalizeVvValue($input['includedvv'] ?? '1');
        $typeRaw = trim((string) ($input['type'] ?? 'Placement'));
        $typeKey = strtolower($typeRaw);
        if (str_contains($typeKey, 'higher') || str_contains($typeKey, 'education')) {
            $recordType = 'Higher Education';
        } elseif (str_contains($typeKey, 'research')) {
            $recordType = 'Research';
        } else {
            $recordType = 'Placement';
        }

        if ($employer === '') {
            Response::error('Employer / institution name is required.', 422);
        }

        $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));
        $placementModel = new StudentPlacementModel();
        $placement = $placementModel->findPlacementByStudent((string) $student['_id']);
        if ($placement === null && $register !== '') {
            $fromReg = $placementModel->findPlacementMapByRegisterNumbers([$register]);
            $placement = $fromReg[$register] ?? null;
        }
        if (!is_array($placement)) {
            $placement = [];
        }
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

        $scopeDeptId = trim((string) ($staffCtx['departmentId'] ?? ''));
        $registryId = $this->registryStudentId($student);
        try {
            $savedId = (new StudentPlacementModel())->upsertForStudent(
                $registryId,
                strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? ''))),
                $placement,
                $scopeDeptId !== '' ? $scopeDeptId : null,
                $this->rosterMetaForStudentPlacement($student)
            );
        } catch (\Throwable $e) {
            Response::error('Could not save placement registry: ' . $e->getMessage(), 500);
        }

        if ($savedId === '') {
            Response::error('Could not save placement registry. Ensure student_placements table exists.', 500);
        }

        return [
            'studentId' => (string) ($student['_id'] ?? $registryId),
            'placement' => DocumentHelper::serialize($placement),
        ];
    }

    /**
     * Snapshot class identity on student_placements for passed-out / AES-missing cohorts.
     *
     * @param array<string, mixed> $student
     * @return array<string, mixed>
     */
    private function rosterMetaForStudentPlacement(array $student): array
    {
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? '')));

        return StudentPlacementModel::normalizeRosterMeta([
            'classBatch'  => $student['classBatch'] ?? $student['stud_class'] ?? '',
            'programme'   => $student['programme'] ?? $student['stud_course'] ?? '',
            'branch'      => $student['branch'] ?? $student['stud_branch'] ?? '',
            'studentName' => $personal['fullName'] ?? $student['displayName'] ?? $student['stud_name'] ?? '',
            'courseId'    => $student['courseId'] ?? $student['course_id'] ?? '',
            'branchId'    => $student['branchId'] ?? $student['branch_id'] ?? '',
            'phone'       => $personal['phone'] ?? $student['phone'] ?? '',
            'email'       => $personal['collegeEmail'] ?? $student['collegeEmail'] ?? $student['email'] ?? '',
            'admissionNo' => $student['admno'] ?? $student['admissionNo'] ?? $register,
        ]);
    }

    /**
     * Overlay authoritative registry rows from student_placements onto roster/student lists.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function attachRegistryPlacements(array $rows): array
    {
        $ids = [];
        $registers = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? $row['_id'] ?? $row['studentId'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
            $reg = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
            if ($reg !== '') {
                $registers[] = $reg;
            }
        }

        $model = new StudentPlacementModel();
        try {
            $byId = $ids !== [] ? $model->findPlacementMapByStudentIds($ids) : [];
            $byRegister = $registers !== [] ? $model->findPlacementMapByRegisterNumbers($registers) : [];
        } catch (\Throwable) {
            $byId = [];
            $byRegister = [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['id'] ?? $row['_id'] ?? $row['studentId'] ?? ''));
            $reg = strtoupper(trim((string) ($row['registerNumber'] ?? $row['admno'] ?? '')));
            $embedded = is_array($row['placement'] ?? null) ? $row['placement'] : [];

            $placement = [];
            if ($id !== '' && isset($byId[$id])) {
                $placement = $byId[$id];
            } elseif ($reg !== '' && isset($byRegister[$reg])) {
                $placement = $byRegister[$reg];
            } elseif ($embedded !== [] && trim((string) ($embedded['company'] ?? '')) !== '') {
                $placement = $this->migrateEmbeddedPlacementToTable($model, $row, $embedded, $reg);
            }

            $row['placement'] = $placement;
            $row['placed'] = trim((string) ($placement['company'] ?? '')) !== '';
            $out[] = $row;
        }

        return $out;
    }

    /**
     * One-time copy of legacy students.placement into student_placements for this roster row.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $embedded
     * @return array<string, mixed>
     */
    private function migrateEmbeddedPlacementToTable(
        StudentPlacementModel $model,
        array $row,
        array $embedded,
        string $register
    ): array {
        $studentId = trim((string) ($row['_id'] ?? $row['id'] ?? ''));
        if ($studentId === '' || !Security::isValidId($studentId)) {
            return [];
        }

        $deptId = trim((string) ($row['departmentId'] ?? ''));
        try {
            $model->upsertForStudent(
                $studentId,
                $register,
                $embedded,
                $deptId !== '' ? $deptId : null,
                StudentPlacementModel::normalizeRosterMeta($row)
            );
        } catch (\Throwable) {
            return [];
        }

        return StudentPlacementModel::placementFieldsFromDoc(array_merge($embedded, [
            'studentId' => $studentId,
            'registerNumber' => $register,
        ]));
    }

    /**
     * Registry entry is department-scoped for viewing. Writes are further limited
     * to AES class teacher / co-class teacher of the student's batch.
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
        StaffContext::assertCanEditClassPlacement($student, $staffCtx);
        $student = $this->ensureLocalStudentForStaffEdit($student, $staffCtx);

        $hasOffer = isset($_FILES['offerLetter']) && (int) ($_FILES['offerLetter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasJoining = isset($_FILES['joiningLetter']) && (int) ($_FILES['joiningLetter']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasCompanyId = isset($_FILES['companyIdDoc']) && (int) ($_FILES['companyIdDoc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasOffer && !$hasJoining && !$hasCompanyId) {
            Response::error('Upload at least one document: offer letter, joining letter, or company ID.', 400);
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $registerNo = (string) ($student['registerNumber'] ?? 'student');
        $registerKey = strtoupper(trim($registerNo));
        $placementModel = new StudentPlacementModel();
        $placement = $placementModel->findPlacementByStudent((string) $student['_id']);
        if ($placement === null && $registerKey !== '') {
            $fromReg = $placementModel->findPlacementMapByRegisterNumbers([$registerKey]);
            $placement = $fromReg[$registerKey] ?? null;
        }
        if (!is_array($placement)) {
            $placement = [];
        }
        $company = (string) ($placement['company'] ?? '');
        $safeCompany = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $company) ?: 'company';
        $savedPaths = [];

        $offerLetter = (string) ($placement['offerLetter'] ?? '');
        $joiningLetter = (string) ($placement['joiningLetter'] ?? '');
        $companyIdDoc = (string) ($placement['companyIdDoc'] ?? '');

        if ($hasOffer) {
            $error = Security::validateUploadedFile($_FILES['offerLetter'], $config['uploads']['max_resume'], ['pdf']);
            if ($error) {
                Response::error($error, 400);
            }
            $storedName = $registerNo . '_' . $safeCompany . '_offer_' . time() . '.pdf';
            $storage = new ObjectStorageService($config);
            try {
                $path = $storage->putUploadedFile(
                    ObjectStorageService::FOLDER_OFFER_LETTERS,
                    $storedName,
                    $_FILES['offerLetter']
                );
            } catch (\Throwable $e) {
                Response::error('Failed to save offer letter to S3: ' . $e->getMessage(), 500);
            }
            $savedPaths[] = $path;
            $offerLetter = $path;
        }

        $joiningLetter = $this->saveStaffDoc('joiningLetter', $registerNo, $safeCompany, 'joining_letter', ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg', 'webp'], $savedPaths) ?? $joiningLetter;
        $companyIdDoc = $this->saveStaffDoc('companyIdDoc', $registerNo, $safeCompany, 'company_id', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'], $savedPaths) ?? $companyIdDoc;

        $placement['offerLetter'] = $offerLetter;
        $placement['joiningLetter'] = $joiningLetter;
        $placement['companyIdDoc'] = $companyIdDoc;
        $placement['updatedAt'] = DocumentHelper::now();

        $scopeDeptId = trim((string) ($staffCtx['departmentId'] ?? ''));
        $registryId = $this->registryStudentId($student);
        try {
            $savedId = (new StudentPlacementModel())->upsertForStudent(
                $registryId,
                strtoupper(trim((string) ($student['registerNumber'] ?? $student['admno'] ?? ''))),
                $placement,
                $scopeDeptId !== '' ? $scopeDeptId : null,
                $this->rosterMetaForStudentPlacement($student)
            );
        } catch (\Throwable $e) {
            $storage = new ObjectStorageService();
            foreach ($savedPaths as $p) {
                $storage->delete((string) $p);
            }
            Response::error('Could not save documents to placement registry: ' . $e->getMessage(), 500);
        }

        if ($savedId === '') {
            $storage = new ObjectStorageService();
            foreach ($savedPaths as $p) {
                $storage->delete((string) $p);
            }
            Response::error('Could not save documents. Ensure student_placements table exists.', 500);
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

        $ext = strtolower(pathinfo((string) ($_FILES[$field]['name'] ?? ''), PATHINFO_EXTENSION));
        $storedName = $registerNo . '_' . $safeCompany . '_' . $prefix . '_' . time() . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $path = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_SELF_PLACEMENT,
                $storedName,
                $_FILES[$field]
            );
        } catch (\Throwable $e) {
            Response::error('Failed to save ' . str_replace('_', ' ', $prefix) . ' to S3: ' . $e->getMessage(), 500);
        }
        $savedPaths[] = $path;

        return $path;
    }
}
