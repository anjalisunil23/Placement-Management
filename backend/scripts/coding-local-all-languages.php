<?php

declare(strict_types=1);

/**
 * Local smoke test for all coding Run Code languages (CLI).
 * Usage: php backend/scripts/coding-local-all-languages.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
if (file_exists($root . '/.env.local')) {
    Dotenv\Dotenv::createMutable($root, '.env.local')->load();
}
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
    $out = $svc->run($language, $source, '', 8000);
    $ok = ($out['ok'] ?? false) === true;
    $stdout = trim((string) ($out['stdout'] ?? ''));
    $stderr = trim((string) ($out['stderr'] ?? ''));
    $status = (string) ($out['status'] ?? '');
    $pass = $ok && $stdout === '42';
    if (!$pass) {
        $failed += 1;
    }
    echo str_pad($language, 12) . ($pass ? 'PASS' : 'FAIL') . " status={$status} stdout=" . json_encode($stdout);
    if ($stderr !== '') {
        echo ' stderr=' . json_encode(substr($stderr, 0, 200));
    }
    echo PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
