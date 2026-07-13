<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * AES placement filters: program → branch → batch for any department.
 */
final class PlacementFilterService
{
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

        if ($fromAes !== []) {
            return $this->sortLabels($fromAes);
        }

        return $this->sortLabels($this->fallbackProgrammes($ctx));
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

        $branches = (new AesApiService())->fetchPlacementBranches($deptAesId, $program);
        if ($branches === []) {
            $branches = $this->fallbackBranchOptions($program);
        }

        return $this->sortLabels($branches);
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
        if ($resolved !== '') {
            return ['Regular'];
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
