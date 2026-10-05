<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
15) One of the angles of a triangle is two-third of sum of adjacent angles of parallelogram.
a) 25 b) 40 c) 35 d) Cannot be determined e) None of these
Directions: Each question consists of a question followed by two statements numbered (i) and (ii) given below it. You have to decide whether the data provided in the statements are sufficient to answer the question. Mark your answer as
(1) if the data in statement (i) alone are sufficient to answer the question, while data in statement (ii) alone are not sufficient to answer the question.
(2) if the data in statement (ii) alone are sufficient to answer the question, while data in statement (i) alone are not sufficient to answer the question.
(3) if the data either in statement (i) alone or in statement (ii) alone are sufficient to answer the question.
(4) if the data given in both statements (i) and (ii) together are not sufficient to answer the question.
(5) if the data given in both statements (i) and (ii) together are necessary to answer the question.
16) Who amongst L, M, N, O and P is the shortest.
i) O is shorter than P but taller than N.
ii) M is not as tall as L.
17) Are all the five friends seated around a circular table facing the centre?
i) Leena sits second to left of Amit.
ii) Ali sits third to the left of Ken.
TXT;

$p = new AptitudeManualQuestionParser();
$r = $p->parse($raw);
echo 'count=' . count($r) . PHP_EOL;
foreach ($r as $q) {
    $n = $q['questionNumber'] ?? '?';
    $type = $q['questionType'] ?? 'MCQ';
    echo "#{$n} type={$type} opts=" . count($q['options'] ?? []) . ' prompt=' . substr((string) ($q['prompt'] ?? ''), 0, 50) . '...' . PHP_EOL;
}
