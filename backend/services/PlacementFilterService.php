<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Utils\Security;

/**
 * AES placement filters: program → branch → batch for any department.
 */
final class PlacementFilterService
{
    /** @var array<string, list<array{stud_course:string,stud_branch:string,stud_class:string}>> */
    private static array $scopedRowsCache = [];

    /** AES parent-department programme labels when getCourses4Placement is unavailable. */
    private const AES_STANDARD_PROGRAMS = [
        'B.Tech',
        'BBA',
        'BCA',
        'M.Tech',
        'MCA',
        'PG Certificate',
        'PhD',
    ];

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
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchProgramOptions(array $ctx): array
    {
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        $fromAes = $deptAesId !== ''
            ? (new AesApiService())->fetchPlacementCourses($deptAesId)
            : [];

        $programs = $fromAes !== [] ? $fromAes : self::AES_STANDARD_PROGRAMS;
        foreach ($this->collectScopedStudInfoRows($ctx) as $row) {
            if ($row['stud_course'] !== '') {
                $programs[] = $row['stud_course'];
            }
        }

        if ($fromAes === []) {
            foreach ($this->fallbackProgrammes($ctx) as $program) {
                $programs[] = $program;
            }
        }

        return $this->sortLabels($programs);
    }

    /**
     * @return list<string>
     */
    private function resolveBranchLabels(string $deptAesId, string $program, array $ctx): array
    {
        $program = trim($program);
        if ($program === '') {
            return [];
        }

        $branches = $deptAesId !== ''
            ? (new AesApiService())->fetchPlacementBranches($deptAesId, $program)
            : [];

        if ($branches === []) {
            $branches = $this->distinctFieldFromScopedRows($ctx, 'stud_branch', $program, '');
        }

        if ($branches !== []) {
            return $this->sortLabels($branches);
        }

        return $this->sortLabels($this->fallbackBranchOptions($program));
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchBranchOptions(array $ctx, string $program): array
    {
        $program = trim($program);
        if ($program === '') {
            return [];
        }

        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId === '') {
            return [];
        }

        return $this->resolveBranchLabels($deptAesId, $program, $ctx);
    }

    /**
     * @return list<string>
     */
    private function fallbackBranchOptions(string $program): array
    {
        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($program);
        if (in_array($resolved, ['MCA', 'BCA', 'INMCA'], true)) {
            return ['Integrated', 'Regular'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return list<string>
     */
    public function fetchBatchOptions(array $ctx, string $program = '', string $branch = ''): array
    {
        $branch = trim($branch);
        $program = trim($program);
        $deptAesId = $this->resolveParentDeptAesId($ctx);
        if ($deptAesId === '') {
            return [];
        }

        $programs = $this->resolveProgrammeList($program);
        if ($programs === []) {
            $programs = $this->programmesForScope($ctx, '');
        }

        $api = new AesApiService();
        $batches = [];
        foreach ($programs as $prog) {
            $rows = $api->fetchPlacementClassBatches($deptAesId, $prog, $branch);
            if ($rows === [] && $branch !== '') {
                $rows = $api->fetchPlacementClassBatches($deptAesId, $prog, '');
            }
            if ($rows !== []) {
                $batches = array_merge($batches, $rows);
            }
        }

        if ($batches === [] && $program !== '') {
            foreach ($programs as $prog) {
                $batches = array_merge(
                    $batches,
                    $this->distinctFieldFromScopedRows($ctx, 'stud_class', $prog, $branch)
                );
            }
        }

        if ($batches === [] && $program === '') {
            $batches = $this->distinctFieldFromScopedRows($ctx, 'stud_class', '', '');
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
        $aesCalls = 0;
        $maxAesCalls = 250;

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
            if (!StaffContext::studentMatchesScope($student, $ctx)) {
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
     * @param array<string, mixed> $student
     */
    private function studInfoRowFromStudent(
        array $student,
        AesApiService $api,
        int &$aesCalls,
        int $maxAesCalls
    ): ?array {
        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
        $course = trim((string) ($personal['course'] ?? ''));
        $branch = '';
        $batch = trim((string) ($student['classBatch'] ?? ''));

        $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
        if ($register !== '' && $aesCalls < $maxAesCalls) {
            $aesCalls++;
            $aesRow = $api->fetchStudInfoPlacementRow($register);
            if ($aesRow['stud_course'] !== '') {
                $course = $aesRow['stud_course'];
            }
            if ($aesRow['stud_branch'] !== '') {
                $branch = $aesRow['stud_branch'];
            }
            if ($aesRow['stud_class'] !== '') {
                $batch = $aesRow['stud_class'];
            }
        }

        if ($course === '' && $batch === '' && $branch === '') {
            return null;
        }

        if ($branch === '') {
            $branch = $this->defaultBranchForProgram($course);
        }

        return [
            'stud_course' => $course,
            'stud_branch' => $branch,
            'stud_class'  => $batch,
        ];
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
            $row = [
                'stud_course' => trim((string) ($node['stud_course'] ?? $node['stud_cource_short'] ?? $node['course'] ?? '')),
                'stud_branch' => trim((string) ($node['stud_branch'] ?? $node['branch'] ?? '')),
                'stud_class'  => trim((string) ($node['stud_class'] ?? $node['classBatch'] ?? $node['batch'] ?? '')),
            ];
            if ($row['stud_branch'] === '' && $row['stud_course'] !== '') {
                $row['stud_branch'] = $this->defaultBranchForProgram($row['stud_course']);
            }
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
            return true;
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

    private function defaultBranchForProgram(string $program): string
    {
        $resolved = DepartmentProgrammeCatalog::resolveProgrammeCode($program);
        if (in_array($resolved, ['MCA', 'BCA', 'INMCA'], true)) {
            return 'Regular';
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
