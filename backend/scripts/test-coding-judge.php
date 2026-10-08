<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodingTestCaseChecker;
use PMS\Utils\CodingExpectedOracle;
use PMS\Utils\CodingInputValidator;

$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
};

$dupProblem = [
    'testCases' => [
        ['id' => 's1', 'sample' => true, 'input' => "4\n1 2 2 4", 'expected' => 'Yes'],
        ['id' => 'h1', 'sample' => false, 'input' => "3\n1 2 3", 'expected' => 'No'],
        ['id' => 'h2', 'sample' => false, 'input' => "5\n1 2 3 4 5", 'expected' => 'No'],
    ],
];
$badToken = CodingInputValidator::validate($dupProblem, "3\n1 2 3 ferfve");
$check('invalid token not ok', empty($badToken['ok']), json_encode($badToken));
$check('invalid token mentions ferfve', str_contains((string) ($badToken['detail'] ?? ''), 'ferfve'), (string) ($badToken['detail'] ?? ''));
$short = CodingInputValidator::validate($dupProblem, "4\n1 2 3");
$check('short array not ok', empty($short['ok']), json_encode($short));
$check('short array count', str_contains((string) ($short['detail'] ?? ''), 'Received 3'), (string) ($short['detail'] ?? ''));
$okIn = CodingInputValidator::validate($dupProblem, "4\n1 2 2 5");
$check('valid custom ok', !empty($okIn['ok']), json_encode($okIn));
$three = CodingInputValidator::validate($dupProblem, "3\n1 2 3");
$oracleNo = CodingExpectedOracle::resolve($dupProblem, "3\n1 2 3", $three['parsed'] ?? null);
$check('oracle No for unique', CodingTestCaseChecker::normalize((string) ($oracleNo['expected'] ?? '')) === 'No', json_encode($oracleNo));
$five = CodingInputValidator::validate($dupProblem, "5\n1 2 3 4 5");
$oracleFive = CodingExpectedOracle::resolve($dupProblem, "5\n1 2 3 4 5", $five['parsed'] ?? null);
$check('oracle No for 5 unique', CodingTestCaseChecker::normalize((string) ($oracleFive['expected'] ?? '')) === 'No', json_encode($oracleFive));
$yesIn = CodingInputValidator::validate($dupProblem, "4\n1 2 2 5");
$oracleYes = CodingExpectedOracle::resolve($dupProblem, "4\n1 2 2 5", $yesIn['parsed'] ?? null);
$check('oracle Yes for duplicates', CodingTestCaseChecker::normalize((string) ($oracleYes['expected'] ?? '')) === 'Yes', json_encode($oracleYes));

$okExec = ['status' => 'OK', 'exit_code' => 0, 'stdout' => '', 'timedOut' => false];
$rte = ['status' => 'Runtime Error', 'exit_code' => 1, 'stdout' => '', 'timedOut' => false];
$ce = ['status' => 'Compilation Error', 'exit_code' => 1, 'stdout' => '', 'timedOut' => false];
$tle = ['status' => 'OK', 'exit_code' => -1, 'stdout' => '', 'timedOut' => true];

$check('empty stdout vs expected is not pass', CodingTestCaseChecker::passed($okExec, '', 'Yes') === false, '');
$check('empty stdout display Wrong Answer', CodingTestCaseChecker::displayStatus($okExec, false) === 'Wrong Answer', CodingTestCaseChecker::displayStatus($okExec, false));
$check('missing expected is not pass', CodingTestCaseChecker::passed($okExec, '', null) === false, '');
$check('correct output passes', CodingTestCaseChecker::passed($okExec, 'Yes', 'Yes') === true, '');
$check('trailing newline still passes', CodingTestCaseChecker::passed($okExec, "Yes\n", 'Yes') === true, '');
$check('wrong output fails', CodingTestCaseChecker::passed($okExec, 'No', 'Yes') === false, '');
$check('substring does not pass', CodingTestCaseChecker::passed($okExec, 'Yes Yes', 'Yes') === false, '');
$check('runtime not pass', CodingTestCaseChecker::passed($rte, 'Yes', 'Yes') === false, '');
$check('compile display', CodingTestCaseChecker::displayStatus($ce, false) === 'Compilation Error', '');
$check('timeout display', CodingTestCaseChecker::displayStatus($tle, false) === 'Time Limit Exceeded', '');
$check('expected 0 is defined', CodingTestCaseChecker::expectedFromTestCase(['expected' => '0']) === '0', '');
$check('output alias', CodingTestCaseChecker::expectedFromTestCase(['output' => 'Yes']) === 'Yes', '');
$check('missing expected null', CodingTestCaseChecker::expectedFromTestCase(['input' => '1']) === null, '');

