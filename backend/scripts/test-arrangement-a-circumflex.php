<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$failed = 0;

$plainA = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 a □ □ U H 6 L';
$fixed = JdTextExtractionService::repairArrangementGlyphs($plainA);
echo 'repaired=' . $fixed . PHP_EOL;
if (!str_contains($fixed, 'â')) {
    echo "FAIL plain a not restored to â\n";
    $failed++;
}
if (preg_match('/(?<=\s)a(?=\s)/u', $fixed) === 1) {
    echo "FAIL plain a still present in arrangement tokens\n";
    $failed++;
}
if (!str_contains($fixed, 'D A 2 B')) {
    echo "FAIL capital A in arrangement was altered\n";
    $failed++;
}

$decomposed = "T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 a\xCC\x82 □ □ U H 6 L";
$fixed2 = JdTextExtractionService::repairArrangementGlyphs($decomposed);
if (!str_contains($fixed2, 'â') || str_contains($fixed2, "a\xCC\x82")) {
    echo "FAIL decomposed a+circumflex not recomposed\n";
    $failed++;
}

$noBoxes = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 a U H 6 L';
$fixed3 = JdTextExtractionService::repairArrangementGlyphs($noBoxes);
if (!str_contains($fixed3, 'â □ □ U')) {
    echo "FAIL a→â + boxes restore failed: {$fixed3}\n";
    $failed++;
}

$raw = "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.\n"
    . $plainA . "\n"
    . "45) How many such symbols are there in the above arrangement?\n"
    . "a) 2\nb) 1\nc) 3\nd) 0\ne) More than 3\n";
$san = (new JdTextExtractionService())->sanitizeManualText($raw);
$r = (new AptitudeManualQuestionParser())->parse($san);
$prompt = '';
foreach ($r as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 45) {
        $prompt = (string) ($q['prompt'] ?? '');
    }
}
echo 'prompt has â=' . (str_contains($prompt, 'â') ? 'yes' : 'no') . PHP_EOL;
if (!str_contains($prompt, 'â')) {
    echo "FAIL parse prompt missing â\n";
    $failed++;
}
// Option labels a) must remain plain a in the options list, not â.
$opts = '';
foreach ($r as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 45) {
        $opts = implode(' ', $q['options'] ?? []);
    }
}
if (str_contains($opts, 'â')) {
    echo "FAIL options incorrectly contain â\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
