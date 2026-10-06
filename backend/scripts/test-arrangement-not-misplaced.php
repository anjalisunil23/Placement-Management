<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$fullLine = 'T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 â □ □ U H 6 L';
$raw = <<<TXT
44) In a certain code ACUIRE is coded as EIRUQAC, then what is the code for DENSITY?
a) YTISNDE
b) YITSNED
c) YTISNED
d) YITSNDE
e) None of these
Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.
T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K
3 1 â □ □ U H 6 L
45) How many such symbols are there in the above arrangement?
a) 2
b) 1
c) 3
d) 0
e) More than 3
46) Which of the following is ninth from the right end of the above arrangement?
a) @
b) #
c) %
d) ©
e) None of these
Verbal Ability (English Language)
Directions: Choose the word most similar in meaning.
47) GRATIFY
a) Pacify
b) Appraise
c) Please
d) Embarrass
e) Depress
TXT;

$failed = 0;

$merged = JdTextExtractionService::extractSymbolArrangementLine(
    "T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K\n3 1 â □ □ U H 6 L"
);
echo 'merged=' . $merged . PHP_EOL;
if (!str_contains($merged, 'U H 6 L') || !str_contains($merged, '© Q K')) {
    echo "FAIL wrap merge incomplete\n";
    $failed++;
}

$san = (new JdTextExtractionService())->sanitizeManualText($raw);
$r = (new AptitudeManualQuestionParser())->parse($san !== '' ? $san : $raw);
$byNum = [];
foreach ($r as $q) {
    $byNum[(int) ($q['questionNumber'] ?? 0)] = $q;
}

$q44 = (string) (($byNum[44]['prompt'] ?? '') . ' ' . implode(' ', $byNum[44]['options'] ?? []));
$q45 = (string) ($byNum[45]['prompt'] ?? '');
$q47 = (string) (($byNum[47]['prompt'] ?? '') . ' ' . implode(' ', $byNum[47]['options'] ?? []));

echo 'Q44 hasArr=' . (preg_match('/T\s+8\s+3\s+1\s+7/u', $q44) ? 'yes' : 'no') . PHP_EOL;
echo 'Q45 hasArr=' . (preg_match('/T\s+8\s+3\s+1\s+7/u', $q45) ? 'yes' : 'no') . PHP_EOL;
echo 'Q45 fullTail=' . (str_contains($q45, 'U H 6 L') ? 'yes' : 'no') . PHP_EOL;
echo 'Q47 hasArr=' . (preg_match('/T\s+8\s+3\s+1\s+7/u', $q47) ? 'yes' : 'no') . PHP_EOL;

if (preg_match('/T\s+8\s+3\s+1\s+7/u', $q44) === 1) {
    echo "FAIL arrangement leaked into Q44\n";
    $failed++;
}
if (preg_match('/T\s+8\s+3\s+1\s+7/u', $q45) !== 1) {
    echo "FAIL arrangement missing from Q45\n";
    $failed++;
}
if (!str_contains($q45, 'U H 6 L')) {
    echo "FAIL Q45 only got truncated arrangement prefix\n";
    $failed++;
}
if (preg_match('/T\s+8\s+3\s+1\s+7/u', $q47) === 1) {
    echo "FAIL arrangement leaked into Verbal Q47\n";
    $failed++;
}
// Truncated prefix alone should not appear as a stray second copy before Directions wording.
if (preg_match('/T\s+8\s+3\s+1\s+7[^\n]*© Q K(?!\s+3\s+1)/u', $q45) === 1
    && !str_contains($q45, $fullLine)
    && !str_contains($q45, 'U H 6 L')) {
    echo "FAIL truncated arrangement left on Q45\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
