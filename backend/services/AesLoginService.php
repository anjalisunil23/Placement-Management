<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\DepartmentModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

/**
 * Handles AES (login.aesajce.in) authentication and maps AES users to PlaceHub accounts.
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
     * @return array<string, mixed>
     */
    public function authenticateCredentials(string $username, string $password): array
    {
        if ($this->authKey === '') {
            throw new \RuntimeException('AES login is not configured on this server.');
        }

        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new \RuntimeException('AES username and password are required.');
        }

        $response = $this->callAes('checkLogin', [
            'username' => $username,
            'password' => $password,
        ]);

        return $this->assertAesSuccess($response);
    }

    /**
     * @param array<string, mixed> $post
     */
    public function handleCallback(array $post): string
    {
        if ($this->authKey === '') {
            throw new \RuntimeException('AES login is not configured on this server.');
        }

        $this->verifyCallbackPayload($post);

        $user = $this->loginFromAesPayload($post);

        $config = require dirname(__DIR__) . '/config/app.php';
        $home = $config['role_dashboards'][$user['role'] ?? ''] ?? '/dashboard.html';

        $next = $this->readNextRedirect($post);
        return $next !== '' ? $next : $home;
    }

    public function expectedCallbackUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $this->refHost . '/callback.php';
    }

    /**
     * AES posts token / user profile here after login.aesajce.in authenticates the user.
     *
     * @param array<string, mixed> $post
     */
    private function verifyCallbackPayload(array $post): void
    {
        $status = $post['status'] ?? null;
        if ($status === false || $status === 'false' || $status === 0 || $status === '0') {
            throw new \RuntimeException('AES login was not successful.');
        }

        $token = $this->pick($post, ['token', 'auth_token', 'session', 'checksum']);
        $identity = $this->pick($post, ['email', 'username', 'un', 'admission_no', 'registerNumber']);

        if ($token === '' && $identity === '') {
            throw new \RuntimeException('Invalid AES callback — missing token or user information.');
        }

        if ($token !== '') {
            $this->verifyTokenWithAes($post, $token);
        }
    }

    /**
     * @param array<string, mixed> $post
     */
    private function verifyTokenWithAes(array $post, string $token): void
    {
        foreach (['verifyLogin', 'confirmLogin', 'validateToken', 'tokenVerify'] as $method) {
            $response = $this->callAes($method, [
                'token'    => $token,
                'checksum' => $this->pick($post, ['checksum']),
                'payload'  => $post,
            ]);

            if (($response['message'] ?? '') === 'Invalid Method ' . $method) {
                continue;
            }

            if (($response['status'] ?? false) === true) {
                return;
            }

            if (isset($response['status']) && $response['status'] === false) {
                throw new \RuntimeException((string) ($response['message'] ?? 'AES token verification failed.'));
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function loginFromAesPayload(array $payload): array
    {
        $profile = $this->extractProfile($payload);
        $user = $this->resolveOrProvisionUser($profile);
        if ($user === null) {
            throw new \RuntimeException('No PlaceHub account matches your AES profile. Students are created automatically on first AES sign-in when admission number is available.');
        }
        $this->assertUserCanLogin($user);
        Security::setSessionUser($user);
        return $user;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function assertAesSuccess(array $response): array
    {
        if (($response['status'] ?? false) === true) {
            return $response;
        }

        $message = trim((string) ($response['message'] ?? $response['title'] ?? 'AES login failed.'));
        $data = $response['data'] ?? [];
        if (is_array($data)) {
            if (($data['root_callback'] ?? null) === false && ($response['title'] ?? '') !== 'Unauthorized website') {
                $message = 'AES callback is not configured yet. Use admission number and password on this page, or ask IT to set callback URL to https://'
                    . $this->refHost . '/callback.php';
            } elseif (($response['title'] ?? '') === 'Unauthorized website') {
                $message = 'AES has not finished authorizing ' . $this->refHost
                    . '. Ask the AES / IT team to enable student login and set callback URL to https://'
                    . $this->refHost . '/callback.php';
            } elseif (!empty($data['root_login_error'])) {
                $message = (string) $data['root_login_error'];
            }
        }

        throw new \RuntimeException($message !== '' ? $message : 'AES login failed.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name:string,email:string,registerNumber:string,role:string,departmentCode:string}
     */
    private function extractProfile(array $payload): array
    {
        $flat = $this->flattenPayload($payload);

        $registerNumber = strtoupper(trim($this->pick($flat, [
            'registerNumber', 'register_number', 'register_no', 'admission_no', 'admissionNo',
            'admission_number', 'username', 'un', 'user_name', 'userid', 'user_id', 'token_user',
        ])));

        $email = strtolower(trim($this->pick($flat, [
            'email', 'mail', 'user_email', 'userEmail', 'college_email', 'official_email',
        ])));

        $name = trim($this->pick($flat, ['name', 'full_name', 'fullName', 'student_name', 'display_name']));
        if ($name === '') {
            $fname = trim($this->pick($flat, ['fname', 'first_name', 'firstName']));
            $lname = trim($this->pick($flat, ['lname', 'last_name', 'lastName']));
            $name = trim($fname . ' ' . $lname);
        }

        $roleHint = strtolower(trim($this->pick($flat, [
            'role', 'user_type', 'userType', 'category', 'type', 'usertype',
        ])));

        $departmentCode = strtoupper(trim($this->pick($flat, [
            'department', 'dept', 'branch', 'department_code', 'dept_code', 'deptCode',
        ])));

        if ($email === '' && $registerNumber !== '' && !str_contains($registerNumber, '@')) {
            $email = $this->syntheticStudentEmail($registerNumber);
        }

        if ($name === '' && $registerNumber !== '') {
            $name = $registerNumber;
        }

        $role = $this->inferRole($roleHint, $registerNumber, $email);

        return [
            'name'           => $name,
            'email'          => $email,
            'registerNumber' => $registerNumber,
            'role'           => $role,
            'departmentCode' => $departmentCode,
        ];
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @return array<string, mixed>
     */
    private function resolveOrProvisionUser(array $profile): ?array
    {
        $userModel = new UserModel();

        if ($profile['email'] !== '') {
            $user = $userModel->findByEmail($profile['email']);
            if ($user) {
                return $this->ensureStudentProfile($user, $profile);
            }
        }

        if ($profile['registerNumber'] !== '') {
            $student = (new StudentModel())->findByRegisterNumber($profile['registerNumber']);
            if ($student && !empty($student['userId'])) {
                $user = $userModel->findById((string) $student['userId']);
                if ($user) {
                    return $user;
                }
            }
        }

        if ($profile['role'] !== 'student') {
            return null;
        }

        if ($profile['email'] === '' || $profile['registerNumber'] === '') {
            return null;
        }

        return $this->provisionStudent($profile);
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @return array<string, mixed>
     */
    private function provisionStudent(array $profile): array
    {
        $userModel = new UserModel();
        $studentModel = new StudentModel();

        if ($studentModel->findByRegisterNumber($profile['registerNumber'])) {
            throw new \RuntimeException('This register number is already linked to another account.');
        }

        if ($userModel->findByEmail($profile['email'])) {
            throw new \RuntimeException('This email is already registered in PlaceHub.');
        }

        $deptId = $this->resolveDepartmentId($profile['departmentCode']);

        $userId = $userModel->createUser([
            'name'     => $profile['name'],
            'email'    => $profile['email'],
            'password' => bin2hex(random_bytes(16)),
            'role'     => 'student',
            'status'   => 'active',
            'approved' => true,
        ]);

        $studentModel->createProfile($userId, [
            'registerNumber' => $profile['registerNumber'],
            'departmentId'   => $deptId,
        ]);

        $user = $userModel->findById($userId);
        if (!$user) {
            throw new \RuntimeException('Could not create your PlaceHub student account.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @return array<string, mixed>
     */
    private function ensureStudentProfile(array $user, array $profile): array
    {
        if (($user['role'] ?? '') !== 'student' || $profile['registerNumber'] === '') {
            return $user;
        }

        $studentModel = new StudentModel();
        $existing = $studentModel->findByUserId((string) $user['_id']);
        if ($existing) {
            return $user;
        }

        if ($studentModel->findByRegisterNumber($profile['registerNumber'])) {
            return $user;
        }

        $studentModel->createProfile((string) $user['_id'], [
            'registerNumber' => $profile['registerNumber'],
            'departmentId'   => $this->resolveDepartmentId($profile['departmentCode']),
        ]);

        return $user;
    }

    private function resolveDepartmentId(string $departmentCode): ?string
    {
        if ($departmentCode === '') {
            return null;
        }

        $deptModel = new DepartmentModel();
        $dept = $deptModel->findByCode($departmentCode);
        if ($dept) {
            return (string) $dept['_id'];
        }

        $dept = $deptModel->findOne(['name' => $departmentCode]);
        return $dept ? (string) $dept['_id'] : null;
    }

    private function syntheticStudentEmail(string $registerNumber): string
    {
        $safe = preg_replace('/[^a-z0-9]/i', '', $registerNumber) ?: 'student';
        return strtolower($safe) . '@students.amaljyothi.ac.in';
    }

    private function inferRole(string $roleHint, string $registerNumber, string $email): string
    {
        if (str_contains($roleHint, 'staff') || str_contains($roleHint, 'faculty') || str_contains($roleHint, 'teacher')) {
            return 'staff';
        }
        if (str_contains($roleHint, 'officer') || str_contains($roleHint, 'placement')) {
            return 'placement_officer';
        }
        if (str_contains($roleHint, 'alumni')) {
            return 'alumni';
        }
        if (str_contains($roleHint, 'student') || str_contains($roleHint, 'parent')) {
            return 'student';
        }

        if ($registerNumber !== '' && preg_match('/^[0-9]{2}[A-Z]{2,4}[0-9]{2,4}$/i', $registerNumber)) {
            return 'student';
        }

        if ($email !== '' && str_contains($email, '@students.amaljyothi.ac.in')) {
            return 'student';
        }

        return 'student';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertUserCanLogin(array $user): void
    {
        if (($user['status'] ?? '') === 'blocked') {
            throw new \RuntimeException('Your account has been blocked. Contact admin.');
        }

        $role = (string) ($user['role'] ?? '');
        if (!($user['approved'] ?? false) && $role !== 'admin') {
            throw new \RuntimeException('Account pending approval.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function flattenPayload(array $payload): array
    {
        $flat = $payload;

        foreach (['data', 'user', 'profile', 'student', 'resp'] as $nestedKey) {
            if (!isset($payload[$nestedKey])) {
                continue;
            }
            $nested = $payload[$nestedKey];
            if (is_string($nested)) {
                $decoded = json_decode($nested, true);
                if (is_array($decoded)) {
                    $nested = $decoded;
                }
            }
            if (!is_array($nested)) {
                continue;
            }
            if (isset($nested['root_callback']) || isset($nested['root_login_error'])) {
                continue;
            }
            $flat = array_merge($flat, $nested);
        }

        return $flat;
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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function callAes(string $method, array $data): array
    {
        $fields = [
            'method'  => $method,
            'authkey' => $this->authKey,
            'refurl'  => $this->refHost,
            'data'    => json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}',
        ];

        $body = $this->postToAes($fields);
        if ($body === '') {
            throw new \RuntimeException('Could not reach the AES login service. Try again later.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid response from AES login service.');
        }

        return $decoded;
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
            CURLOPT_TIMEOUT        => 12,
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
