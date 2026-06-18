<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class AlumniModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::ALUMNI;
    }

    public function findByUserId(string $userId): ?array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return null;
        }
        $doc = $this->collection->findOne(['userId' => $oid]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProfile(string $userId, array $data): string
    {
        $skills = $this->normalizeSkills($data['skills'] ?? []);
        $company = trim((string) ($data['company'] ?? ''));
        $isWorking = $this->normalizeIsWorking($data, $company);

        $doc = [
            'userId'     => Security::toObjectId($userId),
            'company'    => $company,
            'role'       => trim((string) ($data['role'] ?? $data['title'] ?? '')),
            'experience' => (int) ($data['experience'] ?? 0),
            'skills'     => $skills,
            'isWorking'  => $isWorking,
        ];
        return $this->insert($doc);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateProfile(string $profileId, array $input): bool
    {
        $update = [];
        if (array_key_exists('company', $input)) {
            $update['company'] = trim((string) $input['company']);
        }
        if (array_key_exists('role', $input)) {
            $update['role'] = trim((string) $input['role']);
        }
        if (array_key_exists('title', $input)) {
            $update['role'] = trim((string) $input['title']);
        }
        if (array_key_exists('experience', $input)) {
            $update['experience'] = (int) $input['experience'];
        }
        if (array_key_exists('skills', $input)) {
            $update['skills'] = $this->normalizeSkills($input['skills']);
        }
        if (array_key_exists('isWorking', $input)) {
            $update['isWorking'] = $this->normalizeIsWorking($input, $update['company'] ?? null);
        }
        if (empty($update)) {
            return false;
        }
        return $this->update($profileId, $update);
    }

    /**
     * Map alumni profile fields for auth / frontend user object.
     *
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    public static function profileToUserFields(?array $profile): array
    {
        if ($profile === null) {
            return [];
        }
        $company = trim((string) ($profile['company'] ?? ''));
        $isWorking = array_key_exists('isWorking', $profile)
            ? (bool) $profile['isWorking']
            : $company !== '';

        return [
            'company'    => $company,
            'title'      => trim((string) ($profile['role'] ?? '')),
            'experience' => (int) ($profile['experience'] ?? 0),
            'skills'     => $profile['skills'] ?? [],
            'isWorking'  => $isWorking,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function serializeProfile(array $profile): array
    {
        $fields = self::profileToUserFields($profile);
        $profile['title'] = $fields['title'];
        $profile['isWorking'] = $fields['isWorking'];
        return $profile;
    }

    /**
     * @param mixed $skills
     * @return array<int, string>
     */
    private function normalizeSkills(mixed $skills): array
    {
        if (is_string($skills)) {
            return array_values(array_filter(array_map('trim', explode(',', $skills))));
        }
        if (!is_array($skills)) {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($s) => trim((string) $s), $skills)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function normalizeIsWorking(array $data, ?string $company = null): bool
    {
        if (array_key_exists('isWorking', $data)) {
            $val = $data['isWorking'];
            if (is_bool($val)) {
                return $val;
            }
            if (is_string($val)) {
                return in_array(strtolower($val), ['1', 'true', 'on', 'yes'], true);
            }
            return (bool) $val;
        }
        $company = $company ?? trim((string) ($data['company'] ?? ''));
        return $company !== '';
    }
}
