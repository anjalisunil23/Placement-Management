<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

/**
 * Handles AES (login.aesajce.in) SSO callback posts and maps AES users to PlaceHub accounts.
 */
final class AesLoginService
{
    private string $authKey;
    private string $refHost;

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable($root);
            $dotenv->safeLoad();
        }
        if (file_exists($root . '/.env.local')) {
            $dotenv = \Dotenv\Dotenv::createMutable($root, '.env.local');
            $dotenv->safeLoad();
        }

        $this->authKey = trim((string) ($_ENV['AES_AUTH_KEY'] ?? ''));
        $this->refHost = trim((string) ($_ENV['AES_REF_HOST'] ?? ''));
        if ($this->refHost === '') {
            $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            $this->refHost = $appUrl !== '' ? (string) parse_url($appUrl, PHP_URL_HOST) : '';
        }
        if ($this->refHost === '') {
            $this->refHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    public function handleCallback(array $post): string
    {
        if ($this->authKey === '') {
            throw new \RuntimeException('AES login is not configured on this server.');
        }

        $this->verifyWithAes($post);

        $user = $this->resolveUser($post);
        if ($user === null) {
            throw new \RuntimeException('No PlaceHub account matches your AES profile. Contact the placement cell.');
        }

        if (($user['status'] ?? '') === 'blocked') {
            throw new \RuntimeException('Your account has been blocked. Contact admin.');
        }

        $role = (string) ($user['role'] ?? '');
        if (!($user['approved'] ?? false) && $role !== 'admin') {
            throw new \RuntimeException('Account pending approval.');
        }

        Security::setSessionUser($user);

        $config = require dirname(__DIR__) . '/config/app.php';
        $home = $config['role_dashboards'][$role] ?? '/dashboard.html';

        $next = $this->readNextRedirect($post);
        if ($next !== '') {
            return $next;
        }

        return $home;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function verifyWithAes(array $post): void
    {
        if ($this->authKey === '') {
            return;
        }

        $token = $this->pick($post, ['token', 'auth_token', 'session', 'checksum']);
        if ($token === '') {
            return;
        }

        $payload = [
            'method'  => 'confirmLogin',
            'authkey' => $this->authKey,
            'refurl'  => $this->refHost,
            'data'    => json_encode($post, JSON_UNESCAPED_UNICODE) ?: '{}',
        ];

        $response = $this->postToAes($payload);
        if ($response === '') {
            return;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return;
        }
        if (($decoded['message'] ?? '') === 'Invalid Method confirmLogin') {
            return;
        }
        if (isset($decoded['status']) && $decoded['status'] === false) {
            throw new \RuntimeException((string) ($decoded['message'] ?? 'AES login verification failed.'));
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>|null
     */
    private function resolveUser(array $post): ?array
    {
        $userModel = new UserModel();

        $email = strtolower(trim($this->pick($post, ['email', 'mail', 'user_email', 'userEmail'])));
        if ($email !== '' && str_contains($email, '@')) {
            $user = $userModel->findByEmail($email);
            if ($user) {
                return $user;
            }
        }

        $username = trim($this->pick($post, ['username', 'un', 'admission_no', 'admissionNo', 'register_no', 'registerNumber', 'register_number']));
        if ($username !== '') {
            if (str_contains($username, '@')) {
                $user = $userModel->findByEmail($username);
                if ($user) {
                    return $user;
                }
            }

            $student = (new StudentModel())->findByRegisterNumber($username);
            if ($student && !empty($student['userId'])) {
                $user = $userModel->findById((string) $student['userId']);
                if ($user) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function readNextRedirect(array $post): string
    {
        $raw = trim($this->pick($post, ['next', 'redirect', 'return']));
        if ($raw === '' && isset($_COOKIE['ph-aes-next'])) {
            $raw = trim((string) $_COOKIE['ph-aes-next']);
        }

        if ($raw === '') {
            return '';
        }

        setcookie('ph-aes-next', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        if (preg_match('#^(https?:)?//#i', $raw)) {
            return '';
        }

        $path = str_starts_with($raw, '/') ? $raw : '/' . $raw;
        $path = explode('#', $path)[0];
        if (!preg_match('/\.html$/i', $path)) {
            return '';
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $post
     * @param list<string> $keys
     */
    private function pick(array $post, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($post[$key])) {
                continue;
            }
            $value = $post[$key];
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return '';
    }

    /**
     * @param array<string, string> $fields
     */
    private function postToAes(array $fields): string
    {
        if (!function_exists('curl_init')) {
            return '';
        }

        $ch = curl_init('https://login.aesajce.in/api/public_api.php');
        if ($ch === false) {
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: https://' . $this->refHost . '/login.html',
            ],
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? $body : '';
    }
}
