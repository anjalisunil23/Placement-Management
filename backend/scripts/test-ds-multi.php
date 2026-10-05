<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
15) One of the angles of a triangle is two-third of sum of adjacent angles of parallelogram.
a) 25 b) 40 c) 35 d) Cannot be determined e) None of these
Directions: Each of the questions below consists of a question and two statements numbered (i) and (ii) given below it. You have to decide whether the data provided in the statements are sufficient to answer the question. Read both the statements and answer the questions.
Mark your answer as (1) if the data in statement (i) alone are sufficient to answer the question, while the data in statement (ii) alone is not sufficient to the question.
Mark your answer as (2) if the data in statement (ii) alone are sufficient to answer the question, while the data in statement (i) is not sufficient to answer the question.
Mark your answer as (3) if the data either in statement (i) or in statement (ii) alone is sufficient to answer the questions.
Mark your answer as (4) if the data even in both statement (i) and (ii) together are not sufficient to answer the question.
Mark your answer as (5) if the data in both statements (i) and (ii) together are necessary to answer the question.
16) Who amongst L, M, N, O and P is the shortest.
i) O is shorter than P but taller than N.
ii) M is not as tall as L.
17) Are all the five friends viz. Leena, Amit, Arun, Ali and Ken who are seated around a circular table facing the centre?
i) Leena sits second to left of Amit. Amit faces the centre. Arun sits second to right of Leena.
ii) Ali sits third to the left of Ken. Ken faces the centre. Amit sits to the immediate left of Ali but Ken is not an immediate neighbour of Amit.
18) Is T, the grandmother of Q ?
i) P is the mother of Q. Q is the son of R. R is the son of T.
ii) L is father of N and N is daughter of T.
19) Point A is towards which direction from point B?
i) If a person walks 4m towards the north from point A, and takes two consecutive right turns, each after walking 4m, he would reach point C, which is 8m away from point B.
ii) Point D is 2m towards the east of point A and 4m towards the west of point B.
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
echo 'count=' . count($r) . PHP_EOL;
if (count($r) !== 5) {
    echo "FAIL expected 5\n";
    $failed++;
}
foreach ($r as $q) {
    $n = (int) ($q['questionNumber'] ?? 0);
    $type = (string) ($q['questionType'] ?? 'MCQ');
    echo "  #{$n} {$type}\n";
    if ($n >= 16 && $type !== 'DATA_SUFFICIENCY') {
        echo "  FAIL Q{$n} should be DATA_SUFFICIENCY\n";
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
