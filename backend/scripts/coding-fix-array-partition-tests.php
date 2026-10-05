<?php

declare(strict_types=1);

/**
 * Repair Array Partition bank test cases (run on production after deploy if hidden tests fail).
 *   php backend/scripts/coding-fix-array-partition-tests.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Models\CodingProblemBankModel;
use PMS\Services\CodingPracticeRunService;

$canonical = [
    ['id' => 's1', 'label' => 'Sample Test Case', 'sample' => true, 'input' => "4\n1 4 3 2", 'expected' => '4'],
    ['id' => 'h1', 'label' => 'Hidden Test Case 1', 'sample' => false, 'input' => "2\n1 2 3 4", 'expected' => '4'],
    ['id' => 'h2', 'label' => 'Hidden Test Case 2', 'sample' => false, 'input' => "3\n-5 -2 0 4 7 9", 'expected' => '2'],
];

$solution = <<<'PY'
n = int(input())
arr = list(map(int, input().split()))
arr.sort()
print(sum(arr[::2]))
PY;

$bank = new CodingProblemBankModel();
$updated = 0;
foreach ($bank->listProblems(null, null, 5000) as $row) {
    $title = trim((string) ($row['title'] ?? ''));
    if (stripos($title, 'Array Partition') === false) {
        continue;
    }
    $id = (string) ($row['id'] ?? '');
    if ($id === '') {
        continue;
    }
    echo 'Updating problem: ' . $title . ' (' . $id . ')' . PHP_EOL;
    $payload = $row;
    unset($payload['id']);
    $payload['testCases'] = $canonical;
    $bank->saveProblem($payload, $id);
    $updated += 1;

    $problem = CodingProblemBankModel::normalize(array_merge($payload, ['testCases' => $canonical]));
    $run = (new CodingPracticeRunService())->run($problem, 'Python', $solution, "4\n1 4 3 2", 12000);
    $passed = (int) ($run['passedCount'] ?? 0);
    $total = (int) ($run['totalCount'] ?? 0);
    echo 'Verification: ' . $passed . ' / ' . $total . ' passed' . PHP_EOL;
    foreach ($run['results'] ?? [] as $r) {
        echo '  - ' . ($r['label'] ?? '') . ': ' . (($r['passed'] ?? false) ? 'PASS' : 'FAIL')
            . ' out=' . json_encode($r['output'] ?? '')
            . ' expected=' . json_encode($r['expected'] ?? '')
            . PHP_EOL;
    }
}

if ($updated === 0) {
    echo 'No Array Partition problem found in bank.' . PHP_EOL;
    exit(1);
}

exit(0);
