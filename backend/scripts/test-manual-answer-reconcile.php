<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Models\AptitudeTestModel;
use PMS\Services\AptitudeManualAnswerAnalyzer;

$opts = ['6%', '2%', '4%', '8%', '5%'];
$exp = '2 minutes 24 seconds is 4% of an hour.';

$from = AptitudeTestModel::findUniqueOptionInExplanation($opts, $exp);
$computed = AptitudeTestModel::extractComputedNumericFromExplanation($exp);

$ref = new ReflectionClass(AptitudeManualAnswerAnalyzer::class);
$m = $ref->getMethod('reconcileAiAnswer');
$m->setAccessible(true);
$analyzer = new AptitudeManualAnswerAnalyzer(null);
$r = $m->invoke($analyzer, $opts, 0, $exp);

echo 'fromExplanation=' . var_export($from, true) . PHP_EOL;
echo 'computed=' . var_export($computed, true) . PHP_EOL;
echo 'reconciled=' . json_encode($r) . PHP_EOL;

$failed = 0;
if ($from !== 2) {
    echo "FAIL expected fromExplanation index 2 (4%)\n";
    $failed++;
}
if ($computed !== 4.0) {
    echo "FAIL expected computed 4.0\n";
    $failed++;
}
if (($r['correctIndex'] ?? -1) !== 2) {
    echo "FAIL expected reconciled correctIndex 2\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
