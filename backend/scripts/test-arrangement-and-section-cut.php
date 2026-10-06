<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
44) In a certain code ACUIRE is coded as EIRUQAC, then what is the code for DENSITY?
a) YTISNDE
b) YITSNED
c) YTISNED
d) YITSNDE
e) None of these
Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.
T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 # U H 6 L
45) How many such symbols are there in the above arrangement, each of which is immediately preceded by a consonant and not immediately followed by a vowel?
a) 2
b) 1
c) 3
d) 0
e) More than 3
Verbal Ability (English Language)
Directions: Choose the word/group of words which is most similar in meaning to the word/ group of words printed in bold as used in the passage.
46) GRATIFY
a) Pacify
b) Appraise
c) Please
d) Embarrass
e) Depress
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
$byNum = [];
foreach ($r as $q) {
    $byNum[(int) ($q['questionNumber'] ?? 0)] = $q;
}
echo 'count=' . count($r) . PHP_EOL;

$q45 = $byNum[45] ?? null;
if ($q45 === null) {
    echo "FAIL missing Q45\n";
    exit(1);
}
$prompt = (string) ($q45['prompt'] ?? '');
echo 'Q45 prompt=' . substr(str_replace("\n", ' ', $prompt), 0, 180) . PHP_EOL;
$opts = $q45['options'] ?? [];
echo 'Q45 E=' . ($opts[4] ?? $opts['E'] ?? '') . PHP_EOL;

if (!str_contains($prompt, 'letter') && !str_contains($prompt, 'arrangement')) {
    echo "FAIL arrangement directions missing from Q45\n";
    $failed++;
}
if (!preg_match('/T\s+8\s+3/u', $prompt)) {
    echo "FAIL arrangement line missing from Q45\n";
    $failed++;
}
$e = (string) ($opts[4] ?? '');
if (stripos($e, 'Verbal') !== false) {
    echo "FAIL Verbal Ability leaked into option E\n";
    $failed++;
}
if (!preg_match('/More than 3/iu', $e)) {
    echo "FAIL option E missing More than 3\n";
    $failed++;
}
if (str_contains($prompt, 'Verbal Ability') || str_contains($prompt, 'GRATIFY')) {
    echo "FAIL next section leaked into Q45 prompt\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
