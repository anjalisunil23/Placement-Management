<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Resolve AJCE Heads of Department from config + public staff directory.
 * AES login often omits designation, so PlaceHub matches known HODs by email/name.
 */
final class AjceHodDirectory
{
    private const CACHE_TTL = 86400;
    private const CACHE_FILE = 'pms_ajce_hod_directory.json';

    /**
     * @return array{designation:string,name:string,emails:list<string>}|null
     */
    public static function matchUser(array $user): ?array
    {
        $emails = self::collectUserEmails($user);
        $name = self::normalizeName((string) ($user['name'] ?? ''));
        $nameCompact = self::compactName((string) ($user['name'] ?? ''));

        // Seed first (no network) so known HODs elevate even if directory fetch fails.
        $hit = self::matchAgainst(self::seedRecords(), $emails, $name, $nameCompact);
        if ($hit !== null) {
            return $hit;
        }

        return self::matchAgainst(self::cachedDirectoryHods(), $emails, $name, $nameCompact);
    }

    /**
     * @param list<array{designation:string,name:string,emails:list<string>}> $candidateSet
     * @param list<string> $emails
     * @return array{designation:string,name:string,emails:list<string>}|null
     */
    private static function matchAgainst(array $candidateSet, array $emails, string $name, string $nameCompact): ?array
    {
        foreach ($candidateSet as $row) {
            foreach ($row['emails'] as $candidateEmail) {
                if (self::emailsMatch($emails, $candidateEmail)) {
                    return $row;
                }
            }
        }
        if ($name === '' && $nameCompact === '') {
            return null;
        }
        foreach ($candidateSet as $row) {
            if (self::namesMatch($name, $nameCompact, $row['name'])) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<string> $emails
     */
    private static function emailsMatch(array $emails, string $candidateEmail): bool
    {
        $candidateEmail = strtolower(trim($candidateEmail));
        if ($candidateEmail === '' || $emails === []) {
            return false;
        }
        $candidateLocal = explode('@', $candidateEmail)[0] ?? '';
        foreach ($emails as $email) {
            if ($email === $candidateEmail) {
                return true;
            }
            $local = explode('@', $email)[0] ?? '';
            if ($local !== '' && $candidateLocal !== '' && $local === $candidateLocal) {
                return true;
            }
        }

        return false;
    }

    private static function namesMatch(string $name, string $nameCompact, string $rowName): bool
    {
        $rowNorm = self::normalizeName($rowName);
        $rowCompact = self::compactName($rowName);
        if ($rowNorm === '' && $rowCompact === '') {
            return false;
        }
        if ($name !== '' && ($name === $rowNorm || str_contains($name, $rowNorm) || str_contains($rowNorm, $name))) {
            return true;
        }
        $nameCore = self::stripTitles($name);
        $rowCore = self::stripTitles($rowNorm);
        if ($nameCore !== '' && $rowCore !== '' && ($nameCore === $rowCore || str_contains($nameCore, $rowCore) || str_contains($rowCore, $nameCore))) {
            return true;
        }
        // "BIJIMOL T K" vs "BIJIMOL TK" / "JUBY MATHEW"
        if ($nameCompact !== '' && $rowCompact !== '' && ($nameCompact === $rowCompact || str_contains($nameCompact, $rowCompact) || str_contains($rowCompact, $nameCompact))) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function collectUserEmails(array $user): array
    {
        $out = [];
        foreach (['email', 'collegeEmail', 'personalEmail', 'officialEmail', 'staffEmail'] as $key) {
            $email = strtolower(trim((string) ($user[$key] ?? '')));
            if ($email !== '' && str_contains($email, '@')) {
                $out[] = $email;
            }
        }
        $aes = $user['aesProfile'] ?? null;
        if (is_array($aes)) {
            foreach (['email', 'collegeEmail', 'personalEmail', 'staff_email', 'staffEmail', 'official_email'] as $key) {
                $email = strtolower(trim((string) ($aes[$key] ?? '')));
                if ($email !== '' && str_contains($email, '@')) {
                    $out[] = $email;
                }
            }
        }

        return array_values(array_unique($out));
    }

    public static function userIsHod(array $user): bool
    {
        return self::matchUser($user) !== null;
    }

    /**
     * @return list<array{designation:string,name:string,emails:list<string>}>
     */
    public static function allHodRecords(): array
    {
        $rows = array_merge(self::seedRecords(), self::cachedDirectoryHods());

        // Dedupe by normalized name + designation.
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = self::normalizeName($row['name']) . '|' . strtoupper($row['designation']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{designation:string,name:string,emails:list<string>}>
     */
    private static function seedRecords(): array
    {
        $config = [];
        try {
            $app = require dirname(__DIR__) . '/config/app.php';
            $config = is_array($app['hod_accounts'] ?? null) ? $app['hod_accounts'] : [];
        } catch (\Throwable) {
            $config = [];
        }

        $defaults = [
            [
                'name' => 'Dr. Bijimol T K',
                'designation' => 'HOD,Associate Professor',
                'emails' => ['tkbijimol@amaljyothi.ac.in', 'bijimoltk@amaljyothi.ac.in'],
            ],
            [
                'name' => 'Dr. Juby Mathew',
                'designation' => 'HOD,Professor',
                'emails' => ['jubymathew@amaljyothi.ac.in', 'juby.mathew@amaljyothi.ac.in', 'jubym@amaljyothi.ac.in'],
            ],
        ];

        $rows = [];
        foreach (array_merge($defaults, $config) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $designation = trim((string) ($row['designation'] ?? 'HOD'));
            $emails = [];
            foreach ((array) ($row['emails'] ?? []) as $email) {
                $email = strtolower(trim((string) $email));
                if ($email !== '' && str_contains($email, '@')) {
                    $emails[] = $email;
                }
            }
            if ($name === '' && $emails === []) {
                continue;
            }
            if (!HodDetection::designationLooksLikeHod($designation)) {
                $designation = 'HOD';
            }
            $rows[] = [
                'name' => $name !== '' ? $name : ($emails[0] ?? 'HOD'),
                'designation' => $designation,
                'emails' => array_values(array_unique($emails)),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{designation:string,name:string,emails:list<string>}>
     */
    private static function cachedDirectoryHods(): array
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::CACHE_FILE;
        $now = time();
        if (is_file($path)) {
            try {
                $raw = file_get_contents($path);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($decoded)
                    && isset($decoded['fetchedAt'], $decoded['rows'])
                    && ($now - (int) $decoded['fetchedAt']) < self::CACHE_TTL
                    && is_array($decoded['rows'])) {
                    return array_values(array_filter($decoded['rows'], 'is_array'));
                }
            } catch (\Throwable) {
                // Refresh below.
            }
        }

        $rows = self::fetchDirectoryHods();
        try {
            file_put_contents($path, json_encode([
                'fetchedAt' => $now,
                'rows' => $rows,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Non-fatal.
        }

        return $rows;
    }

    /**
     * Pull HOD cards from the public AJCE staff profile department pages.
     *
     * @return list<array{designation:string,name:string,emails:list<string>}>
     */
    private static function fetchDirectoryHods(): array
    {
        $deptUrls = [
            // Computer Science & Engineering
            'https://www.amaljyothi.ac.in/staffprofile/staffProfile.php?deptCode=aFVQTVBvbHdKdUZ3RVNDZXY1QTNqZz09',
            // Master of Computer Applications
            'https://www.amaljyothi.ac.in/staffprofile/staffProfile.php?deptCode=T3d5SU9lSEQ2b0VKRk95Y3kyOGxTZz09',
        ];

        $rows = [];
        foreach ($deptUrls as $url) {
            $html = self::httpGet($url);
            if ($html === '') {
                continue;
            }
            if (!preg_match_all(
                '/<div class="sp-name"[^>]*>\s*(.*?)\s*<\/div>\s*<div class="sp-desig"[^>]*>\s*(.*?)\s*<\/div>/si',
                $html,
                $matches,
                PREG_SET_ORDER
            )) {
                // Fallback: name/desig without relying on attribute order.
                if (!preg_match_all('/sp-name[^>]*>\s*(.*?)\s*<\/div>.*?sp-desig[^>]*>\s*(.*?)\s*<\/div>/si', $html, $matches, PREG_SET_ORDER)) {
                    continue;
                }
            }

            foreach ($matches as $match) {
                $name = trim(html_entity_decode(strip_tags($match[1])));
                $designation = trim(html_entity_decode(strip_tags($match[2])));
                if ($name === '' || !HodDetection::designationLooksLikeHod($designation)) {
                    continue;
                }
                $emails = self::guessEmailsFromContext($html, $name);
                $rows[] = [
                    'name' => $name,
                    'designation' => $designation,
                    'emails' => $emails,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function guessEmailsFromContext(string $html, string $name): array
    {
        $emails = [];
        // Profile subdomain near the name, e.g. jubymathew.amaljyothi.ac.in
        $quoted = preg_quote($name, '/');
        if (preg_match('/https?:\/\/([a-z0-9.-]+)\.amaljyothi\.ac\.in[^"]{0,200}' . $quoted . '|' . $quoted . '[^"]{0,200}https?:\/\/([a-z0-9.-]+)\.amaljyothi\.ac\.in/si', $html, $m)) {
            $host = strtolower((string) ($m[1] !== '' ? $m[1] : ($m[2] ?? '')));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if ($host !== '' && !str_contains($host, '/')) {
                $local = explode('.', $host)[0] ?? '';
                if ($local !== '' && $local !== 'www' && $local !== 'amaljyothi') {
                    $emails[] = $local . '@amaljyothi.ac.in';
                }
            }
        }

        return array_values(array_unique($emails));
    }

    private static function httpGet(string $url): string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 8,
                    'header' => "User-Agent: PlaceHub/1.0\r\n",
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);

            return is_string($raw) ? $raw : '';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'User-Agent: PlaceHub/1.0',
                'Accept: text/html',
            ],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        return is_string($raw) ? $raw : '';
    }

    private static function normalizeName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = preg_replace('/[^A-Z0-9\s]/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    private static function compactName(string $name): string
    {
        return str_replace(' ', '', self::stripTitles(self::normalizeName($name)));
    }

    private static function stripTitles(string $normalizedName): string
    {
        return trim(preg_replace('/\b(DR|PROF|PROFESSOR|FR|REV|MR|MRS|MS)\b/', '', $normalizedName) ?? $normalizedName);
    }
}
