<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

/**
 * Immutable audit log for Placement Policy acceptances.
 * Students cannot update or delete these rows through APIs.
 */
class PolicyAcceptanceLogModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::POLICY_ACCEPTANCE_LOGS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function logAcceptance(array $data): string
    {
        return $this->insert([
            'studentId'       => Security::toObjectId((string) ($data['studentId'] ?? '')),
            'userId'          => Security::toObjectId((string) ($data['userId'] ?? '')),
            'studentName'     => trim((string) ($data['studentName'] ?? '')),
            'registerNumber'  => trim((string) ($data['registerNumber'] ?? '')),
            'policyVersion'   => trim((string) ($data['policyVersion'] ?? '')),
            'acceptedAt'      => (string) ($data['acceptedAt'] ?? gmdate('c')),
            'acceptedIp'      => trim((string) ($data['acceptedIp'] ?? '')),
            'userAgent'       => trim((string) ($data['userAgent'] ?? '')),
            'deviceType'      => trim((string) ($data['deviceType'] ?? 'unknown')),
            'action'          => 'Accepted Placement Policy',
            'immutable'       => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(int $limit = 200, int $skip = 0): array
    {
        return $this->findAll([], $limit, $skip, ['acceptedAt' => -1, 'createdAt' => -1]);
    }
}
