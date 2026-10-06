<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
Directions: Each question has statements (i) and (ii).
Mark your answer as (1) if the data in statement (i) alone are sufficient to answer the question, while the data in statement (ii) alone is not sufficient to the question.
Mark your answer as (2) if the data in statement (ii) alone are sufficient to answer the question, while the data in statement (i) is not sufficient to answer the question.
Mark your answer as (3) if the data either in statement (i) or in statement (ii) alone is sufficient to answer the questions.
Mark your answer as (4) if the data even in both statement (i) and (ii) together are not sufficient to answer the question.
Mark your answer as (5) if the data in both statements (i) and (ii) together are necessary to answer the question.
19) Point A is towards which direction from point B?
i) If a person walks 4m towards the north from point A, and takes two consecutive right turns, each after walking 4m, he would reach point C, which is 8m away from point B.
ii) Point D is 2m towards the east of point A and 4m towards the west of point B.
Directions (20 - 23): Study the following information to answer the given questions: Twelve people are sitting in two parallel rows containing six people each.
20) Who sits opposite to P?
a) A b) B c) C d) D e) E
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
$ds = null;
foreach ($r as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 19) {
        $ds = $q;
        break;
    }
}
echo 'total=' . count($r) . PHP_EOL;
if ($ds === null) {
    echo "FAIL missing Q19\n";
    exit(1);
}
$prompt = (string) ($ds['prompt'] ?? '');
echo 'Q19 type=' . ($ds['questionType'] ?? '') . PHP_EOL;
echo 'prompt=' . substr(str_replace("\n", ' ', $prompt), 0, 160) . PHP_EOL;
if (preg_match('/Directions\s*\(\s*20/iu', $prompt) || str_contains($prompt, 'Twelve people')) {
    echo "FAIL next Directions (20-23) leaked into Q19\n";
    $failed++;
}
if (!str_contains($prompt, 'Point A') || !str_contains($prompt, 'point B')) {
    echo "FAIL Q19 stem missing\n";
    $failed++;
}
$has20 = false;
foreach ($r as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 20) {
        $has20 = true;
        if (str_contains((string) ($q['prompt'] ?? ''), 'Twelve people') || str_contains((string) ($q['prompt'] ?? ''), 'Who sits opposite')) {
            // ok — separate question
        }
    }
}
if (!$has20) {
    echo "WARN Q20 not parsed (ok if only DS isolated)\n";
}
exit($failed > 0 ? 1 : 0);
