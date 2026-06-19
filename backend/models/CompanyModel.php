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
