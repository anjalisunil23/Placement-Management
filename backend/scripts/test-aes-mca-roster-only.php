<?php

declare(strict_types=1);

/** AES-only roster probe (no MariaDB). */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\AesApiService;
use PMS\Services\ClassInchargeRegistry;
use PMS\Services\DepartmentProgrammeCatalog;
use PMS\Services\PlacementFilterService;

$program = trim((string) ($argv[1] ?? 'MCA'));
$batchArg = trim((string) ($argv[2] ?? ''));

$ctx = [
    'department' => [
        'name' => 'Computer Applications',
        'code' => 'MCA',
    ],
];

echo "AES roster probe (no DB)\n";
echo "Program: {$program}\n";

$authKey = $_ENV['AES_AUTH_KEY'] ?? '';
if (is_array($authKey)) {
    $authKey = (string) ($authKey[0] ?? '');
}
$authKey = trim((string) $authKey);
echo 'AES_AUTH_KEY configured: ' . ($authKey !== '' ? 'yes' : 'no') . "\n";

$aes = new AesApiService();
$deptList = $aes->loadDepartmentsFromApi();
echo 'AES departments loaded: ' . count($deptList) . "\n";
if ($deptList === [] && $authKey === '') {
    echo "Cannot call AES without AES_AUTH_KEY in .env\n";
    exit(1);
}

$deptAesId = (new PlacementFilterService())->resolveParentDeptAesId($ctx);
echo 'Resolved AES department id: ' . ($deptAesId !== '' ? $deptAesId : 'FAILED') . "\n";
if ($deptAesId === '') {
    foreach ($deptList as $row) {
        $n = (string) ($row['name'] ?? '');
        if (stripos($n, 'computer') !== false || stripos($n, 'application') !== false) {
            echo "  hint dept: {$n} aesId=" . ($row['aesId'] ?? $row['code'] ?? '') . "\n";
        }
    }
    exit(1);
}

$batches = $aes->fetchPlacementClassBatches($deptAesId, $program);
echo 'Class batches from AES: ' . count($batches) . "\n";
if ($batches !== []) {
    echo '  ' . implode(', ', array_slice($batches, 0, 8)) . (count($batches) > 8 ? '…' : '') . "\n";
}

$toTry = [];
if ($batchArg !== '') {
    $toTry[] = $batchArg;
}
foreach ($batches as $b) {
    if (count($toTry) >= 5) {
        break;
    }
    if (!in_array($b, $toTry, true)) {
        $toTry[] = $b;
    }
}

$totalStudents = 0;
foreach ($toTry as $batch) {
    $rows = $aes->fetchClassStudInfo4Placement($deptAesId, $program, $batch, true);
    $n = count($rows);
    $totalStudents += $n;
    echo "\nBatch \"{$batch}\": {$n} student(s)";
    if ($batchArg !== '' && ClassInchargeRegistry::batchesSameAdmissionCohort($batch, $batchArg)) {
        echo ' [cohort match with ' . $batchArg . ']';
    }
    echo "\n";
    if ($n > 0) {
        $r = $rows[0];
        echo '  sample: ' . trim((string) ($r['stud_name'] ?? $r['name'] ?? '?'))
            . ' | ' . trim((string) ($r['admno'] ?? $r['stud_admno'] ?? '?')) . "\n";
    }
}

if ($batchArg !== '' && !in_array($batchArg, $toTry, true)) {
    $rows = $aes->fetchClassStudInfo4Placement($deptAesId, $program, $batchArg, true);
    echo "\nDirect UI batch \"{$batchArg}\": " . count($rows) . " student(s)\n";
}

echo "\nProgramme batch hints matching MCA: ";
$scoped = array_values(array_filter(
    $batches,
    static fn (string $label): bool => DepartmentProgrammeCatalog::batchHintMatchesProgramme($label, $program)
));
echo count($scoped) . " batch(es)\n";

exit($totalStudents > 0 || count($scoped) > 0 ? 0 : 2);
