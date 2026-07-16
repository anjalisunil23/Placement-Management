<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Utils\Security;

/**
 * AES placement filters: program → branch → batch from getStudInfo4Placement only.
 */
final class PlacementFilterService
{
    /** @var array<string, list<array{stud_course:string,stud_branch:string,stud_class:string}>> */
    private static array $scopedRowsCache = [];

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
        $programmes = $this->distinctFieldFromScopedRows($ctx, 'stud_course', '', '');
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId !== '') {
            try {
                $programmes = array_merge(
                    $programmes,
                    (new AesApiService())->fetchPlacementCourses($deptAesId)
                );
            } catch (\Throwable) {
                // Keep session/local/catalog options when AES is temporarily unavailable.
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
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId !== '') {
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
    public function fetchBatchOptions(array $ctx, string $program = '', string $branch = ''): array
    {
        $branch = trim($branch);
        $program = trim($program);

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

        foreach ($this->assignedBatchLabelsForScope($ctx, $program, $branch) as $batch) {
            $batches[] = $batch;
        }

        return $this->sortLabels(array_values(array_unique($batches)));
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
        $cacheKey = (string) ($ctx['departmentId'] ?? '');
        if ($cacheKey !== '' && isset(self::$scopedRowsCache[$cacheKey])) {
            return self::$scopedRowsCache[$cacheKey];
        }

        $rows = [];
        $seen = [];

        $this->appendStudInfoRowsFromAesProfile(Security::getSessionAesProfile(), $rows, $seen);

        $api = new AesApiService();
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId !== '') {
            try {
                foreach ($api->fetchAllStudInfo4Placement(['stud_deptcode' => $deptAesId]) as $record) {
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

        if ($cacheKey !== '') {
            self::$scopedRowsCache[$cacheKey] = $rows;
        }

        return $rows;
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
