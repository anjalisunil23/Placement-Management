<?php

declare(strict_types=1);

/**
 * Regression: Array Partition correct solution must pass 3/3 test cases.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodingPracticeRunService;

$problem = [
    'title' => 'Array Partition',
    'testCases' => [
        ['id' => 's1', 'sample' => true, 'input' => "4\n1 4 3 2", 'expected' => '4'],
        ['id' => 'h1', 'sample' => false, 'input' => "2\n1 2 3 4", 'expected' => '4'],
        ['id' => 'h2', 'sample' => false, 'input' => "3\n-5 -2 0 4 7 9", 'expected' => '2'],
    ],
];

$solution = <<<'PY'
n = int(input())
arr = list(map(int, input().split()))
arr.sort()
print(sum(arr[::2]))
PY;

$svc = new CodingPracticeRunService();
$run = $svc->run($problem, 'Python', $solution, "4\n1 4 3 2", 15000);

$fail = 0;
foreach ($run['results'] ?? [] as $r) {
    $label = (string) ($r['label'] ?? $r['id'] ?? '?');
    $ok = !empty($r['passed']);
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label
        . ' stdout=' . json_encode($r['output'] ?? '')
        . ' expected=' . json_encode($r['expected'] ?? '')
        . ' exit=' . (int) ($r['execution']['exitCode'] ?? -1)
        . PHP_EOL;
    if (!$ok) {
        $fail += 1;
    }
}

$pc = (int) ($run['passedCount'] ?? 0);
$tc = (int) ($run['totalCount'] ?? 0);
echo 'Summary: ' . $pc . ' / ' . $tc . PHP_EOL;

exit($fail === 0 && $pc === 3 ? 0 : 1);
