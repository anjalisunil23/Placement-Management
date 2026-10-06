<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\JdTextExtractionService;

$line = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L';
$full = "Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.\n"
    . $line . "\n"
    . "45) How many such symbols are there in the above arrangement?\n"
    . "a) 2 b) 1 c) 3 d) 0 e) More than 3\n";

$san = (new JdTextExtractionService())->sanitizeManualText($full);
$failed = 0;
if ($san === '' || !str_contains($san, 'T 8 3 1 7') || !str_contains($san, '©')) {
    echo "FAIL sanitize dropped arrangement line\n";
    $failed++;
}
$repaired = JdTextExtractionService::repairCommonPdfMojibake($full);
if (!str_contains($repaired, '©') || !str_contains($repaired, '□')) {
    echo "FAIL mojibake repair destroyed symbols\n";
    $failed++;
}
echo $failed === 0 ? "PASS arrangement survives sanitize/repair\n" : "FAIL count={$failed}\n";
exit($failed > 0 ? 1 : 0);
