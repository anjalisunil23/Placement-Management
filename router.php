<?php

declare(strict_types=1);

/**
 * Router for PHP built-in development server:
 *   php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// REST API
if (preg_match('#^/backend/api#', $uri)) {
    require __DIR__ . '/backend/api/index.php';
    return true;
}

// Legacy auth PHP files
if (preg_match('#^/backend/auth/(login|register|logout)\.php$#', $uri)) {
    require __DIR__ . $uri;
    return true;
}

// Static files (frontend, uploads)
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // let built-in server serve the file
}

// Default: login page
if ($uri === '/' || $uri === '') {
    header('Location: /frontend/pages/login.html');
    exit;
}

return false;
