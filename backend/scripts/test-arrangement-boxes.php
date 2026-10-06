<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$line = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L';
$raw = "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.\n"
    . $line . "\n"
    . "45) How many such symbols are there in the above arrangement?\n"
    . "a) 2\nb) 1\nc) 3\nd) 0\ne) More than 3\n";

$failed = 0;
$san = (new JdTextExtractionService())->sanitizeManualText($raw);
echo 'san boxes=' . substr_count($san, '□') . PHP_EOL;
if (substr_count($san, '□') < 2) {
    echo "FAIL sanitize dropped boxes\n";
    $failed++;
}

$r = (new AptitudeManualQuestionParser())->parse($san);
$prompt = '';
foreach ($r as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 45) {
        $prompt = (string) ($q['prompt'] ?? '');
    }
}
echo 'prompt=' . $prompt . PHP_EOL;
echo 'prompt boxes=' . substr_count($prompt, '□') . PHP_EOL;
if (substr_count($prompt, '□') < 2) {
    echo "FAIL parse dropped boxes\n";
    $failed++;
}

// OCR often drops the hollow squares, leaving "â U" or "â   U".
$dropped = str_replace('â □ □ U', 'â U', $raw);
$san2 = (new JdTextExtractionService())->sanitizeManualText($dropped);
$fixed = JdTextExtractionService::repairArrangementGlyphs($san2);
echo 'repaired=' . (str_contains($fixed, '□') ? 'yes' : 'no') . PHP_EOL;
$r2 = (new AptitudeManualQuestionParser())->parse($fixed);
foreach ($r2 as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 45) {
        $p2 = (string) ($q['prompt'] ?? '');
        echo 'repaired prompt boxes=' . substr_count($p2, '□') . PHP_EOL;
        if (substr_count($p2, '□') < 2) {
            echo "FAIL repair did not restore boxes\n";
            $failed++;
        }
    }
}

exit($failed > 0 ? 1 : 0);
