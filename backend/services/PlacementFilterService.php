<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\StudentPlacementModel;
use PMS\Utils\Security;

/**
 * AES placement filters: program → branch → batch from getStudInfo4Placement only.
 */
final class PlacementFilterService
{
    /** @var array<string, list<array{stud_course:string,stud_branch:string,stud_class:string}>> */
    private static array $scopedRowsCache = [];

    public static function clearScopedRowsCache(): void
    {
        self::$scopedRowsCache = [];
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function resolveParentDeptAesId(array $ctx): string
    {
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $aesId = trim((string) ($dept['aesId'] ?? ''));
        if ($aesId !== '' && ctype_digit($aesId)) {
            return $aesId;
        }

        $code = strtoupper(trim((string) ($dept['code'] ?? '')));
        if ($code !== '' && ctype_digit($code)) {
            return $code;
        }

        $name = trim((string) ($dept['name'] ?? ''));
        $group = DepartmentProgrammeCatalog::findGroupForDepartment($code, $name);
        if ($group !== null) {
            $resolved = $this->resolveAesIdByParentName($group['parent']);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $this->resolveAesIdByCodeOrName($code, $name);
    }

    /**
     * Department visible to the signed-in staff member.
     *
     * @param array<string, mixed> $ctx
     * @return list<array{id:string,code:string,name:string}>
     */
    public function fetchDepartmentOptions(array $ctx): array
    {
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $id = (string) ($dept['_id'] ?? $ctx['departmentId'] ?? '');
        $code = strtoupper(trim((string) ($dept['code'] ?? '')));
        $name = trim((string) ($dept['name'] ?? ''));
        if ($id === '' && $code === '' && $name === '') {
            return [];
        }

        return [[
            'id' => $id,
            'code' => $code,
            'name' => $name,
        ]];
    }

    /**
     * Distinct stud_course values from getStudInfo4Placement for the department.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchProgramOptions(array $ctx): array
    {
        if (!empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes()) {
            return $this->fetchProgramOptionsFromRegistryTable($ctx);
        }

        $programmes = $this->distinctFieldFromScopedRows($ctx, 'stud_course', '', '');
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        $enrichFromAes = $deptAesId !== ''
            && (empty($ctx['filterMode']) || !empty($ctx['staffScope']));
        if ($enrichFromAes) {
            try {
                $programmes = array_merge(
                    $programmes,
                    (new AesApiService())->fetchPlacementCourses($deptAesId)
                );
            } catch (\Throwable) {
                // Keep session/local/catalog options when AES is temporarily unavailable.
            }
        } elseif ($deptAesId === ''
            && !empty($ctx['campusWide'])
            && !empty($ctx['placementStaffRegistryFilters'])
            && $this->placementStudRole($ctx) !== 'alumni') {
            try {
                $api = new AesApiService();
                foreach ($this->academicDepartmentAesIds() as $aesId) {
                    $programmes = array_merge($programmes, $api->fetchPlacementCourses($aesId));
                }
            } catch (\Throwable) {
                // Keep scoped rows when AES is temporarily unavailable.
            }
        }

        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $group = DepartmentProgrammeCatalog::findGroupForDepartment(
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? '')
        );
        if ($group !== null) {
            $programmes = array_merge(
                $programmes,
                DepartmentProgrammeCatalog::programmeCodesForGroup($group)
            );
        }

        $canonical = [];
        $aesApi = new AesApiService();
        foreach ($programmes as $programme) {
            $raw = trim((string) $programme);
            if ($raw === '') {
                continue;
            }
            // Keep AES course-level shorts (BT / MT) as branch options alongside MCA / CS / …
            if ($aesApi->isCourseLevelShort($raw)) {
                $short = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
                if ($short === 'BTECH') {
                    $short = 'BT';
                } elseif ($short === 'MTECH') {
                    $short = 'MT';
                }
                if ($short !== '') {
                    $canonical[] = $short;
                }
                continue;
            }
            $code = DepartmentProgrammeCatalog::resolveProgrammeCode($raw);
            if ($code !== '') {
                $canonical[] = $code;
            }
        }

        if ($this->departmentProgrammeCodesForFilterScope($ctx) !== null) {
            $canonical = array_values(array_filter(
                $canonical,
                fn (string $code): bool => $this->programmeMatchesDepartmentFilterScope($code, $ctx)
            ));
        }

        return $this->sortLabels(array_values(array_unique(array_filter($canonical))));
    }

    /**
     * Staff Placements dropdown — programmes from catalog + student_placements (no AES).
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function fetchProgramOptionsFromRegistryTable(array $ctx): array
    {
        $canonical = [];
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $group = DepartmentProgrammeCatalog::findGroupForDepartment(
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? '')
        );
        if ($group !== null) {
            foreach (DepartmentProgrammeCatalog::programmeCodesForGroup($group) as $code) {
                $code = DepartmentProgrammeCatalog::resolveProgrammeCode(trim($code));
                if ($code !== '') {
                    $canonical[] = $code;
                }
            }
        }

        $deptId = trim((string) ($ctx['departmentId'] ?? ''));
        if ($deptId !== '') {
            try {
                $model = new StudentPlacementModel();
                $deptFilter = ['$or' => [
                    ['departmentId' => $deptId],
                    ['departmentId' => ''],
                ]];
                foreach ($model->pluckField('programme', $deptFilter, 800) as $raw) {
                    $code = DepartmentProgrammeCatalog::resolveProgrammeCode(trim($raw));
                    if ($code !== '') {
                        $canonical[] = $code;
                    }
                }
            } catch (\Throwable) {
                // optional table
            }
        }

        foreach (StaffContext::assignedClassBatches($ctx) as $batchLabel) {
            $hint = DepartmentProgrammeCatalog::programmeInferredFromBatchLabel(trim((string) $batchLabel));
            if ($hint !== '') {
                $canonical[] = $hint;
            }
        }

        if ($this->departmentProgrammeCodesForFilterScope($ctx) !== null) {
            $canonical = array_values(array_filter(
                $canonical,
                fn (string $code): bool => $this->programmeMatchesDepartmentFilterScope($code, $ctx)
            ));
        }

        return $this->sortLabels(array_values(array_unique(array_filter($canonical))));
    }

    /**
     * Distinct stud_branch values for the selected programme.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchBranchOptions(array $ctx, string $program): array
    {
        $program = trim($program);
        if ($program === '') {
            return [];
        }

        $branches = $this->distinctFieldFromScopedRows($ctx, 'stud_branch', $program, '');
        $skipAes = !empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes();
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        $enrichFromAes = !$skipAes
            && $deptAesId !== ''
            && (empty($ctx['filterMode']) || !empty($ctx['staffScope']));
        if ($enrichFromAes) {
            try {
                $branches = array_merge(
                    $branches,
                    (new AesApiService())->fetchPlacementBranches($deptAesId, $program)
                );
            } catch (\Throwable) {
                // Keep discovered values when AES is temporarily unavailable.
            }
        }

        $branches = array_values(array_filter(array_map('trim', $branches), static fn (string $v): bool => $v !== ''));
        if ($branches === []) {
            $branches[] = 'Regular';
        }

        return $this->sortLabels(array_values(array_unique($branches)));
    }

    /**
     * Distinct stud_class values (current and previous batches) for programme + branch.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchBatchOptions(array $ctx, string $program = '', string $branch = '', bool $finalYearOnly = false): array
    {
        $branch = trim($branch);
        $program = trim($program);

        if ($this->placementStudRole($ctx) === 'all') {
            if (!empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes()) {
                $studentCtx = array_merge($ctx, ['placementStudRole' => 'student']);

                return $this->fetchBatchOptions($studentCtx, $program, $branch, $finalYearOnly);
            }
            $studentCtx = array_merge($ctx, ['placementStudRole' => 'student']);
            $alumniCtx = array_merge($ctx, ['placementStudRole' => 'alumni']);

            return $this->sortLabels(array_values(array_unique(array_merge(
                $this->fetchBatchOptions($studentCtx, $program, $branch, $finalYearOnly),
                $this->fetchBatchOptions($alumniCtx, $program, $branch, $finalYearOnly)
            ))));
        }

        if ($this->placementStudRole($ctx) === 'alumni') {
            $batches = $this->distinctAlumniClassBatches($ctx, $program, $branch);
            $batches = $this->normalizeBatchLabelsForFilters($batches, $ctx);

            return $finalYearOnly
                ? $this->preferSpecificFinalYearBatches($batches)
                : $batches;
        }

        $batches = [];
        if ($program === '') {
            $batches = $this->distinctFieldFromScopedRows($ctx, 'stud_class', '', $branch);
        } else {
            foreach ($this->resolveProgrammeList($program) as $prog) {
                $batches = array_merge(
                    $batches,
                    $this->distinctFieldFromScopedRows($ctx, 'stud_class', $prog, $branch)
                );
            }
        }

        $staffRegistryFilters = !empty($ctx['placementStaffRegistryFilters']);
        if (!$staffRegistryFilters) {
            foreach ($this->assignedBatchLabelsForScope($ctx, $program, $branch) as $batch) {
                $batches[] = $batch;
            }

            $deptId = trim((string) ($ctx['departmentId'] ?? ''));
            if ($deptId !== '') {
                try {
                    $batches = array_merge(
                        $batches,
                        (new StudentPlacementModel())->findDistinctClassBatches($deptId, $program)
                    );
                } catch (\Throwable) {
                    // student_placements optional until schema is applied
                }
            }
        }

        $batches = array_merge(
            $batches,
            $this->mergeAesStudyingClassBatchesForFilterDropdown($ctx, $program, $branch)
        );

        if ($staffRegistryFilters) {
            $batches = $this->refineStaffRegistryBatchOptions($batches, $ctx, $program, $branch);
        }

        $batches = $this->normalizeBatchLabelsForFilters($batches, $ctx);
        if (empty($ctx['filterMode'])) {
            $batches = $this->restrictBatchOptionsToStaffAssignment($ctx, $batches, $program, $branch);
        }
        if (!$finalYearOnly) {
            return $batches;
        }

        $hint = trim($program . ' ' . $branch);
        $classifier = new OfficerDataService();

        $batches = array_values(array_filter(
            $batches,
            static fn (string $batch): bool => $classifier->isFinalYearClassBatch($batch, $hint)
        ));

        return $this->preferSpecificFinalYearBatches($batches);
    }

    /**
     * One label per cohort (e.g. keep MCA2024-2028-S8 over MCA2024-2028 when both appear).
     *
     * @param list<string> $batches
     * @return list<string>
     */
    private function dedupeBatchLabelsByCohort(array $batches): array
    {
        $best = [];
        foreach ($batches as $batch) {
            $batch = trim((string) $batch);
            if ($batch === '') {
                continue;
            }
            $cohort = ClassInchargeRegistry::cohortKey($batch);
            $key = $cohort !== '' ? strtoupper($cohort) : strtoupper($batch);
            if (!isset($best[$key]) || strlen($batch) > strlen($best[$key])) {
                $best[$key] = $batch;
            }
        }

        return array_values($best);
    }

    /**
     * Staff Placements registry batch dropdown: drop junk, enforce programme / branch scope.
     *
     * @param list<string> $batches
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function refineStaffRegistryBatchOptions(
        array $batches,
        array $ctx,
        string $program,
        string $branch
    ): array {
        $refined = [];
        foreach ($batches as $batch) {
            $batch = trim((string) $batch);
            if ($batch === '' || !$this->isPlausibleStudClassBatchLabel($batch)) {
                continue;
            }
            if ($program !== '' && !$this->batchMatchesProgramme($batch, $program)) {
                continue;
            }
            if ($branch !== '' && !$this->batchMatchesBranch($ctx, $batch, $program, $branch)) {
                continue;
            }
            $course = $this->programmeCodeFromBatch($batch);
            if ($course !== '' && !$this->programmeMatchesDepartmentFilterScope($course, $ctx)) {
                continue;
            }
            $refined[] = $batch;
        }

        return $this->preferSemesterSpecificBatchLabels($refined);
    }

    private function isPlausibleStudClassBatchLabel(string $batch): bool
    {
        $batch = trim($batch);
        if ($batch === '' || strlen($batch) < 8) {
            return false;
        }
        if (strcasecmp($batch, 'Regular') === 0) {
            return false;
        }
        if (preg_match('/\d{4}/', $batch) !== 1) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]*$/', $batch) === 1;
    }

    /**
     * When AES returns both MCA2024-2028 and MCA2024-2028-S8, keep the semester-specific label.
     *
     * @param list<string> $batches
     * @return list<string>
     */
    private function preferSemesterSpecificBatchLabels(array $batches): array
    {
        $byNorm = [];
        foreach ($batches as $batch) {
            $batch = trim((string) $batch);
            if ($batch === '') {
                continue;
            }
            $byNorm[strtoupper($batch)] = $batch;
        }

        $drop = [];
        foreach ($byNorm as $norm => $label) {
            if (preg_match('/-S(10|[1-9])$/i', $label) === 1) {
                continue;
            }
            $cohort = ClassInchargeRegistry::cohortKey($label);
            foreach ($byNorm as $other) {
                if (preg_match('/-S(10|[1-9])$/i', $other) !== 1) {
                    continue;
                }
                if (strcasecmp(ClassInchargeRegistry::cohortKey($other), $cohort) === 0) {
                    $drop[$norm] = true;
                    break;
                }
            }
        }

        $out = [];
        foreach ($byNorm as $norm => $label) {
            if (!isset($drop[$norm])) {
                $out[] = $label;
            }
        }

        return $out;
    }

    /**
     * Filter dropdowns: keep every distinct stud_class (S4, S6, S8, …). Roster views still cohort-dedupe.
     *
     * @param list<string> $batches
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function normalizeBatchLabelsForFilters(array $batches, array $ctx): array
    {
        $batches = array_values(array_unique(array_filter(array_map(
            static fn ($b) => trim((string) $b),
            $batches
        ), static fn (string $b): bool => $b !== '')));

        if (!empty($ctx['placementStaffRegistryFilters'])) {
            $batches = array_values(array_filter(
                $batches,
                fn (string $b): bool => $this->isPlausibleStudClassBatchLabel($b)
            ));
            $batches = $this->preferSemesterSpecificBatchLabels($batches);
        }

        if (!empty($ctx['filterMode'])) {
            return $this->sortLabels($batches);
        }

        return $this->sortLabels($this->dedupeBatchLabelsByCohort($batches));
    }

    /**
     * Staff/officer filter mode: all current batches per programme from AES without full directory load.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function mergeAesStudyingClassBatchesForFilterDropdown(array $ctx, string $program, string $branch): array
    {
        if (!empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes()) {
            return [];
        }
        if (empty($ctx['filterMode']) || $this->placementStudRole($ctx) === 'alumni') {
            return [];
        }

        if ($program === '' && !empty($ctx['placementStaffRegistryFilters'])) {
            if (!empty($ctx['campusWide'])) {
                return [];
            }
            $programmes = $this->departmentProgrammeCodesForFilterScope($ctx) ?? [];
        } elseif ($program === '') {
            $programmes = $this->fetchProgramOptions($ctx);
        } else {
            $programmes = $this->resolveProgrammeList($program);
        }
        if ($programmes === []) {
            return [];
        }

        $deptAesIds = [];
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId !== '') {
            $deptAesIds = [$deptAesId];
        } elseif (!empty($ctx['campusWide']) && !empty($ctx['placementStaffRegistryFilters']) && $program !== '') {
            $deptAesIds = $this->academicDepartmentAesIds();
        }
        if ($deptAesIds === []) {
            return [];
        }

        $api = new AesApiService();
        $collected = [];
        foreach ($deptAesIds as $aesId) {
            foreach ($programmes as $prog) {
                $prog = trim((string) $prog);
                if ($prog === '') {
                    continue;
                }
                try {
                    $collected = array_merge(
                        $collected,
                        $api->fetchPlacementClassBatches($aesId, $prog, $branch)
                    );
                } catch (\Throwable) {
                    // Keep other sources when AES is temporarily unavailable.
                }
            }
        }

        return $collected;
    }

    /**
     * @return list<string>
     */
    private function academicDepartmentAesIds(): array
    {
        $ids = [];
        try {
            foreach ((new AesApiService())->listDepartments() as $row) {
                $aesId = trim((string) ($row['aesId'] ?? ''));
                if ($aesId !== '' && preg_match('/^\d+$/', $aesId) === 1) {
                    $ids[$aesId] = true;
                }
            }
        } catch (\Throwable) {
            // Fall back to local catalog below.
        }

        if ($ids === []) {
            foreach ((new DepartmentModel())->findAll([], 200) as $dept) {
                $aesId = trim((string) ($dept['aesId'] ?? ''));
                if ($aesId !== '' && preg_match('/^\d+$/', $aesId) === 1) {
                    $ids[$aesId] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function staffRegistryFiltersSkipLiveAes(): bool
    {
        $v = strtolower(trim((string) ($_ENV['STAFF_PLACEMENT_FILTERS_SKIP_AES'] ?? '1')));

        return !in_array($v, ['0', 'false', 'no', 'off'], true);
    }

    private function placementStudRole(array $ctx): string
    {
        $role = strtolower(trim((string) ($ctx['placementStudRole'] ?? 'student')));
        if ($role === 'all' || $role === 'both') {
            return 'all';
        }

        return $role === 'alumni' ? 'alumni' : 'student';
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function distinctAlumniClassBatches(array $ctx, string $program, string $branch): array
    {
        if (!empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes()) {
            return $this->distinctClassBatchesFromLocalRegistry($ctx, $program, $branch);
        }

        $batches = [];
        $scopedProgrammes = $this->departmentProgrammeCodesForFilterScope($ctx);
        foreach ((new OfficerDataService())->listAlumniDirectoryRecordsForScope($ctx) as $record) {
            $batch = trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? ''));
            if ($batch === '') {
                continue;
            }
            $course = $this->normalizeProgrammeForClass(
                (string) ($record['stud_course'] ?? $record['stud_cource_short'] ?? ''),
                $batch
            );
            $row = [
                'stud_course' => $course,
                'stud_branch' => trim((string) ($record['stud_branch'] ?? '')) ?: 'Regular',
                'stud_class' => $batch,
            ];
            if (!$this->rowMatchesProgramme($row, $program)) {
                continue;
            }
            if ($scopedProgrammes !== null && !$this->programmeMatchesDepartmentFilterScope($course, $ctx)) {
                continue;
            }
            if ($branch !== '' && strcasecmp($row['stud_branch'], $branch) !== 0) {
                continue;
            }
            $batches[] = $batch;
        }

        $deptId = trim((string) ($ctx['departmentId'] ?? ''));
        if ($deptId !== '') {
            try {
                $batches = array_merge(
                    $batches,
                    (new StudentPlacementModel())->findDistinctClassBatches($deptId, $program)
                );
            } catch (\Throwable) {
                // optional table
            }
        }

        return $batches;
    }

    /**
     * Staff registry filter dropdown — batches from CT assignment + student_placements (no AES alumni directory).
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function distinctClassBatchesFromLocalRegistry(array $ctx, string $program, string $branch): array
    {
        $batches = [];
        foreach ($this->assignedBatchLabelsForScope($ctx, $program, $branch) as $label) {
            $batches[] = $label;
        }
        foreach (StaffContext::assignedClassBatches($ctx) as $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $batches[] = $label;
            }
        }
        $deptId = trim((string) ($ctx['departmentId'] ?? ''));
        if ($deptId !== '') {
            try {
                $batches = array_merge(
                    $batches,
                    (new StudentPlacementModel())->findDistinctClassBatches($deptId, $program, 800)
                );
            } catch (\Throwable) {
                // optional table
            }
        }

        return $this->refineStaffRegistryBatchOptions($batches, $ctx, $program, $branch);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param list<array{stud_course:string,stud_branch:string,stud_class:string}> $rows
     * @param array<string, true> $seen
     */
    private function appendAlumniDirectoryFilterRows(array $ctx, array &$rows, array &$seen): void
    {
        foreach ((new OfficerDataService())->listAlumniDirectoryRecordsForScope($ctx) as $record) {
            $batch = trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? ''));
            $course = $this->normalizeProgrammeForClass(
                (string) ($record['stud_course'] ?? $record['stud_cource_short'] ?? ''),
                $batch
            );
            if ($course !== '' && !$this->programmeMatchesDepartmentFilterScope($course, $ctx)) {
                continue;
            }
            if ($course === '' && $batch === '') {
                continue;
            }
            $branch = trim((string) ($record['stud_branch'] ?? ''));
            $row = [
                'stud_course' => $course,
                'stud_branch' => $branch !== '' ? $branch : 'Regular',
                'stud_class' => $batch,
            ];
            $this->pushStudInfoRow($row, $rows, $seen);
        }
    }

    /**
     * Class teachers / co-class teachers: only their assigned batches, not whole-dept AES lists.
     *
     * @param array<string, mixed> $ctx
     * @param list<string> $batches
     * @return list<string>
     */
    private function restrictBatchOptionsToStaffAssignment(
        array $ctx,
        array $batches,
        string $program,
        string $branch
    ): array {
        if (!empty($ctx['filterMode']) || empty($ctx['staffScope'])) {
            return $batches;
        }

        $assigned = StaffContext::assignedClassBatches($ctx);
        if ($assigned === []) {
            return $batches;
        }

        $scoped = array_values(array_filter(
            $batches,
            static fn (string $batch): bool => StaffContext::classBatchMatchesAssigned($batch, $assigned)
        ));

        foreach ($this->assignedBatchLabelsForScope($ctx, $program, $branch) as $label) {
            if (!in_array($label, $scoped, true)) {
                $scoped[] = $label;
            }
        }

        foreach ($assigned as $label) {
            $label = trim((string) $label);
            if ($label !== '' && !in_array($label, $scoped, true)) {
                $scoped[] = $label;
            }
        }

        return $this->sortLabels($this->dedupeBatchLabelsByCohort($scoped));
    }

    /**
     * When both a plain year-range batch and a semester-specific final-year batch exist,
     * keep only the more specific semester-labelled option in the filter.
     *
     * @param list<string> $batches
     * @return list<string>
     */
    private function preferSpecificFinalYearBatches(array $batches): array
    {
        $normalizedBases = [];
        foreach ($batches as $batch) {
            $raw = trim((string) $batch);
            if ($raw === '') {
                continue;
            }
            if (preg_match('/(?:^|[^A-Z0-9])S(?:10|[1-9])(?:[^A-Z0-9]|$)/i', $raw) === 1) {
                $base = preg_replace('/(?:^|[^A-Z0-9])S(?:10|[1-9])(?:[^A-Z0-9]|$)/i', '', $raw) ?? $raw;
                $base = strtoupper(preg_replace('/[^A-Z0-9]/', '', $base) ?? '');
                if ($base !== '') {
                    $normalizedBases[$base] = true;
                }
            }
        }

        if ($normalizedBases === []) {
            return $batches;
        }

        return array_values(array_filter(
            $batches,
            static function (string $batch) use ($normalizedBases): bool {
                $raw = trim($batch);
                if ($raw === '') {
                    return false;
                }
                if (preg_match('/(?:^|[^A-Z0-9])S(?:10|[1-9])(?:[^A-Z0-9]|$)/i', $raw) === 1) {
                    return true;
                }
                $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
                return !isset($normalizedBases[$normalized]);
            }
        ));
    }

    /**
     * @return list<string>
     */
    private function resolveProgrammeList(string $program): array
    {
        $program = trim($program);
        if ($program === '') {
            return [];
        }

        if (str_contains($program, '|')) {
            $parts = preg_split('/\|/', $program) ?: [];
            $codes = [];
            foreach ($parts as $part) {
                $label = trim((string) $part);
                if ($label !== '') {
                    $codes[] = $label;
                }
            }

            return array_values(array_unique($codes));
        }

        return [$program];
    }

    /**
     * Hiring overview branch filter (programme codes).
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function programmesForScope(array $ctx, string $branchFilter = ''): array
    {
        $branchFilter = trim($branchFilter);
        if ($branchFilter !== '') {
            $targets = array_values(array_filter(array_map(
                static fn (string $part) => DepartmentProgrammeCatalog::resolveProgrammeCode($part),
                preg_split('/\|/', $branchFilter) ?: []
            ), static fn (string $code) => $code !== ''));

            return array_values(array_unique($targets));
        }

        return $this->fallbackProgrammes($ctx);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function fallbackProgrammes(array $ctx): array
    {
        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $code = strtoupper(trim((string) ($dept['code'] ?? '')));
        $name = trim((string) ($dept['name'] ?? ''));
        $group = DepartmentProgrammeCatalog::findGroupForDepartment($code, $name);
        if ($group !== null) {
            return DepartmentProgrammeCatalog::programmeCodesForGroup($group);
        }

        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($code);
        if ($resolved !== '' && $resolved === DepartmentProgrammeCatalog::normalizeCode($code)) {
            return [$resolved];
        }

        return $code !== '' ? [$code] : [];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<array{stud_course:string,stud_branch:string,stud_class:string}>
     */
    private function collectScopedStudInfoRows(array $ctx): array
    {
        $filterMode = !empty($ctx['filterMode']);
        $cacheKey = (string) ($ctx['departmentId'] ?? '');
        if ($filterMode) {
            $cacheKey .= '|filter|' . $this->placementStudRole($ctx);
            if (!empty($ctx['placementStaffRegistryFilters'])) {
                $cacheKey .= '|staffreg';
            }
            if (!empty($ctx['campusWide'])) {
                $cacheKey .= '|campus';
            }
        }
        if ($cacheKey !== '' && isset(self::$scopedRowsCache[$cacheKey]) && self::$scopedRowsCache[$cacheKey] !== []) {
            return self::$scopedRowsCache[$cacheKey];
        }

        $rows = [];
        $seen = [];

        $this->appendStudInfoRowsFromAesProfile(Security::getSessionAesProfile(), $rows, $seen);

        if ($filterMode) {
            $this->appendFilterModeStudInfoRows($ctx, $rows, $seen);
            $role = $this->placementStudRole($ctx);
            $skipAlumniAes = !empty($ctx['placementStaffRegistryFilters']) && $this->staffRegistryFiltersSkipLiveAes();
            if (!$skipAlumniAes && ($role === 'alumni' || $role === 'all')) {
                $this->appendAlumniDirectoryFilterRows($ctx, $rows, $seen);
            }
        }

        $api = new AesApiService();
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId !== '' && !$filterMode && $this->placementStudRole($ctx) !== 'alumni') {
            try {
                foreach ($api->fetchAllStudInfo4Placement(['stud_deptcode' => $deptAesId], true) as $record) {
                    $recordDept = trim((string) ($record['stud_deptcode'] ?? ''));
                    if ($recordDept !== '' && strcasecmp($recordDept, $deptAesId) !== 0) {
                        continue;
                    }
                    $batch = trim((string) ($record['stud_class'] ?? $record['classBatch'] ?? ''));
                    $course = $this->normalizeProgrammeForClass(
                        (string) ($record['stud_course'] ?? $record['stud_cource_short'] ?? ''),
                        $batch
                    );
                    $branch = trim((string) ($record['stud_branch'] ?? ''));
                    if ($course === '' && $batch === '') {
                        continue;
                    }
                    $row = [
                        'stud_course' => $course,
                        'stud_branch' => $branch !== '' ? $branch : 'Regular',
                        'stud_class' => $batch,
                    ];
                    $key = strtolower(implode('|', $row));
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $rows[] = $row;
                    }
                }
            } catch (\Throwable) {
                // Continue with session/local rows when the directory is unavailable.
            }
        }

        if (!$filterMode) {
            $aesCalls = 0;
            $maxAesCalls = 800;

            try {
                $filter = StaffContext::studentCollectionFilter($ctx);
            } catch (\Throwable) {
                $filter = [];
            }

            try {
                $students = (new StudentModel())->findAll($filter, 5000);
            } catch (\Throwable) {
                $students = [];
            }

            foreach ($students as $student) {
                if (!$this->studentInDepartment($student, $ctx)) {
                    continue;
                }

                $row = $this->studInfoRowFromStudent($student, $api, $aesCalls, $maxAesCalls);
                if ($row === null) {
                    continue;
                }

                $key = strtolower(implode('|', [$row['stud_course'], $row['stud_branch'], $row['stud_class']]));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        if ($cacheKey !== '' && $rows !== [] && count($rows) <= 1500) {
            self::$scopedRowsCache[$cacheKey] = $rows;
        }

        return $rows;
    }

    /**
     * Filter dropdowns: catalog, assigned classes, and local registry — no AES directory scan.
     *
     * @param array<string, mixed> $ctx
     * @param list<array{stud_course:string,stud_branch:string,stud_class:string}> $rows
     * @param array<string, true> $seen
     */
    private function appendFilterModeStudInfoRows(array $ctx, array &$rows, array &$seen): void
    {
        $staffRegistry = !empty($ctx['placementStaffRegistryFilters']);
        $assigned = StaffContext::assignedClassBatches($ctx);
        foreach ($assigned as $batchLabel) {
            $batchLabel = trim((string) $batchLabel);
            if ($batchLabel === '') {
                continue;
            }
            if ($staffRegistry && !$this->isPlausibleStudClassBatchLabel($batchLabel)) {
                continue;
            }
            $course = $this->programmeCodeFromBatch($batchLabel);
            if (!$this->programmeMatchesDepartmentFilterScope($course, $ctx)) {
                continue;
            }
            $row = [
                'stud_course' => $course,
                'stud_branch' => 'Regular',
                'stud_class' => $batchLabel,
            ];
            $this->pushStudInfoRow($row, $rows, $seen);
        }

        $deptId = trim((string) ($ctx['departmentId'] ?? ''));
        if ($deptId !== '') {
            try {
                foreach ((new StudentPlacementModel())->findDistinctClassBatches($deptId, '', 500) as $batch) {
                    $batch = trim((string) $batch);
                    if ($batch === '') {
                        continue;
                    }
                    if ($staffRegistry && !$this->isPlausibleStudClassBatchLabel($batch)) {
                        continue;
                    }
                    $course = $this->programmeCodeFromBatch($batch);
                    if ($staffRegistry && !$this->programmeMatchesDepartmentFilterScope($course, $ctx)) {
                        continue;
                    }
                    $row = [
                        'stud_course' => $course,
                        'stud_branch' => 'Regular',
                        'stud_class' => $batch,
                    ];
                    $this->pushStudInfoRow($row, $rows, $seen);
                }
            } catch (\Throwable) {
                // student_placements optional until schema is applied
            }
        }

        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $group = DepartmentProgrammeCatalog::findGroupForDepartment(
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? '')
        );
        if ($group !== null) {
            foreach (DepartmentProgrammeCatalog::programmeCodesForGroup($group) as $code) {
                $code = trim($code);
                if ($code === '') {
                    continue;
                }
                $row = [
                    'stud_course' => $code,
                    'stud_branch' => 'Regular',
                    'stud_class' => '',
                ];
                $this->pushStudInfoRow($row, $rows, $seen);
            }
        }
    }

    /**
     * @param array{stud_course:string,stud_branch:string,stud_class:string} $row
     * @param list<array{stud_course:string,stud_branch:string,stud_class:string}> $rows
     * @param array<string, true> $seen
     */
    private function pushStudInfoRow(array $row, array &$rows, array &$seen): void
    {
        $key = strtolower(implode('|', [$row['stud_course'], $row['stud_branch'], $row['stud_class']]));
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $rows[] = $row;
    }

    /**
     * When a department is selected on staff Placements filters, limit CT-assigned batches
     * and catalog hints to that department's programmes.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>|null null = no restriction (all departments)
     */
    private function departmentProgrammeCodesForFilterScope(array $ctx): ?array
    {
        if (!empty($ctx['campusWide']) || trim((string) ($ctx['departmentId'] ?? '')) === '') {
            return null;
        }

        $dept = is_array($ctx['department'] ?? null) ? $ctx['department'] : [];
        $codes = [];
        $group = DepartmentProgrammeCatalog::findGroupForDepartment(
            (string) ($dept['code'] ?? ''),
            (string) ($dept['name'] ?? '')
        );
        if ($group !== null) {
            $codes = array_merge($codes, DepartmentProgrammeCatalog::programmeCodesForGroup($group));
        }
        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode((string) ($dept['code'] ?? ''));
        if ($resolved !== '') {
            $codes[] = $resolved;
        }

        $codes = array_values(array_unique(array_filter(array_map(
            static fn (string $c): string => DepartmentProgrammeCatalog::resolveProgrammeCode($c),
            $codes
        ), static fn (string $c): bool => $c !== '')));

        return $codes !== [] ? $codes : null;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function programmeMatchesDepartmentFilterScope(string $programmeCode, array $ctx): bool
    {
        $scope = $this->departmentProgrammeCodesForFilterScope($ctx);
        if ($scope === null) {
            return true;
        }
        $programmeCode = DepartmentProgrammeCatalog::resolveProgrammeCode(trim($programmeCode));
        if ($programmeCode === '') {
            return false;
        }
        foreach ($scope as $code) {
            if (strcasecmp($programmeCode, $code) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter dropdowns include every batch in the department (current and previous).
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $ctx
     */
    private function studentInDepartment(array $student, array $ctx): bool
    {
        $scopeDept = trim((string) ($ctx['departmentId'] ?? ''));
        if ($scopeDept === '') {
            return false;
        }

        return (string) ($student['departmentId'] ?? '') === $scopeDept;
    }

    /**
     * @param array<string, mixed> $student
     */
    private function studInfoRowFromStudent(
        array $student,
        AesApiService $api,
        int &$aesCalls,
        int $maxAesCalls
    ): ?array {
        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register === '' || $aesCalls >= $maxAesCalls) {
            return null;
        }

        $aesCalls++;
        $aesRow = $api->fetchStudInfoPlacementRow($register);
        $course = $this->normalizeProgrammeForClass(
            (string) $aesRow['stud_course'],
            (string) $aesRow['stud_class']
        );
        $branch = trim($aesRow['stud_branch']);
        $batch = trim($aesRow['stud_class']);

        if ($course === '' && $batch === '' && $branch === '') {
            return null;
        }

        return [
            'stud_course' => $course,
            'stud_branch' => $branch !== '' ? $branch : 'Regular',
            'stud_class'  => $batch,
        ];
    }

    private function normalizeProgrammeForClass(string $course, string $batch): string
    {
        $classCode = DepartmentProgrammeCatalog::normalizeCode($batch);
        if (str_contains($classCode, 'MCAINT') || str_contains($classCode, 'INMCA')) {
            return 'INMCA';
        }

        $fromBatch = $this->programmeCodeFromBatch($batch);
        if ($fromBatch !== '') {
            return $fromBatch;
        }

        return DepartmentProgrammeCatalog::resolveProgrammeCode($course);
    }

    /**
     * @param array<string, mixed> $profile
     * @param list<array{stud_course:string,stud_branch:string,stud_class:string}> $rows
     * @param array<string, true> $seen
     */
    private function appendStudInfoRowsFromAesProfile(array $profile, array &$rows, array &$seen): void
    {
        if ($profile === []) {
            return;
        }

        $this->walkAesProfileForStudInfoRows($profile, $rows, $seen);
    }

    /**
     * @param list<array{stud_course:string,stud_branch:string,stud_class:string}> $rows
     * @param array<string, true> $seen
     */
    private function walkAesProfileForStudInfoRows(mixed $node, array &$rows, array &$seen): void
    {
        if (is_string($node)) {
            return;
        }

        if (!is_array($node)) {
            return;
        }

        if ($this->isStudInfoFilterRow($node)) {
            $batch = trim((string) ($node['stud_class'] ?? $node['classBatch'] ?? $node['batch'] ?? ''));
            $row = [
                'stud_course' => $this->normalizeProgrammeForClass(
                    (string) ($node['stud_course'] ?? $node['stud_cource_short'] ?? $node['course'] ?? ''),
                    $batch
                ),
                'stud_branch' => trim((string) ($node['stud_branch'] ?? $node['branch'] ?? '')) ?: 'Regular',
                'stud_class'  => $batch,
            ];
            if ($row['stud_course'] !== '' || $row['stud_class'] !== '') {
                $key = strtolower(implode('|', $row));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = $row;
                }
            }

            return;
        }

        foreach ($node as $item) {
            $this->walkAesProfileForStudInfoRows($item, $rows, $seen);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isStudInfoFilterRow(array $row): bool
    {
        foreach (['stud_class', 'stud_branch', 'stud_course', 'stud_cource_short', 'classBatch', 'batch'] as $key) {
            if (!empty($row[$key]) && is_scalar($row[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function distinctFieldFromScopedRows(
        array $ctx,
        string $field,
        string $program,
        string $branch
    ): array {
        $labels = [];
        foreach ($this->collectScopedStudInfoRows($ctx) as $row) {
            if (!$this->rowMatchesProgramme($row, $program)) {
                continue;
            }
            if ($branch !== '' && strcasecmp($row['stud_branch'], $branch) !== 0) {
                continue;
            }

            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $labels[] = $value;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array{stud_course:string,stud_branch:string,stud_class:string} $row
     */
    private function rowMatchesProgramme(array $row, string $programmeCode): bool
    {
        if ($programmeCode === '') {
            return true;
        }

        $rowCourse = trim($row['stud_course']);
        if ($rowCourse === '') {
            return false;
        }

        $targets = array_values(array_unique(array_filter([
            trim($programmeCode),
            DepartmentProgrammeCatalog::resolveProgrammeCode($programmeCode),
        ], static fn (string $code) => $code !== '')));

        foreach ($targets as $target) {
            if (strcasecmp($rowCourse, $target) === 0) {
                return true;
            }
            if (strcasecmp(
                DepartmentProgrammeCatalog::normalizeCode($rowCourse),
                DepartmentProgrammeCatalog::normalizeCode($target)
            ) === 0) {
                return true;
            }
        }

        $batchLabel = trim((string) ($row['stud_class'] ?? ''));
        if ($batchLabel === '') {
            return false;
        }

        $inferred = $this->programmeCodeFromBatch($batchLabel);
        if ($inferred === '') {
            return false;
        }

        foreach ($targets as $target) {
            if (strcasecmp($inferred, $target) === 0) {
                return true;
            }
            if (strcasecmp(
                DepartmentProgrammeCatalog::normalizeCode($inferred),
                DepartmentProgrammeCatalog::normalizeCode($target)
            ) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * AES staff assigned classes include current and previous batches.
     *
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    private function assignedBatchLabelsForScope(array $ctx, string $program, string $branch): array
    {
        $labels = [];
        foreach (StaffContext::assignedClassBatches($ctx) as $batchLabel) {
            $batchLabel = trim((string) $batchLabel);
            if ($batchLabel === '') {
                continue;
            }
            if ($program !== '' && !$this->batchMatchesProgramme($batchLabel, $program)) {
                continue;
            }
            if ($branch !== '' && !$this->batchMatchesBranch($ctx, $batchLabel, $program, $branch)) {
                continue;
            }
            $labels[] = $batchLabel;
        }

        return $labels;
    }

    private function batchMatchesProgramme(string $batchLabel, string $program): bool
    {
        $program = trim($program);
        if ($program === '') {
            return true;
        }

        // Infer programme from the batch label with longest-prefix matching so
        // MCAINT… maps to INMCA instead of the MCA prefix.
        $inferred = $this->programmeCodeFromBatch($batchLabel);
        if ($inferred !== '') {
            return $this->rowMatchesProgramme(
                ['stud_course' => $inferred, 'stud_branch' => '', 'stud_class' => $batchLabel],
                $program
            );
        }

        $batchNorm = DepartmentProgrammeCatalog::normalizeCode($batchLabel);
        $targets = array_values(array_unique(array_filter([
            $program,
            DepartmentProgrammeCatalog::resolveProgrammeCode($program),
            DepartmentProgrammeCatalog::normalizeCode($program),
        ], static fn (string $code) => $code !== '')));

        foreach ($targets as $target) {
            if (strcasecmp($batchLabel, $target) === 0) {
                return true;
            }
            $targetNorm = DepartmentProgrammeCatalog::normalizeCode($target);
            if ($targetNorm !== '' && $batchNorm === $targetNorm) {
                return true;
            }
        }

        return false;
    }

    private function batchMatchesBranch(array $ctx, string $batchLabel, string $program, string $branch): bool
    {
        if ($branch === '') {
            return true;
        }

        foreach ($this->collectScopedStudInfoRows($ctx) as $row) {
            if (strcasecmp($row['stud_class'], $batchLabel) !== 0) {
                continue;
            }
            if (!$this->rowMatchesProgramme($row, $program)) {
                continue;
            }
            if ($row['stud_branch'] !== '' && strcasecmp($row['stud_branch'], $branch) === 0) {
                return true;
            }
        }

        return false;
    }

    private function programmeCodeFromBatch(string $batchLabel): string
    {
        $normalized = DepartmentProgrammeCatalog::normalizeCode($batchLabel);
        if ($normalized === '') {
            return '';
        }

        $tokens = [];
        foreach (DepartmentProgrammeCatalog::groups() as $group) {
            foreach ($group['programmes'] as $programme) {
                $canonical = DepartmentProgrammeCatalog::normalizeCode($programme['code']);
                if ($canonical === '') {
                    continue;
                }
                $tokens[$canonical] = $canonical;
                foreach ($programme['aliases'] as $alias) {
                    $aliasNorm = DepartmentProgrammeCatalog::normalizeCode($alias);
                    if ($aliasNorm !== '') {
                        $tokens[$aliasNorm] = $canonical;
                    }
                }
            }
        }

        $keys = array_keys($tokens);
        usort($keys, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $token) {
            if ($token !== '' && str_starts_with($normalized, $token)) {
                return DepartmentProgrammeCatalog::resolveProgrammeCode($tokens[$token]);
            }
        }

        return '';
    }

    private function resolveAesIdByParentName(string $parentName): string
    {
        $needle = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($parentName)) ?? '');
        if ($needle === '') {
            return '';
        }

        foreach ((new AesApiService())->loadDepartmentsFromApi() as $row) {
            $name = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) ($row['name'] ?? '')) ?? '');
            if ($name === '' || ($name !== $needle && !str_contains($name, $needle) && !str_contains($needle, $name))) {
                continue;
            }
            $id = trim((string) ($row['aesId'] ?? ''));
            if ($id !== '' && ctype_digit($id)) {
                return $id;
            }
            $rowCode = trim((string) ($row['code'] ?? ''));
            if ($rowCode !== '' && ctype_digit($rowCode)) {
                return $rowCode;
            }
        }

        return '';
    }

    private function resolveAesIdByCodeOrName(string $code, string $name): string
    {
        $api = new AesApiService();
        foreach ([$code, $name] as $hint) {
            $hint = trim($hint);
            if ($hint === '') {
                continue;
            }
            foreach ($api->loadDepartmentsFromApi() as $row) {
                $rowCode = strtoupper(trim((string) ($row['code'] ?? '')));
                $rowName = strtoupper(trim((string) ($row['name'] ?? '')));
                $hintUpper = strtoupper($hint);
                if ($rowCode !== $hintUpper && $rowName !== $hintUpper
                    && !str_contains($rowName, $hintUpper) && !str_contains($hintUpper, $rowName)) {
                    continue;
                }
                $id = trim((string) ($row['aesId'] ?? ''));
                if ($id !== '' && ctype_digit($id)) {
                    return $id;
                }
                if (ctype_digit((string) ($row['code'] ?? ''))) {
                    return (string) $row['code'];
                }
            }
        }

        return '';
    }

    /**
     * @param list<string> $labels
     * @return list<string>
     */
    private function sortLabels(array $labels): array
    {
        $labels = array_values(array_unique(array_filter(array_map(
            static fn ($label) => trim((string) $label),
            $labels
        ), static fn (string $label) => $label !== '')));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return $labels;
    }
}
