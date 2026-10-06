<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
Directions: In each question below are given three statements followed by two conclusions
numbered I and II. You have to take the three given statements to be true even if they seem to
be at variance from commonly known facts and then decide which of the given conclusions
logically follows from the three given statements, disregarding commonly known facts. Then
decide which of the answer (A), (B), (C), (D) and (E) is correct answer and indicate it on the
answer sheet.
A) If only conclusion I follows
B) If only conclusion II follows
C) If either conclusion I or conclusion II follows
D) If neither conclusion I nor conclusion II follows
E) If both conclusions I and II follow
29) Statements
Some cards are plastics
Some plastics are metals
All metals are pots
Conclusions
I) Some pots are cards
II) No pots are cards
30) Statements
All chairs are tables
All tables are trains
All trains are buses
Conclusions
I) All tables are buses
II) All trains are tables
Banking Awareness (Sample Questions)
31) Which of the following is not a money market instrument?
a) Bonds
b) Treasury bills
c) Certificate of deposit
d) More than one of the above
e) None of these
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}

$failed = 0;
echo 'nums=' . implode(',', array_keys($by)) . PHP_EOL;

foreach ([29, 30, 31] as $n) {
    if (!isset($by[$n])) {
        echo "FAIL missing Q{$n}\n";
        $failed++;
    }
}

$q29 = $by[29] ?? [];
$q30 = $by[30] ?? [];
$q31 = $by[31] ?? [];

if (($q29['questionType'] ?? '') !== 'STATEMENTS_CONCLUSIONS') {
    echo "FAIL Q29 type\n";
    $failed++;
}
if (($q30['questionType'] ?? '') !== 'STATEMENTS_CONCLUSIONS') {
    echo "FAIL Q30 type\n";
    $failed++;
}
if (($q31['questionType'] ?? '') === 'STATEMENTS_CONCLUSIONS') {
    echo "FAIL Q31 should not be STATEMENTS_CONCLUSIONS\n";
    $failed++;
}

$p30 = (string) ($q30['prompt'] ?? '');
echo "Q30 prompt=\n{$p30}\n";
if (stripos($p30, 'Banking Awareness') !== false || stripos($p30, 'money market') !== false) {
    echo "FAIL Banking/Q31 leaked into Q30\n";
    $failed++;
}
if (!str_contains($p30, "Statements\n") || !str_contains($p30, "\nII) ")) {
    echo "FAIL Q30 not multi-line SC prompt\n";
    $failed++;
}

$p31 = (string) ($q31['prompt'] ?? '');
$dir31 = (string) ($q31['directionsBlock'] ?? '');
$opts31 = $q31['options'] ?? [];
echo 'Q31 prompt=' . $p31 . PHP_EOL;
echo 'Q31 section=' . ($q31['section'] ?? '') . PHP_EOL;
echo 'Q31 A=' . ($opts31[0] ?? '') . PHP_EOL;

if (stripos($p31, 'money market') === false) {
    echo "FAIL Q31 prompt wrong\n";
    $failed++;
}
if (stripos($p31, 'Statements') !== false || stripos($p31, 'All chairs') !== false) {
    echo "FAIL SC content leaked into Q31\n";
    $failed++;
}
if (stripos($dir31, 'conclusion') !== false || stripos($dir31, 'If only conclusion') !== false) {
    echo "FAIL SC directions attached to Q31\n";
    $failed++;
}
if (stripos((string) ($opts31[0] ?? ''), 'If only conclusion') !== false) {
    echo "FAIL SC options attached to Q31\n";
    $failed++;
}
if (strcasecmp(trim((string) ($opts31[0] ?? '')), 'Bonds') !== 0) {
    echo "FAIL Q31 option A should be Bonds\n";
    $failed++;
}
if (stripos((string) ($q31['section'] ?? ''), 'Banking') === false) {
    echo "FAIL Q31 section should be Banking Awareness\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
