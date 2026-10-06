<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
Directions (7 - 11): Study the following information carefully and answer the given questions: Eight friends P, Q, R, S, T, V, W and Y are sitting around a square table.
7) Who sits second to the left of Q?
a) V b) P c) T d) Y e) Cannot be determined
8) What is the position of T with respect to V?
a) Fourth to the left b) Second to the left c) Third to the left d) Third to the right e) Second to the right
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
echo 'count=' . count($r) . PHP_EOL;
if (count($r) < 2) {
    echo "FAIL expected at least 2\n";
    $failed++;
}
foreach ($r as $q) {
    $n = (int) ($q['questionNumber'] ?? 0);
    $prompt = (string) ($q['prompt'] ?? '');
    echo "  #{$n} prompt=" . substr(str_replace("\n", ' ', $prompt), 0, 100) . PHP_EOL;
    if (preg_match('/Directions\s*\(\s*\d+\s*[-–]\s*\d+\s*\)\s*:/iu', $prompt)) {
        echo "  FAIL Directions (N-M): label leaked into prompt\n";
        $failed++;
    }
    if (!str_contains($prompt, 'Eight friends')) {
        echo "  FAIL passage body missing from prompt\n";
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
