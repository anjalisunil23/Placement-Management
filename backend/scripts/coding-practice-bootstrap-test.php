<?php

declare(strict_types=1);

/**
 * Simulates cPanel API bootstrap (bootstrap-services only, no index.php util list).
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
};

$check('CodingExecutionDebug class', class_exists(\PMS\Utils\CodingExecutionDebug::class, false), '');
$check('pms_coding_exec_debug_log', function_exists('pms_coding_exec_debug_log'), '');

$problem = [
    'testCases' => [
        ['id' => 's1', 'sample' => true, 'input' => "4\n1 4 3 2", 'expected' => '4'],
    ],
];
$code = "n = int(input())\narr = list(map(int, input().split()))\n";
try {
    $run = (new PMS\Services\CodingPracticeRunService())->run($problem, 'Python', $code, "4\n1 4 3 2", 8000);
    $check('practice run completes', true, '');
    $check('not Runtime Error', ($run['custom']['status'] ?? '') !== 'Runtime Error', 'status=' . ($run['custom']['status'] ?? ''));
    $check('Execution Successful', ($run['custom']['status'] ?? '') === 'Execution Successful', '');
    $check('execution.succeeded', !empty($run['custom']['execution']['succeeded']), '');
} catch (\Throwable $e) {
    $check('practice run completes', false, $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
