<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class BroadcastLogModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::BROADCAST_LOGS;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function logBroadcast(array $data): string
    {
        return $this->insert([
            'sentBy'          => Security::toObjectId((string) ($data['sentBy'] ?? '')),
            'sentByName'      => trim((string) ($data['sentByName'] ?? '')),
            'sentByRole'      => trim((string) ($data['sentByRole'] ?? '')),
            'title'           => trim((string) ($data['title'] ?? '')),
            'message'         => trim((string) ($data['message'] ?? '')),
            'audience'        => trim((string) ($data['audience'] ?? '')),
            'audienceLabel'   => trim((string) ($data['audienceLabel'] ?? '')),
            'departmentId'    => !empty($data['departmentId']) ? Security::toObjectId((string) $data['departmentId']) : null,
            'recipientCount'  => (int) ($data['recipientCount'] ?? 0),
            'emailSentCount'  => (int) ($data['emailSentCount'] ?? 0),
            'sendEmail'       => (bool) ($data['sendEmail'] ?? true),
            'status'          => 'delivered',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        return $this->findAll([], $limit, 0, ['createdAt' => -1]);
    }
}
