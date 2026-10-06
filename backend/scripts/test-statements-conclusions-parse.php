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
TXT;

$p = new AptitudeManualQuestionParser();
$r = $p->parse($raw);
$failed = 0;
echo 'count=' . count($r) . PHP_EOL;
if (count($r) !== 2) {
    echo "FAIL expected 2 questions\n";
    $failed++;
}

$expectedOpts = [
    'If only conclusion I follows',
    'If only conclusion II follows',
    'If either conclusion I or conclusion II follows',
    'If neither conclusion I nor conclusion II follows',
    'If both conclusions I and II follow',
];

foreach ($r as $q) {
    $n = (int) ($q['questionNumber'] ?? 0);
    $type = (string) ($q['questionType'] ?? '');
    $dir = (string) ($q['directionsBlock'] ?? '');
    $opts = array_values(array_map('strval', $q['options'] ?? []));
    echo "  #{$n} type={$type} opts=" . count($opts) . ' dir_len=' . strlen($dir) . PHP_EOL;
    if ($type !== 'STATEMENTS_CONCLUSIONS') {
        echo "  FAIL type\n";
        $failed++;
    }
    if ($dir === '' || !str_contains($dir, 'statements')) {
        echo "  FAIL directionsBlock missing instruction\n";
        $failed++;
    }
    if (preg_match('/\bA\)\s*If only/iu', $dir) === 1) {
        echo "  FAIL directionsBlock should not repeat A-E options\n";
        $failed++;
    }
    if (str_contains($dir, 'is correct answer and indicate')) {
        echo "  FAIL directionsBlock still has answer-sheet A,B,C prose\n";
        $failed++;
    }
    if (!str_contains((string) ($q['prompt'] ?? ''), 'Statements')) {
        echo "  FAIL prompt missing Statements\n";
        $failed++;
    }
    if (str_contains((string) ($q['prompt'] ?? ''), 'A) If only')) {
        echo "  FAIL prompt should not repeat A-E options\n";
        $failed++;
    }
    foreach ($expectedOpts as $i => $want) {
        $got = trim((string) ($opts[$i] ?? ''));
        if ($got !== $want) {
            echo "  FAIL option " . chr(65 + $i) . " expected [{$want}] got [{$got}]\n";
            $failed++;
        }
    }
    foreach ($opts as $o) {
        if (preg_match('/^(?:,|and)\s*$/iu', trim($o)) || str_contains($o, 'is correct answer')) {
            echo "  FAIL junk option: {$o}\n";
            $failed++;
        }
    }
}
exit($failed > 0 ? 1 : 0);
