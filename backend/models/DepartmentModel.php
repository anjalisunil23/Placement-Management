<?php

declare(strict_types=1);

namespace PMS\Models;

use PMS\Schemas\Collections;

class DepartmentModel extends BaseModel
{
    protected function collectionName(): string
    {
        return Collections::DEPARTMENTS;
    }

    public function findByCode(string $code): ?array
    {
        $doc = $this->collection->findOne(['code' => strtoupper(trim($code))]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDepartment(array $data): string
    {
        return $this->insert([
            'name' => $data['name'],
            'code' => strtoupper(trim($data['code'])),
        ]);
    }
}
