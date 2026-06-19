<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

class PlacementNewsModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::PLACEMENT_NEWS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function published(int $limit = 50): array
    {
        return $this->findAll([], $limit, 0, ['date' => -1, 'createdAt' => -1]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createNews(array $data): string
    {
        return $this->insert([
            'title'   => trim((string) ($data['title'] ?? '')),
            'summary' => trim((string) ($data['summary'] ?? '')),
            'date'    => (string) ($data['date'] ?? ''),
            'link'    => trim((string) ($data['link'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateNews(string $id, array $data): bool
    {
        $allowed = ['title', 'summary', 'date', 'link'];
        $update = array_intersect_key($data, array_flip($allowed));
        if (empty($update)) {
            return false;
        }
        return $this->update($id, $update);
    }
}
