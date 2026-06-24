<?php

declare(strict_types=1);

/**
 * Serves the AJCE campus hero background (works when static /css/img/ is blocked).
 */
$path = __DIR__ . '/css/ajce-campus-hero.png';
if (!is_file($path)) {
    $path = __DIR__ . '/css/img/ajce-campus-hero.png';
}

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Hero background not found';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
