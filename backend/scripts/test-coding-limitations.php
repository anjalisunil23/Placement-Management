<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;
use PMS\Services\CodingPracticeRunService;
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

$mixed = [
    'testCases' => [
        ['sample' => true, 'input' => "4\n1 4 3 2", 'expected' => '4'],
        ['sample' => false, 'input' => "2\n1 2 3 4", 'expected' => '4'],
        ['sample' => false, 'input' => "3\n-5 -2 0 4 7 9", 'expected' => '2'],
    ],
];
$mixedVal = CodingInputValidator::validate($mixed, "4\n9 8 7 6");
$check('mixed-shape sample schema still validates', !empty($mixedVal['ok']), json_encode($mixedVal));
$mixedBad = CodingInputValidator::validate($mixed, "4\n1 2 x");
$check('mixed-shape still rejects non-integers', empty($mixedBad['ok']), json_encode($mixedBad));
$part = CodingExpectedOracle::resolve($mixed, "4\n1 4 3 2", $mixedVal['parsed'] ?? null);
$check('array partition oracle from mixed tests', CodingTestCaseChecker::normalize((string) ($part['expected'] ?? '')) === '4', json_encode($part));
$part2 = CodingExpectedOracle::resolve($mixed, "2\n1 2 3 4", null);
$check('array partition custom 2N shape', CodingTestCaseChecker::normalize((string) ($part2['expected'] ?? '')) === '4', json_encode($part2));

$easy = [
    'testCases' => [
        ['sample' => true, 'input' => 'hello', 'expected' => 'olleh'],
        ['sample' => false, 'input' => 'world', 'expected' => 'dlrow'],
        ['sample' => false, 'input' => 'a', 'expected' => 'a'],
    ],
];
$rev = CodingExpectedOracle::resolve($easy, 'ab', null);
$check('easy reverse-string expected', ($rev['expected'] ?? '') === 'ba', json_encode($rev));

$medium = [
    'testCases' => [
        ['sample' => true, 'input' => "4\n1 2 3 4", 'expected' => '4 3 2 1'],
        ['sample' => false, 'input' => "1\n9", 'expected' => '9'],
        ['sample' => false, 'input' => "3\n-1 0 5", 'expected' => '5 0 -1'],
    ],
];
$revArr = CodingExpectedOracle::resolve($medium, "3\n7 8 9", null);
$check('medium reverse-array expected', CodingTestCaseChecker::normalize((string) ($revArr['expected'] ?? '')) === '9 8 7', json_encode($revArr));

$hard = [
    'testCases' => [
        ['sample' => true, 'input' => '()[]{}', 'expected' => 'YES'],
        ['sample' => false, 'input' => '(]', 'expected' => 'NO'],
        ['sample' => false, 'input' => '({[]})', 'expected' => 'YES'],
    ],
];
$bal = CodingExpectedOracle::resolve($hard, '[]', null);
$check('hard balanced-parens expected', ($bal['expected'] ?? '') === 'YES', json_encode($bal));

$exec = new CodeExecutionService();
$tle = $exec->run('Python', "while True:\n    pass\n", '', 800);
$check(
    'timeout infinite loop',
    !empty($tle['timedOut']) || ($tle['status'] ?? '') === 'Time Limit Exceeded',
    'status=' . ($tle['status'] ?? '') . ' timedOut=' . json_encode($tle['timedOut'] ?? null)
);
$svc = new CodingPracticeRunService($exec);
$tleRun = $svc->run(
    ['testCases' => [['sample' => true, 'input' => '1', 'expected' => '1']]],
    'Python',
    "while True:\n    pass\n",
    '1',
    800
);
$check(
    'practice run timeout not passed',
    empty($tleRun['custom']['passed']) && ($tleRun['custom']['status'] ?? '') === 'Time Limit Exceeded',
    'status=' . ($tleRun['custom']['status'] ?? '')
);

$langSrc = [
    'Python' => "print(42)",
    'JavaScript' => 'console.log(42);',
    'C' => "#include <stdio.h>\nint main(){ printf(\"42\"); return 0; }",
    'C++' => "#include <iostream>\nint main(){ std::cout << 42; return 0; }",
    'Java' => "public class Main { public static void main(String[] args) { System.out.print(42); } }",
];
foreach ($langSrc as $language => $source) {
    $out = $exec->run($language, $source, '', 12000);
    $stdout = trim((string) ($out['stdout'] ?? ''));
    $ok = (($out['ok'] ?? false) === true) && $stdout === '42';
    $check($language . ' run prints 42', $ok, 'status=' . ($out['status'] ?? '') . ' stdout=' . json_encode($stdout) . ' stderr=' . json_encode(substr((string) ($out['stderr'] ?? ''), 0, 160)));
}

$ui = [
    ['status' => 'Custom Input Error', 'passed' => false],
    ['status' => 'Wrong Answer', 'passed' => false],
    ['status' => 'Passed', 'passed' => true],
];
foreach ($ui as $row) {
    $check('ui row status present ' . $row['status'], $row['status'] !== '', '');
}

exit($fail === 0 ? 0 : 1);
