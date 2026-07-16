<?php

declare(strict_types=1);

namespace PMS\Middleware;

use PMS\Models\AlumniModel;
use PMS\Models\CompanyModel;
use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\StudentModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\JwtHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Authentication middleware — validates JWT or PHP session.
 */
final class AuthMiddleware
{
  private static ?array $currentUser = null;

  public static function resolvedRole(array $user): string
  {
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    $aesService = new \PMS\Services\AesLoginService();
    if ($aesService->isSuperAdminEmail($email)) {
      return 'admin';
    }

    $role = trim((string) ($user['role'] ?? ''));
    if ($role === 'staff' && self::isHodStaff($user)) {
      return 'placement_officer';
    }

    return $role;
  }

  private static function isHodStaff(array $user): bool
  {
    if (trim((string) ($user['role'] ?? '')) !== 'staff') {
      return false;
    }

    try {
      $profile = \PMS\Services\StaffContext::ensureProfile($user);
    } catch (\Throwable) {
      $profile = [];
    }

    $designation = strtoupper(trim((string) ($profile['designation'] ?? $user['designation'] ?? '')));
    if ($designation === '') {
      return false;
    }

    return preg_match(
      '/\bHOD\b|\bHEAD\s+OF\s+DEPARTMENT\b|\bDEPARTMENT\s+HEAD\b|\bPROFESSOR\s*&\s*HEAD\b/',
      $designation
    ) === 1;
  }

  /**
   * Authenticate request; returns user document or sends 401.
   *
   * @return array<string, mixed>
   */
  public static function authenticate(): array
  {
    if (self::$currentUser !== null) {
      return self::$currentUser;
    }

    $userModel = new UserModel();

    $session = Security::getSessionUser();
    if ($session !== null) {
      Security::touchSession();
      $user = $userModel->findById($session['id']);
      if ($user) {
        $user = $userModel->ensureLoginReady($user);
      }
      if ($user && $userModel->canLogin($user)) {
        self::$currentUser = $user;
        return $user;
      }
    }

    Response::unauthorized('Authentication required.');
  }

  /** Optional auth — does not terminate on failure. */
  public static function optional(): ?array
  {
    try {
      $token = JwtHelper::extractFromHeader();
      if ($token !== null) {
        $payload = JwtHelper::decode($token);
        if ($payload && isset($payload['sub'])) {
          $userModel = new UserModel();
          $user = $userModel->findById((string) $payload['sub']);
          if ($user) {
            self::$currentUser = $user;
            return $user;
          }
        }
      }
      $session = Security::getSessionUser();
      if ($session !== null) {
        $userModel = new UserModel();
        return $userModel->findById($session['id']);
      }
    } catch (\Throwable) {
      return null;
    }
    return null;
  }

  public static function currentUser(): ?array
  {
    return self::$currentUser;
  }

