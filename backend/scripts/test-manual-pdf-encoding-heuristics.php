<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\JdTextExtractionService;

$badPdftotext = <<<'TXT'
Directions (9 - 12): These questions are based on the following letter/number/symbol arrangement.
T 8 3 1 7 F J 5 % E R @ 4 D A 2 B @ K 3 1 â = U H 6 L
9) Four of the following five are alike
a) E@%
b) #78
TXT;

$goodOcr = <<<'TXT'
Directions (9 - 12): These questions are based on the following letter/number/symbol arrangement.
T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 # U H 6 L
9) Four of the following five are alike
a) E@%
b) #78
TXT;

foreach (['bad' => $badPdftotext, 'good' => $goodOcr] as $label => $text) {
    echo $label . ' encodingIssues=' . (JdTextExtractionService::hasLikelyFontEncodingIssues($text) ? 'yes' : 'no');
    echo ' symbolOcr=' . (JdTextExtractionService::symbolArrangementNeedsOcr($text) ? 'yes' : 'no');
    echo ' quality=' . JdTextExtractionService::manualExtractQuality($text) . PHP_EOL;
}
