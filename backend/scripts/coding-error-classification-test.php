<?php

declare(strict_types=1);

/**
 * Verdict + error presentation smoke tests for Run Code / grading.
 * Usage: php backend/scripts/coding-error-classification-test.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/utils/CodingExecutionErrorFormatter.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;
use PMS\Services\CodingTestCaseChecker;
use PMS\Utils\CodingExecutionErrorFormatter;

$svc = new CodeExecutionService();
$fail = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name;
    if ($detail !== '') {
        echo ' — ' . $detail;
    }
    echo PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
};

// Test 1 — Python runtime error (unpack)
$src1 = "n, x, _ = map(int, input().split())\nprint(n)\n";
$in1 = "5\n";
$exec1 = $svc->run('Python', $src1, $in1, 8000);
$ver1 = CodingTestCaseChecker::verdictFromExecution($exec1, '5');
$check(
    'Test 1 Python runtime error',
    $ver1 === 'Runtime Error'
        && str_contains((string) ($exec1['errorSummary'] ?? ''), 'expected 3')
        && str_contains((string) ($exec1['errorDetail'] ?? ''), 'ValueError'),
    'verdict=' . $ver1 . ' summary=' . substr((string) ($exec1['errorSummary'] ?? ''), 0, 80)
);

// Test 2 — Python accepted
$src2 = "n, x, _ = map(int, input().split())\nprint(n + x)\n";
$in2 = "5 10 20\n";
$exec2 = $svc->run('Python', $src2, $in2, 8000);
$ver2 = CodingTestCaseChecker::verdictFromExecution($exec2, '15');
$check(
    'Test 2 Python accepted',
    $ver2 === 'Accepted' && trim((string) ($exec2['stdout'] ?? '')) === '15',
    'verdict=' . $ver2 . ' out=' . trim((string) ($exec2['stdout'] ?? ''))
);

// Test 3 — Python syntax error
$src3 = "print(\"Hello\"\n";
$exec3 = $svc->run('Python', $src3, '', 8000);
$ver3 = CodingTestCaseChecker::verdictFromExecution($exec3, 'Hello');
$check(
    'Test 3 Python syntax error',
    $ver3 === 'Compilation Error' || (string) ($exec3['status'] ?? '') === 'Compilation Error',
    'verdict=' . $ver3 . ' status=' . (string) ($exec3['status'] ?? '')
);

// Test 4 — Wrong answer
$src4 = "print(1)\n";
$exec4 = $svc->run('Python', $src4, '', 8000);
$ver4 = CodingTestCaseChecker::verdictFromExecution($exec4, '2');
$check('Test 4 Wrong answer', $ver4 === 'Wrong Answer', 'verdict=' . $ver4);

// Test 5 — C++ compilation error
$src5 = "#include <iostream>\nint main(){ std::cout << 1 return 0; }\n";
$exec5 = $svc->run('C++', $src5, '', 12000);
$ver5 = CodingTestCaseChecker::verdictFromExecution($exec5, '1');
$check(
    'Test 5 C++ compilation error',
    $ver5 === 'Compilation Error' || (string) ($exec5['status'] ?? '') === 'Compilation Error',
    'verdict=' . $ver5 . ' status=' . (string) ($exec5['status'] ?? '')
);

// Test 6 — C++ runtime (divide by zero — may vary by platform; use abort/null deref alternative)
$src6 = "#include <cstdlib>\nint main(){ abort(); return 0; }\n";
$exec6 = $svc->run('C++', $src6, '', 12000);
$ver6 = CodingTestCaseChecker::verdictFromExecution($exec6, '0');
$check(
    'Test 6 C++ runtime error',
    $ver6 === 'Runtime Error' || (string) ($exec6['status'] ?? '') === 'Runtime Error',
    'verdict=' . $ver6 . ' status=' . (string) ($exec6['status'] ?? '')
);

// Formatter unit (no sandbox)
$mock = CodingExecutionErrorFormatter::enrich([
    'ok' => false,
    'status' => 'Runtime Error',
    'stderr' => "Traceback...\nValueError: not enough values to unpack (expected 3, got 1)",
], 'Python');
$check(
    'Formatter unpack summary',
    str_contains((string) ($mock['errorSummary'] ?? ''), 'expected 3'),
    (string) ($mock['errorSummary'] ?? '')
);

exit($fail === 0 ? 0 : 1);