  /**
   * @return array<string, mixed>
   */
  public static function userResponse(array $user, ?string $token = null): array
  {
    $config = require dirname(__DIR__) . '/config/app.php';
    $data = DocumentHelper::serialize($user);
    $aesService = new \PMS\Services\AesLoginService();
    $effectiveRole = self::resolvedRole($user);
    $data['role'] = $effectiveRole;
    $data['dashboard'] = $config['role_dashboards'][$effectiveRole] ?? '/public-stats.html';

    if ($effectiveRole === 'alumni') {
      $profile = (new AlumniModel())->findByUserId((string) $user['_id']);
      if ($profile) {
        $data = array_merge($data, AlumniModel::profileToUserFields($profile));
      }
      $photo = $aesService->resolveAlumniProfilePhoto($user, $profile, false);
      if (($photo['photoUrl'] ?? '') !== '') {
        $data['photoUrl'] = $photo['photoUrl'];
        $data['photo'] = $photo['photo'];
      }
    }
    if ($effectiveRole === 'company') {
      $company = (new CompanyModel())->findByUserId((string) $user['_id']);
      if ($company) {
        $data = array_merge($data, CompanyModel::profileToUserFields($company));
      }
    }
    if (($user['role'] ?? '') === 'staff') {
      $profile = \PMS\Services\StaffContext::ensureProfile($user);
      $dept = !empty($profile['departmentId'])
        ? (new DepartmentModel())->findById((string) $profile['departmentId'])
        : null;
      $data = array_merge($data, StaffModel::profileToUserFields($profile, $dept));
      $assigned = \PMS\Services\StaffContext::assignedClassBatches([
        'profile' => $profile,
        'departmentId' => (string) ($profile['departmentId'] ?? ''),
      ]);
      if ($assigned !== []) {
        $data['assignedClassBatches'] = $assigned;
      }
      $photo = (new \PMS\Services\AesLoginService())->resolveProfilePhoto($profile, $user);
      if (($photo['photoUrl'] ?? '') !== '') {
        $data['photoUrl'] = $photo['photoUrl'];
        $data['photo'] = $photo['photo'];
      }
    }
    if ($effectiveRole === 'placement_officer') {
      $profile = (new PlacementOfficerModel())->findByUserId((string) $user['_id']);
      if (!$profile && ($user['role'] ?? '') === 'staff') {
        $profile = \PMS\Services\StaffContext::ensureProfile($user);
      }
      if ($profile) {
        $dept = !empty($profile['departmentId'])
          ? (new DepartmentModel())->findById((string) $profile['departmentId'])
          : null;
        $deptCode = trim((string) ($dept['code'] ?? ''));
        $deptName = trim((string) ($dept['name'] ?? ''));
        $aesDeptId = trim((string) ($dept['aesId'] ?? ''));
        // Prefer readable code/name over numeric AES ids in the session.
        if ($deptCode !== '' && !ctype_digit($deptCode)) {
          $data['department'] = $deptCode;
        } elseif ($deptName !== '' && !ctype_digit($deptName)) {
          $data['department'] = $deptName;
        } else {
          $data['department'] = $deptCode !== '' ? $deptCode : $deptName;
        }
        $data['departmentCode'] = $deptCode;
        $data['departmentName'] = $deptName !== '' ? $deptName : $deptCode;
        $data['departmentId'] = $dept ? (string) $dept['_id'] : '';
        if ($aesDeptId !== '') {
          $data['departmentAesId'] = $aesDeptId;
        }
      }
      $staffProfile = (new StaffModel())->findByUserId((string) $user['_id']);
      $photo = (new \PMS\Services\AesLoginService())->resolveProfilePhoto($staffProfile ?? $profile, $user);
      if (($photo['photoUrl'] ?? '') !== '') {
        $data['photoUrl'] = $photo['photoUrl'];
        $data['photo'] = $photo['photo'];
      }
    }
    if (($user['role'] ?? '') === 'student') {
      $profile = (new StudentModel())->findByUserId((string) $user['_id']);
      if ($profile) {
        $dept = !empty($profile['departmentId'])
          ? (new DepartmentModel())->findById((string) $profile['departmentId'])
          : null;
        $data = array_merge($data, StudentModel::profileToUserFields($profile, $dept));
        $aesDept = (new \PMS\Services\AesLoginService())->resolveStudentDepartmentFields(
            $dept ? [
                'id'   => (string) ($dept['_id'] ?? ''),
                'code' => (string) ($dept['code'] ?? ''),
                'name' => (string) ($dept['name'] ?? ''),
            ] : null,
            (string) ($profile['registerNumber'] ?? '')
        );
        if ($aesDept['code'] !== '' || $aesDept['name'] !== '') {
          $data['department'] = $aesDept['code'];
          $data['departmentName'] = $aesDept['name'];
        }
      }
    }
    if ($token !== null) {
      $data['token'] = $token;
    }

    $aesProfile = Security::getSessionAesProfile();
    $service = new \PMS\Services\AesLoginService();
    $data = $service->applyAesSessionToUserFields($data);
    if ($effectiveRole === 'student') {
      $syncedName = $service->syncStudentNameFromPlacement(
          array_merge($user, $data),
          (string) ($data['registerNumber'] ?? '')
      );
      if ($syncedName !== '') {
        $data['name'] = $syncedName;
      }
      $studentProfile = (new StudentModel())->findByUserId((string) $user['_id']);
      if ($studentProfile) {
        $photo = is_array($studentProfile['photo'] ?? null) ? $studentProfile['photo'] : null;
        $photoUrl = is_array($photo) ? trim((string) ($photo['url'] ?? '')) : '';
        if ($photoUrl !== '' && filter_var($photoUrl, FILTER_VALIDATE_URL)) {
          $data['photoUrl'] = $photoUrl;
          $data['photo'] = [
            'url'    => $photoUrl,
            'source' => (string) ($photo['source'] ?? 'aes'),
          ];
        }
      }
    }
    if ($aesProfile !== []) {
      $aesProfile = DocumentHelper::jsonSafe($service->sanitizeAesProfileForClient($aesProfile));
      if (is_array($aesProfile)) {
        $data['aesProfile'] = $aesProfile;
      }
    }

    $data['role'] = self::resolvedRole(array_merge($user, $data));
    $data['dashboard'] = $config['role_dashboards'][$data['role']] ?? '/public-stats.html';

    $safe = DocumentHelper::jsonSafe($data);
    return is_array($safe) ? $safe : $data;
  }
}
