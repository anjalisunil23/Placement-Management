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

// Debug AES POST payload
if ($uri === '/favicon.ico') {
    header('Content-Type: image/svg+xml');
    readfile(__DIR__ . '/favicon.svg');
    return true;
}

if ($uri === '/dologin' || $uri === '/dologin.php') {
    require __DIR__ . '/dologin.php';
    return true;
}

if ($uri === '/logo.php') {
    require __DIR__ . '/logo.php';
    return true;
}

if ($uri === '/hero-bg.php') {
    require __DIR__ . '/hero-bg.php';
    return true;
}

// Home → landing page
if ($uri === '/' || $uri === '/index' || $uri === '/index.html') {
    header('Location: /public-stats.html');
    exit;
}

// Legacy public stats URLs
if ($uri === '/public/stats.html' || $uri === '/public_stats.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public-stats.html');
    return true;
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
