<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class CompanyModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::COMPANIES;
    }

    public function findByUserId(string $userId): ?array
    {
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return null;
        }
        return $this->findOne(['userId' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEnriched(int $limit = 200): array
    {
        $rows = [];
        foreach ($this->findAll([], $limit) as $company) {
            $rows[] = $this->enrichCompanyRecord($company);
        }

        return $rows;
    }

    /**
     * Fill missing website and HR contact from linked user or matching referrals.
     *
     * @param array<string, mixed> $company
     * @return array<string, mixed>
     */
    public function enrichCompanyRecord(array $company): array
    {
        $contacts = $this->normalizeContacts($company['contacts'] ?? null);
        $primary = $contacts[0];

        $userId = (string) ($company['userId'] ?? '');
        if ($userId !== '') {
            $user = (new UserModel())->findById($userId);
            if ($user) {
                if ($primary['name'] === '') {
                    $primary['name'] = trim((string) ($user['name'] ?? ''));
                }
                if ($primary['email'] === '') {
                    $primary['email'] = trim((string) ($user['email'] ?? ''));
                }
                if ($primary['phone'] === '') {
                    $primary['phone'] = trim((string) ($user['phone'] ?? ''));
                }
            }
        }

        $website = trim((string) ($company['website'] ?? ''));
        $companyName = trim((string) ($company['companyName'] ?? ''));
        if ($companyName !== ''
            && ($primary['name'] === '' || $primary['email'] === '' || $primary['phone'] === '' || $website === '')) {
            $referral = $this->findReferralContactForCompany($companyName);
            if ($referral !== null) {
                if ($primary['name'] === '') {
                    $primary['name'] = $referral['name'];
                }
                if ($primary['email'] === '') {
                    $primary['email'] = $referral['email'];
                }
                if ($primary['phone'] === '') {
                    $primary['phone'] = $referral['phone'];
                }
                if ($website === '') {
                    $website = $referral['website'];
                }
            }
        }

        $company['contacts'] = [$primary];
        if ($website !== '') {
            $company['website'] = $website;
        }

        return $company;
    }

    /**
     * @return array<int, array{name:string,email:string,phone:string}>
     */
    private function normalizeContacts(mixed $contacts): array
    {
        if (is_array($contacts) && array_key_exists('name', $contacts)) {
            return [[
                'name'  => trim((string) ($contacts['name'] ?? '')),
                'email' => trim((string) ($contacts['email'] ?? '')),
                'phone' => trim((string) ($contacts['phone'] ?? '')),
            ]];
        }

        if (!is_array($contacts) || $contacts === []) {
            return [['name' => '', 'email' => '', 'phone' => '']];
        }

        $first = is_array($contacts[0] ?? null) ? $contacts[0] : [];

        return [[
            'name'  => trim((string) ($first['name'] ?? '')),
            'email' => trim((string) ($first['email'] ?? '')),
            'phone' => trim((string) ($first['phone'] ?? '')),
        ]];
    }

    /**
     * @return array{name:string,email:string,phone:string,website:string}|null
     */
    private function findReferralContactForCompany(string $companyName): ?array
    {
        $needle = strtolower($companyName);
        $best = null;
        $bestScore = -1;
        $scoreStatus = ['registered' => 4, 'contacted' => 3, 'pending' => 2, 'rejected' => 0];

        foreach ((new RecommendationModel())->findAll([], 500) as $rec) {
            if (strtolower(trim((string) ($rec['companyName'] ?? ''))) !== $needle) {
                continue;
            }
            $score = $scoreStatus[(string) ($rec['status'] ?? 'pending')] ?? 1;
            if ($score <= $bestScore) {
                continue;
            }
            $contact = is_array($rec['contact'] ?? null) ? $rec['contact'] : [];
            $best = [
                'name'    => trim((string) ($contact['name'] ?? '')),
                'email'   => trim((string) ($contact['email'] ?? '')),
                'phone'   => trim((string) ($contact['phone'] ?? '')),
                'website' => trim((string) ($rec['companyWebsite'] ?? '')),
            ];
            $bestScore = $score;
        }

        foreach ((new AlumniReferralModel())->findAll([], 500) as $rec) {
            if (strtolower(trim((string) ($rec['companyName'] ?? ''))) !== $needle) {
                continue;
            }
            $score = $scoreStatus[(string) ($rec['status'] ?? 'pending')] ?? 1;
            if ($score <= $bestScore) {
                continue;
            }
            $contact = is_array($rec['contact'] ?? null) ? $rec['contact'] : [];
            $best = [
                'name'    => trim((string) ($contact['name'] ?? '')),
                'email'   => trim((string) ($contact['email'] ?? '')),
                'phone'   => trim((string) ($contact['phone'] ?? '')),
                'website' => trim((string) ($rec['companyWebsite'] ?? $rec['link'] ?? '')),
            ];
            $bestScore = $score;
        }

        if ($best === null) {
            return null;
        }
        if ($best['name'] === '' && $best['email'] === '' && $best['phone'] === '' && $best['website'] === '') {
            return null;
        }

        return $best;
    }

    /**
     * Normalize company names for duplicate detection.
     */
    public static function normalizeCompanyName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9.&\s+-]/', '', $name) ?? $name;

        return trim($name);
    }

    /**
     * Find an existing company by case-insensitive normalized name.
     *
     * @return array<string, mixed>|null
     */
    public function findByNormalizedName(string $companyName): ?array
    {
        $needle = self::normalizeCompanyName($companyName);
        if ($needle === '') {
            return null;
        }

        foreach ($this->findAll([], 5000) as $company) {
            $existing = self::normalizeCompanyName((string) ($company['companyName'] ?? ''));
            if ($existing !== '' && $existing === $needle) {
                return $company;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createCompany(array $data): string
    {
        $doc = [
            'companyName'        => $data['companyName'],
            'category'           => $data['category'] ?? 'Software',
            'tier'               => $data['tier'] ?? 'Tier 2',
            'contacts'           => $data['contacts'] ?? [],
            'recruitmentHistory' => [],
            'associationStatus'  => $data['associationStatus'] ?? 'pending',
            'comments'           => $data['comments'] ?? '',
            'website'            => $data['website'] ?? '',
            'description'        => $data['description'] ?? '',
        ];
        if (!empty($data['userId'])) {
            $doc['userId'] = Security::toObjectId($data['userId']);
        }
        return $this->insert($doc);
    }

    public function deleteByUserId(string $userId): bool
    {
        $profile = $this->findByUserId($userId);
        if (!$profile) {
            return false;
        }
        return $this->delete((string) $profile['_id']);
    }

    /**
     * @param array<string, mixed>|null $company
     * @return array<string, mixed>
     */
    public static function profileToUserFields(?array $company): array
    {
        if ($company === null) {
            return [];
        }
        return [
            'companyId'   => (string) ($company['_id'] ?? ''),
            'companyName' => (string) ($company['companyName'] ?? ''),
            'category'    => (string) ($company['category'] ?? ''),
            'tier'        => (string) ($company['tier'] ?? ''),
            'website'     => (string) ($company['website'] ?? ''),
        ];
    }
}
