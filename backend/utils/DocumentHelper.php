<?php

declare(strict_types=1);

namespace PMS\Utils;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Common document serialization helpers.
 */
final class DocumentHelper
{
    /**
     * @param array<string, mixed>|object|null $doc
     * @return array<string, mixed>|null
     */
    public static function serialize(mixed $doc): ?array
    {
        if ($doc === null) {
            return null;
        }
        if (is_object($doc) && method_exists($doc, 'getArrayCopy')) {
            $doc = $doc->getArrayCopy();
        }
        if (!is_array($doc)) {
            return null;
        }

        $out = [];
        foreach ($doc as $key => $value) {
            if ($key === 'password') {
                continue;
            }
            $out[$key] = self::serializeValue($value);
        }
        return $out;
    }

    private static function serializeValue(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format('c');
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::serializeValue($v), $value);
        }
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            return self::serialize($value->getArrayCopy());
        }
        return $value;
    }

    public static function now(): UTCDateTime
    {
        return new UTCDateTime();
    }

    /**
     * @param array<int, mixed> $docs
     * @return array<int, array<string, mixed>>
     */
    public static function serializeMany(array $docs): array
    {
        return array_values(array_filter(array_map(
            fn ($d) => self::serialize($d),
            $docs
        )));
    }
}
