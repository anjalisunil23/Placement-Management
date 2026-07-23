<?php

declare(strict_types=1);

namespace PMS\Auth;

use PMS\Middleware\AuthMiddleware;
use PMS\Models\AlumniModel;
use PMS\Models\CompanyModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Services\NotificationService;
use PMS\Schemas\Collections;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;
use PMS\Utils\Validator;

/**
 * Authentication controller — register, login, logout.
 */
final class AuthController
{
    private UserModel $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    /** POST /api/auth/register */
    public function register(): void
    {
        $input = Validator::sanitizeArray(json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);

        $rules = [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email',
            'password' => 'required|min:8|max:128',
            'role'     => 'required|in:student,staff,company,alumni',
        ];

        $errors = Validator::validate($input, $rules);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        if ($this->userModel->findByEmail($input['email'])) {
            Response::error('Email already registered.', 409);
        }

        // Students need extra fields
        $approved = false;
        $status = 'pending';
        if ($input['role'] === 'company') {
            $approved = false;
        }

        $userId = $this->userModel->createUser([
            'name'     => $input['name'],
            'email'    => $input['email'],
            'password' => $input['password'],
            'role'     => $input['role'],
            'status'   => $status,
            'approved' => $approved,
        ]);

        // Create role-specific profile
        $this->createRoleProfile($userId, $input['role'], $input);

        if ($input['role'] === 'student') {
            (new NotificationService())->notifyUser(
                $userId,
                'registration_pending',
                'Registration Received',
                'Your registration is pending approval from the placement officer.'
            );
        }

        Response::success(
            ['userId' => $userId],
            'Registration successful. Awaiting admin approval.',
            201
        );
    }

    /** POST /api/auth/login */
    public function login(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        if (isset($input['email'])) {
            $input['email'] = strtolower(trim((string) $input['email']));
        }
        if (isset($input['password'])) {
            $input['password'] = (string) $input['password'];
        }
        $errors = Validator::validate($input, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $user = $this->userModel->findByEmail($input['email']);
        if (!$user || !Security::verifyPassword($input['password'], (string) ($user['password'] ?? ''))) {
            Response::error('Invalid email or password.', 401);
        }

        if (($user['status'] ?? '') === 'blocked') {
            Response::error('Your account has been blocked. Contact admin.', 403);
        }

        $user = $this->userModel->ensureLoginReady($user);
        if (!$this->userModel->canLogin($user)) {
            Response::error('Account pending approval.', 403);
        }

        Security::setSessionUser($user);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Response::success(
            AuthMiddleware::userResponse($user, null),
            'Login successful.'
        );
    }

    /** POST /api/auth/logout */
    public function logout(): void
    {
        Security::destroySession();
        Response::success(null, 'Logged out successfully.');
    }

    /** GET /api/auth/me — ?fast=1 skips AES name/dept enrichment (post-login boot) */
    public function me(): void
    {
        $user = AuthMiddleware::authenticate();
        $fast = isset($_GET['fast']) && (string) $_GET['fast'] !== '0' && (string) $_GET['fast'] !== '';
        if (!$fast && !empty($_SESSION['ph_auth_fast_boot'])) {
            $fast = true;
            unset($_SESSION['ph_auth_fast_boot']);
        }
        Response::success(AuthMiddleware::userResponse($user, null, ['fast' => $fast]));
    }

    /** POST /api/auth/change-password */
    public function changePassword(): void
    {
        $user = AuthMiddleware::authenticate();
        $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
        $errors = Validator::validate($input, [
            'currentPassword' => 'required',
            'newPassword'     => 'required|min:8|max:128',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        if (!Security::verifyPassword($input['currentPassword'], $user['password'])) {
            Response::error('Current password is incorrect.', 403);
        }

        if (Security::verifyPassword($input['newPassword'], $user['password'])) {
            Response::error('New password must be different from the current password.', 422);
        }

        $this->userModel->updateUser((string) $user['_id'], [
            'password' => $input['newPassword'],
        ]);

        Response::success(null, 'Password changed successfully.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createRoleProfile(string $userId, string $role, array $input): void
    {
        switch ($role) {
            case 'student':
                $studentErrors = Validator::validate($input, [
                    'registerNumber' => 'required',
                ]);
                if (!empty($studentErrors)) {
                    return;
                }
                $studentModel = new StudentModel();
                if ($studentModel->findByRegisterNumber($input['registerNumber'])) {
                    return;
                }
                $studentModel->createProfile($userId, $input);
                break;

            case 'staff':
                (new StaffModel())->createProfile($userId, $input);
                break;

            case 'company':
                if (!empty($input['companyName'])) {
                    $companyName = trim((string) $input['companyName']);
                    $companyModel = new CompanyModel();
                    $existing = $companyModel->findByNormalizedName($companyName);
                    if ($existing === null) {
                        $companyModel->createCompany([
                            'userId'      => $userId,
                            'companyName' => $companyName,
                            'category'    => $input['category'] ?? 'Software',
                            'tier'        => $input['tier'] ?? 'Tier 2',
                        ]);
                    } else {
                        $companyModel->update((string) $existing['_id'], [
                            'userId'            => Security::toObjectId($userId),
                            'associationStatus' => $input['associationStatus'] ?? 'active',
                        ]);
                    }
                }
                break;

            case 'alumni':
                (new AlumniModel())->createProfile($userId, $input);
                break;
        }
    }
}
