<?php

declare(strict_types=1);

namespace PMS\Middleware;

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

    // Try JWT first
    $token = JwtHelper::extractFromHeader();
    if ($token !== null) {
      $payload = JwtHelper::decode($token);
      if ($payload && isset($payload['sub'])) {
        $user = $userModel->findById((string) $payload['sub']);
        if ($user && ($user['status'] ?? '') === 'active' && ($user['approved'] ?? false)) {
          self::$currentUser = $user;
          return $user;
        }
      }
      Response::unauthorized('Invalid or expired token.');
    }

    // Fall back to session
    $session = Security::getSessionUser();
    if ($session !== null) {
      $user = $userModel->findById($session['id']);
      if ($user && ($user['status'] ?? '') === 'active' && ($user['approved'] ?? false)) {
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
    $data['dashboard'] = $config['role_dashboards'][$user['role']] ?? '/frontend/pages/login.html';
    if ($token !== null) {
      $data['token'] = $token;
    }
    return $data;
  }
}
