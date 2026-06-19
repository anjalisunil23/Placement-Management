<?php

declare(strict_types=1);

namespace PMS\Middleware;

use PMS\Models\DepartmentModel;
use PMS\Models\PlacementOfficerModel;
use PMS\Models\StaffModel;
use PMS\Models\UserModel;
use PMS\Utils\DocumentHelper;
use PMS\Utils\Response;
use PMS\Utils\Security;

/**
 * Authentication middleware — validates JWT or PHP session.
 */
final class AuthMiddleware
{
  private static ?array $currentUser = null;

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
      $user = $userModel->findById($session['id']);
      $role = $user['role'] ?? '';
      $active = ($user['status'] ?? '') === 'active';
      $approved = ($user['approved'] ?? false) || $role === 'admin';
      if ($user && $active && $approved) {
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
        $data['dashboard'] = $config['role_dashboards'][$user['role']] ?? '/login.html';
        if (($user['role'] ?? '') === 'staff') {
            $profile = (new StaffModel())->findByUserId((string) $user['_id']);
            if ($profile) {
                $dept = !empty($profile['departmentId'])
                    ? (new DepartmentModel())->findById((string) $profile['departmentId'])
                    : null;
                $data['designation'] = $profile['designation'] ?? '';
                $data['department'] = $dept['code'] ?? $dept['name'] ?? '';
                $data['departmentId'] = $dept ? (string) $dept['_id'] : '';
            }
        }
        if (($user['role'] ?? '') === 'placement_officer') {
            $profile = (new PlacementOfficerModel())->findByUserId((string) $user['_id']);
            if ($profile) {
                $dept = !empty($profile['departmentId'])
                    ? (new DepartmentModel())->findById((string) $profile['departmentId'])
                    : null;
                $data['department'] = $dept['code'] ?? $dept['name'] ?? '';
                $data['departmentId'] = $dept ? (string) $dept['_id'] : '';
            }
        }
        if ($token !== null) {
      $data['token'] = $token;
    }
    return $data;
  }
}
