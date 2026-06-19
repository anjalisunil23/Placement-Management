<?php

declare(strict_types=1);

namespace PMS\Database;

/**
 * Translates Mongo-style filter arrays into SQL WHERE clauses for JSON payload tables.
 */
final class QueryHelper
{
    /**
     * @param array<string, mixed> $filter
     * @return array{0: string, 1: array<int, mixed>}
     */
    public static function buildWhere(array $filter): array
    {
        if ($filter === []) {
            return ['1=1', []];
        }

        $parts = [];
        $params = [];

        foreach ($filter as $key => $value) {
            if ($key === '$or') {
                $orParts = [];
                foreach ((array) $value as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }
                    [$sql, $branchParams] = self::buildWhere($branch);
                    $orParts[] = "($sql)";
                    $params = array_merge($params, $branchParams);
                }
                if ($orParts !== []) {
                    $parts[] = '(' . implode(' OR ', $orParts) . ')';
                }
                continue;
            }

            if ($key === '$and') {
                foreach ((array) $value as $branch) {
                    if (!is_array($branch)) {
                        continue;
                    }
                    [$sql, $branchParams] = self::buildWhere($branch);
                    $parts[] = "($sql)";
                    $params = array_merge($params, $branchParams);
                }
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $column = $key === '_id' ? 'id' : self::jsonPath($key);

            if (is_array($value) && self::isOperatorMap($value)) {
                foreach ($value as $op => $operand) {
                    $parts[] = self::operatorClause($column, (string) $op, $operand, $params);
                }
                continue;
            }

            if ($value === null) {
                $parts[] = "(JSON_EXTRACT(payload, '{$column}') IS NULL OR JSON_TYPE(JSON_EXTRACT(payload, '{$column}')) = 'NULL')";
                continue;
            }

            $parts[] = self::equalityClause($column, $value, $params);
        }

        if ($parts === []) {
            return ['1=1', []];
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * @param array<string, mixed> $sort e.g. ['createdAt' => -1, 'date' => -1]
     * @return string
     */
    public static function buildOrderBy(array $sort): string
    {
        if ($sort === []) {
            return 'created_at DESC';
        }

        $clauses = [];
        foreach ($sort as $field => $direction) {
            $dir = ((int) $direction) < 0 ? 'DESC' : 'ASC';
            if ($field === 'createdAt' || $field === '_id') {
                $clauses[] = 'created_at ' . $dir;
                continue;
            }
            if ($field === 'updatedAt') {
                $clauses[] = 'updated_at ' . $dir;
                continue;
            }
            $path = self::jsonPath((string) $field);
            $clauses[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '{$path}')) {$dir}";
        }

        return implode(', ', $clauses);
    }

    private static function jsonPath(string $field): string
    {
        $segments = explode('.', $field);
        return '$.' . implode('.', array_map(
            static fn (string $s) => str_replace("'", "\\'", $s),
            $segments
        ));
    }

    /**
     * @param array<mixed> $value
     */
    private static function isOperatorMap(array $value): bool
    {
        foreach (array_keys($value) as $k) {
            if (is_string($k) && str_starts_with($k, '$')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function equalityClause(string $path, mixed $value, array &$params): string
    {
        if (is_bool($value)) {
            return 'JSON_EXTRACT(payload, \'' . $path . '\') = ' . ($value ? 'true' : 'false');
        }
        $params[] = self::normalizeValue($value);
        return 'JSON_UNQUOTE(JSON_EXTRACT(payload, \'' . $path . '\')) = ?';
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function operatorClause(string $path, string $op, mixed $operand, array &$params): string
    {
        $extract = 'JSON_UNQUOTE(JSON_EXTRACT(payload, \'' . $path . '\'))';

        return match ($op) {
            '$eq' => self::equalityClause($path, $operand, $params),
            '$ne' => self::appendParam('(' . $extract . ' IS NULL OR ' . $extract . ' != ?)', $operand, $params),
            '$gt' => self::appendParam('CAST(' . $extract . ' AS DECIMAL(18,4)) > ?', $operand, $params),
            '$gte' => self::appendParam('CAST(' . $extract . ' AS DECIMAL(18,4)) >= ?', $operand, $params),
            '$lt' => self::appendParam('CAST(' . $extract . ' AS DECIMAL(18,4)) < ?', $operand, $params),
            '$lte' => self::appendParam('CAST(' . $extract . ' AS DECIMAL(18,4)) <= ?', $operand, $params),
            '$in' => self::inClause($path, (array) $operand, false, $params),
            '$nin' => self::inClause($path, (array) $operand, true, $params),
            '$regex' => self::appendParam('LOWER(' . $extract . ') LIKE LOWER(?)', $operand, $params),
            default => '1=1',
        };
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function appendParam(string $sql, mixed $operand, array &$params): string
    {
        $params[] = self::normalizeValue($operand);
        return $sql;
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, mixed> $params
     */
    private static function inClause(string $path, array $values, bool $negate, array &$params): string
    {
        if ($values === []) {
            return $negate ? '1=1' : '1=0';
        }
        $extract = 'JSON_UNQUOTE(JSON_EXTRACT(payload, \'' . $path . '\'))';
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        foreach ($values as $v) {
            $params[] = self::normalizeValue($v);
        }
        $op = $negate ? 'NOT IN' : 'IN';
        return "{$extract} {$op} ({$placeholders})";
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return $value;
    }
}
