<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$line = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L';
$raw = <<<TXT
70) Income available to a person after deducting taxes is called?
A. Taxable Income
B. Cash Income
C. Income at source
D. Disposable Income
E. None of these {$line}
Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.
{$line}
45) How many such symbols are there in the above arrangement?
a) 2
b) 1
c) 3
d) 0
e) More than 3
TXT;

$failed = 0;
$cut = AptitudeManualQuestionParser::cutTrailingArrangementLine('None of these ' . $line);
echo 'cut=' . $cut . PHP_EOL;
if (trim($cut) !== 'None of these') {
    echo "FAIL cutTrailingArrangementLine\n";
    $failed++;
}

$san = (new JdTextExtractionService())->sanitizeManualText($raw);
$r = (new AptitudeManualQuestionParser())->parse($san !== '' ? $san : $raw);
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}

$e70 = (string) (($by[70]['options'] ?? [])[4] ?? '');
echo 'Q70 E=' . $e70 . PHP_EOL;
if (preg_match('/T\s+8\s+3\s+1\s+7/u', $e70) === 1 || str_contains($e70, '© Q K')) {
    echo "FAIL arrangement leaked onto Q70 option E\n";
    $failed++;
}
if (trim($e70) !== 'None of these') {
    echo "FAIL Q70 E expected [None of these] got [{$e70}]\n";
    $failed++;
}

$p45 = (string) ($by[45]['prompt'] ?? '');
echo 'Q45 hasArr=' . (preg_match('/T\s+8\s+3\s+1\s+7/u', $p45) ? 'yes' : 'no') . PHP_EOL;
if (preg_match('/T\s+8\s+3\s+1\s+7/u', $p45) !== 1) {
    echo "FAIL arrangement missing from Q45\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
