<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$cases = [
    'paren_statements' => <<<'TXT'
Directions: Each of the questions below consists of a question and two statements numbered (i) and (ii) given below it.
Mark your answer as (1) if the data in statement (i) alone are sufficient to answer the question, while the data in statement (ii) alone is not sufficient to the question.
Mark your answer as (2) if the data in statement (ii) alone are sufficient to answer the question, while the data in statement (i) is not sufficient to the question.
Mark your answer as (3) if the data either in statement (i) or in statement (ii) alone is sufficient to answer the questions.
Mark your answer as (4) if the data even in both statement (i) and (ii) together are not sufficient to answer the question.
Mark your answer as (5) if the data in both statements (i) and (ii) together are necessary to answer the question.
16) Who amongst L, M, N, O and P is the shortest.
(i) O is shorter than P but taller than N.
(ii) M is not as tall as L.
TXT,
    'inline_q16' => <<<'TXT'
Directions: Each question has statements (i) and (ii). Mark your answer as (1) if statement (i) alone is sufficient.
Mark your answer as (2) if statement (ii) alone is sufficient.
Mark your answer as (3) if either alone is sufficient.
Mark your answer as (4) if both together are not sufficient.
Mark your answer as (5) if both together are necessary. 16) Who amongst L, M, N, O and P is the shortest.
i) O is shorter than P but taller than N.
ii) M is not as tall as L.
TXT,
];

$p = new AptitudeManualQuestionParser();
$failed = 0;
foreach ($cases as $name => $raw) {
    $r = $p->parse($raw);
    $expect = $name === 'paren_statements' || $name === 'inline_q16' ? 1 : null;
    echo $name . ' count=' . count($r) . PHP_EOL;
    if ($expect !== null && count($r) !== $expect) {
        echo '  FAIL expected ' . $expect . ' question(s)' . PHP_EOL;
        $failed++;
    }
    foreach ($r as $q) {
        $type = $q['questionType'] ?? 'MCQ';
        echo '  #' . ($q['questionNumber'] ?? '?') . ' type=' . $type . ' opts=' . count($q['options'] ?? []) . PHP_EOL;
        if ($type !== 'DATA_SUFFICIENCY') {
            echo '  FAIL expected DATA_SUFFICIENCY' . PHP_EOL;
            $failed++;
        }
    }
}
exit($failed > 0 ? 1 : 0);
