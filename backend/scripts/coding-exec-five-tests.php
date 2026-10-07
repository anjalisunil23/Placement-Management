<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;
use PMS\Services\CodingPracticeRunService;
use PMS\Services\CodingTestCaseChecker;

$exec = new CodeExecutionService();
$in = "4\n1 4 3 2";
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
};

$problem = ['testCases' => [['id' => 's1', 'sample' => true, 'input' => $in, 'expected' => '4']]];

$t1 = "n = int(input())\narr = list(map(int, input().split()))\n";
$r1 = $exec->run('Python', $t1, $in, 15000);
$pr1 = (new CodingPracticeRunService($exec))->run($problem, 'Python', $t1, $in, 15000);
$check('TEST1 status OK', ($r1['status'] ?? '') === 'OK' && ($r1['ok'] ?? false) === true, json_encode($r1));
$check('TEST1 verdict not Runtime', CodingTestCaseChecker::verdictFromExecution($r1, '4') !== 'Runtime Error', CodingTestCaseChecker::verdictFromExecution($r1, '4'));
$check('TEST1 practice Wrong Answer', ($pr1['custom']['status'] ?? '') === 'Wrong Answer' && empty($pr1['custom']['passed']), 'status=' . ($pr1['custom']['status'] ?? ''));

$t2 = "n = int(input())\narr = list(map(int, input().split()))\narr.sort()\nanswer = sum(arr[::2])\nprint(answer)\n";
$r2 = $exec->run('Python', $t2, $in, 15000);
$check('TEST2 Accepted', CodingTestCaseChecker::verdictFromExecution($r2, '4') === 'Accepted', 'out=' . trim((string) ($r2['stdout'] ?? '')));

$t3 = "n = int(input())\narr = list(map(int, input().split()))\nprint(arr[10])\n";
$r3 = $exec->run('Python', $t3, $in, 15000);
$check('TEST3 Runtime Error', ($r3['status'] ?? '') === 'Runtime Error', 'status=' . ($r3['status'] ?? ''));

$t4 = "n, x, _ = map(int, input().split())\n";
$r4 = $exec->run('Python', $t4, "5\n", 15000);
$check('TEST4 Runtime Error', ($r4['status'] ?? '') === 'Runtime Error', 'stderr=' . substr((string) ($r4['stderr'] ?? ''), 0, 80));

$t5 = 'print("Hello"';
$r5 = $exec->run('Python', $t5, '', 15000);
$check('TEST5 compile/syntax', in_array($r5['status'] ?? '', ['Compilation Error', 'Syntax Error', 'Runtime Error'], true), 'status=' . ($r5['status'] ?? ''));

$check('Practice overall not Runtime', ($pr1['overall'] ?? '') !== 'Runtime Error', 'overall=' . ($pr1['overall'] ?? ''));

exit($fail === 0 ? 0 : 1);
