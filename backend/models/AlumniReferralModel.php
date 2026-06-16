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
        return $this->insert([
            'alumniUserId' => Security::toObjectId($alumniUserId),
            'jobTitle'     => $data['jobTitle'] ?? '',
            'companyName'  => $data['companyName'] ?? '',
            'description'  => $data['description'] ?? '',
            'link'         => $data['link'] ?? '',
            'package'      => $data['package'] ?? '',
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
}
