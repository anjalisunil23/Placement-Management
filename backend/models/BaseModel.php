<?php

declare(strict_types=1);

namespace PMS\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use PMS\Config\Database;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Base model with common MongoDB operations.
 */
abstract class BaseModel
{
    protected Collection $collection;

    abstract protected function collectionName(): string;

    public function __construct()
    {
        $this->collection = Database::collection($this->collectionName());
    }

    public function findById(string $id): ?array
    {
        $oid = Security::toObjectId($id);
        if ($oid === null) {
            return null;
        }
        $doc = $this->collection->findOne(['_id' => $oid]);
        return $doc ? (array) $doc : null;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $filter = [], int $limit = 100, int $skip = 0): array
    {
        $cursor = $this->collection->find(
            Security::sanitizeFilterValue($filter) ?? [],
            ['limit' => $limit, 'skip' => $skip, 'sort' => ['createdAt' => -1]]
        );
        $results = [];
        foreach ($cursor as $doc) {
            $results[] = (array) $doc;
        }
        return $results;
    }

    public function count(array $filter = []): int
    {
        return $this->collection->countDocuments(
            Security::sanitizeFilterValue($filter) ?? []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string
    {
        $data['createdAt'] = DocumentHelper::now();
        $data['updatedAt'] = DocumentHelper::now();
        $result = $this->collection->insertOne($data);
        return (string) $result->getInsertedId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        $oid = Security::toObjectId($id);
        if ($oid === null) {
            return false;
        }
        unset($data['_id'], $data['createdAt']);
        $data['updatedAt'] = DocumentHelper::now();
        $result = $this->collection->updateOne(
            ['_id' => $oid],
            ['$set' => $data]
        );
        return $result->getModifiedCount() > 0 || $result->getMatchedCount() > 0;
    }

    public function delete(string $id): bool
    {
        $oid = Security::toObjectId($id);
        if ($oid === null) {
            return false;
        }
        $result = $this->collection->deleteOne(['_id' => $oid]);
        return $result->getDeletedCount() > 0;
    }
}
