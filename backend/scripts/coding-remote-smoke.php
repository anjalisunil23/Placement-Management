<?php

declare(strict_types=1);

/**
 * CLI smoke test: self-hosted Piston via CodeExecutionService.
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
use PMS\Services\CodingExecutionConfig;

if (!CodingExecutionConfig::remoteExecutionConfigured()) {
    fwrite(STDERR, "Set CODE_EXECUTION_URL (e.g. http://127.0.0.1:2000)\n");
    exit(2);
}

$svc = new CodeExecutionService();
$out = $svc->run(
    'C++',
    "#include <iostream>\nint main(){ std::cout << 42; return 0; }",
    '',
    5000
);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($out['ok'] ?? false) ? 0 : 1);
