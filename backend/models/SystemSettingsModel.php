<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;

class SystemSettingsModel extends BaseModel
{
    private const DOC_KEY = 'default';

    protected function collectionName(): string
    {
        return Collections::SYSTEM_SETTINGS;
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
        $allowed = ['placementYear', 'emailFrom', 'maxUploadMb', 'smtpEnabled', 'notifyOnApproval'];
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
            'placementYear'    => '2025-26',
            'emailFrom'        => 'placement@college.edu',
            'maxUploadMb'      => 10,
            'smtpEnabled'      => true,
            'notifyOnApproval' => true,
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
