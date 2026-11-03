<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AlumniModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;

/**
 * In-request index of alumni employment offer letters.
 * Matches registry admission numbers and account emails. Paths stay server-side.
 */
final class AlumniEmploymentOfferIndex
{
    /** @var array<string, array{path:string,updatedAt:string,company:string}> */
    private array $byRegister = [];

    /** @var array<string, array{path:string,updatedAt:string,company:string}> */
    private array $byEmail = [];

    private static ?self $loaded = null;

    public static function load(): self
    {
        if (self::$loaded instanceof self) {
            return self::$loaded;
        }

        $index = new self();
        try {
            $index->build();
        } catch (\Throwable) {
            // Registry and follow-up still work from student_placement_details.
        }
        self::$loaded = $index;

        return $index;
    }

    /**
     * @return array{path:string,updatedAt:string,company:string}|null
     */
    public function find(string $registerNumber, string $email = ''): ?array
    {
        $register = strtoupper(trim($registerNumber));
        if ($register !== '' && isset($this->byRegister[$register])) {
            return $this->byRegister[$register];
        }
        $email = strtolower(trim($email));
        if ($email !== '' && isset($this->byEmail[$email])) {
            return $this->byEmail[$email];
        }

        return null;
    }

    /**
     * Attach one alumni letter to the matching placement entry.
     * Multiple placements keep their own files; an unmatched letter is not copied onto the wrong row.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function matchingEntryIndex(array $entries, string $company): ?int
    {
        $company = self::normalizeName($company);
        $placementIndexes = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = trim((string) ($entry['type'] ?? ''));
            if (in_array($type, ['Higher Education', 'Research'], true)) {
                continue;
            }
            $placementIndexes[] = (int) $index;
            $employer = self::normalizeName((string) ($entry['employer'] ?? ''));
            if ($company !== '' && $employer !== '' && $employer === $company) {
                return (int) $index;
            }
        }
        if (count($placementIndexes) === 1) {
            return $placementIndexes[0];
        }

        return null;
    }

    private function build(): void
    {
        $profiles = (new AlumniModel())->findAll([], 20000, 0, ['updatedAt' => -1]);
        if ($profiles === []) {
            return;
        }

        $usersById = [];
        foreach ((new UserModel())->findAll(['role' => 'alumni'], 20000, 0, ['updatedAt' => -1]) as $user) {
            if (!is_array($user)) {
                continue;
            }
            $id = self::scalarId($user['_id'] ?? '');
            if ($id !== '') {
                $usersById[$id] = $user;
            }
        }

        $studentModel = new StudentModel();
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $docs = is_array($profile['employmentDocs'] ?? null) ? $profile['employmentDocs'] : [];
            $path = trim((string) ($docs['offerLetter'] ?? ''));
            if ($path === '') {
                continue;
            }
            $hit = [
                'path' => $path,
                'updatedAt' => self::timeString($docs['updatedAt'] ?? ''),
                'company' => trim((string) ($profile['company'] ?? '')),
            ];
            $userId = self::scalarId($profile['userId'] ?? '');
            $register = strtoupper(trim((string) ($profile['registerNumber'] ?? '')));
            $email = '';
            if ($userId !== '' && isset($usersById[$userId])) {
                $email = strtolower(trim((string) ($usersById[$userId]['email'] ?? '')));
                if ($register === '') {
                    $register = strtoupper(trim((string) ($usersById[$userId]['registerNumber'] ?? '')));
                }
            }
            if ($register === '' && $userId !== '') {
                $student = $studentModel->findByUserId($userId);
                if (is_array($student)) {
                    $register = strtoupper(trim((string) ($student['registerNumber'] ?? '')));
                    if ($email === '') {
                        $personal = is_array($student['personal'] ?? null) ? $student['personal'] : [];
                        $email = strtolower(trim((string) ($personal['collegeEmail'] ?? $student['collegeEmail'] ?? '')));
                    }
                }
            }
            if ($register !== '') {
                $this->byRegister[$register] = $hit;
            }
            if ($email !== '') {
                $this->byEmail[$email] = $hit;
            }
        }
    }

    private static function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private static function scalarId(mixed $value): string
    {
        if (is_array($value)) {
            if (isset($value['$oid'])) {
                return trim((string) $value['$oid']);
            }
            if (isset($value['oid'])) {
                return trim((string) $value['oid']);
            }
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return trim((string) $value);
        }

        return trim((string) $value);
    }

    private static function timeString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            foreach (['date', '$date'] as $key) {
                if (isset($value[$key]) && is_string($value[$key])) {
                    return trim($value[$key]);
                }
            }
        }

        return '';
    }
}
