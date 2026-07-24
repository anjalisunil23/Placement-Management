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
    if ($role === 'staff' && self::isHod($user)) {
      return 'placement_officer';
    }

    return $role;
  }

  /**
   * Staff whose designation / AES profile is Head of Department (HOD).
   * They get placement_officer dashboard as the default department PO.
   */
  public static function isHod(array $user): bool
  {
    $role = trim((string) ($user['role'] ?? ''));
    // HOD accounts stay role=staff in DB; assigned POs use placement_officer.
    if ($role !== 'staff') {
      return false;
    }

    if (($user['isHod'] ?? false) === true
      || ($user['isHod'] ?? null) === 1
      || ($user['isHod'] ?? null) === '1') {
      return true;
    }

    try {
      Security::startSession();
      if (!empty($_SESSION['ph_is_hod'])) {
        return true;
      }
    } catch (\Throwable) {
      // Session may be unavailable on CLI.
    }

    $designation = trim((string) ($user['designation'] ?? ''));
    if (\PMS\Services\HodDetection::designationLooksLikeHod($designation)) {
      return true;
    }

    try {
      $profile = \PMS\Services\StaffContext::ensureProfile($user);
    } catch (\Throwable) {
      $profile = [];
    }

    if (($profile['isHod'] ?? false) === true || ($profile['isHod'] ?? null) === 1 || ($profile['isHod'] ?? null) === '1') {
      return true;
    }

    $profileDesig = (string) ($profile['designation'] ?? '');
    if (\PMS\Services\HodDetection::designationLooksLikeHod($profileDesig)) {
      return true;
    }

    $aes = Security::getSessionAesProfile();
    if (is_array($aes) && $aes !== []) {
      if (!empty($aes['isHod']) || \PMS\Services\HodDetection::payloadIndicatesHod($aes)) {
        return true;
      }
    }

    if (\PMS\Services\AjceHodDirectory::userIsHod($user)) {
      return true;
    }

    return false;
  }

  /** @deprecated Use isHod() */
  private static function isHodStaff(array $user): bool
  {
    return self::isHod($user);
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
  /**
   * @param array<string, mixed> $user
   * @param array{fast?:bool}|null $opts fast=true skips AES name/dept enrichment (post-login boot)
   * @return array<string, mixed>
   */
  public static function userResponse(array $user, ?string $token = null, ?array $opts = null): array
  {
    $fast = !empty($opts['fast']);
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
      $profile = $fast
        ? (new StaffModel())->findByUserId((string) $user['_id'])
        : \PMS\Services\StaffContext::ensureProfile($user);
      if ($profile) {
        $dept = !empty($profile['departmentId'])
          ? (new DepartmentModel())->findById((string) $profile['departmentId'])
          : null;
        $data = array_merge($data, StaffModel::profileToUserFields($profile, $dept));
        if (!$fast) {
          $assigned = \PMS\Services\StaffContext::assignedClassBatches([
            'profile' => $profile,
            'departmentId' => (string) ($profile['departmentId'] ?? ''),
          ]);
          if ($assigned !== []) {
            $data['assignedClassBatches'] = $assigned;
          }
        } elseif (!empty($profile['assignedClassBatches']) && is_array($profile['assignedClassBatches'])) {
          $data['assignedClassBatches'] = array_values($profile['assignedClassBatches']);
        }
        $photo = $aesService->resolveProfilePhoto($profile, $user);
        if (($photo['photoUrl'] ?? '') !== '') {
          $data['photoUrl'] = $photo['photoUrl'];
          $data['photo'] = $photo['photo'];
        }
      }
    }
    if ($effectiveRole === 'placement_officer') {
      $profile = (new PlacementOfficerModel())->findByUserId((string) $user['_id']);
      if (!$profile && ($user['role'] ?? '') === 'staff') {
        $profile = $fast
          ? (new StaffModel())->findByUserId((string) $user['_id'])
          : \PMS\Services\StaffContext::ensureProfile($user);
      }
      if ($profile) {
        $dept = !empty($profile['departmentId'])
          ? (new DepartmentModel())->findById((string) $profile['departmentId'])
          : null;
        $deptCode = trim((string) ($dept['code'] ?? ''));
        $deptName = trim((string) ($dept['name'] ?? ''));
        $aesDeptId = trim((string) ($dept['aesId'] ?? ''));
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
      $photo = $aesService->resolveProfilePhoto($staffProfile ?? $profile, $user);
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
        if (!$fast) {
          $aesDept = $aesService->resolveStudentDepartmentFields(
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
        $photo = is_array($profile['photo'] ?? null) ? $profile['photo'] : null;
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
    if ($token !== null) {
      $data['token'] = $token;
    }

    $aesProfile = Security::getSessionAesProfile();
    $data = $aesService->applyAesSessionToUserFields($data);
    if ($effectiveRole === 'student' && !$fast) {
      $syncedName = $aesService->syncStudentNameFromPlacement(
          array_merge($user, $data),
          (string) ($data['registerNumber'] ?? '')
      );
      if ($syncedName !== '') {
        $data['name'] = $syncedName;
      }
    }
    if ($aesProfile !== []) {
      $aesProfile = DocumentHelper::jsonSafe($aesService->sanitizeAesProfileForClient($aesProfile));
      if (is_array($aesProfile)) {
        $data['aesProfile'] = $aesProfile;
      }
    }

    $data['role'] = self::resolvedRole(array_merge($user, $data));
    $data['dashboard'] = $config['role_dashboards'][$data['role']] ?? '/public-stats.html';
    $data['isHod'] = self::isHod(array_merge($user, $data));
    if ($data['isHod']) {
      $data['role'] = 'placement_officer';
      $data['dashboard'] = $config['role_dashboards']['placement_officer'] ?? $data['dashboard'];
      $directoryHod = \PMS\Services\AjceHodDirectory::matchUser(array_merge($user, $data));
      $desig = (string) ($data['designation'] ?? '');
      if (!\PMS\Services\HodDetection::designationLooksLikeHod($desig) && $directoryHod !== null) {
        $desig = (string) $directoryHod['designation'];
      }
      $data['designation'] = \PMS\Services\HodDetection::normalizeDesignationForHod($desig, true);
      // Persist HOD flag + designation so elevation works without AES session next time.
      try {
        $staffModel = new StaffModel();
        $staff = $staffModel->findByUserId((string) ($user['_id'] ?? ''));
        if ($staff) {
          $needsDesig = !\PMS\Services\HodDetection::designationLooksLikeHod((string) ($staff['designation'] ?? ''));
          $needsFlag = empty($staff['isHod']);
          if ($needsDesig || $needsFlag) {
            $patch = ['isHod' => true];
            if ($needsDesig) {
              $patch['designation'] = (string) $data['designation'];
            }
            $staffModel->updateProfile((string) $staff['_id'], $patch);
          }
        }
      } catch (\Throwable) {
        // Non-fatal — role elevation still applies for this request.
      }
    }

    $safe = DocumentHelper::jsonSafe($data);
    return is_array($safe) ? $safe : $data;
  }
}
