<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * AJCE parent departments and programme / branch codes (KTU / AES).
 */
final class DepartmentProgrammeCatalog
{
    /**
     * @return list<array{parent:string,programmes:list<array{code:string,label:string,aliases:list<string>}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'parent' => 'Computer Applications',
                'programmes' => [
                    ['code' => 'MCA', 'label' => 'MCA', 'aliases' => ['MCAR', 'MCAREG']],
                    ['code' => 'BCA', 'label' => 'BCA', 'aliases' => ['BCAH', 'BCAHONS']],
                    ['code' => 'INMCA', 'label' => 'Integrated MCA', 'aliases' => ['INTMCA', 'IMCA', 'DDMCA']],
                ],
            ],
            [
                'parent' => 'Computer Science & Engineering',
                'programmes' => [
                    ['code' => 'CS', 'label' => 'CS — Computer Science and Engineering', 'aliases' => ['CSE']],
                    ['code' => 'CT', 'label' => 'CT — CSE (Artificial Intelligence)', 'aliases' => ['CSAI']],
                    ['code' => 'CY', 'label' => 'CY — CSE (Cyber Security)', 'aliases' => ['CSY']],
                    ['code' => 'MTECHCS', 'label' => 'M.Tech — Computer Science and Engineering', 'aliases' => ['MTECH CSE', 'MTCSE', 'MTECHCSE', 'PGCSE']],
                ],
            ],
            [
                'parent' => 'AI & Information Technology',
                'programmes' => [
                    ['code' => 'AD', 'label' => 'AD — Artificial Intelligence & Data Science', 'aliases' => ['AIDS', 'AI']],
                    ['code' => 'IT', 'label' => 'IT — Information Technology', 'aliases' => []],
                ],
            ],
            [
                'parent' => 'Electronics & Communication',
                'programmes' => [
                    ['code' => 'EC', 'label' => 'EC — Electronics & Communication Engineering', 'aliases' => ['ECE', 'ECEA']],
                    ['code' => 'MTECHVLSI', 'label' => 'M.Tech — VLSI Design and Embedded Systems', 'aliases' => ['MTECH VLSI', 'VLSI', 'MTVLSI']],
                ],
            ],
            [
                'parent' => 'Electrical & Electronics',
                'programmes' => [
                    ['code' => 'EE', 'label' => 'EE — Electrical & Electronics Engineering', 'aliases' => ['EEE']],
                    ['code' => 'MTECHES', 'label' => 'M.Tech — Energy Systems', 'aliases' => ['MTECH ES', 'ENERGY SYSTEMS', 'MTES', 'PEPS']],
                ],
            ],
            [
                'parent' => 'Mechanical Engineering',
                'programmes' => [
                    ['code' => 'ME', 'label' => 'ME — Mechanical Engineering', 'aliases' => ['MECH']],
                    ['code' => 'MA', 'label' => 'MA — Mechanical Engineering (Automobile)', 'aliases' => ['AU', 'AUTO']],
                    ['code' => 'MTECHEV', 'label' => 'M.Tech — Electric Vehicle Technology', 'aliases' => ['MTECH EV', 'EV']],
                ],
            ],
            [
                'parent' => 'Civil Engineering',
                'programmes' => [
                    ['code' => 'CE', 'label' => 'CE — Civil Engineering', 'aliases' => ['CIVIL']],
                    ['code' => 'MTECHSECM', 'label' => 'M.Tech — Structural Engg. & Construction Management', 'aliases' => ['MTECH SECM', 'SECM', 'STRUCTURAL']],
                ],
            ],
            [
                'parent' => 'Chemical Engineering',
                'programmes' => [
                    ['code' => 'CH', 'label' => 'CH — Chemical Engineering', 'aliases' => ['CHEM', 'CHE']],
                    ['code' => 'MTECHENV', 'label' => 'M.Tech — Environmental Engineering', 'aliases' => ['MTECH ENV', 'ENVIRONMENTAL']],
                ],
            ],
            [
                'parent' => 'Food Technology',
                'programmes' => [
                    ['code' => 'FT', 'label' => 'FT — Food Technology', 'aliases' => ['FOOD', 'FE']],
                ],
            ],
            [
                'parent' => 'Metallurgical & Materials',
                'programmes' => [
                    ['code' => 'MG', 'label' => 'MG — Metallurgical & Materials Engineering', 'aliases' => ['MT', 'MET', 'MME']],
                ],
            ],
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($code)) ?? '');
    }

    /**
     * @return array<string, string> alias => canonical
     */
    public static function aliasMap(): array
    {
        static $map = null;
        if (is_array($map)) {
            return $map;
        }

        $map = [];
        foreach (self::groups() as $group) {
            foreach ($group['programmes'] as $programme) {
                $canonical = self::normalizeCode($programme['code']);
                if ($canonical === '') {
                    continue;
                }
                $map[$canonical] = $canonical;
                foreach ($programme['aliases'] as $alias) {
                    $key = self::normalizeCode($alias);
                    if ($key !== '') {
                        $map[$key] = $canonical;
                    }
                }
            }
        }

        return $map;
    }

    public static function resolveProgrammeCode(string $codeOrAlias): string
    {
        $needle = self::normalizeCode($codeOrAlias);
        if ($needle === '') {
            return '';
        }

        return self::aliasMap()[$needle] ?? $needle;
    }

    /**
     * @return array{parent:string,programmes:list<array{code:string,label:string,aliases:list<string>}>}|null
     */
    public static function findGroupByProgramme(string $codeOrAlias): ?array
    {
        $needle = self::resolveProgrammeCode($codeOrAlias);
        if ($needle === '') {
            return null;
        }

        foreach (self::groups() as $group) {
            foreach ($group['programmes'] as $programme) {
                if (self::normalizeCode($programme['code']) === $needle) {
                    return $group;
                }
            }
        }

        return null;
    }

    /**
     * @return array{parent:string,programmes:list<array{code:string,label:string,aliases:list<string>}>}|null
     */
    public static function findGroupByParentName(string $parentName): ?array
    {
        $needle = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($parentName)) ?? '');
        if ($needle === '') {
            return null;
        }

        foreach (self::groups() as $group) {
            $parent = strtolower(preg_replace('/[^a-z0-9]+/', '', $group['parent']) ?? '');
            if ($parent === $needle || str_contains($parent, $needle) || str_contains($needle, $parent)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return array{parent:string,programmes:list<array{code:string,label:string,aliases:list<string>}>}|null
     */
    public static function findGroupForDepartment(string $code, string $name = ''): ?array
    {
        $byProgramme = self::findGroupByProgramme($code);
        if ($byProgramme !== null) {
            return $byProgramme;
        }

        $byParent = self::findGroupByParentName($name !== '' ? $name : $code);
        if ($byParent !== null) {
            return $byParent;
        }

        $needle = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($name !== '' ? $name : $code)) ?? '');
        if ($needle === '') {
            return null;
        }

        foreach (self::groups() as $group) {
            $parent = strtolower(preg_replace('/[^a-z0-9]+/', '', $group['parent']) ?? '');
            if (str_contains($needle, $parent) || str_contains($parent, $needle)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array{parent:string,programmes:list<array{code:string,label:string,aliases:list<string>}>} $group
     * @return list<string>
     */
    public static function programmeCodesForGroup(array $group): array
    {
        $codes = [];
        foreach ($group['programmes'] as $programme) {
            $code = self::normalizeCode($programme['code']);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }
}
