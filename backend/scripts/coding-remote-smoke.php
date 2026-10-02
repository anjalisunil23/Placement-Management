<?php

declare(strict_types=1);

/**
 * CLI smoke test: local miss → Wandbox remote (run on server after deploy).
 * Usage: php backend/scripts/coding-remote-smoke.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;

$svc = new CodeExecutionService();
$out = $svc->run(
    'C++',
    "#include <iostream>\nint main(){ std::cout << 42; return 0; }",
    '',
    5000
);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($out['ok'] ?? false) ? 0 : 1);
