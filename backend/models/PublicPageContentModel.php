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
            'placed'       => 2154,
            'companies'    => 142,
            'highestPkg'   => 68.0,
            'avgPkg'       => 9.4,
            'medianPkg'    => 8.2,
            'lowestPkg'    => 3.5,
            'headline'     => 'Where ambition meets opportunity',
            'achievements' => 'Record ₹68 LPA international offer · 92.5% MCA placement rate',
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
