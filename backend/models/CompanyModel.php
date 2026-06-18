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
    public function createCompany(array $data): string
    {
        $doc = [
            'userId'             => isset($data['userId']) ? Security::toObjectId($data['userId']) : null,
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
        return $this->insert($doc);
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
