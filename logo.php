<?php

declare(strict_types=1);

/**
 * Serves the AJCE placement portal logo (works even when static /css/img/ is blocked).
 */
$candidates = [
    __DIR__ . '/css/img/ajce-logo.jpg',
    __DIR__ . '/css/img/ajce-logo.png',
    __DIR__ . '/ajce-logo.jpg',
    __DIR__ . '/ajce-logo.png',
];

$path = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo not found';
    exit;
}

$mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
