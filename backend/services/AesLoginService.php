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
        $aes = require dirname(__DIR__) . '/config/aes.php';
        $this->authKey = (string) ($aes['auth_key'] ?? '');
        $this->refHost = (string) ($aes['ref_host'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticateCredentials(string $username, string $password): array
    {
        if ($this->authKey === '') {
            throw new \RuntimeException($this->missingAesConfigMessage());
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
            throw new \RuntimeException($this->missingAesConfigMessage());
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
     * Proxy AES username/password or social login to login.aesajce.in.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function checkLogin(array $data): array
    {
        if ($this->authKey === '') {
            throw new \RuntimeException($this->missingAesConfigMessage());
        }

        $response = $this->postToAes('checkLogin', $data, 'index.php');
        if ($response === '') {
            throw new \RuntimeException('Could not reach AES login server.');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid response from AES login server.');
        }

        return $decoded;
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
            return;
        }

        $response = $this->postToAes('confirmLogin', $post, 'public_api.php');
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
        $aesDetails = $this->collectAesDetails($payload);
        $user = $this->resolveOrProvisionUser($profile);
        if ($user === null) {
            throw new \RuntimeException('No PlaceHub account matches your AES profile. Students are created automatically on first AES sign-in when admission number is available.');
        }
        $this->assertUserCanLogin($user);
        $this->syncStudentFromAes($user, $profile, $aesDetails);
        Security::setSessionUser($user, $aesDetails);
        return $user;
    }

    /**
     * All non-sensitive fields from the AES login payload (decrypted profile + POST).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function collectAesDetails(array $payload): array
    {
        $flat = $this->flattenPayload($payload);
        $sensitive = [
            'password', 'pwd', 'pass', 'checksum', 'token', 'auth_token', 'session',
            'secret', 'api_key', 'authkey', 'enckey', 'enc_key',
        ];
        $details = [];

        foreach ($flat as $key => $value) {
            if (!is_scalar($value) && !is_array($value)) {
                continue;
            }
            $keyStr = (string) $key;
            $lower = strtolower($keyStr);
            foreach ($sensitive as $needle) {
                if ($lower === $needle || str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            if (is_array($value)) {
                $details[$keyStr] = $value;
                continue;
            }
            $details[$keyStr] = is_bool($value) ? $value : trim((string) $value);
        }

        return $details;
    }

    /**
     * Map common AES field names to PlaceHub user/profile keys for the client.
     *
     * @param array<string, mixed> $aesDetails
     * @return array<string, mixed>
     */
    public function mapAesDetailsToUserFields(array $aesDetails): array
    {
        $mapped = [
            'phone'          => $this->pick($aesDetails, ['phone', 'mobile', 'phone_no', 'phoneNo', 'mob', 'contact', 'contact_no']),
            'registerNumber' => strtoupper($this->pick($aesDetails, ['registerNumber', 'register_number', 'admission_no', 'admissionNo', 'username', 'un'])),
            'classBatch'     => $this->pick($aesDetails, ['classBatch', 'class_batch', 'batch', 'year_of_study', 'yearOfStudy']),
            'course'         => $this->pick($aesDetails, ['course', 'program', 'programme', 'degree', 'stream']),
            'year'           => $this->pick($aesDetails, ['year', 'academic_year', 'academicYear', 'batch_year']),
            'semester'       => $this->pick($aesDetails, ['semester', 'sem', 'current_semester']),
            'designation'    => $this->pick($aesDetails, ['designation', 'title', 'job_title', 'jobTitle']),
            'gender'         => $this->pick($aesDetails, ['gender', 'sex']),
            'bloodGroup'     => $this->pick($aesDetails, ['bloodGroup', 'blood_group', 'blood']),
            'address'        => $this->pick($aesDetails, ['address', 'addr', 'permanent_address', 'permanentAddress']),
            'parentName'     => $this->pick($aesDetails, ['parentName', 'parent_name', 'father_name', 'fatherName', 'guardian']),
            'dob'            => $this->pick($aesDetails, ['dob', 'date_of_birth', 'dateOfBirth', 'birthdate']),
            'aadhar'         => $this->pick($aesDetails, ['aadhar', 'aadhaar', 'aadhar_no', 'aadhaarNo']),
        ];

        $cgpa = $this->pick($aesDetails, ['cgpa', 'CGPA', 'gpa', 'GPA']);
        if ($cgpa !== '' && is_numeric($cgpa)) {
            $mapped['cgpa'] = (float) $cgpa;
        }
        $backlogs = $this->pick($aesDetails, ['backlogs', 'arrears', 'standing_arrears']);
        if ($backlogs !== '' && is_numeric($backlogs)) {
            $mapped['backlogs'] = (int) $backlogs;
        }

        $dept = $this->pick($aesDetails, ['department', 'dept', 'branch', 'department_code', 'dept_code']);
        if ($dept !== '') {
            $mapped['department'] = strtoupper($dept);
        }

        return array_filter(
            $mapped,
            static fn ($value) => $value !== null && $value !== '' && $value !== []
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     */
    private function syncStudentFromAes(array $user, array $profile, array $aesDetails): void
    {
        if (($user['role'] ?? '') !== 'student') {
            return;
        }

        $studentModel = new StudentModel();
        $existing = $studentModel->findByUserId((string) $user['_id']);
        if (!$existing) {
            return;
        }

        $extras = $this->mapAesDetailsToUserFields($aesDetails);
        $patch = [];

        if ($profile['registerNumber'] !== '' && empty($existing['registerNumber'])) {
            $patch['registerNumber'] = $profile['registerNumber'];
        }
        if (!empty($extras['classBatch']) && empty($existing['classBatch'])) {
            $patch['classBatch'] = (string) $extras['classBatch'];
        }

        $deptId = $this->resolveDepartmentId($profile['departmentCode']);
        if ($deptId && empty($existing['departmentId'])) {
            $patch['departmentId'] = $deptId;
        }

        $academic = is_array($existing['academic'] ?? null) ? $existing['academic'] : [];
        $academicPatch = [];
        if (isset($extras['cgpa']) && (float) ($academic['cgpa'] ?? 0) <= 0) {
            $academicPatch['cgpa'] = (float) $extras['cgpa'];
        }
        if (isset($extras['backlogs']) && !isset($academic['backlogs'])) {
            $academicPatch['backlogs'] = (int) $extras['backlogs'];
        }
        if ($academicPatch !== []) {
            $patch['academic'] = array_merge($academic, $academicPatch);
        }

        $personal = is_array($existing['personal'] ?? null) ? $existing['personal'] : [];
        $personalPatch = [];
        foreach (['phone', 'gender', 'bloodGroup', 'address', 'parentName', 'dob', 'aadhar', 'course', 'year', 'semester'] as $field) {
            if (!empty($extras[$field]) && empty($personal[$field])) {
                $personalPatch[$field] = $extras[$field];
            }
        }
        if ($personalPatch !== []) {
            $patch['personal'] = array_merge($personal, $personalPatch);
        }

        if ($patch !== []) {
            $studentModel->update((string) $existing['_id'], $patch);
        }
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
        $body = $this->postToAes($method, $data);
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
     * AES expects PHP-style fields: data[username]=… not data as a JSON string.
     *
     * @param array<string, mixed> $data
     */
    private function flattenAesData(array $data, string $prefix = 'data'): array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $fieldKey = $prefix . '[' . $key . ']';
            if (is_scalar($value)) {
                $fields[$fieldKey] = (string) $value;
                continue;
            }
            if (is_array($value)) {
                foreach ($this->flattenAesData($value, $fieldKey) as $nestedKey => $nestedValue) {
                    $fields[$nestedKey] = $nestedValue;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postToAes(string $method, array $data, string $endpoint = 'public_api.php'): string
    {
        $fields = array_merge(
            [
                'method'  => $method,
                'authkey' => $this->authKey,
                'refurl'  => $this->refHost,
            ],
            $this->flattenAesData($data)
        );

        if (function_exists('curl_init')) {
            $ch = curl_init('https://login.aesajce.in/api/' . ltrim($endpoint, '/'));
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query($fields),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Referer: https://' . $this->refHost . '/public-stats.html',
                    ],
                ]);

                $body = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($body) && $body !== '') {
                    return $body;
                }
                if ($httpCode >= 400) {
                    return '';
                }
            }
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                    . 'Referer: https://' . $this->refHost . "/public-stats.html\r\n",
                'content' => http_build_query($fields),
                'timeout' => 15,
            ],
        ]);

        $body = @file_get_contents('https://login.aesajce.in/api/' . ltrim($endpoint, '/'), false, $context);

        return is_string($body) ? $body : '';
    }

    private function missingAesConfigMessage(): string
    {
        return 'AES login is not configured on this server. Add AES_AUTH_KEY and AES_REF_HOST to public_html/.env, then redeploy.';
    }
}
