<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$raw = <<<'TXT'
42) 8kg of rice costing Rs. 16 per kg is mixed with 4kg of rice costing Rs. 22 per kg. What is the average cost?
A. 20
B. 18
C. 16
D. 19
E. 17 Study the following information carefully and answer he given questions: Eight friends P, Q, R, S, T, V and W are sitting around a square table. four of them sit at four corners of the square while four sit in the middle of each of the four sides.
43) Who sits third to the right of V?
a) P
b) T
c) R
d) S
e) None of these
TXT;

$failed = 0;
$cut = AptitudeManualQuestionParser::cutAtNextStudyPassage('17 Study the following information carefully and answer he given questions: Eight friends');
echo 'cut=' . $cut . PHP_EOL;
if (trim($cut) !== '17') {
    echo "FAIL cutAtNextStudyPassage\n";
    $failed++;
}

$san = (new JdTextExtractionService())->sanitizeManualText($raw);
$r = (new AptitudeManualQuestionParser())->parse($san !== '' ? $san : $raw);
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}

$e42 = (string) (($by[42]['options'] ?? [])[4] ?? '');
$p43 = (string) ($by[43]['prompt'] ?? '');
echo 'Q42 E=' . $e42 . PHP_EOL;
echo 'Q43 prompt=' . substr(str_replace("\n", ' ', $p43), 0, 120) . PHP_EOL;

if (stripos($e42, 'Study the following') !== false || stripos($e42, 'Eight friends') !== false) {
    echo "FAIL study passage leaked into Q42 option E\n";
    $failed++;
}
if (trim($e42) !== '17') {
    echo "FAIL Q42 E expected 17 got [{$e42}]\n";
    $failed++;
}
if (stripos($p43, 'Eight friends') === false && stripos($p43, 'square table') === false) {
    echo "FAIL seating passage missing from Q43\n";
    $failed++;
}
if (stripos($p43, 'average cost') !== false || stripos($p43, '8kg of rice') !== false) {
    echo "FAIL rice question leaked into Q43\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
