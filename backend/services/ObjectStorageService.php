<?php

declare(strict_types=1);

namespace PMS\Services;

/**
 * Object storage via the campus S3 Lambda (presigned URL API).
 *
 * Upload:  GET ?method=getPreSignedUrl&ext=&path=Docs/ajce-placements/{folder}
 *          then PUT body to uploadURL (with x-amz-acl: private)
 * Download: GET ?method=s3Download&object=ajce-placements/{folder}/{file}
 * Delete:   GET ?method=s3Delete&object=ajce-placements/{folder}/{file}
 *
 * MariaDB paths use: s3://ajce-placements/{folder}/{filename}
 * Legacy local files under uploads/ remain readable as a fallback.
 */
final class ObjectStorageService
{
    public const FOLDER_RESUMES = 'resumes';
    public const FOLDER_CERTIFICATES = 'certificates';
    public const FOLDER_REPORTS = 'reports';
    public const FOLDER_JD = 'jd';
    public const FOLDER_SHORTLISTS = 'shortlists';
    public const FOLDER_SIGNED_REPORTS = 'signed_reports';
    public const FOLDER_OFFER_LETTERS = 'offer_letters';
    public const FOLDER_SELF_PLACEMENT = 'self_placement';
    public const FOLDER_ALUMNI_EMPLOYMENT = 'alumni_employment';
    public const FOLDER_PHOTOS = 'photos';
    public const FOLDER_JOB_POSTERS = 'job-posters';

    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, mixed> */
    private array $s3;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require dirname(__DIR__) . '/config/app.php';
        $this->s3 = is_array($this->config['s3'] ?? null) ? $this->config['s3'] : [];
    }

    public function isConfigured(): bool
    {
        return $this->apiEndpoint() !== '';
    }

    public function bucket(): string
    {
        // Logical app bucket / prefix shown in URIs (ajce-placements).
        return trim((string) ($this->s3['prefix'] ?? $this->s3['bucket'] ?? 'ajce-placements'), '/');
    }

    public function apiEndpoint(): string
    {
        return rtrim(trim((string) ($this->s3['api_endpoint'] ?? '')), '/');
    }

    public function docsRoot(): string
    {
        return trim((string) ($this->s3['docs_root'] ?? 'Docs'), '/');
    }

    public function uri(string $folder, string $filename): string
    {
        // objectKey already includes the logical prefix (ajce-placements/...).
        return 's3://' . $this->objectKey($folder, $filename);
    }

    /**
     * Logical object key used with Lambda s3Download / s3Delete
     * (Lambda itself prefixes Docs/).
     */
    public function objectKey(string $folder, string $filename): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $filename = basename(str_replace('\\', '/', $filename));
        $prefix = $this->bucket();

        if ($folder === '') {
            return $prefix . '/' . $filename;
        }

        return $prefix . '/' . $folder . '/' . $filename;
    }

    /**
     * Path argument for getPreSignedUrl (includes Docs/ root).
     */
    public function uploadPath(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $docs = $this->docsRoot();
        $prefix = $this->bucket();
        $base = $docs !== '' ? $docs . '/' . $prefix : $prefix;

        return $folder === '' ? $base : $base . '/' . $folder;
    }

    public function mediaUrl(string $folder, string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $publicBase = rtrim((string) ($this->s3['public_base_url'] ?? ''), '/');
        if ($publicBase !== '') {
            return $publicBase . '/' . $folder . '/' . rawurlencode($filename);
        }

        return '/backend/api/media/' . rawurlencode($folder) . '/' . rawurlencode($filename);
    }

    /**
     * Basename actually stored in S3 (Lambda generates random names).
     */
    public function storedNameFromUri(string $uriOrPath): string
    {
        $resolved = $this->resolve($uriOrPath);

        return $resolved['filename'] !== '' ? $resolved['filename'] : basename(str_replace('\\', '/', $uriOrPath));
    }

    /**
     * @param array<string, mixed> $file
     */
    public function putUploadedFile(string $folder, string $filename, array $file, ?string $contentType = null): string
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_readable($tmp))) {
            throw new \RuntimeException('Invalid uploaded file.');
        }
        $body = file_get_contents($tmp);
        if ($body === false) {
            throw new \RuntimeException('Failed to read uploaded file.');
        }
        $mime = $contentType
            ?: (string) ($file['type'] ?? '')
            ?: $this->guessMime($filename);

        return $this->putContents($folder, $filename, $body, $mime);
    }

    /**
     * Upload bytes. $filename is used only for extension hint — Lambda assigns the real name.
     */
    public function putContents(string $folder, string $filename, string $contents, ?string $contentType = null): string
    {
        $this->assertConfigured();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'bin';
        }
        $mime = $contentType ?: $this->guessMime($filename);

        $presign = $this->lambdaJson([
            'method' => 'getPreSignedUrl',
            'ext' => $ext,
            'path' => $this->uploadPath($folder),
        ]);
        $uploadUrl = (string) ($presign['uploadURL'] ?? $presign['uploadUrl'] ?? '');
        $remoteName = (string) ($presign['fileName'] ?? $presign['filename'] ?? '');
        if ($uploadUrl === '' || $remoteName === '') {
            throw new \RuntimeException('S3 Lambda did not return uploadURL/fileName.');
        }

        $this->putToPresignedUrl($uploadUrl, $contents, $mime);

        return $this->uri($folder, $remoteName);
    }

    public function putLocalFile(string $folder, string $filename, string $localPath, ?string $contentType = null): string
    {
        $body = @file_get_contents($localPath);
        if ($body === false) {
            throw new \RuntimeException('Failed to read local file for S3 upload.');
        }

        return $this->putContents($folder, $filename, $body, $contentType);
    }

    public function delete(string $uriOrPath): void
    {
        $resolved = $this->resolve($uriOrPath);
        if ($resolved['scheme'] === 's3') {
            if (!$this->isConfigured()) {
                return;
            }
            try {
                $object = $this->lambdaObjectFromResolved($resolved);
                if ($object !== '') {
                    $this->lambdaJson([
                        'method' => 's3Delete',
                        'object' => $object,
                    ]);
                }
            } catch (\Throwable) {
                // Best-effort delete.
            }
            return;
        }
        if ($resolved['local'] !== null && is_file($resolved['local'])) {
            @unlink($resolved['local']);
        }
    }

    public function exists(string $uriOrPath): bool
    {
        try {
            $this->getContents($uriOrPath);
            return true;
        } catch (\Throwable) {
            $resolved = $this->resolve($uriOrPath);

            return $resolved['local'] !== null && is_file($resolved['local']) && is_readable($resolved['local']);
        }
    }

    public function getContents(string $uriOrPath): string
    {
        $resolved = $this->resolve($uriOrPath);
        if ($resolved['scheme'] === 's3') {
            $this->assertConfigured();
            $object = $this->lambdaObjectFromResolved($resolved);
            $meta = $this->lambdaJson([
                'method' => 's3Download',
                'object' => $object,
            ]);
            $url = (string) ($meta['url'] ?? '');
            if ($url === '') {
                throw new \RuntimeException('S3 Lambda did not return a download URL.');
            }

            return $this->httpGetBody($url);
        }
        if ($resolved['local'] === null || !is_file($resolved['local'])) {
            throw new \RuntimeException('File not found.');
        }
        $body = file_get_contents($resolved['local']);
        if ($body === false) {
            throw new \RuntimeException('Failed to read file.');
        }

        return $body;
    }

    /**
     * @return array{path:string,temp:bool}
     */
    public function materialize(string $uriOrPath): array
    {
        $resolved = $this->resolve($uriOrPath);
        if ($resolved['scheme'] === 'local') {
            if ($resolved['local'] === null || !is_file($resolved['local'])) {
                throw new \RuntimeException('File not found.');
            }

            return ['path' => $resolved['local'], 'temp' => false];
        }

        $body = $this->getContents($uriOrPath);
        $ext = pathinfo($resolved['filename'] !== '' ? $resolved['filename'] : $resolved['key'], PATHINFO_EXTENSION);
        $tmp = tempnam(sys_get_temp_dir(), 'pms_s3_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file.');
        }
        if ($ext !== '') {
            $named = $tmp . '.' . $ext;
            @rename($tmp, $named);
            $tmp = $named;
        }
        if (file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write temp file.');
        }

        return ['path' => $tmp, 'temp' => true];
    }

    public function stream(string $uriOrPath, string $downloadName, string $mime, bool $inline = true): void
    {
        $body = $this->getContents($uriOrPath);
        $disposition = $inline ? 'inline' : 'attachment';
        $safeName = str_replace(['"', "\r", "\n"], '', basename($downloadName));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    /**
     * @return array{scheme:string,key:string,local:?string,folder:string,filename:string}
     */
    public function resolve(string $uriOrPath, ?string $folderHint = null): array
    {
        $raw = trim(str_replace('\\', '/', $uriOrPath));
        if ($raw === '') {
            return ['scheme' => 'local', 'key' => '', 'local' => null, 'folder' => '', 'filename' => ''];
        }

        if (str_starts_with($raw, 'uploads://')) {
            $raw = substr($raw, strlen('uploads://'));
        }

        if (str_starts_with($raw, 's3://')) {
            // s3://ajce-placements/resumes/file.pdf → key ajce-placements/resumes/file.pdf
            $key = $this->normalizeLogicalKey(substr($raw, 5));
            $filename = basename($key);
            $folder = $this->folderFromLogicalKey($key);

            return [
                'scheme' => 's3',
                'key' => $key,
                'local' => null,
                'folder' => $folder,
                'filename' => $filename,
            ];
        }

        if (preg_match('#/backend/api/media/([^/]+)/([^/?#]+)#', $raw, $m) === 1) {
            $folder = rawurldecode($m[1]);
            $filename = rawurldecode($m[2]);
            $key = $this->objectKey($folder, $filename);

            return [
                'scheme' => 's3',
                'key' => $key,
                'local' => null,
                'folder' => $folder,
                'filename' => $filename,
            ];
        }

        if (preg_match('#(?:^|/)uploads/([^/]+)/([^/?#]+)$#', $raw, $m) === 1) {
            $folder = $m[1];
            $filename = $m[2];
            $local = $this->legacyLocalPath($folder, $filename);
            if ($this->isConfigured()) {
                return [
                    'scheme' => 's3',
                    'key' => $this->objectKey($folder, $filename),
                    'local' => $local,
                    'folder' => $folder,
                    'filename' => $filename,
                ];
            }

            return [
                'scheme' => 'local',
                'key' => '',
                'local' => $local,
                'folder' => $folder,
                'filename' => $filename,
            ];
        }

        if (preg_match('#^[a-zA-Z]:/#', $raw) === 1 || str_starts_with($raw, '/') || str_contains($raw, '/uploads/')) {
            $filename = basename($raw);
            $folder = $folderHint ?: $this->guessFolderFromPath($raw);
            $local = is_file($raw) ? $raw : ($folder !== '' ? $this->legacyLocalPath($folder, $filename) : null);
            if ($local !== null && is_file($local)) {
                return [
                    'scheme' => 'local',
                    'key' => '',
                    'local' => $local,
                    'folder' => $folder,
                    'filename' => $filename,
                ];
            }
            if ($this->isConfigured() && $folder !== '') {
                return [
                    'scheme' => 's3',
                    'key' => $this->objectKey($folder, $filename),
                    'local' => $local,
                    'folder' => $folder,
                    'filename' => $filename,
                ];
            }

            return [
                'scheme' => 'local',
                'key' => '',
                'local' => is_file($raw) ? $raw : null,
                'folder' => $folder,
                'filename' => $filename,
            ];
        }

        $filename = basename($raw);
        $folder = (string) ($folderHint ?? '');
        if ($folder !== '' && $this->isConfigured()) {
            return [
                'scheme' => 's3',
                'key' => $this->objectKey($folder, $filename),
                'local' => $this->legacyLocalPath($folder, $filename),
                'folder' => $folder,
                'filename' => $filename,
            ];
        }

        return [
            'scheme' => 'local',
            'key' => '',
            'local' => $folder !== '' ? $this->legacyLocalPath($folder, $filename) : null,
            'folder' => $folder,
            'filename' => $filename,
        ];
    }

    public function getContentsWithFallback(string $uriOrPath, ?string $folderHint = null): string
    {
        $resolved = $this->resolve($uriOrPath, $folderHint);
        if ($resolved['scheme'] === 's3' && $this->isConfigured()) {
            try {
                return $this->getContents($this->uri($resolved['folder'], $resolved['filename']));
            } catch (\Throwable) {
                if ($resolved['local'] !== null && is_file($resolved['local'])) {
                    $body = file_get_contents($resolved['local']);
                    if ($body !== false) {
                        return $body;
                    }
                }
                throw new \RuntimeException('File not found.');
            }
        }
        if ($resolved['local'] !== null && is_file($resolved['local'])) {
            $body = file_get_contents($resolved['local']);
            if ($body !== false) {
                return $body;
            }
        }
        throw new \RuntimeException('File not found.');
    }

    public function streamWithFallback(
        string $uriOrPath,
        string $downloadName,
        string $mime,
        bool $inline = true,
        ?string $folderHint = null
    ): void {
        $body = $this->getContentsWithFallback($uriOrPath, $folderHint);
        $disposition = $inline ? 'inline' : 'attachment';
        $safeName = str_replace(['"', "\r", "\n"], '', basename($downloadName));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
        header('Content-Length: ' . (string) strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    public function guessMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'S3 Lambda is not configured. Set AWS_S3_API_ENDPOINT in .env.'
            );
        }
    }

    /**
     * @param array{scheme:string,key:string,folder:string,filename:string} $resolved
     */
    private function lambdaObjectFromResolved(array $resolved): string
    {
        if ($resolved['key'] !== '') {
            return $this->normalizeLogicalKey($resolved['key']);
        }
        if ($resolved['folder'] !== '' && $resolved['filename'] !== '') {
            return $this->objectKey($resolved['folder'], $resolved['filename']);
        }

        return $resolved['filename'];
    }

    private function normalizeLogicalKey(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $docs = $this->docsRoot();
        if ($docs !== '' && str_starts_with($key, $docs . '/')) {
            $key = substr($key, strlen($docs) + 1);
        }
        // Drop physical bucket name if present (iqac-docs/...)
        $physical = trim((string) ($this->s3['physical_bucket'] ?? 'iqac-docs'), '/');
        if ($physical !== '' && str_starts_with($key, $physical . '/')) {
            $key = substr($key, strlen($physical) + 1);
        }
        $prefix = $this->bucket();
        if ($prefix !== '' && !str_starts_with($key, $prefix . '/') && !str_contains($key, '/')) {
            // bare filename — leave as-is; caller should pass folder hint
            return $key;
        }

        return $key;
    }

    private function folderFromLogicalKey(string $key): string
    {
        $key = $this->normalizeLogicalKey($key);
        $prefix = $this->bucket();
        if ($prefix !== '' && str_starts_with($key, $prefix . '/')) {
            $rest = substr($key, strlen($prefix) + 1);
            $dir = trim(dirname($rest), '.');

            return $dir === '.' ? '' : $dir;
        }
        $dir = trim(dirname($key), '.');

        return $dir === '.' ? '' : $dir;
    }

    private function guessFolderFromPath(string $path): string
    {
        $map = [
            '/resumes/' => self::FOLDER_RESUMES,
            '/certificates/' => self::FOLDER_CERTIFICATES,
            '/reports/' => self::FOLDER_REPORTS,
            '/jd/' => self::FOLDER_JD,
            '/shortlists/' => self::FOLDER_SHORTLISTS,
            '/signed_reports/' => self::FOLDER_SIGNED_REPORTS,
            '/offer_letters/' => self::FOLDER_OFFER_LETTERS,
            '/self_placement/' => self::FOLDER_SELF_PLACEMENT,
            '/alumni_employment/' => self::FOLDER_ALUMNI_EMPLOYMENT,
            '/photos/' => self::FOLDER_PHOTOS,
            '/job-posters/' => self::FOLDER_JOB_POSTERS,
            '/job_posters/' => self::FOLDER_JOB_POSTERS,
        ];
        $normalized = str_replace('\\', '/', $path);
        foreach ($map as $needle => $folder) {
            if (str_contains($normalized, $needle)) {
                return $folder;
            }
        }

        return '';
    }

    private function legacyLocalPath(string $folder, string $filename): ?string
    {
        $filename = basename($filename);
        $uploads = is_array($this->config['uploads'] ?? null) ? $this->config['uploads'] : [];
        $candidates = [];
        $dirKey = match ($folder) {
            self::FOLDER_RESUMES => 'resume_dir',
            self::FOLDER_CERTIFICATES => 'certificate_dir',
            self::FOLDER_REPORTS => 'reports_dir',
            self::FOLDER_JD => 'jd_dir',
            self::FOLDER_SHORTLISTS => 'shortlist_dir',
            self::FOLDER_SIGNED_REPORTS => 'signed_dir',
            self::FOLDER_OFFER_LETTERS => 'offer_letter_dir',
            self::FOLDER_SELF_PLACEMENT => 'self_placement_dir',
            self::FOLDER_ALUMNI_EMPLOYMENT => 'alumni_employment_dir',
            self::FOLDER_PHOTOS => 'photo_dir',
            self::FOLDER_JOB_POSTERS => 'job_poster_dir',
            default => null,
        };
        if ($dirKey !== null && !empty($uploads[$dirKey])) {
            $candidates[] = rtrim((string) $uploads[$dirKey], '/\\') . DIRECTORY_SEPARATOR . $filename;
        }
        $root = dirname(__DIR__, 2);
        $candidates[] = $root . '/uploads/' . $folder . '/' . $filename;
        $candidates[] = $root . '/uploads/ajce-placements/' . $folder . '/' . $filename;
        if ($folder === self::FOLDER_RESUMES) {
            $candidates[] = $root . '/uploads/resumes/' . $filename;
        }
        if ($folder === self::FOLDER_REPORTS) {
            $candidates[] = $root . '/uploads/reports/' . $filename;
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function lambdaJson(array $query): array
    {
        $url = $this->apiEndpoint() . '?' . http_build_query($query);
        $raw = $this->httpGetBody($url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from S3 Lambda.');
        }

        return $decoded;
    }

    private function putToPresignedUrl(string $url, string $body, string $mime): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize S3 upload.');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $mime,
                'x-amz-acl: private',
                'Content-Length: ' . (string) strlen($body),
            ],
            CURLOPT_TIMEOUT => (int) ($this->s3['timeout'] ?? 60),
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException('S3 upload failed: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('S3 upload failed (' . $status . ').');
        }
    }

    private function httpGetBody(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize HTTP request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => (int) ($this->s3['timeout'] ?? 60),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('HTTP request failed (' . $status . ').');
        }

        return (string) $body;
    }
}
