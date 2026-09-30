<?php

declare(strict_types=1);

/**
 * Integration checks for CodeExecutionService + CodingTestCaseChecker.
 * Requires CODE_EXECUTION_URL (self-hosted Piston) for remote tests.
 *
 * Usage: php backend/scripts/coding-exec-test.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;
use PMS\Services\CodingExecutionConfig;
use PMS\Services\CodingTestCaseChecker;

$svc = new CodeExecutionService();
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $failed;
    if ($cond) {
        echo "PASS  {$label}\n";
    } else {
        echo "FAIL  {$label}\n";
        $failed++;
    }
}

if (!CodingExecutionConfig::remoteExecutionConfigured()) {
    echo "SKIP  remote tests (set CODE_EXECUTION_URL to self-hosted Piston)\n";
    exit(0);
}

$cppOk = $svc->run('C++', "#include <iostream>\nusing namespace std;\nint main(){int n;cin>>n;cout<<n;return 0;}", "4\n", 5000);
assertTrue(($cppOk['ok'] ?? false) === true && trim((string) $cppOk['stdout']) === '4', 'C++ run sample input 4');

$pyOk = $svc->run('Python', "n=int(input())\nprint(n)", "42\n", 5000);
assertTrue(($pyOk['ok'] ?? false) === true && trim((string) $pyOk['stdout']) === '42', 'Python run');

$jsOk = $svc->run('JavaScript', "const fs=require('fs');console.log(fs.readFileSync(0,'utf8').trim());", "hi\n", 5000);
assertTrue(($jsOk['ok'] ?? false) === true && trim((string) $jsOk['stdout']) === 'hi', 'JavaScript run');

$javaSrc = "import java.util.Scanner;\npublic class Main {\n  public static void main(String[] a){ System.out.print(new Scanner(System.in).nextInt()); }\n}\n";
$javaOk = $svc->run('Java', $javaSrc, "7\n", 8000);
assertTrue(($javaOk['ok'] ?? false) === true && trim((string) $javaOk['stdout']) === '7', 'Java run');

$compileFail = $svc->run('C++', "int main( {", '', 5000);
assertTrue(($compileFail['status'] ?? '') === 'Compilation Error', 'C++ compilation error');

$runtimeFail = $svc->run('Python', "raise RuntimeError('x')", '', 5000);
assertTrue(($runtimeFail['status'] ?? '') === 'Runtime Error', 'Python runtime error');

$wa = $svc->run('Python', "print(1)", '', 5000);
$verdict = CodingTestCaseChecker::verdictFromExecution($wa, '2');
assertTrue($verdict === 'Wrong Answer', 'Wrong answer verdict');

$acc = $svc->run('Python', "print(4)", '', 5000);
assertTrue(CodingTestCaseChecker::verdictFromExecution($acc, '4') === 'Accepted', 'Accepted verdict');

$timeout = $svc->run('Python', "while True: pass", '', 1500);
assertTrue(
    ($timeout['status'] ?? '') === 'Time Limit Exceeded' || !empty($timeout['timedOut']),
    'Infinite loop timeout'
);

$partitionInput = "4\n1 4 3 2\n";
$partitionPy = <<<'PY'
n = int(input())
arr = list(map(int, input().split()))
arr.sort()
print(sum(arr[i] for i in range(0, n, 2)))
PY;
$part = $svc->run('Python', $partitionPy, $partitionInput, 5000);
assertTrue(
    CodingTestCaseChecker::verdictFromExecution($part, '4') === 'Accepted',
    'Array-style partition sample (N then N ints → 4)'
);

echo $failed === 0 ? "All tests passed.\n" : "{$failed} test(s) failed.\n";
exit($failed === 0 ? 0 : 1);
