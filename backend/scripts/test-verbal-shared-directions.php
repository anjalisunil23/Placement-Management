<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
Verbal Ability (English Language)
Directions: Choose the word/group of words which is most similar in meaning to the word/ group of words printed in bold as used in the passage.
46) GRATIFY
a) delight
b) humour
c) grateful
d) please
e) satisfy
47) AMAZED
a) surprised
b) emotional
c) appalled
d) scared
e) troubled
48) WORRIED
a) angry
b) concerned
c) relaxed
d) annoyed
e) confused
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}
echo 'count=' . count($r) . PHP_EOL;
foreach ([46, 47, 48] as $n) {
    $prompt = (string) ($by[$n]['prompt'] ?? '');
    $hasDir = stripos($prompt, 'most similar in meaning') !== false;
    echo "Q{$n} hasDirections=" . ($hasDir ? 'yes' : 'no') . ' prompt=' . substr(str_replace("\n", ' ', $prompt), 0, 90) . PHP_EOL;
    if (!$hasDir) {
        echo "  FAIL missing shared directions\n";
        $failed++;
    }
}
// Arrangement singular "question" must still be one-only.
$arr = <<<'TXT'
Directions: The following question is based on the letter / number / symbol arrangement. Study it carefully and answer the questions.
T 8 3 1 7 F J 5 % E R @ 4 D A 2 B © Q K 3 1 # U H 6 L
45) How many such symbols are there in the above arrangement?
a) 2 b) 1 c) 3 d) 0 e) More than 3
46) GRATIFY
a) delight b) humour c) grateful d) please e) satisfy
TXT;
$r2 = (new AptitudeManualQuestionParser())->parse($arr);
$p46 = '';
foreach ($r2 as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 46) {
        $p46 = (string) ($q['prompt'] ?? '');
    }
}
if (stripos($p46, 'letter') !== false || stripos($p46, 'arrangement') !== false) {
    echo "FAIL arrangement directions leaked onto Q46\n";
    $failed++;
}
exit($failed > 0 ? 1 : 0);
