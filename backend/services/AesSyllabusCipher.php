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
        $cached = self::cachedPdf($encid);
        if ($cached !== '') {
            return $cached;
        }
        $url = self::downloadUrl($encid);
        $body = self::httpGet($url, true);
        if (!str_starts_with($body, '%PDF')) {
            $body = self::httpGet($url, false);
        }
        if (!str_starts_with($body, '%PDF')) {
            throw new \RuntimeException('Could not download that syllabus.');
        }
        self::rememberPdf($encid, $body);

        return $body;
    }

    /**
     * Plain text from a campus TCPDF syllabus, for AI question generation.
     */
    public static function extractText(string $pdf): string
    {
        $viaShell = self::pdftotext($pdf);
        if (mb_strlen($viaShell) >= 80) {
            return self::normalizeExtractedText($viaShell);
        }

        return self::normalizeExtractedText(self::extractTextHeuristic($pdf));
    }

    private static function rememberPdf(string $encid, string $pdf): void
    {
        if ($pdf === '' || strlen($pdf) > 8000000) {
            return;
        }
        $path = self::cachePath($encid);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $pdf, LOCK_EX) === false) {
            @unlink($tmp);

            return;
        }
        @rename($tmp, $path);
    }

    private static function cachedPdf(string $encid): string
    {
        $path = self::cachePath($encid);
        if (!is_readable($path)) {
            return '';
        }
        if ((time() - (int) filemtime($path)) > 43200) {
            @unlink($path);

            return '';
        }
        $pdf = file_get_contents($path);

        return is_string($pdf) && str_starts_with($pdf, '%PDF') ? $pdf : '';
    }

    private static function cachePath(string $encid): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pms-syllabus' . DIRECTORY_SEPARATOR . hash('sha256', $encid) . '.pdf';
    }

    private static function pdftotext(string $pdf): string
    {
        if (!function_exists('exec') || $pdf === '') {
            return '';
        }
        $base = tempnam(sys_get_temp_dir(), 'syl_');
        if ($base === false) {
            return '';
        }
        $pdfPath = $base . '.pdf';
        $txtPath = $base . '.txt';
        @unlink($base);
        if (file_put_contents($pdfPath, $pdf) === false) {
            return '';
        }
        exec(
            'pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($txtPath) . ' 2>&1',
            $output,
            $code
        );
        $text = ($code === 0 && is_readable($txtPath)) ? trim((string) file_get_contents($txtPath)) : '';
        @unlink($pdfPath);
        @unlink($txtPath);

        return $text;
    }

    private static function extractTextHeuristic(string $pdf): string
    {
        $text = '';
        foreach (self::flateDecodedStreams($pdf) as $content) {
            $text .= self::textFromContentStream($content) . "\n";
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function flateDecodedStreams(string $pdf): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($pdf);
        while ($offset < $length) {
            $pos = strpos($pdf, 'stream', $offset);
            if ($pos === false) {
                break;
            }
            $head = substr($pdf, max(0, $pos - 180), 180);
            if (preg_match('/\/Length\s+(\d+)/', $head, $match) !== 1) {
                $offset = $pos + 6;
                continue;
            }
            $start = $pos + 6;
            if (($pdf[$start] ?? '') === "\r") {
                $start++;
            }
            if (($pdf[$start] ?? '') === "\n") {
                $start++;
            }
            $chunk = substr($pdf, $start, (int) $match[1]);
            $decoded = @gzuncompress($chunk);
            if (!is_string($decoded) || $decoded === '') {
                $inflated = @gzinflate($chunk);
                $decoded = is_string($inflated) ? $inflated : '';
            }
            if ($decoded !== '') {
                $out[] = $decoded;
            }
            $offset = $start + (int) $match[1];
        }

        return $out;
    }

    private static function textFromContentStream(string $content): string
    {
        $text = '';
        if (preg_match_all('/\[(.*?)\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $content, $ops, PREG_SET_ORDER) === false) {
            return '';
        }
        foreach ($ops as $op) {
            $full = $op[0];
            if (str_ends_with(rtrim($full), 'TJ')) {
                $body = $op[1] ?? '';
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|(-?\d+(?:\.\d+)?)/s', $body, $items) === false) {
                    continue;
                }
                foreach ($items[0] as $item) {
                    if (($item[0] ?? '') === '(') {
                        $text .= self::unescapePdfLiteral($item);
                    } elseif ((float) $item < -80) {
                        $text .= ' ';
                    }
                }
            } elseif (preg_match('/\((?:\\\\.|[^\\\\)])*\)/s', $full, $literal) === 1) {
                $text .= self::unescapePdfLiteral($literal[0]);
            }
            $text .= "\n";
        }

        return $text;
    }

    private static function unescapePdfLiteral(string $raw): string
    {
        if ($raw === '' || $raw[0] !== '(') {
            return '';
        }

        return stripcslashes(str_replace("\x00", '', substr($raw, 1, -1)));
    }

    private static function normalizeExtractedText(string $text): string
    {
        $text = preg_replace('/Powered by TCPDF[^\n]*/i', ' ', $text) ?? $text;
        $text = preg_replace('/-\s*\n\s*/', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/', "\n", $text) ?? $text;
        $text = trim($text);
        $text = OpenAIService::cleanUtf8($text);
        if (mb_strlen($text) > 16000) {
            $text = mb_substr($text, 0, 16000);
        }

        return $text;
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
