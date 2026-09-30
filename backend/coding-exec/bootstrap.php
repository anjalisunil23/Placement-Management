<?php

declare(strict_types=1);

/** Minimal bootstrap for coding-exec CLI worker (isolated from web FPM). */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php\n");
    exit(2);
}
require_once $autoload;

require_once dirname(__DIR__) . '/bootstrap-services.php';
$backendDir = dirname(__DIR__);
pms_load_backend_services($backendDir);

if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
if (file_exists($root . '/.env.local')) {
    Dotenv\Dotenv::createMutable($root, '.env.local')->load();
}
