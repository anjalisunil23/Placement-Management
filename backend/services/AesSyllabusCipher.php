<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * AES-256-CBC encoding used by campus syllabus ids (aesenc).
 */
final class AesSyllabusCipher
{
    /**
     * Encode a semester subject id the same way campus PHP does:
     * $_encid = aesenc($semsubId);
     */
    public static function encrypt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $encoded = openssl_encrypt(
            $value,
            'AES-256-CBC',
            self::key(),
            0,
            self::iv()
        );

        return is_string($encoded) ? $encoded : '';
    }

    public static function decrypt(string $encoded): string
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return '';
        }
        $decoded = openssl_decrypt(
            base64_decode($encoded),
            'AES-256-CBC',
            self::key(),
            0,
            self::iv()
        );
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
        $plain = openssl_decrypt($encoded, 'AES-256-CBC', self::key(), 0, self::iv());

        return is_string($plain) ? $plain : '';
    }

    private static function key(): string
    {
        return hash('sha256', 'mcka!@#syl');
    }

    private static function iv(): string
    {
        return substr(hash('sha256', 'mckaaes_syl@ajce'), 0, 16);
    }
}
