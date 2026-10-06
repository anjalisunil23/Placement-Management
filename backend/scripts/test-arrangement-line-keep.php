<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$line = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L';
$cases = [
    'newline' => "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.\n{$line}\n45) How many such symbols are there in the above arrangement, each of which is immediately preceded by a consonant and not immediately followed by a vowel?\na) 2\nb) 1\nc) 3\nd) 0\ne) More than 3\n",
    // PDF reading order quirk: arrangement extracted after the question.
    'after_question' => "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.\n45) How many such symbols are there in the above arrangement, each of which is immediately preceded by a consonant and not immediately followed by a vowel?\na) 2\nb) 1\nc) 3\nd) 0\ne) More than 3\n{$line}\n",
    'flat' => "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions. {$line} 45) How many such symbols are there in the above arrangement, each of which is immediately preceded by a consonant and not immediately followed by a vowel? a) 2 b) 1 c) 3 d) 0 e) More than 3\n",
];

$failed = 0;
echo 'isArrangementLine=' . (JdTextExtractionService::isSymbolArrangementLine($line) ? 'yes' : 'no') . PHP_EOL;
echo 'needsOcrWithoutLine=' . (JdTextExtractionService::symbolArrangementNeedsOcr(
    "Directions: letter/number/symbol arrangement.\n45) How many in the above arrangement?\na) 1"
) ? 'yes' : 'no') . PHP_EOL;

foreach ($cases as $name => $raw) {
    $san = (new JdTextExtractionService())->sanitizeManualText($raw);
    $r = (new AptitudeManualQuestionParser())->parse($san !== '' ? $san : $raw);
    $prompt = '';
    foreach ($r as $q) {
        if ((int) ($q['questionNumber'] ?? 0) === 45) {
            $prompt = (string) ($q['prompt'] ?? '');
        }
    }
    $hasLine = (bool) preg_match('/T\s+8\s+3\s+1\s+7\s+F\s+J\s+5\s+%/u', $prompt);
    echo $name . ' hasLine=' . ($hasLine ? 'yes' : 'no') . PHP_EOL;
    if (!$hasLine) {
        echo '  FAIL prompt=' . substr(str_replace("\n", ' ', $prompt), 0, 180) . PHP_EOL;
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
