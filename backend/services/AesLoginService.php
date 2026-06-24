<?php

declare(strict_types=1);

namespace PMS\Services;

use PMS\Models\AlumniModel;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
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
        $aesDetails = $this->collectAesDetails($payload);
        $extended = $this->fetchExtendedProfileFromAes($payload);
        if ($extended !== []) {
            $aesDetails = array_merge($aesDetails, $this->collectAesDetails($extended));
            $payload = array_merge($payload, $extended);
        }

        $profile = $this->extractProfile(array_merge($payload, $aesDetails));
        $mapped = $this->mapAesDetailsToUserFields($aesDetails);
        $register = strtoupper((string) ($profile['registerNumber'] ?? ''));
        $emails = $this->resolveAesEmails($aesDetails, $mapped, $register, '');
        if ($emails['collegeEmail'] !== '') {
            $aesDetails['collegeEmail'] = $emails['collegeEmail'];
        }
        if ($emails['personalEmail'] !== '') {
            $aesDetails['personalEmail'] = $emails['personalEmail'];
        }
        $aesName = $this->resolveAesName($aesDetails, $mapped, $register, $emails['personalEmail']);
        if ($aesName !== '') {
            $aesDetails['name'] = $aesName;
            $profile['name'] = $aesName;
        }
        $profile['email'] = $emails['email'] !== ''
            ? $emails['email']
            : ($emails['collegeEmail'] !== '' ? $emails['collegeEmail'] : ($emails['personalEmail'] !== '' ? $emails['personalEmail'] : $profile['email']));
        $profile['role'] = $this->inferRoleFromAes(array_merge($payload, $aesDetails), $profile, $emails);

        $adminUser = $this->resolveSuperAdminUser($profile, $aesDetails, $mapped, $emails);
        if ($adminUser !== null) {
            $profile['role'] = 'admin';
            $user = $adminUser;
        } else {
            $officerUser = $this->resolvePlacementOfficerUser($profile, $aesDetails, $mapped, $emails);
            if ($officerUser !== null) {
                $profile['role'] = 'placement_officer';
                $user = $officerUser;
            } else {
                $user = $this->resolveOrProvisionUser($profile, $aesDetails, $mapped, $emails);
            }
        }
        if ($user === null) {
            if ($profile['role'] === 'placement_officer') {
                throw new \RuntimeException('No PlaceHub account matches your AES placement officer profile. Ask the placement cell to register your account first.');
            }
            if ($profile['role'] === 'staff') {
                throw new \RuntimeException('Could not create your PlaceHub staff account from AES login. A valid college email is required.');
            }
            if ($profile['role'] === 'alumni') {
                throw new \RuntimeException('Could not create your PlaceHub alumni account from AES login. A valid email is required.');
            }
            throw new \RuntimeException('No PlaceHub account matches your AES profile. Students are created automatically on first AES sign-in when admission number is available.');
        }
        $this->assertUserCanLogin($user);
        $user = (new UserModel())->ensureLoginReady($user);
        $user = $this->normalizeSuperAdminUser($user);
        $user = $this->syncUserFromAes($user, $profile, $aesDetails);
        $this->syncRoleProfileFromAes($user, $profile, $aesDetails);
        $user = $this->ensurePlacementOfficerProfile($user, $profile, $mapped);
        $user = $this->normalizeSuperAdminUser($user);
        Security::setSessionUser($user, $this->sanitizeAesProfileForClient($aesDetails));
        return $user;
    }

    /**
     * Remove auth-sensitive AES fields before storing in session or sending to the client.
     *
     * @param array<string, mixed> $aesProfile
     * @return array<string, mixed>
     */
    public function sanitizeAesProfileForClient(array $aesProfile): array
    {
        $strip = [
            'role', 'user_type', 'userType', 'category', 'type', 'usertype', 'user_role', 'userRole',
            'login_type', 'logintype', 'account_type', 'accounttype', 'designation_type', 'portal', 'module',
            'dashboard', 'staff', 'employee', 'faculty', 'alumni', 'student',
        ];
        foreach ($strip as $key) {
            unset($aesProfile[$key]);
        }

        return $aesProfile;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     * @param array<string, mixed> $mapped
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $resolvedEmails
     * @return array<string, mixed>|null
     */
    private function resolveSuperAdminUser(array $profile, array $aesDetails, array $mapped, array $resolvedEmails): ?array
    {
        $lookup = $this->collectLookupEmails($profile, $aesDetails, $mapped, $resolvedEmails);
        $config = require dirname(__DIR__) . '/config/app.php';

        foreach ($config['super_admin_emails'] ?? [] as $adminEmail) {
            foreach ($lookup as $email) {
                if (strcasecmp($email, $adminEmail) === 0) {
                    return $this->ensureSuperAdminAccount($adminEmail);
                }
            }
        }

        foreach ($lookup as $email) {
            if ($this->isSuperAdminEmail($email)) {
                return $this->ensureSuperAdminAccount($email);
            }

            $user = (new UserModel())->findByEmail($email);
            if ($user && ($user['role'] ?? '') === 'admin') {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function normalizeSuperAdminUser(array $user): array
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if (!$this->isSuperAdminEmail($email)) {
            return $user;
        }

        $promoted = $this->ensureSuperAdminAccount($email);

        return $promoted ?? $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ensureSuperAdminAccount(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $userModel = new UserModel();
        $user = $userModel->findByEmail($email);
        if (!$user) {
            return null;
        }

        if (($user['role'] ?? '') !== 'admin') {
            $userModel->updateUser((string) $user['_id'], [
                'role'     => 'admin',
                'approved' => true,
                'status'   => 'active',
            ]);
            $user = $userModel->findById((string) $user['_id']);
        }

        return $user ?: null;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     * @param array<string, mixed> $mapped
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $resolvedEmails
     * @return array<string, mixed>|null
     */
    private function resolvePlacementOfficerUser(array $profile, array $aesDetails, array $mapped, array $resolvedEmails): ?array
    {
        foreach ($this->collectLookupEmails($profile, $aesDetails, $mapped, $resolvedEmails) as $email) {
            $user = (new UserModel())->findByEmail($email);
            if ($user && ($user['role'] ?? '') === 'placement_officer') {
                return $this->ensurePlacementOfficerProfile($user, $profile, $mapped);
            }
        }

        return null;
    }

    public function isSuperAdminEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $allowlist = $config['super_admin_emails'] ?? [];

        return in_array($email, $allowlist, true);
    }

    private function isPlacementOfficerHint(string $text): bool
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return false;
        }
        if (str_contains($text, 'officer') && str_contains($text, 'placement')) {
            return true;
        }

        return (bool) preg_match('/\bplacement[\s_-]*officer\b/', $text);
    }

    /**
     * Merge AES session fields into an API/user payload (for profile endpoints and /auth/me).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function applyAesSessionToUserFields(array $data): array
    {
        $aesProfile = \PMS\Utils\Security::getSessionAesProfile();
        if ($aesProfile === []) {
            return $data;
        }

        $aesProfile = is_array($aesProfile) ? $aesProfile : [];
        $mapped = $this->mapAesDetailsToUserFields($aesProfile);

        foreach ($mapped as $key => $value) {
            if ($key === 'name') {
                continue;
            }
            if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) {
                $data[$key] = $value;
            }
            if (in_array($key, ['cgpa', 'backlogs'], true) && ($data[$key] === 0 || $data[$key] === 0.0)) {
                $data[$key] = $value;
            }
        }

        $register = (string) ($data['registerNumber'] ?? $mapped['registerNumber'] ?? '');
        $aesName = $this->resolveAesName(
            $aesProfile,
            $mapped,
            $register,
            (string) ($data['personalEmail'] ?? $mapped['personalEmail'] ?? '')
        );
        if ($aesName !== '') {
            $data['name'] = $aesName;
        }

        if (empty($data['phone']) && !empty($mapped['phone'])) {
            $data['phone'] = $mapped['phone'];
        }

        $aesPhone = trim($this->pick($aesProfile, [
            'phone', 'mobile', 'phone_no', 'phoneNo', 'mob', 'contact', 'contact_no', 'mobile_no', 'mobileno',
            'cell', 'cell_no', 'cellNo', 'whatsapp', 'whatsapp_no',
            'student_mobile', 'studentMobile', 'stu_mobile', 'stuMobile',
            'parent_mobile', 'parentMobile', 'father_mobile', 'fatherMobile', 'mother_mobile', 'motherMobile',
            'guardian_mobile', 'guardianMobile', 'alternate_mobile', 'alternateMobile',
            'stu_phone', 'stuPhone', 'personal_mobile', 'personalMobile',
        ]));
        if ($aesPhone === '' && !empty($mapped['phone'])) {
            $aesPhone = trim((string) $mapped['phone']);
        }
        if ($aesPhone !== '' && $this->isValidPhone($aesPhone)) {
            $data['phone'] = $aesPhone;
        }

        $aesEmail = strtolower(trim($this->pick($aesProfile, [
            'email', 'mail', 'user_email', 'userEmail', 'college_email', 'official_email',
            'student_email', 'studentEmail', 'email_id', 'emailid', 'email_address', 'emailAddress',
            'stu_email', 'stuEmail', 'personal_email', 'personalEmail',
        ])));
        if ($aesEmail === '' && !empty($mapped['email'])) {
            $aesEmail = strtolower(trim((string) $mapped['email']));
        }

        $resolvedEmails = $this->resolveAesEmails($aesProfile, $mapped, $register, $aesEmail);
        if ($resolvedEmails['personalEmail'] !== '') {
            $data['personalEmail'] = $resolvedEmails['personalEmail'];
        }
        if ($resolvedEmails['collegeEmail'] !== '') {
            $data['collegeEmail'] = $resolvedEmails['collegeEmail'];
        }
        if (!empty($data['collegeEmail']) && $this->isSyntheticStudentEmail((string) $data['collegeEmail'], $register)) {
            unset($data['collegeEmail']);
        }
        if ($resolvedEmails['email'] !== '') {
            $data['email'] = $resolvedEmails['email'];
        }

        $aesDept = strtoupper(trim($this->pickInsensitive($aesProfile, [
            'deptCode', 'dept_code', 'department_code', 'branch_code', 'deptshort', 'dept_short',
            'department', 'dept', 'branch', 'deptCode', 'br',
        ])));
        $aesDeptName = trim($this->pickInsensitive($aesProfile, [
            'deptName', 'dept_name', 'department_name', 'branch_name', 'departmentName',
        ]));
        if ($aesDept === '' && !empty($mapped['department'])) {
            $aesDept = strtoupper((string) $mapped['department']);
        }
        if ($aesDeptName === '' && !empty($mapped['departmentName'])) {
            $aesDeptName = (string) $mapped['departmentName'];
        }
        if ($aesDept !== '') {
            $data['department'] = $aesDept;
        }
        if ($aesDeptName !== '') {
            $data['departmentName'] = $aesDeptName;
        } elseif ($aesDept !== '') {
            $data['departmentName'] = $aesDept;
        }

        $aesCgpa = $this->pick($aesProfile, [
            'cgpa', 'CGPA', 'gpa', 'GPA', 'current_cgpa', 'currentCgpa', 'cumulative_cgpa', 'overall_cgpa',
        ]);
        if ($aesCgpa === '' && isset($mapped['cgpa'])) {
            $aesCgpa = (string) $mapped['cgpa'];
        }
        if ($aesCgpa !== '' && is_numeric($aesCgpa) && (float) $aesCgpa > 0) {
            $data['cgpa'] = (float) $aesCgpa;
        }

        return $data;
    }

    /**
     * Debug helper for dologin.php?debug=1 — inspect AES field mapping.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function debugAesPayload(array $payload): array
    {
        $details = $this->collectAesDetails($payload);
        return [
            'aesDetails'  => $details,
            'mapped'      => $this->mapAesDetailsToUserFields($details),
            'profileScan' => $this->deepScanAesProfileFields($payload),
            'resolvedName' => $this->resolveAesName(
                $details,
                $this->mapAesDetailsToUserFields($details),
                strtoupper((string) ($details['registerNumber'] ?? $details['admission_no'] ?? '')),
                (string) ($details['personalEmail'] ?? '')
            ),
        ];
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

        return array_merge($details, $this->deepScanAesProfileFields($payload));
    }

    /**
     * Walk nested AES payloads for profile fields (name, email, phone, department, CGPA).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function deepScanAesProfileFields(array $payload): array
    {
        $found = [];

        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $text = trim((string) $value);
                if ($text === '') {
                    continue;
                }
                $lower = strtolower((string) $key);

                if (
                    str_contains($lower, 'mail')
                    || $lower === 'email'
                    || str_ends_with($lower, '_email')
                    || $lower === 'e_mail'
                ) {
                    $email = strtolower($text);
                    if (!str_contains($email, '@') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    if ($this->isCollegeEmailFieldKey($lower)) {
                        if (empty($found['collegeEmail'])) {
                            $found['collegeEmail'] = $email;
                        }
                        continue;
                    }
                    if ($this->isPersonalEmailFieldKey($lower)) {
                        if (empty($found['personalEmail'])) {
                            $found['personalEmail'] = $email;
                        }
                        continue;
                    }
                    if ($this->isCollegeEmail($email)) {
                        if (empty($found['collegeEmail'])) {
                            $found['collegeEmail'] = $email;
                        }
                    } elseif (empty($found['personalEmail'])) {
                        $found['personalEmail'] = $email;
                    } elseif (empty($found['email'])) {
                        $found['email'] = $email;
                    }
                }

                if (
                    str_contains($lower, 'phone')
                    || str_contains($lower, 'mobile')
                    || str_contains($lower, 'mob')
                    || str_contains($lower, 'whatsapp')
                    || ($lower === 'contact' && preg_match('/\d{6,}/', $text))
                ) {
                    if ($this->isValidPhone($text)) {
                        $found['phone'] = $text;
                    }
                }

                if (
                    ($lower === 'name' || str_ends_with($lower, '_name') || $lower === 'fullname' || $lower === 'full_name')
                    && !str_contains($lower, 'parent')
                    && !str_contains($lower, 'father')
                    && !str_contains($lower, 'mother')
                    && !str_contains($lower, 'guardian')
                    && !str_contains($lower, 'dept')
                    && !str_contains($lower, 'branch')
                ) {
                    if ($this->isRealPersonName($text)) {
                        if (!isset($found['_nameCandidates']) || !is_array($found['_nameCandidates'])) {
                            $found['_nameCandidates'] = [];
                        }
                        $found['_nameCandidates'][] = ['name' => $text, 'key' => $lower];
                    }
                }

                if (
                    $lower === 'department'
                    || $lower === 'dept'
                    || $lower === 'branch'
                    || $lower === 'br'
                    || $lower === 'deptcode'
                    || $lower === 'deptname'
                    || $lower === 'deptshort'
                    || str_contains($lower, 'dept_')
                    || str_contains($lower, 'branch_')
                    || str_contains($lower, 'department_')
                    || $lower === 'specialisation'
                    || $lower === 'specialization'
                    || $lower === 'programme'
                    || $lower === 'program'
                ) {
                    if (strlen($text) >= 1 && strlen($text) <= 80) {
                        if (in_array($lower, ['deptname', 'dept_name', 'department_name', 'branch_name'], true)) {
                            $found['departmentName'] = $text;
                        } elseif (in_array($lower, ['deptcode', 'dept_code', 'deptshort', 'dept_short', 'br'], true)) {
                            $found['department'] = strtoupper($text);
                        } elseif (empty($found['department']) && !preg_match('/^\d+$/', $text)) {
                            $found['department'] = strtoupper($text);
                        } elseif (empty($found['department'])) {
                            $found['department'] = strtoupper($text);
                        }
                    }
                }

                if (
                    $lower === 'cgpa'
                    || $lower === 'gpa'
                    || str_contains($lower, 'cgpa')
                    || str_contains($lower, 'grade_point')
                ) {
                    if (is_numeric($text)) {
                        $cgpa = (float) $text;
                        if ($cgpa > 0 && $cgpa <= 10) {
                            $found['cgpa'] = $cgpa;
                        }
                    }
                }
            }
        };

        $walk($payload);

        if (!empty($found['_nameCandidates']) && is_array($found['_nameCandidates'])) {
            $best = $this->pickBestAesName($found['_nameCandidates'], '');
            if ($best !== '') {
                $found['name'] = $best;
            }
            unset($found['_nameCandidates']);
        }

        return $found;
    }

    /**
     * @deprecated Use deepScanAesProfileFields
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function deepScanContactFields(array $payload): array
    {
        $scan = $this->deepScanAesProfileFields($payload);
        $contact = [];
        if (!empty($scan['email'])) {
            $contact['email'] = (string) $scan['email'];
        }
        if (!empty($scan['phone'])) {
            $contact['phone'] = (string) $scan['phone'];
        }

        return $contact;
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
            'name'           => trim($this->pickInsensitive($aesDetails, [
                    'name', 'full_name', 'fullName', 'fullname', 'student_name', 'studentName', 'stu_name', 'sname',
                    'staff_name', 'staffName', 'emp_name', 'employee_name', 'faculty_name', 'display_name',
                    'studname', 'stud_name', 'studentname', 'StudentName', 'nm', 'stu_nm', 'stuNm', 'uname', 'stuname',
                ]))
                ?: trim($this->pickInsensitive($aesDetails, ['fname', 'first_name', 'firstName', 'firstname', 'stu_fname']) . ' ' . $this->pickInsensitive($aesDetails, ['lname', 'last_name', 'lastName', 'lastname', 'stu_lname'])),
            'phone'          => $this->pick($aesDetails, [
                'phone', 'mobile', 'phone_no', 'phoneNo', 'mob', 'contact', 'contact_no', 'mobile_no', 'mobileno',
                'cell', 'cell_no', 'cellNo', 'whatsapp', 'whatsapp_no',
                'student_mobile', 'studentMobile', 'stu_mobile', 'stuMobile',
                'parent_mobile', 'parentMobile', 'father_mobile', 'fatherMobile', 'mother_mobile', 'motherMobile',
                'guardian_mobile', 'guardianMobile', 'alternate_mobile', 'alternateMobile',
                'stu_phone', 'stuPhone', 'personal_mobile', 'personalMobile',
            ]),
            'email'          => strtolower(trim($this->pickInsensitive($aesDetails, [
                'college_email', 'collegeemail', 'college_mail', 'collegemail', 'college_mail_id',
                'official_email', 'student_email', 'studentEmail', 'stu_email', 'stuEmail',
                'institutional_email', 'institute_email', 'inst_email', 'ajce_email',
                'email', 'mail', 'user_email', 'userEmail', 'email_id', 'emailid', 'email_address', 'emailAddress',
                'personal_email', 'personalEmail',
            ]))),
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

        $cgpa = $this->pick($aesDetails, [
            'cgpa', 'CGPA', 'gpa', 'GPA', 'current_cgpa', 'currentCgpa', 'cumulative_cgpa', 'overall_cgpa',
            'totcgpa', 'tot_cgpa', 'curcgpa', 'cur_cgpa', 'grade_point', 'gradePoint',
        ]);
        if ($cgpa !== '' && is_numeric($cgpa)) {
            $mapped['cgpa'] = (float) $cgpa;
        }
        $backlogs = $this->pick($aesDetails, ['backlogs', 'arrears', 'standing_arrears']);
        if ($backlogs !== '' && is_numeric($backlogs)) {
            $mapped['backlogs'] = (int) $backlogs;
        }

        $deptCode = strtoupper(trim($this->pickInsensitive($aesDetails, [
            'deptCode', 'dept_code', 'department_code', 'branch_code', 'deptshort', 'dept_short', 'br',
            'department', 'dept', 'branch',
        ])));
        $deptName = trim($this->pickInsensitive($aesDetails, [
            'deptName', 'dept_name', 'department_name', 'branch_name', 'departmentName',
        ]));
        if ($deptCode !== '') {
            $mapped['department'] = $deptCode;
        }
        if ($deptName !== '') {
            $mapped['departmentName'] = $deptName;
        }
        if ($deptCode === '' && $deptName !== '') {
            $mapped['department'] = strtoupper($deptName);
        }

        $register = (string) ($mapped['registerNumber'] ?? '');
        $resolvedName = $this->scanNameFromAesData($aesDetails, $register);
        if ($resolvedName !== '') {
            $mapped['name'] = $resolvedName;
        }

        $emailScan = $this->scanEmailsFromAesData($aesDetails, $register);
        if ($emailScan['collegeEmail'] !== '') {
            $mapped['collegeEmail'] = $emailScan['collegeEmail'];
        }
        if ($emailScan['personalEmail'] !== '') {
            $mapped['personalEmail'] = $emailScan['personalEmail'];
        }
        if (!empty($mapped['collegeEmail'])) {
            $mapped['email'] = (string) $mapped['collegeEmail'];
        } elseif (!empty($mapped['personalEmail'])) {
            $mapped['email'] = (string) $mapped['personalEmail'];
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
    private function syncRoleProfileFromAes(array $user, array $profile, array $aesDetails): void
    {
        $role = (string) ($user['role'] ?? '');
        if ($role === 'student') {
            $this->syncStudentFromAes($user, $profile, $aesDetails);
            return;
        }
        if ($role === 'staff') {
            $this->syncStaffFromAes($user, $profile, $aesDetails);
            return;
        }
        if ($role === 'placement_officer') {
            $this->syncPlacementOfficerFromAes($user, $profile, $aesDetails);
            return;
        }
        if ($role === 'alumni') {
            $this->syncAlumniFromAes($user, $profile, $aesDetails);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     * @return array<string, mixed>
     */
    private function syncUserFromAes(array $user, array $profile, array $aesDetails): array
    {
        $mapped = $this->mapAesDetailsToUserFields($aesDetails);
        $patch = [];

        $name = $this->resolveAesName(
            $aesDetails,
            $mapped,
            $profile['registerNumber'],
            (string) ($mapped['personalEmail'] ?? $mapped['email'] ?? $profile['email'])
        );
        if ($name !== '') {
            $patch['name'] = $name;
        } elseif ($this->shouldReplaceDisplayName((string) ($user['name'] ?? ''), $profile['registerNumber'])) {
            $personalOnly = (string) ($mapped['personalEmail'] ?? '');
            if ($personalOnly !== '' && !$this->isCollegeEmail($personalOnly)) {
                $inferred = $this->inferNameFromEmail($personalOnly);
                if ($this->isRealPersonName($inferred)) {
                    $patch['name'] = $inferred;
                }
            }
        }

        $aesEmail = strtolower(trim((string) ($mapped['email'] ?? '')));
        if ($aesEmail === '' && $profile['email'] !== '' && filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            $aesEmail = strtolower($profile['email']);
        }
        if ($aesEmail !== '' && filter_var($aesEmail, FILTER_VALIDATE_EMAIL)) {
            $currentEmail = strtolower(trim((string) ($user['email'] ?? '')));
            if ($currentEmail === '' || $this->isSyntheticStudentEmail($currentEmail, $profile['registerNumber'])) {
                $patch['email'] = $aesEmail;
            }
        }

        if ($patch === []) {
            return $user;
        }

        (new UserModel())->updateUser((string) $user['_id'], $patch);
        return array_merge($user, $patch);
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

        if ($profile['registerNumber'] !== '') {
            $patch['registerNumber'] = $profile['registerNumber'];
        }
        if (!empty($extras['classBatch'])) {
            $patch['classBatch'] = (string) $extras['classBatch'];
        }

        $deptCode = $profile['departmentCode'] !== ''
            ? $profile['departmentCode']
            : (string) ($extras['department'] ?? '');
        $deptId = $this->resolveDepartmentIdFromProfile($profile, $extras);
        if ($deptId) {
            $patch['departmentId'] = $deptId;
        }

        $academic = is_array($existing['academic'] ?? null) ? $existing['academic'] : [];
        $academicPatch = [];
        if (isset($extras['cgpa']) && (float) $extras['cgpa'] > 0) {
            if ((float) ($academic['cgpa'] ?? 0) <= 0 || (float) $extras['cgpa'] > (float) ($academic['cgpa'] ?? 0)) {
                $academicPatch['cgpa'] = (float) $extras['cgpa'];
            }
        }
        if (isset($extras['backlogs'])) {
            $academicPatch['backlogs'] = (int) $extras['backlogs'];
        }
        if ($academicPatch !== []) {
            $patch['academic'] = array_merge($academic, $academicPatch);
        }

        $personal = is_array($existing['personal'] ?? null) ? $existing['personal'] : [];
        $personalPatch = [];
        foreach (['phone', 'gender', 'bloodGroup', 'address', 'parentName', 'dob', 'aadhar', 'course', 'year', 'semester'] as $field) {
            if (!empty($extras[$field])) {
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
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     */
    private function syncStaffFromAes(array $user, array $profile, array $aesDetails): void
    {
        $staffModel = new StaffModel();
        $existing = $staffModel->findByUserId((string) $user['_id']);
        $extras = $this->mapAesDetailsToUserFields($aesDetails);

        if (!$existing) {
            $designation = trim((string) ($extras['designation'] ?? ''));
            $staffModel->createProfile((string) $user['_id'], [
                'departmentId' => $this->resolveDepartmentId($profile['departmentCode'] !== '' ? $profile['departmentCode'] : (string) ($extras['department'] ?? '')),
                'designation'  => $designation !== '' ? $designation : 'Faculty',
                'phone'        => (string) ($extras['phone'] ?? ''),
            ]);

            return;
        }

        $patch = [];

        if (!empty($extras['designation'])) {
            $patch['designation'] = (string) $extras['designation'];
        }
        if (!empty($extras['phone'])) {
            $patch['phone'] = (string) $extras['phone'];
        }

        $deptId = $this->resolveDepartmentIdFromProfile($profile, $extras);
        if ($deptId) {
            $patch['departmentId'] = $deptId;
        }

        if ($patch !== []) {
            $staffModel->updateProfile((string) $existing['_id'], $patch);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function ensurePlacementOfficerProfile(array $user, array $profile, array $mapped = []): array
    {
        if (($user['role'] ?? '') !== 'placement_officer') {
            return $user;
        }

        $poModel = new PlacementOfficerModel();
        if ($poModel->findByUserId((string) $user['_id'])) {
            return $user;
        }

        $deptId = $this->resolveDepartmentId($profile['departmentCode']);
        if ($deptId === null && !empty($mapped['department'])) {
            $deptId = $this->resolveDepartmentId((string) $mapped['department']);
        }
        if ($deptId === null) {
            return $user;
        }

        $existingDept = $poModel->findByDepartment($deptId);
        if ($existingDept) {
            $poModel->deleteByDepartment($deptId);
        }

        $designation = trim((string) ($mapped['designation'] ?? ''));
        if ($designation === '') {
            $designation = 'Placement Officer';
        }

        try {
            $poModel->createProfile((string) $user['_id'], [
                'departmentId' => $deptId,
                'designation'  => $designation,
            ]);
        } catch (\Throwable) {
            return $user;
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     */
    private function syncAlumniFromAes(array $user, array $profile, array $aesDetails): void
    {
        if (($user['role'] ?? '') !== 'alumni') {
            return;
        }

        $alumniModel = new AlumniModel();
        $existing = $alumniModel->findByUserId((string) $user['_id']);
        $extras = $this->mapAesDetailsToUserFields($aesDetails);

        $company = trim((string) ($extras['company'] ?? $extras['organization'] ?? $extras['employer'] ?? ''));
        $title = trim((string) ($extras['designation'] ?? $extras['title'] ?? $extras['job_title'] ?? $extras['jobTitle'] ?? ''));

        if (!$existing) {
            $alumniModel->createProfile((string) $user['_id'], [
                'company'    => $company,
                'role'       => $title,
                'title'      => $title,
                'experience' => (int) ($extras['experience'] ?? 0),
                'isWorking'  => $company !== '',
            ]);

            return;
        }

        $patch = [];
        if ($company !== '') {
            $patch['company'] = $company;
            $patch['isWorking'] = true;
        }
        if ($title !== '') {
            $patch['role'] = $title;
            $patch['title'] = $title;
        }
        if (isset($extras['experience'])) {
            $patch['experience'] = (int) $extras['experience'];
        }

        if ($patch !== []) {
            $alumniModel->updateProfile((string) $existing['_id'], $patch);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     */
    private function syncPlacementOfficerFromAes(array $user, array $profile, array $aesDetails): void
    {
        $officerModel = new \PMS\Models\PlacementOfficerModel();
        $existing = $officerModel->findByUserId((string) $user['_id']);
        if (!$existing) {
            return;
        }

        $extras = $this->mapAesDetailsToUserFields($aesDetails);
        $patch = [];

        if (!empty($extras['designation'])) {
            $patch['designation'] = (string) $extras['designation'];
        }

        $deptId = $this->resolveDepartmentIdFromProfile($profile, $extras);
        if ($deptId) {
            $patch['departmentId'] = $deptId;
        }

        if ($patch !== []) {
            $officerModel->update((string) $existing['_id'], $patch);
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
            'student_email', 'studentEmail', 'email_id', 'emailid', 'email_address', 'emailAddress',
            'stu_email', 'stuEmail', 'personal_email', 'personalEmail',
        ])));
        $contactScan = $this->deepScanAesProfileFields($payload);
        if ($email === '' && !empty($contactScan['email'])) {
            $email = (string) $contactScan['email'];
        }

        $name = $this->scanNameFromAesData($flat, $registerNumber);
        if ($name === '' && !empty($contactScan['name'])) {
            $name = (string) $contactScan['name'];
        }

        $roleHint = strtolower(trim($this->pick($flat, [
            'role', 'user_type', 'userType', 'category', 'type', 'usertype', 'user_role', 'userRole',
            'login_type', 'logintype', 'account_type', 'accounttype', 'portal', 'module',
        ])));

        $departmentCode = strtoupper(trim($this->pickInsensitive($flat, [
            'deptCode', 'dept_code', 'department_code', 'branch_code', 'deptshort', 'dept_short', 'br',
            'department', 'dept', 'branch',
            'branch_name', 'branchName', 'dept_name', 'deptName', 'department_name',
            'specialisation', 'specialization', 'programme', 'program',
        ])));
        if ($departmentCode === '' && !empty($contactScan['department'])) {
            $departmentCode = strtoupper((string) $contactScan['department']);
        }
        $departmentName = trim($this->pickInsensitive($flat, [
            'deptName', 'dept_name', 'department_name', 'branch_name', 'departmentName',
        ]));
        if ($departmentName === '' && !empty($contactScan['departmentName'])) {
            $departmentName = (string) $contactScan['departmentName'];
        }
        if ($departmentCode === '' && $departmentName !== '') {
            $departmentCode = strtoupper($departmentName);
        }

        $cgpaRaw = $this->pick($flat, [
            'cgpa', 'CGPA', 'gpa', 'GPA', 'current_cgpa', 'currentCgpa', 'cumulative_cgpa', 'overall_cgpa',
        ]);
        if ($cgpaRaw === '' && isset($contactScan['cgpa'])) {
            $cgpaRaw = (string) $contactScan['cgpa'];
        }

        if ($name === '' && $registerNumber !== '') {
            $name = '';
        }
        if ($name === '') {
            $emailScan = $this->scanEmailsFromAesData($flat, $registerNumber);
            $personalEmail = (string) ($emailScan['personalEmail'] ?? '');
            if ($personalEmail !== '' && $this->isPersonalEmailDomain($personalEmail)) {
                $name = $this->inferNameFromEmail($personalEmail);
            }
        }

        $role = $this->inferRoleFromAes(
            $payload,
            [
                'registerNumber' => $registerNumber,
                'email'          => $email,
            ],
            $this->scanEmailsFromAesData($flat, $registerNumber),
            $roleHint
        );

        return [
            'name'           => $name,
            'email'          => $email,
            'registerNumber' => $registerNumber,
            'role'           => $role,
            'departmentCode' => $departmentCode,
            'cgpa'           => ($cgpaRaw !== '' && is_numeric($cgpaRaw)) ? (float) $cgpaRaw : null,
        ];
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     * @param array<string, mixed> $mapped
     * @param array{personalEmail:string,collegeEmail:string,email:string} $resolvedEmails
     * @return array<string, mixed>
     */
    private function resolveOrProvisionUser(array $profile, array $aesDetails = [], array $mapped = [], array $resolvedEmails = []): ?array
    {
        $adminUser = $this->resolveSuperAdminUser($profile, $aesDetails, $mapped, $resolvedEmails);
        if ($adminUser !== null) {
            return $adminUser;
        }

        $officerUser = $this->resolvePlacementOfficerUser($profile, $aesDetails, $mapped, $resolvedEmails);
        if ($officerUser !== null) {
            return $officerUser;
        }

        $userModel = new UserModel();

        foreach ($this->collectLookupEmails($profile, $aesDetails, $mapped, $resolvedEmails) as $email) {
            if ($this->isSuperAdminEmail($email)) {
                $admin = $this->ensureSuperAdminAccount($email);
                if ($admin) {
                    return $admin;
                }
            }

            $user = $userModel->findByEmail($email);
            if ($user) {
                if (($user['role'] ?? '') === 'student') {
                    return $this->ensureStudentProfile($user, $profile);
                }
                if (($user['role'] ?? '') === 'placement_officer') {
                    return $this->ensurePlacementOfficerProfile($user, $profile, $mapped);
                }
                if (($user['role'] ?? '') === 'staff') {
                    return $this->ensureStaffProfile($user, $profile, $mapped);
                }
                if (($user['role'] ?? '') === 'alumni') {
                    return $this->ensureAlumniProfile($user, $profile, $mapped);
                }

                return $user;
            }
        }

        if ($profile['registerNumber'] !== '' && $profile['role'] === 'student') {
            $student = (new StudentModel())->findByRegisterNumber($profile['registerNumber']);
            if ($student && !empty($student['userId'])) {
                $user = $userModel->findById((string) $student['userId']);
                if ($user) {
                    return $user;
                }
            }
        }

        if ($profile['role'] === 'staff') {
            $toProvision = $this->profileForStaffProvisioning($profile, $resolvedEmails);
            if ($toProvision['email'] !== '' && $this->isSuperAdminEmail($toProvision['email'])) {
                return null;
            }
            if ($toProvision['email'] !== '') {
                return $this->provisionStaff($toProvision, $mapped);
            }

            return null;
        }

        if ($profile['role'] === 'alumni') {
            $toProvision = $this->profileForAlumniProvisioning($profile, $resolvedEmails);
            if ($toProvision['email'] !== '') {
                return $this->provisionAlumni($toProvision, $mapped);
            }

            return null;
        }

        if ($profile['role'] !== 'student') {
            return null;
        }

        if ($profile['registerNumber'] === '') {
            return null;
        }

        $toProvision = $this->profileForProvisioning($profile);
        if ($toProvision['email'] === '') {
            return null;
        }

        return $this->provisionStudent($toProvision);
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $aesDetails
     * @param array<string, mixed> $mapped
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $resolvedEmails
     * @return list<string>
     */
    private function collectLookupEmails(array $profile, array $aesDetails, array $mapped, array $resolvedEmails): array
    {
        $emails = [
            (string) ($profile['email'] ?? ''),
            (string) ($resolvedEmails['collegeEmail'] ?? ''),
            (string) ($resolvedEmails['personalEmail'] ?? ''),
            (string) ($resolvedEmails['email'] ?? ''),
            (string) ($mapped['collegeEmail'] ?? ''),
            (string) ($mapped['personalEmail'] ?? ''),
            (string) ($mapped['email'] ?? ''),
        ];

        $flat = $this->flattenScalarsDeep(array_merge($aesDetails, $mapped));
        foreach ($flat as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = strtolower(trim((string) $value));
            if (str_contains($text, '@') && filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $text;
            }
        }

        $unique = [];
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $unique[$email] = $email;
        }

        return array_values($unique);
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @return array{name:string,email:string,registerNumber:string,role:string,departmentCode:string}
     */
    private function profileForProvisioning(array $profile): array
    {
        if ($profile['email'] === '' && $profile['registerNumber'] !== '') {
            $profile['email'] = $this->syntheticStudentEmail($profile['registerNumber']);
        }

        return $profile;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $resolvedEmails
     * @return array{name:string,email:string,registerNumber:string,role:string,departmentCode:string}
     */
    private function profileForStaffProvisioning(array $profile, array $resolvedEmails): array
    {
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['collegeEmail'] ?? '');
        }
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['personalEmail'] ?? '');
        }
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['email'] ?? '');
        }

        return $profile;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $resolvedEmails
     * @return array{name:string,email:string,registerNumber:string,role:string,departmentCode:string}
     */
    private function profileForAlumniProvisioning(array $profile, array $resolvedEmails): array
    {
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['personalEmail'] ?? '');
        }
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['collegeEmail'] ?? '');
        }
        if ($profile['email'] === '') {
            $profile['email'] = (string) ($resolvedEmails['email'] ?? '');
        }

        return $profile;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function provisionAlumni(array $profile, array $mapped = []): array
    {
        $userModel = new UserModel();
        $alumniModel = new AlumniModel();

        if ($userModel->findByEmail($profile['email'])) {
            throw new \RuntimeException('This email is already registered in PlaceHub.');
        }

        $name = trim($profile['name'] ?? '');
        if ($name === '') {
            $name = 'Alumni';
        }

        $company = trim((string) ($mapped['company'] ?? $mapped['organization'] ?? $mapped['employer'] ?? ''));
        $title = trim((string) ($mapped['designation'] ?? $mapped['title'] ?? $mapped['job_title'] ?? $mapped['jobTitle'] ?? ''));
        $experience = (int) ($mapped['experience'] ?? 0);

        $userId = $userModel->createUser([
            'name'     => $name,
            'email'    => $profile['email'],
            'password' => bin2hex(random_bytes(16)),
            'role'     => 'alumni',
            'status'   => 'active',
            'approved' => true,
        ]);

        $alumniModel->createProfile($userId, [
            'company'    => $company,
            'role'       => $title,
            'title'      => $title,
            'experience' => $experience,
            'isWorking'  => $company !== '',
        ]);

        $user = $userModel->findById($userId);
        if (!$user) {
            throw new \RuntimeException('Could not create your PlaceHub alumni account.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function ensureAlumniProfile(array $user, array $profile, array $mapped = []): array
    {
        if (($user['role'] ?? '') !== 'alumni') {
            return $user;
        }

        $alumniModel = new AlumniModel();
        if ($alumniModel->findByUserId((string) $user['_id'])) {
            return $user;
        }

        $company = trim((string) ($mapped['company'] ?? $mapped['organization'] ?? $mapped['employer'] ?? ''));
        $title = trim((string) ($mapped['designation'] ?? $mapped['title'] ?? $mapped['job_title'] ?? $mapped['jobTitle'] ?? ''));

        $alumniModel->createProfile((string) $user['_id'], [
            'company'    => $company,
            'role'       => $title,
            'title'      => $title,
            'experience' => (int) ($mapped['experience'] ?? 0),
            'isWorking'  => $company !== '',
        ]);

        return $user;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function provisionStaff(array $profile, array $mapped = []): array
    {
        $userModel = new UserModel();
        $staffModel = new StaffModel();

        if ($userModel->findByEmail($profile['email'])) {
            throw new \RuntimeException('This email is already registered in PlaceHub.');
        }

        $name = trim($profile['name'] ?? '');
        if ($name === '') {
            $name = 'Staff Member';
        }

        $deptId = $this->resolveDepartmentId($profile['departmentCode']);
        $designation = trim((string) ($mapped['designation'] ?? ''));
        if ($designation === '') {
            $designation = 'Faculty';
        }

        $userId = $userModel->createUser([
            'name'     => $name,
            'email'    => $profile['email'],
            'password' => bin2hex(random_bytes(16)),
            'role'     => 'staff',
            'status'   => 'active',
            'approved' => true,
        ]);

        $staffModel->createProfile($userId, [
            'departmentId' => $deptId,
            'designation'  => $designation,
            'phone'        => (string) ($mapped['phone'] ?? ''),
        ]);

        $user = $userModel->findById($userId);
        if (!$user) {
            throw new \RuntimeException('Could not create your PlaceHub staff account.');
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $mapped
     * @return array<string, mixed>
     */
    private function ensureStaffProfile(array $user, array $profile, array $mapped = []): array
    {
        if (($user['role'] ?? '') !== 'staff') {
            return $user;
        }

        $staffModel = new StaffModel();
        if ($staffModel->findByUserId((string) $user['_id'])) {
            return $user;
        }

        $designation = trim((string) ($mapped['designation'] ?? ''));
        if ($designation === '') {
            $designation = 'Faculty';
        }

        $staffModel->createProfile((string) $user['_id'], [
            'departmentId' => $this->resolveDepartmentId($profile['departmentCode']),
            'designation'  => $designation,
            'phone'        => (string) ($mapped['phone'] ?? ''),
        ]);

        return $user;
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

        $academic = [];
        if (isset($profile['cgpa']) && $profile['cgpa'] !== null) {
            $academic['cgpa'] = (float) $profile['cgpa'];
        }

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
            'academic'       => $academic,
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

        try {
            (new AesApiService())->syncDepartmentsToLocal();
            $dept = $deptModel->findByCode($departmentCode);
            if ($dept) {
                return (string) $dept['_id'];
            }
        } catch (\Throwable) {
            // Local departments only.
        }

        $dept = $deptModel->findOne(['name' => $departmentCode]);
        return $dept ? (string) $dept['_id'] : null;
    }

    /**
     * @param array{name:string,email:string,registerNumber:string,role:string,departmentCode:string} $profile
     * @param array<string, mixed> $extras
     */
    private function resolveDepartmentIdFromProfile(array $profile, array $extras): ?string
    {
        $deptCode = $profile['departmentCode'] !== ''
            ? $profile['departmentCode']
            : (string) ($extras['department'] ?? '');
        $deptId = $this->resolveDepartmentId($deptCode);
        if ($deptId !== null) {
            return $deptId;
        }

        $deptName = (string) ($extras['departmentName'] ?? '');
        return $deptName !== '' ? $this->resolveDepartmentId($deptName) : null;
    }

    private function syntheticStudentEmail(string $registerNumber): string
    {
        $safe = preg_replace('/[^a-z0-9]/i', '', $registerNumber) ?: 'student';
        return strtolower($safe) . '@students.amaljyothi.ac.in';
    }

    public function excludeSyntheticCollegeEmail(string $email, string $registerNumber): string
    {
        return $this->isSyntheticStudentEmail($email, $registerNumber) ? '' : trim($email);
    }

    private function isSyntheticStudentEmail(string $email, string $registerNumber): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@students.amaljyothi.ac.in')) {
            return false;
        }
        if ($registerNumber === '') {
            return true;
        }
        $local = strstr($email, '@', true) ?: '';
        $safeReg = strtolower(preg_replace('/[^a-z0-9]/i', '', $registerNumber) ?: '');

        return $local !== '' && $safeReg !== '' && $local === $safeReg;
    }

    private function inferRole(string $roleHint, string $registerNumber, string $email): string
    {
        return $this->inferRoleFromAes([], [
            'registerNumber' => $registerNumber,
            'email'          => $email,
        ], [
            'collegeEmail'  => $this->isCollegeEmail($email) ? $email : '',
            'personalEmail' => $this->isPersonalEmailDomain($email) ? $email : '',
            'email'         => $email,
        ], $roleHint);
    }

    /**
     * Detect AES portal login category (alumni/staff/student) from callback payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $flat
     */
    private function detectAesLoginCategory(array $payload, array $flat, string $roleHint): string
    {
        $candidates = [$roleHint];
        foreach ($flat as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $lowerKey = strtolower((string) $key);
            if (!preg_match('/(role|type|category|portal|module|login|section|user)/', $lowerKey)) {
                continue;
            }
            $candidates[] = strtolower(trim((string) $value));
        }

        foreach ($candidates as $text) {
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'alumni') || str_contains($text, 'alum') || str_contains($text, 'ex-student') || str_contains($text, 'ex_student') || str_contains($text, 'passedout') || str_contains($text, 'passed_out')) {
                return 'alumni';
            }
            foreach (['staff', 'faculty', 'teacher', 'employee', 'hod', 'professor', 'lecturer'] as $needle) {
                if (str_contains($text, $needle)) {
                    return 'staff';
                }
            }
            if ($this->isPlacementOfficerHint($text)) {
                return 'placement_officer';
            }
        }

        if (isset($payload['alumni']) || isset($flat['alumni']) || isset($flat['alum'])) {
            return 'alumni';
        }
        if (isset($payload['staff']) || isset($flat['staff']) || isset($flat['employee']) || isset($flat['faculty'])) {
            return 'staff';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{name?:string,email?:string,registerNumber?:string,role?:string,departmentCode?:string} $profile
     * @param array{personalEmail?:string,collegeEmail?:string,email?:string} $emailScan
     */
    private function inferRoleFromAes(array $payload, array $profile, array $emailScan, string $roleHint = ''): string
    {
        $flat = $payload !== [] ? $this->flattenScalarsDeep($payload) : [];
        $lookupEmails = $this->collectLookupEmails(
            [
                'name'           => (string) ($profile['name'] ?? ''),
                'email'          => (string) ($profile['email'] ?? ''),
                'registerNumber' => (string) ($profile['registerNumber'] ?? ''),
                'role'           => '',
                'departmentCode' => (string) ($profile['departmentCode'] ?? ''),
            ],
            $flat,
            [],
            $emailScan
        );
        foreach ($lookupEmails as $email) {
            if ($this->isSuperAdminEmail($email)) {
                return 'admin';
            }
            $existing = (new UserModel())->findByEmail($email);
            if ($existing && ($existing['role'] ?? '') === 'admin') {
                return 'admin';
            }
            if ($existing && ($existing['role'] ?? '') === 'placement_officer') {
                return 'placement_officer';
            }
        }

        if ($roleHint === '') {
            $roleHint = strtolower(trim($this->pickInsensitive($flat, [
                'role', 'user_type', 'userType', 'category', 'type', 'usertype', 'user_role', 'userRole',
                'login_type', 'logintype', 'account_type', 'accounttype', 'designation_type', 'portal', 'module',
            ])));
        } else {
            $roleHint = strtolower($roleHint);
        }

        $loginCategory = $this->detectAesLoginCategory($payload, $flat, $roleHint);
        if ($loginCategory !== '') {
            return $loginCategory;
        }

        foreach (['staff', 'faculty', 'teacher', 'employee', 'hod', 'professor', 'lecturer', 'non-teaching', 'non_teaching'] as $needle) {
            if (str_contains($roleHint, $needle)) {
                return 'staff';
            }
        }
        if ($this->isPlacementOfficerHint($roleHint)) {
            return 'placement_officer';
        }
        if (str_contains($roleHint, 'alumni')) {
            return 'alumni';
        }
        if (str_contains($roleHint, 'student') || str_contains($roleHint, 'parent')) {
            return 'student';
        }

        foreach ($flat as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $lowerKey = strtolower((string) $key);
            if (!in_array($lowerKey, ['role', 'user_type', 'usertype', 'category', 'type', 'login_type', 'account_type'], true)) {
                continue;
            }
            $text = strtolower(trim((string) $value));
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'staff') || str_contains($text, 'faculty') || str_contains($text, 'teacher') || str_contains($text, 'employee')) {
                return 'staff';
            }
            if ($this->isPlacementOfficerHint($text)) {
                return 'placement_officer';
            }
            if (str_contains($text, 'alumni') || str_contains($text, 'alum') || str_contains($text, 'ex-student') || str_contains($text, 'ex_student')) {
                return 'alumni';
            }
            if (str_contains($text, 'student') || str_contains($text, 'parent')) {
                return 'student';
            }
        }

        if (isset($payload['staff']) || isset($flat['staff']) || isset($flat['employee']) || isset($flat['faculty'])) {
            return 'staff';
        }
        if (isset($payload['alumni']) || isset($flat['alumni']) || isset($flat['alum'])) {
            return 'alumni';
        }

        $registerNumber = strtoupper(trim((string) ($profile['registerNumber'] ?? '')));
        $collegeEmail = strtolower(trim((string) ($emailScan['collegeEmail'] ?? '')));
        $personalEmail = strtolower(trim((string) ($emailScan['personalEmail'] ?? '')));
        $primaryEmail = strtolower(trim((string) ($profile['email'] ?? $emailScan['email'] ?? '')));

        if ($this->isStudentCollegeEmail($collegeEmail) || $this->isStudentCollegeEmail($primaryEmail)) {
            return 'student';
        }
        if ($this->isStaffCollegeEmail($collegeEmail) || $this->isStaffCollegeEmail($primaryEmail)) {
            return 'staff';
        }

        if ($registerNumber !== '' && preg_match('/^[0-9]{2}[A-Z]{2,4}[0-9]{2,4}$/i', $registerNumber)) {
            return 'student';
        }
        if ($registerNumber !== '' && preg_match('/^\d{4,6}$/', $registerNumber)) {
            return 'student';
        }

        if ($primaryEmail !== '' && str_contains($primaryEmail, '@students.amaljyothi.ac.in')) {
            return 'student';
        }

        if ($personalEmail !== '' && $this->isPersonalEmailDomain($personalEmail) && !$this->isStaffCollegeEmail($collegeEmail)) {
            return 'student';
        }

        if ($collegeEmail !== '' || ($primaryEmail !== '' && $this->isCollegeEmail($primaryEmail))) {
            return 'staff';
        }

        return 'student';
    }

    private function isStudentCollegeEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        if (str_contains($email, '@students.amaljyothi.ac.in')) {
            return true;
        }

        return (bool) preg_match('/@(mca|cse|ece|eee|me|ce|it|cs|mba|mtech|ecea|eee|mech|civil)\.ajce\.in$/', $email);
    }

    private function isStaffCollegeEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !$this->isCollegeEmail($email)) {
            return false;
        }

        return !$this->isStudentCollegeEmail($email);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertUserCanLogin(array $user): void
    {
        if (($user['status'] ?? '') === 'blocked') {
            throw new \RuntimeException('Your account has been blocked. Contact admin.');
        }

        $userModel = new UserModel();
        $user = $userModel->ensureLoginReady($user);
        if (!$userModel->canLogin($user)) {
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

        foreach (['data', 'user', 'profile', 'student', 'staff', 'employee', 'resp', 'details', 'userData', 'personal', 'contact'] as $nestedKey) {
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

        return array_merge($flat, $this->flattenScalarsDeep($payload));
    }

    /**
     * Pull every scalar leaf from nested AES JSON into a flat key => value map.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function flattenScalarsDeep(array $payload): array
    {
        $out = [];

        $walk = function (mixed $node) use (&$walk, &$out): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walk($value);
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $keyStr = (string) $key;
                $text = is_bool($value) ? $value : trim((string) $value);
                if ($text === '' && $text !== false) {
                    continue;
                }
                if (!isset($out[$keyStr]) || $out[$keyStr] === '' || $out[$keyStr] === null) {
                    $out[$keyStr] = $text;
                }
            }
        };

        $walk($payload);

        return $out;
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
     * @param array<string, mixed> $post
     * @param list<string> $keys
     */
    private function pickInsensitive(array $post, array $keys): string
    {
        $lowerMap = [];
        foreach ($post as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $lowerMap[strtolower((string) $key)] = $text;
        }

        foreach ($keys as $key) {
            $value = $lowerMap[strtolower($key)] ?? '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array{collegeEmail:string,personalEmail:string}
     */
    private function scanEmailsFromAesData(array $data, string $register = ''): array
    {
        $college = '';
        $personal = '';
        $register = strtoupper($register !== '' ? $register : $this->pickInsensitive($data, [
            'registerNumber', 'register_number', 'admission_no', 'admissionNo', 'username', 'un',
        ]));
        $flat = $this->flattenScalarsDeep($data);

        foreach ($flat as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if (!str_contains($text, '@')) {
                continue;
            }
            $email = strtolower($text);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $lowerKey = strtolower((string) $key);
            if (preg_match('/\[([^\]]+)\]$/', $lowerKey, $matches)) {
                $lowerKey = strtolower((string) $matches[1]);
            }

            if ($this->isCollegeEmailFieldKey($lowerKey)) {
                if ($college === '' && !$this->isSyntheticStudentEmail($email, $register) && !$this->isPersonalEmailDomain($email)) {
                    $college = $email;
                }
                continue;
            }
            if ($this->isPersonalEmailFieldKey($lowerKey)) {
                if ($personal === '') {
                    $personal = $email;
                }
                continue;
            }

            if ($this->isCollegeEmail($email)) {
                if ($college === '' && !$this->isSyntheticStudentEmail($email, $register)) {
                    $college = $email;
                }
            } elseif ($this->isPersonalEmailDomain($email) && $personal === '') {
                $personal = $email;
            }
        }

        $collegeKeys = [
            'college_email', 'collegeemail', 'college_mail', 'collegemail', 'college_mail_id', 'collegemailid',
            'official_email', 'student_email', 'studentemail', 'stu_email', 'stuemail', 'stud_email', 'studemail',
            'institutional_email', 'institute_email', 'inst_email', 'inst_mail', 'ajce_email', 'cmail', 'c_mail',
            'university_email', 'campus_email', 'collegeemailid', 'college_mailid', 'mail_id', 'mailid',
        ];
        $pickedCollege = strtolower(trim($this->pickInsensitive($flat, $collegeKeys)));
        if (
            $pickedCollege !== ''
            && filter_var($pickedCollege, FILTER_VALIDATE_EMAIL)
            && !$this->isSyntheticStudentEmail($pickedCollege, $register)
            && !$this->isPersonalEmailDomain($pickedCollege)
        ) {
            $college = $pickedCollege;
        }

        $personalKeys = [
            'personal_email', 'personalemail', 'gmail', 'alternate_email', 'alt_email', 'private_email',
            'personal_mail', 'personalemailid', 'alternate_mail',
        ];
        $pickedPersonal = strtolower(trim($this->pickInsensitive($flat, $personalKeys)));
        if ($pickedPersonal !== '' && filter_var($pickedPersonal, FILTER_VALIDATE_EMAIL) && !$this->isCollegeEmail($pickedPersonal)) {
            $personal = $pickedPersonal;
        }

        return $this->normalizeResolvedEmails($college, $personal);
    }

    private function isCollegeEmailFieldKey(string $lowerKey): bool
    {
        $needles = [
            'college_email', 'collegeemail', 'college_mail', 'collegemail', 'college_mail_id', 'collegemailid',
            'official_email', 'institutional_email', 'institute_email', 'inst_email', 'inst_mail',
            'student_email', 'studentemail', 'stu_email', 'stuemail', 'stud_email', 'studemail',
            'ajce_email', 'cmail', 'c_mail', 'university_email', 'campus_email', 'collegeemailid', 'college_mailid',
        ];
        foreach ($needles as $needle) {
            if ($lowerKey === $needle || str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPersonalEmailFieldKey(string $lowerKey): bool
    {
        $needles = [
            'personal_email', 'personalemail', 'gmail', 'alternate_email', 'alt_email', 'private_email',
            'personal_mail', 'alternate_mail',
        ];
        foreach ($needles as $needle) {
            if ($lowerKey === $needle || str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPersonalEmailDomain(string $email): bool
    {
        $email = strtolower(trim($email));
        $domain = strstr($email, '@') ?: '';
        if ($domain === '') {
            return false;
        }

        $personalDomains = [
            '@gmail.com', '@googlemail.com', '@yahoo.com', '@yahoo.in', '@outlook.com', '@hotmail.com',
            '@live.com', '@icloud.com', '@protonmail.com', '@rediffmail.com',
        ];
        foreach ($personalDomains as $needle) {
            if (str_ends_with($email, $needle)) {
                return true;
            }
        }

        return false;
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

    /**
     * Ask AES for extended profile fields when the callback only includes admission number.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function fetchExtendedProfileFromAes(array $payload): array
    {
        if ($this->authKey === '') {
            return [];
        }

        $flat = $this->flattenPayload($payload);
        $username = $this->pick($flat, [
            'username', 'un', 'admission_no', 'admissionNo', 'registerNumber', 'register_number',
            'userid', 'user_id', 'token_user', 'admission_number',
        ]);
        if ($username === '') {
            return [];
        }

        $token = $this->pick($flat, ['token', 'auth_token', 'session', 'checksum']);
        $baseRequest = [
            'username'     => $username,
            'un'           => $username,
            'admission_no' => $username,
            'registerNumber' => $username,
        ];
        if ($token !== '') {
            $baseRequest['token'] = $token;
            $baseRequest['checksum'] = $token;
        }

        try {
            $placementInfo = (new AesApiService())->fetchStudentPlacementProfile($baseRequest);
            if ($placementInfo !== []) {
                return $placementInfo;
            }
        } catch (\Throwable) {
            // Fall back to legacy login.aesajce.in profile methods.
        }

        $methods = [
            'getStudentDetails',
            'getUserDetails',
            'getStaffDetails',
            'getStudentProfile',
            'getPersonalDetails',
            'getContactDetails',
            'getProfile',
            'getUserProfile',
            'userDetails',
            'studentProfile',
            'studentDetails',
        ];

        foreach ($methods as $method) {
            try {
                $response = $this->callAes($method, $baseRequest);
            } catch (\Throwable) {
                continue;
            }

            if (($response['message'] ?? '') === 'Invalid Method ' . $method) {
                continue;
            }

            if (($response['status'] ?? false) === true) {
                $data = $response['data'] ?? $response['profile'] ?? $response['user'] ?? $response['student'] ?? null;
                if (is_array($data) && $data !== []) {
                    return $data;
                }
            }

            if (
                isset($response['name'])
                || isset($response['student_name'])
                || isset($response['stu_name'])
                || isset($response['email'])
                || isset($response['mobile'])
                || isset($response['phone'])
                || isset($response['student_email'])
            ) {
                return $response;
            }
        }

        return [];
    }

    private function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '' || strlen($digits) < 6) {
            return false;
        }

        return !in_array($digits, ['919876543210', '9876543210'], true);
    }

    private function isCollegeEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '') {
            return false;
        }

        if (str_contains($domain, 'amaljyothi.ac.in')) {
            return true;
        }

        return $domain === 'ajce.in' || str_ends_with($domain, '.ajce.in');
    }

    /**
     * @return array{personalEmail:string,collegeEmail:string,email:string}
     */
    private function normalizeResolvedEmails(string $college, string $personal): array
    {
        $college = strtolower(trim($college));
        $personal = strtolower(trim($personal));

        if ($personal !== '' && $this->isCollegeEmail($personal)) {
            if ($college === '') {
                $college = $personal;
            }
            $personal = '';
        }

        if ($college !== '' && $this->isPersonalEmailDomain($college)) {
            if ($personal === '') {
                $personal = $college;
            }
            $college = '';
        }

        return [
            'personalEmail' => $personal,
            'collegeEmail'  => $college,
            'email'         => $college !== '' ? $college : $personal,
        ];
    }

    /**
     * @param array<string, mixed> $aesProfile
     * @param array<string, mixed> $mapped
     */
    private function pickCollegeEmail(array $aesProfile, array $mapped, string $register): string
    {
        $scanned = $this->scanEmailsFromAesData(array_merge($aesProfile, $mapped), $register);

        return $scanned['collegeEmail'];
    }

    /**
     * @param array<string, mixed> $aesProfile
     * @param array<string, mixed> $mapped
     * @return array{personalEmail:string,collegeEmail:string,email:string}
     */
    private function resolveAesEmails(array $aesProfile, array $mapped, string $register, string $primaryEmail = ''): array
    {
        $scanned = $this->scanEmailsFromAesData(array_merge($aesProfile, $mapped), $register);
        $college = $scanned['collegeEmail'];
        $personal = $scanned['personalEmail'];

        if (!empty($mapped['collegeEmail']) && $college === '') {
            $candidate = strtolower(trim((string) $mapped['collegeEmail']));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) && !$this->isSyntheticStudentEmail($candidate, $register)) {
                $college = $candidate;
            }
        }
        if (!empty($mapped['personalEmail']) && $personal === '') {
            $candidate = strtolower(trim((string) $mapped['personalEmail']));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) && !$this->isCollegeEmail($candidate)) {
                $personal = $candidate;
            }
        }

        if ($primaryEmail !== '' && filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
            if ($this->isCollegeEmail($primaryEmail)) {
                if ($college === '' && !$this->isSyntheticStudentEmail($primaryEmail, $register)) {
                    $college = $primaryEmail;
                }
            } elseif ($this->isPersonalEmailDomain($primaryEmail) && $personal === '') {
                $personal = $primaryEmail;
            }
        }

        return $this->normalizeResolvedEmails($college, $personal);
    }

    private function inferNameFromEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        $local = strstr($email, '@', true) ?: '';
        $local = preg_replace('/\d+$/', '', $local) ?? '';
        $local = preg_replace('/[._+-]+/', ' ', $local) ?? '';
        $local = trim($local);
        if (strlen($local) < 3 || !preg_match('/[a-zA-Z]/', $local)) {
            return '';
        }

        return mb_convert_case($local, MB_CASE_TITLE, 'UTF-8');
    }

    private function resolveAesName(array $aesProfile, array $mapped, string $register, string $personalEmail = ''): string
    {
        $name = $this->scanNameFromAesData(array_merge($aesProfile, $mapped), $register);
        if ($name !== '') {
            return $name;
        }

        if ($personalEmail !== '' && $this->isPersonalEmailDomain($personalEmail)) {
            $inferred = $this->inferNameFromEmail($personalEmail);
            if ($this->isRealPersonName($inferred) && !$this->isPlaceholderName($inferred, $register)) {
                return $inferred;
            }
        }

        return '';
    }

    private function scanNameFromAesData(array $data, string $register = ''): string
    {
        $register = strtoupper($register !== '' ? $register : $this->pickInsensitive($data, [
            'registerNumber', 'register_number', 'admission_no', 'admissionNo', 'username', 'un',
        ]));
        $flat = $this->flattenScalarsDeep($data);
        $candidates = [];

        $priorityKeys = [
            'full_name', 'fullname', 'student_full_name', 'studentfullname', 'stud_full_name', 'studfullname',
            'user_full_name', 'complete_name', 'name_in_full', 'applicant_name', 'candidate_name',
            'student_name', 'studentname', 'studname', 'stud_name', 'stu_name', 'stuname',
            'display_name', 'displayname', 'staff_name', 'staffname', 'emp_name', 'employee_name', 'faculty_name',
            'name', 'sname', 'nm', 'stu_nm', 'uname',
        ];
        foreach ($priorityKeys as $key) {
            $val = trim($this->pickInsensitive($flat, [$key]));
            if ($val !== '') {
                $candidates[] = ['name' => $val, 'key' => $key];
            }
        }

        $fname = trim($this->pickInsensitive($flat, ['fname', 'first_name', 'firstname', 'firstName', 'stu_fname', 'student_fname']));
        $lname = trim($this->pickInsensitive($flat, ['lname', 'last_name', 'lastname', 'lastName', 'stu_lname', 'student_lname', 'mname', 'middle_name']));
        $combined = trim($fname . ' ' . $lname);
        if ($combined !== '') {
            $candidates[] = ['name' => $combined, 'key' => 'first_last_combined'];
        }

        foreach ($flat as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $lowerKey = strtolower((string) $key);
            if (preg_match('/\[([^\]]+)\]$/', $lowerKey, $matches)) {
                $lowerKey = strtolower((string) $matches[1]);
            }
            if (!$this->isNameFieldKey($lowerKey)) {
                continue;
            }
            $candidates[] = ['name' => $text, 'key' => $lowerKey];
        }

        return $this->pickBestAesName($candidates, $register);
    }

    /**
     * @param list<array{name:string,key:string}> $candidates
     */
    private function pickBestAesName(array $candidates, string $register): string
    {
        $best = '';
        $bestScore = -1;
        $seen = [];

        foreach ($candidates as $entry) {
            $name = trim((string) ($entry['name'] ?? ''));
            $key = strtolower((string) ($entry['key'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized = strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            if (!$this->isRealPersonName($name) || $this->isPlaceholderName($name, $register)) {
                continue;
            }
            $score = $this->nameFieldScore($name, $key);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $best;
    }

    private function nameFieldScore(string $name, string $key): int
    {
        $score = strlen($name);
        if (str_contains($key, 'full')) {
            $score += 200;
        }
        if (str_contains($key, 'first_last')) {
            $score += 150;
        }
        if (str_contains($key, 'student') || str_contains($key, 'stud')) {
            $score += 80;
        }
        if (str_contains($name, ' ')) {
            $score += 50;
        }
        if ($key === 'name' || $key === 'nm' || $key === 'uname') {
            $score -= 30;
        }

        return $score;
    }

    private function isNameFieldKey(string $lowerKey): bool
    {
        if (in_array($lowerKey, [
            'name', 'fullname', 'full_name', 'displayname', 'display_name', 'nm', 'uname', 'sname',
            'studname', 'stuname', 'studentname', 'studentfullname', 'student_full_name', 'studfullname',
        ], true)) {
            return true;
        }
        if (str_contains($lowerKey, 'parent') || str_contains($lowerKey, 'father') || str_contains($lowerKey, 'mother') || str_contains($lowerKey, 'guardian')) {
            return false;
        }
        if (str_contains($lowerKey, 'dept') || str_contains($lowerKey, 'branch') || str_contains($lowerKey, 'company')) {
            return false;
        }

        return str_ends_with($lowerKey, '_name') || str_contains($lowerKey, 'studname') || str_contains($lowerKey, 'studentname');
    }

    private function isPlaceholderName(string $name, string $registerNumber): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }
        if ($registerNumber !== '' && strcasecmp($name, $registerNumber) === 0) {
            return true;
        }

        return (bool) preg_match('/^\d+$/', $name);
    }

    private function isRealPersonName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return false;
        }

        return (bool) preg_match('/[a-zA-Z]/', $name);
    }

    private function shouldReplaceDisplayName(string $current, string $registerNumber): bool
    {
        $current = trim($current);
        if ($current === '') {
            return true;
        }
        if ($registerNumber !== '' && strcasecmp($current, $registerNumber) === 0) {
            return true;
        }

        return (bool) preg_match('/^\d+$/', $current);
    }

    private function missingAesConfigMessage(): string
    {
        return 'AES login is not configured on this server. Add AES_AUTH_KEY and AES_REF_HOST to public_html/.env, then redeploy.';
    }
}
