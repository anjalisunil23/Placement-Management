<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

class PublicPageContentModel extends BaseModel
{
    private const DOC_KEY = 'default';

    protected function collectionName(): string
    {
        return Collections::PUBLIC_PAGE_CONTENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $doc = $this->findOne(['key' => self::DOC_KEY]);
        return $this->normalize($doc ?? []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $allowed = [
            'season', 'placed', 'companies', 'highestPkg', 'avgPkg',
            'medianPkg', 'lowestPkg', 'headline', 'achievements',
        ];
        $update = array_intersect_key($data, array_flip($allowed));
        $update['key'] = self::DOC_KEY;
        $update['updatedAt'] = DocumentHelper::now();

        $this->upsert(
            ['key' => self::DOC_KEY],
            $update,
            ['createdAt' => DocumentHelper::now()]
        );

        return $this->get();
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function normalize(array $doc): array
    {
        $defaults = [
            'season'       => '2025-26',
            'placed'       => 0,
            'companies'    => 0,
            'highestPkg'   => 0.0,
            'avgPkg'       => 0.0,
            'medianPkg'    => 0.0,
            'lowestPkg'    => 0.0,
            'headline'     => 'Where ambition meets opportunity',
            'achievements' => 'Placement statistics are computed live from campus data.',
        ];

        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $doc)) {
                $doc[$key] = $value;
            }
        }

        unset($doc['_id'], $doc['key'], $doc['createdAt'], $doc['updatedAt']);

        return $doc;
    }
}
