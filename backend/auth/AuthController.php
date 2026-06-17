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
use PMS\Utils\JwtHelper;
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
        $errors = Validator::validate($input, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed.', 422, $errors);
        }

        $user = $this->userModel->findByEmail($input['email']);
        if (!$user || !Security::verifyPassword($input['password'], $user['password'])) {
            Response::error('Invalid email or password.', 401);
        }

        if (($user['status'] ?? '') === 'blocked') {
            Response::error('Your account has been blocked. Contact admin.', 403);
        }

        if (!($user['approved'] ?? false) && ($user['role'] ?? '') !== 'admin') {
            Response::error('Account pending approval.', 403);
        }

        Security::setSessionUser($user);

        $token = JwtHelper::encode([
            'sub'  => (string) $user['_id'],
            'role' => $user['role'],
            'email'=> $user['email'],
        ]);

        Response::success(
            AuthMiddleware::userResponse($user, $token),
            'Login successful.'
        );
    }

    /** POST /api/auth/logout */
    public function logout(): void
    {
        Security::destroySession();
        Response::success(null, 'Logged out successfully.');
    }

    /** GET /api/auth/me */
    public function me(): void
    {
        $user = AuthMiddleware::authenticate();
        Response::success(AuthMiddleware::userResponse($user));
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
                    'departmentId'   => 'required',
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
                    (new CompanyModel())->createCompany([
                        'userId'      => $userId,
                        'companyName' => $input['companyName'],
                        'category'    => $input['category'] ?? 'Software',
                        'tier'        => $input['tier'] ?? 'Tier 2',
                    ]);
                }
                break;

            case 'alumni':
                (new AlumniModel())->createProfile($userId, $input);
                break;
        }
    }
}
