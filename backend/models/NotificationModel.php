<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\Security;

class NotificationModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::NOTIFICATIONS;
    }

    public function notify(string $userId, string $type, string $title, string $message, array $metadata = []): string
    {
        return $this->insert([
            'userId'   => Security::toObjectId($userId),
            'type'     => $type,
            'title'    => $title,
            'message'  => $message,
            'read'     => false,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(string $userId, bool $unreadOnly = false): array
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return [];
        }
        $filter = ['userId' => $oid];
        if ($unreadOnly) {
            $filter['read'] = false;
        }
        return $this->findAll($filter);
    }

    public function markRead(string $id): bool
    {
        return $this->update($id, ['read' => true]);
    }

    public function markAllRead(string $userId): int
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return 0;
        }
        $result = $this->collection->updateMany(
            ['userId' => $oid, 'read' => false],
            ['$set' => ['read' => true]]
        );
        return (int) $result->getModifiedCount();
    }

    public function countUnread(string $userId): int
    {
        $oid = Security::toObjectId($userId);
        if ($oid === null) {
            return 0;
        }
        return (int) $this->collection->countDocuments(['userId' => $oid, 'read' => false]);
    }
}
