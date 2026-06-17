<?php

declare(strict_types=1);

/**
 * Legacy auth endpoints — delegate to REST API.
 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Auth\AuthController;

$action = basename($_SERVER['SCRIPT_NAME'], '.php');
$controller = new AuthController();

match ($action) {
    'login'    => $controller->login(),
    'register' => $controller->register(),
    'logout'   => $controller->logout(),
    default    => http_response_code(404),
};
