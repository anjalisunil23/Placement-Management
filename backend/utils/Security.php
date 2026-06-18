<?php

declare(strict_types=1);

namespace PMS\Utils;

use MongoDB\BSON\ObjectId;

/**
 * Security utilities: password hashing, session, MongoDB safe IDs.
 */
final class Security
{
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $config = require dirname(__DIR__) . '/config/app.php';
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', '1');
            session_set_cookie_params([
                'lifetime' => $config['session']['lifetime'],
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function setSessionUser(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => (string) $user['_id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ];
    }

    public static function destroySession(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params);
        }
        session_destroy();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getSessionUser(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function toObjectId(string $id): ?ObjectId
    {
        if (!preg_match('/^[a-f\d]{24}$/i', $id)) {
            return null;
        }
        try {
            return new ObjectId($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Prevent NoSQL injection — allow whitelisted MongoDB operators.
     *
     * @param mixed $value
     */
    public static function sanitizeFilterValue(mixed $value): mixed
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if ($value instanceof ObjectId) {
            return $value;
        }
        if (is_array($value)) {
            $allowedOps = ['$eq', '$ne', '$gt', '$gte', '$lt', '$lte', '$in', '$nin', '$and', '$or'];
            $safe = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && str_starts_with($k, '$') && !in_array($k, $allowedOps, true)) {
                    continue;
                }
                $safe[$k] = self::sanitizeFilterValue($v);
            }
            return $safe;
        }
        return null;
    }

    /**
     * Validate resume filename: Name_RegisterNo_Resume.ext
     */
    public static function validateResumeFilename(string $filename, string $name, string $registerNumber): bool
    {
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '', $name);
        $expected = $safeName . '_' . $registerNumber . '_Resume';
        $base = pathinfo($filename, PATHINFO_FILENAME);
        return strcasecmp($base, $expected) === 0;
    }

    /** @return string[] */
    public static function allowedResumeExtensions(): array
    {
        return ['pdf', 'doc', 'docx'];
    }

    /** @return string[] */
    public static function allowedPhotoExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    public static function validateUploadedFile(array $file, int $maxSize, array $allowedExtensions): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'File upload failed.';
        }
        if (($file['size'] ?? 0) > $maxSize) {
            return 'File exceeds maximum allowed size.';
        }
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            return 'Invalid file type.';
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'pdf'  => ['application/pdf', 'application/octet-stream', 'application/x-pdf'],
            'doc'  => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'webp' => ['image/webp'],
        ];
        $validMimes = [];
        foreach ($allowedExtensions as $e) {
            $validMimes = array_merge($validMimes, $allowedMimes[$e] ?? []);
        }
        if (!in_array($mime, $validMimes, true)) {
            return 'File content does not match extension.';
        }
        return null;
    }
}
