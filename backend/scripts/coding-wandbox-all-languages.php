<?php

declare(strict_types=1);

/**
 * Production-style smoke test: Wandbox only (no local compilers).
 * Usage: php backend/scripts/coding-wandbox-all-languages.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
putenv('CODING_EXECUTOR=remote_only');
putenv('CODING_REMOTE_BACKENDS=wandbox');
putenv('CODING_WANDBOX_ENABLED=true');
if (PHP_OS_FAMILY === 'Windows' && !isset($_ENV['CODING_CACERT_PATH'])) {
    putenv('CODING_HTTP_SSL_VERIFY=false');
    $_ENV['CODING_HTTP_SSL_VERIFY'] = 'false';
}
$_ENV['CODING_EXECUTOR'] = 'remote_only';
$_ENV['CODING_REMOTE_BACKENDS'] = 'wandbox';
$_ENV['CODING_WANDBOX_ENABLED'] = 'true';

require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;

$samples = [
    'Python' => "print(42)",
    'JavaScript' => 'console.log(42);',
    'C' => "#include <stdio.h>\nint main(){ printf(\"42\"); return 0; }",
    'C++' => "#include <iostream>\nint main(){ std::cout << 42; return 0; }",
    'Java' => "public class Main { public static void main(String[] args) { System.out.print(42); } }",
];

$svc = new CodeExecutionService();
$failed = 0;
foreach ($samples as $language => $source) {
    $out = $svc->run($language, $source, '', 12000);
    $ok = ($out['ok'] ?? false) === true;
    $stdout = trim((string) ($out['stdout'] ?? ''));
    $engine = (string) ($out['execEngine'] ?? $out['execBackend'] ?? '');
    $pass = $ok && $stdout === '42';
    if (!$pass) {
        $failed += 1;
    }
    echo str_pad($language, 12) . ($pass ? 'PASS' : 'FAIL');
    echo ' engine=' . ($engine !== '' ? $engine : '?');
    echo ' status=' . (string) ($out['status'] ?? '');
    if (!$pass) {
        echo ' stderr=' . json_encode(substr((string) ($out['stderr'] ?? ''), 0, 180));
    }
    echo PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
