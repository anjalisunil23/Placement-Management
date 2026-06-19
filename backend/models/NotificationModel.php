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
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return [];
        }
        $filter = ['userId' => $id];
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
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return 0;
        }
        return $this->updateMany(
            ['userId' => $id, 'read' => false],
            ['read' => true]
        );
    }

    public function countUnread(string $userId): int
    {
        $id = Security::toObjectId($userId);
        if ($id === null) {
            return 0;
        }
        return $this->count(['userId' => $id, 'read' => false]);
    }
}
