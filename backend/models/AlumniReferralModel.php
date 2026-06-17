<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class AlumniReferralModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::ALUMNI_REFERRALS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createReferral(string $alumniUserId, array $data): string
    {
        $link = trim((string) ($data['link'] ?? $data['companyWebsite'] ?? ''));
        $type = trim((string) ($data['referralType'] ?? $data['type'] ?? 'Either'));

        return $this->insert([
            'alumniUserId' => Security::toObjectId($alumniUserId),
            'jobTitle'     => trim((string) ($data['jobTitle'] ?? '')),
            'companyName'  => trim((string) ($data['companyName'] ?? '')),
            'description'  => trim((string) ($data['description'] ?? '')),
            'link'         => $link,
            'package'      => trim((string) ($data['package'] ?? '')),
            'referralType' => $type !== '' ? $type : 'Either',
            'status'       => 'submitted',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByAlumni(string $alumniUserId): array
    {
        $oid = Security::toObjectId($alumniUserId);
        if ($oid === null) {
            return [];
        }
        return $this->findAll(['alumniUserId' => $oid]);
    }

    public function countByAlumni(string $alumniUserId): int
    {
        $oid = Security::toObjectId($alumniUserId);
        if ($oid === null) {
            return 0;
        }
        return $this->count(['alumniUserId' => $oid]);
    }
}
