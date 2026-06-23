<?php

declare(strict_types=1);

/**
 * Router for PHP built-in development server:
 *   php -S localhost:8080 router.php
 *
 * Serves static HTML/CSS/JS and routes API requests to the PHP backend.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

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

// AES institute SSO callback (login.aesajce.in)
if (preg_match('#^/(callback|aes-callback)\.php$#', $uri)) {
    require __DIR__ . $uri;
    return true;
}

// Home → landing page
if ($uri === '/' || $uri === '/index' || $uri === '/index.html') {
    header('Location: /public-stats.html');
    exit;
}

// Existing files (css, js, images, .html, etc.)
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

// Extensionless routes: /public-stats → public-stats.html
if (!pathinfo($uri, PATHINFO_EXTENSION)) {
    $htmlFile = __DIR__ . rtrim($uri, '/') . '.html';
    if (is_file($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($htmlFile);
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<h1>404 — Not found</h1><p><a href="/public-stats.html">Back to home</a></p>';
return true;