$problem = [
    'testCases' => [
        ['id' => 's1', 'sample' => true, 'input' => "4\n1 2 2 5", 'expected' => 'Yes'],
        ['id' => 'h1', 'sample' => false, 'input' => "3\n1 2 3", 'expected' => 'No'],
    ],
];

try {
    $svc = new PMS\Services\CodingPracticeRunService();
    $template = "n = int(input())\narr = list(map(int, input().split()))\n# Write your logic below\n";
    $invRun = $svc->run($problem, 'Python', $template, "3\n1 2 3 ferfve", 2000);
    $check('run invalid custom', ($invRun['custom']['status'] ?? '') === 'Custom Input Error', 'status=' . ($invRun['custom']['status'] ?? ''));
    $check('invalid does not execute student code', ($invRun['custom']['execution']['engineStatus'] ?? '') === 'not_run', json_encode($invRun['custom']['execution'] ?? []));
    $check('invalid expected not sample copy', ($invRun['custom']['expected'] ?? 'x') === '', 'expected=' . ($invRun['custom']['expected'] ?? ''));

    $run = $svc->run($problem, 'Python', $template, "4\n1 2 2 5", 8000);
    $check('template custom not passed', empty($run['custom']['passed']), 'passed=' . json_encode($run['custom']['passed'] ?? null));
    $check('template custom Wrong Answer', ($run['custom']['status'] ?? '') === 'Wrong Answer', 'status=' . ($run['custom']['status'] ?? ''));
    $check('template sample not passed', empty(($run['results'][0] ?? [])['passed']), '');
    $check('template passedCount 0', (int) ($run['passedCount'] ?? -1) === 0, 'count=' . ($run['passedCount'] ?? ''));

    $unmatched = $svc->run($problem, 'Python', $template, "9\n9 9 9 9", 8000);
    $check('unmatched custom not passed', empty($unmatched['custom']['passed']), 'status=' . ($unmatched['custom']['status'] ?? ''));

    $good = "n=int(input())\na=list(map(int,input().split()))\nprint('Yes' if len(a)!=len(set(a)) else 'No')\n";
    $okRun = $svc->run($problem, 'Python', $good, "4\n1 2 2 5", 8000);
    $check('correct custom passed', !empty($okRun['custom']['passed']), 'status=' . ($okRun['custom']['status'] ?? '') . ' out=' . ($okRun['custom']['output'] ?? ''));
    $check('correct sample passed', !empty(($okRun['results'][0] ?? [])['passed']), 'status=' . (($okRun['results'][0] ?? [])['status'] ?? ''));
    $fiveRun = $svc->run($problem, 'Python', $good, "5\n1 2 3 4 5", 8000);
    $check('custom five expected No', CodingTestCaseChecker::normalize((string) ($fiveRun['custom']['expected'] ?? '')) === 'No', 'expected=' . ($fiveRun['custom']['expected'] ?? ''));
    $check('custom five passed', !empty($fiveRun['custom']['passed']), 'status=' . ($fiveRun['custom']['status'] ?? '') . ' out=' . ($fiveRun['custom']['output'] ?? ''));

    $wrong = "n=int(input())\na=list(map(int,input().split()))\nprint('No')\n";
    $badRun = $svc->run($problem, 'Python', $wrong, "4\n1 2 2 5", 8000);
    $check('wrong solution not passed', empty($badRun['custom']['passed']) && ($badRun['custom']['status'] ?? '') === 'Wrong Answer', 'status=' . ($badRun['custom']['status'] ?? ''));

    $compile = $svc->run($problem, 'Python', 'print("Hello"', "4\n1 2 2 5", 8000);
    $check(
        'syntax is compile/runtime',
        in_array($compile['custom']['status'] ?? '', ['Compilation Error', 'Syntax Error', 'Runtime Error'], true) && empty($compile['custom']['passed']),
        'status=' . ($compile['custom']['status'] ?? '')
    );
} catch (Throwable $e) {
    $check('practice executor available', false, $e->getMessage());
}

exit($fail === 0 ? 0 : 1);
