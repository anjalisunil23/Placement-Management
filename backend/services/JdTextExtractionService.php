<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Utils\Security;

/**
 * Extract plain text from pasted JD, PDF, or image uploads.
 */
final class JdTextExtractionService
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_TEXT_CHARS = 50000;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(
        private ?OpenAIService $openai = null
    ) {
        $this->openai = $openai ?? new OpenAIService();
    }

    /**
     * @return array{text:string,filename:?string,method:string}
     */
    public function extractFromUpload(array $file): array
    {
        $error = Security::validateUploadedFile($file, self::MAX_FILE_BYTES, self::ALLOWED_EXTENSIONS);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $name = basename((string) ($file['name'] ?? 'jd-upload'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $text = match ($ext) {
            'pdf' => $this->extractPdfText($tmp),
            'jpg', 'jpeg', 'png' => $this->extractImageText($tmp, $ext),
            default => throw new \InvalidArgumentException('Unsupported file type.'),
        };

        $text = $this->sanitizeText($text);
        if ($text === '') {
            throw new \RuntimeException(
                'Unable to extract text from this file. Please upload a clearer PDF/image or paste the JD text manually.'
            );
        }

        $stored = $this->persistUploadedFile($file, $name, $ext);

        return array_merge([
            'text' => $text,
            'filename' => $name,
            'method' => $ext === 'pdf' ? 'pdf' : 'ocr',
        ], $stored);
    }

    /**
     * @return array<string, string>
     */
    private function persistUploadedFile(array $file, string $originalName, string $ext): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $storedName = 'apt_jd_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $storage = new ObjectStorageService($config);
        try {
            $uri = $storage->putUploadedFile(
                ObjectStorageService::FOLDER_JD,
                $storedName,
                $file
            );
        } catch (\Throwable $e) {
            error_log('[PMS Aptitude JD] file store failed: ' . $e->getMessage());

            return [];
        }

        $filename = $storage->storedNameFromUri($uri);
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return [
            'jdFile' => $uri,
            'jdFileUrl' => $storage->mediaUrl(ObjectStorageService::FOLDER_JD, $filename),
            'jdMimeType' => $mime,
        ];
    }

    public function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }

        return $text;
    }

    private function extractPdfText(string $path): string
    {
        $viaShell = $this->extractPdfViaPdftotext($path);
        if ($viaShell !== '') {
            return $viaShell;
        }

        return $this->extractPdfTextHeuristic($path);
    }

    private function extractPdfViaPdftotext(string $path): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $out = tempnam(sys_get_temp_dir(), 'pms_jd_');
        if ($out === false) {
            return '';
        }
        $txtPath = $out . '.txt';
        @unlink($out);

        $cmd = 'pdftotext ' . escapeshellarg($path) . ' ' . escapeshellarg($txtPath) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_readable($txtPath)) {
            @unlink($txtPath);

            return '';
        }
        $text = (string) file_get_contents($txtPath);
        @unlink($txtPath);

        return trim($text);
    }

    private function extractPdfTextHeuristic(string $path): string
    {
        $data = file_get_contents($path);
        if ($data === false || $data === '') {
            return '';
        }

        $parts = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $data, $matches)) {
            foreach ($matches[0] as $raw) {
                $inner = substr($raw, 1, -1);
                $inner = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $inner);
                $inner = trim($inner);
                if ($inner !== '' && preg_match('/[\p{L}\p{N}]/u', $inner)) {
                    $parts[] = $inner;
                }
            }
        }

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams)) {
            foreach ($streams[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = $stream;
                }
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $decoded, $innerMatches)) {
                    foreach ($innerMatches[0] as $raw) {
                        $inner = substr($raw, 1, -1);
                        $inner = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $inner);
                        $inner = trim($inner);
                        if ($inner !== '' && preg_match('/[\p{L}\p{N}]/u', $inner)) {
                            $parts[] = $inner;
                        }
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    public function extractTextFromStoredUri(string $uri, ?string $mimeHint = null): string
    {
        $storage = new ObjectStorageService();
        $body = $storage->getContentsWithFallback($uri, ObjectStorageService::FOLDER_JD);
        if ($body === '') {
            return '';
        }

        $resolved = $storage->resolve($uri);
        $filename = (string) ($resolved['filename'] ?? 'jd.pdf');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' && $mimeHint !== null) {
            $ext = match ($mimeHint) {
                'application/pdf' => 'pdf',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                default => 'pdf',
            };
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pms_stu_jd_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to process document.');
        }
        file_put_contents($tmp, $body);

        try {
            $text = match ($ext) {
                'pdf' => $this->extractPdfText($tmp),
                'jpg', 'jpeg', 'png' => $this->extractImageText($tmp, $ext),
                default => $this->extractPdfText($tmp),
            };
        } finally {
            @unlink($tmp);
        }

        return $this->sanitizeText($text);
    }

    private function extractImageText(string $path, string $ext): string
    {
        if (!$this->openai->isConfigured()) {
            throw new \RuntimeException(
                'Image OCR requires OpenAI to be configured on the server. Paste the JD text manually instead.'
            );
        }

        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return '';
        }

        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return $this->openai->extractTextFromImage(base64_encode($bytes), $mime);
    }
}
