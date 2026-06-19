<?php

declare(strict_types=1);

namespace PMS\Utils;

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
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if (is_array($value)) {
            return array_map(fn ($v) => self::serializeValue($v), $value);
        }
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            return self::serialize($value->getArrayCopy());
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return $value;
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
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
