<?php

declare(strict_types=1);

namespace PMS\Models;

use PDO;
use PMS\Config\Database;
use PMS\Database\QueryHelper;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Security;

/**
 * Base model — MariaDB JSON document storage with Mongo-compatible document shapes.
 */
abstract class BaseModel
{
    protected PDO $db;
    protected string $table;

    abstract protected function collectionName(): string;

    public function __construct()
    {
        $this->db = Database::pdo();
        $this->table = $this->collectionName();
    }

    public function findById(string $id): ?array
    {
        if (!Security::isValidId($id)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id, payload, created_at, updated_at FROM `{$this->table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->rowToDoc($row) : null;
    }

    /**
     * Bulk load by primary key — avoids N+1 findById loops on list endpoints.
     *
     * @param array<int, string> $ids
     * @return array<string, array<string, mixed>> keyed by id
     */
    public function findByIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && Security::isValidId($id)) {
                $clean[$id] = true;
            }
        }
        $ids = array_keys($clean);
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, payload, created_at, updated_at FROM `{$this->table}` WHERE id IN ({$placeholders})"
            );
            $stmt->execute($chunk);
            while ($row = $stmt->fetch()) {
                $doc = $this->rowToDoc($row);
                $map[(string) ($doc['_id'] ?? $row['id'])] = $doc;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function findOne(array $filter, array $options = []): ?array
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $sort = $options['sort'] ?? ['createdAt' => -1];
        $orderBy = QueryHelper::buildOrderBy(is_array($sort) ? $sort : ['createdAt' => -1]);

        $sql = "SELECT id, payload, created_at, updated_at FROM `{$this->table}` WHERE {$where} ORDER BY {$orderBy} LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? $this->rowToDoc($row) : null;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $filter = [], int $limit = 100, int $skip = 0, array $sort = ['createdAt' => -1]): array
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $orderBy = QueryHelper::buildOrderBy($sort);

        $sql = "SELECT id, payload, created_at, updated_at FROM `{$this->table}` WHERE {$where} ORDER BY {$orderBy} LIMIT " . (int) $limit . " OFFSET " . (int) $skip;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch()) {
            $results[] = $this->rowToDoc($row);
        }
        return $results;
    }

    /**
     * Id-only lookup — avoids decoding large JSON payloads for filter/$in builds.
     *
     * @param array<string, mixed> $filter
     * @return list<string>
     */
    public function findIds(array $filter = [], int $limit = 5000): array
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $sql = "SELECT id FROM `{$this->table}` WHERE {$where} LIMIT " . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        while ($row = $stmt->fetch()) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }

    /**
     * Pluck a top-level JSON string field without decoding full payloads.
     *
     * @param array<string, mixed> $filter
     * @return list<string>
     */
    public function pluckField(string $field, array $filter = [], int $limit = 5000): array
    {
        $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field) ?? '';
        if ($field === '') {
            return [];
        }
        [$where, $params] = QueryHelper::buildWhere($filter);
        $sql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$field}')) AS v
                FROM `{$this->table}` WHERE {$where} LIMIT " . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $values = [];
        while ($row = $stmt->fetch()) {
            $v = trim((string) ($row['v'] ?? ''));
            if ($v !== '' && strcasecmp($v, 'null') !== 0) {
                $values[] = $v;
            }
        }

        return $values;
    }

    /**
     * GROUP BY a top-level JSON field using SQL (no full payload decode).
     *
     * @param array<string, mixed> $filter
     * @return array<string, int> value => count
     */
    public function countByField(string $field, array $filter = []): array
    {
        $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field) ?? '';
        if ($field === '') {
            return [];
        }
        [$where, $params] = QueryHelper::buildWhere($filter);
        $sql = "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$field}')), '') AS grp, COUNT(*) AS cnt
                FROM `{$this->table}` WHERE {$where}
                GROUP BY grp";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        while ($row = $stmt->fetch()) {
            $grp = trim((string) ($row['grp'] ?? ''));
            if ($grp === '' || strcasecmp($grp, 'null') === 0) {
                $grp = '';
            }
            $out[$grp] = (int) ($row['cnt'] ?? 0);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function count(array $filter = []): int
    {
        [$where, $params] = QueryHelper::buildWhere($filter);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string
    {
        $id = Security::generateId();
        $now = DocumentHelper::now();
        $payload = $this->normalizeForStorage($data);
        unset($payload['_id']);

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` (id, payload, created_at, updated_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }

        $existing = $this->findById($id);
        if (!$existing) {
            return false;
        }

        unset($data['_id'], $data['createdAt']);
        $merged = array_merge($existing, $this->normalizeForStorage($data));
        unset($merged['_id']);

        $now = DocumentHelper::now();
        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}` SET payload = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([
            json_encode($merged, JSON_THROW_ON_ERROR),
            $now,
            $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(string $id): bool
    {
        if (!Security::isValidId($id)) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM `{$this->table}` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $set
     */
    public function updateMany(array $filter, array $set): int
    {
        $rows = $this->findAll($filter, 5000, 0, ['createdAt' => 1]);
        $count = 0;
        foreach ($rows as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id !== '' && $this->update($id, $set)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $filter
     */
    public function deleteMany(array $filter): int
    {
        $rows = $this->findAll($filter, 5000, 0, ['createdAt' => 1]);
        $count = 0;
        foreach ($rows as $row) {
            $id = (string) ($row['_id'] ?? '');
            if ($id !== '' && $this->delete($id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $set
     * @param array<string, mixed> $setOnInsert
     */
    public function upsert(array $filter, array $set, array $setOnInsert = []): string
    {
        $existing = $this->findOne($filter);
        if ($existing) {
            $id = (string) ($existing['_id'] ?? '');
            $this->update($id, $set);
            return $id;
        }

        return $this->insert(array_merge($setOnInsert, $set));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function rowToDoc(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $doc = $payload;
        $doc['_id'] = (string) $row['id'];
        $doc['createdAt'] = $row['created_at'] ?? $doc['createdAt'] ?? null;
        $doc['updatedAt'] = $row['updated_at'] ?? $doc['updatedAt'] ?? null;

        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalizeForStorage(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = $this->normalizeValue($value);
        }
        return $out;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        if (is_array($value)) {
            $arr = [];
            foreach ($value as $k => $v) {
                $arr[$k] = $this->normalizeValue($v);
            }
            return $arr;
        }
        return $value;
    }
}
