<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Class teacher (CT) and co-class teacher (CoCT) assignments for placement edits.
 *
 * AES placement APIs do not publish these roles; Computer Applications assignments
 * are maintained here from the department staff directory (CT- / CoCT- badges).
 * Semester suffixes (S1, S3, …) may change — matching uses the cohort prefix.
 */
final class ClassInchargeRegistry
{
    /**
     * @return array<string, array{classTeacher: string, coClassTeacher: string, emails?: list<string>}>
     */
    public static function assignments(): array
    {
        return [
            // MCA
            'MCA2025-27' => [
                'classTeacher'   => 'Nimmy Francis',
                'coClassTeacher' => 'Jinson Devis',
            ],
            'MCA2026-28' => [
                'classTeacher'   => 'Amal K Jose',
                'coClassTeacher' => 'Ajith G S',
            ],
            // Integrated MCA
            'MCAINT2022-27' => [
                'classTeacher'   => 'Meera Rose Mathew',
                'coClassTeacher' => 'Rony Tom',
            ],
            'MCAINT2023-28' => [
                'classTeacher'   => 'Binumon Joseph',
                'coClassTeacher' => 'Dr. Mintu Movi',
            ],
            'MCAINT2024-29' => [
                'classTeacher'   => 'Navyamol K T',
                'coClassTeacher' => 'Somina Augustine',
            ],
            'MCAINT2025-30' => [
                'classTeacher'   => 'Sona Maria Sebastian',
                'coClassTeacher' => 'Susmin Mariam Chacko',
            ],
            'MCAINT2026-31' => [
                'classTeacher'   => 'Anit James',
                'coClassTeacher' => '',
            ],
        ];
    }

    /**
     * Batches (canonical cohort keys) this staff member is class / co-class teacher for.
     *
     * @param array<string, mixed> $staffCtx
     * @return list<string>
     */
    public static function batchesForStaff(array $staffCtx): array
    {
        $identity = self::staffIdentityTokens($staffCtx);
        if ($identity === []) {
            return [];
        }

        $batches = [];
        foreach (self::assignments() as $cohort => $roles) {
            $names = [
                (string) ($roles['classTeacher'] ?? ''),
                (string) ($roles['coClassTeacher'] ?? ''),
            ];
            foreach ($names as $name) {
                if ($name !== '' && self::identityMatchesName($identity, $name)) {
                    $batches[] = $cohort;
                    break;
                }
            }
            foreach ($roles['emails'] ?? [] as $email) {
                if (self::identityMatchesEmail($identity, (string) $email)) {
                    $batches[] = $cohort;
                    break;
                }
            }
        }

        return array_values(array_unique($batches));
    }

    /**
     * @param array<string, mixed> $staffCtx
     */
    public static function staffIsInchargeOfBatch(array $staffCtx, string $batch): bool
    {
        $batch = trim($batch);
        if ($batch === '') {
            return false;
        }
        $cohort = self::cohortKey($batch);
        foreach (self::batchesForStaff($staffCtx) as $assigned) {
            if (strcasecmp($cohort, self::cohortKey($assigned)) === 0) {
                return true;
            }
            if (strcasecmp($batch, trim($assigned)) === 0) {
                return true;
            }
        }

        // Also allow exact/cohort match when AES assigned list already has the full class label.
        return false;
    }

    /**
     * Strip trailing semester marker: MCAINT2022-27-S9 → MCAINT2022-27.
     */
    public static function cohortKey(string $batch): string
    {
        $batch = strtoupper(trim($batch));
        $batch = preg_replace('/\s+/', '', $batch) ?? $batch;
        if (preg_match('/^(.*)-S\d+$/i', $batch, $m) === 1) {
            return strtoupper($m[1]);
        }

        return $batch;
    }

    /**
     * True when this class label belongs to a CT/CoCT-mapped cohort.
     */
    public static function isMappedCohort(string $batch): bool
    {
        $cohort = self::cohortKey($batch);
        if ($cohort === '') {
            return false;
        }
        foreach (array_keys(self::assignments()) as $key) {
            if (strcasecmp(self::cohortKey((string) $key), $cohort) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $staffCtx
     * @return list<string>
     */
    private static function staffIdentityTokens(array $staffCtx): array
    {
        $user = is_array($staffCtx['user'] ?? null) ? $staffCtx['user'] : [];
        $profile = is_array($staffCtx['profile'] ?? null) ? $staffCtx['profile'] : [];
        $aes = \PMS\Utils\Security::getSessionAesProfile();
        $aes = is_array($aes) ? $aes : [];

        $names = [
            (string) ($user['name'] ?? ''),
            (string) ($profile['name'] ?? ''),
            (string) ($aes['stud_name'] ?? $aes['name'] ?? $aes['staff_name'] ?? $aes['emp_name'] ?? ''),
        ];
        $emails = [
            (string) ($user['email'] ?? ''),
            (string) ($profile['email'] ?? ''),
            (string) ($aes['email'] ?? $aes['staff_email'] ?? $aes['emp_email'] ?? $aes['collegeEmail'] ?? ''),
        ];

        $tokens = [];
        foreach ($names as $name) {
            $n = self::normalizePersonName($name);
            if ($n !== '') {
                $tokens[] = 'name:' . $n;
            }
        }
        foreach ($emails as $email) {
            $e = strtolower(trim($email));
            if ($e !== '' && str_contains($e, '@')) {
                $tokens[] = 'email:' . $e;
                $local = strstr($e, '@', true);
                if (is_string($local) && $local !== '') {
                    $tokens[] = 'local:' . preg_replace('/[^a-z0-9]/', '', $local);
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param list<string> $identity
     */
    private static function identityMatchesName(array $identity, string $name): bool
    {
        $want = self::normalizePersonName($name);
        if ($want === '') {
            return false;
        }
        foreach ($identity as $token) {
            if (!str_starts_with($token, 'name:')) {
                continue;
            }
            $have = substr($token, 5);
            if ($have === $want || str_contains($have, $want) || str_contains($want, $have)) {
                return true;
            }
            // Token overlap (handles "Meera Rose Mathew" vs "Meera Mathew")
            $wantParts = array_values(array_filter(explode(' ', $want), static fn ($p) => strlen($p) > 2));
            $haveParts = array_values(array_filter(explode(' ', $have), static fn ($p) => strlen($p) > 2));
            if ($wantParts !== [] && $haveParts !== []) {
                $overlap = array_intersect($wantParts, $haveParts);
                if (count($overlap) >= min(2, count($wantParts), count($haveParts))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $identity
     */
    private static function identityMatchesEmail(array $identity, string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        foreach ($identity as $token) {
            if ($token === 'email:' . $email) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePersonName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\b(dr|fr|mr|mrs|ms|prof|professor|asst|assistant)\b\.?/i', '', $name) ?? $name;
        $name = preg_replace('/[^a-z\s]/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }
}
