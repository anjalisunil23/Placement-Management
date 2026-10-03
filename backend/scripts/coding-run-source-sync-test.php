<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodingPracticeRunService;
use PMS\Services\CodingTestCaseChecker;
use PMS\Services\CodeExecutionService;

$problem = [
    'testCases' => [
        ['id' => 's1', 'sample' => true, 'input' => "4\n1 4 3 2", 'expected' => '4'],
        ['id' => 'h1', 'sample' => false, 'input' => "2\n1 1", 'expected' => '0'],
    ],
];

$svc = new CodingPracticeRunService();
$exec = new CodeExecutionService();
$fail = 0;

$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
};

$in = "4\n1 4 3 2";

// CASE 1
$code1 = "n = int(input())\narr = list(map(int, input().split()))\n\n# Write your logic below\n";
$r1 = $exec->run('Python', $code1, $in, 8000);
$check('CASE 1 execute status OK', ($r1['status'] ?? '') === 'OK', 'status=' . ($r1['status'] ?? ''));

$run1 = $svc->run($problem, 'Python', $code1, $in, 8000);
$sample1 = $run1['results'][0] ?? [];
$check('CASE 1 sample not Runtime Error', ($sample1['status'] ?? '') !== 'Runtime Error', 'status=' . ($sample1['status'] ?? ''));

// CASE 2
$code2 = "n = int(input())\narr = list(map(int, input().split()))\narr.sort()\nprint(sum(arr[::2]))\n";
$r2 = $exec->run('Python', $code2, $in, 8000);
$check('CASE 2 stdout 4', trim((string) ($r2['stdout'] ?? '')) === '4', 'out=' . trim((string) ($r2['stdout'] ?? '')));
$ver2 = CodingTestCaseChecker::verdictFromExecution($r2, '4');
$check('CASE 2 Accepted', $ver2 === 'Accepted', 'verdict=' . $ver2);

// CASE 3
$code3 = "n, x, _ = map(int, input().split())\n";
$r3 = $exec->run('Python', $code3, "5\n", 8000);
$check('CASE 3 Runtime Error', ($r3['status'] ?? '') === 'Runtime Error', 'status=' . ($r3['status'] ?? ''));

// CASE 4 — empty custom stdin uses sample input (no false Runtime Error on output)
$run4 = $svc->run($problem, 'Python', $code1, '', 8000);
$check('CASE 4 overall not Runtime Error', ($run4['overall'] ?? '') !== 'Runtime Error', 'overall=' . ($run4['overall'] ?? ''));
$check('CASE 4 custom Wrong Answer', ($run4['custom']['status'] ?? '') === 'Wrong Answer', 'status=' . ($run4['custom']['status'] ?? ''));

exit($fail === 0 ? 0 : 1);
