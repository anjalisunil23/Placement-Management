<?php

declare(strict_types=1);

/**
 * Serves the AJCE placement portal logo (works even when static /css/img/ is blocked).
 */
$path = __DIR__ . '/css/ajce-logo.png';
if (!is_file($path)) {
    $path = __DIR__ . '/css/img/ajce-logo.png';
}

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Logo not found';
    exit;
}

$mime = 'image/png';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
