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
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }
        // syllabusDownload.php decrypts with openssl_decrypt(base64_decode($id), ..., 0).
        // openssl_encrypt(..., 0) is already base64, so the URL id is wrapped once more.
        return base64_encode($encoded);
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

    public static function downloadUrl(string $encid): string
    {
        return 'https://www.aesajce.in/autonomy/syllabusDownload.php?id='
            . rawurlencode($encid)
            . '&key=' . self::downloadKey();
    }

    public static function fetchPdf(string $encid): string
    {
        $encid = trim($encid);
        if ($encid === '') {
            return '';
        }
        $url = self::downloadUrl($encid);
        $body = self::httpGet($url, true);
        if (!str_starts_with($body, '%PDF')) {
            $body = self::httpGet($url, false);
        }
        if (!str_starts_with($body, '%PDF')) {
            throw new \RuntimeException('Could not download that syllabus.');
        }

        return $body;
    }

    private static function downloadKey(): string
    {
        return '8d279a9aec738b906911e3350235a03024bde475';
    }

    private static function httpGet(string $url, bool $sslVerify): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf, */*;q=0.1',
                'Origin: https://www.aesajce.in',
                'Referer: https://www.aesajce.in/',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? $body : '';
    }
}
