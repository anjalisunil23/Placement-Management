<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
Directions: Choose the word/group of words which is most similar in meaning to the word/ group of words printed in bold as used in the passage.
54) GRATIFY
a) delight
b) humour
c) grateful
d) please
e) satisfy
55) The crowd loved her Performance and gave her a stand ovation as she left the stage.
a) stand ovate
b) stood ovation
c) stand the ovation
d) standing ovation
e) No correction required
Basic Computer Knowledge and Digital Banking (Sample Questions)
56) World Wide Web is a collection of all information, resources, pictures, sounds, and multimedia on the internet which is accessed through ..........
a) HTML
b) FTP
c) UDP
d) HTTP
e) SMTP
57) .................. is used in second-generation computers.
a) Transistors
b) Vacuum tubes
c) Microprocessor
d) Integrated circuit
e) None of these
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}
echo 'count=' . count($r) . PHP_EOL;

$p55 = (string) ($by[55]['prompt'] ?? '');
$e55 = (string) (($by[55]['options'] ?? [])[4] ?? '');
echo 'Q55 E=' . $e55 . PHP_EOL;
if (stripos($e55, 'Basic Computer') !== false || stripos($e55, 'Sample Questions') !== false) {
    echo "FAIL section heading leaked into Q55 option E\n";
    $failed++;
}
if (stripos($p55, 'Basic Computer') !== false) {
    echo "FAIL section heading leaked into Q55 prompt\n";
    $failed++;
}

foreach ([56, 57] as $n) {
    $prompt = (string) ($by[$n]['prompt'] ?? '');
    echo "Q{$n} prompt=" . substr(str_replace("\n", ' ', $prompt), 0, 90) . PHP_EOL;
    if (stripos($prompt, 'most similar in meaning') !== false || stripos($prompt, 'Choose the word') !== false) {
        echo "  FAIL previous verbal Directions followed into Q{$n}\n";
        $failed++;
    }
    if ($prompt === '') {
        echo "  FAIL missing Q{$n}\n";
        $failed++;
    }
}

$cut = AptitudeManualQuestionParser::cutAtNextSectionHeading(
    'No correction required Basic Computer Knowledge and Digital Banking (Sample Questions)'
);
echo 'cut=' . $cut . PHP_EOL;
if (stripos($cut, 'Basic Computer') !== false) {
    echo "FAIL cutAtNextSectionHeading did not remove heading\n";
    $failed++;
}

$bankRaw = <<<'TXT'
55) The crowd loved her Performance and gave her a stand ovation as she left the stage.
a) stand ovate
b) stood ovation
c) stand the ovation
d) standing ovation
e) No correction required
Banking Awareness (Sample Questions)
56) Which of the following is a public sector bank?
a) HDFC Bank
b) Axis Bank
c) SBI
d) Yes Bank
e) None of these
TXT;
$bank = (new AptitudeManualQuestionParser())->parse($bankRaw);
$bankBy = [];
foreach ($bank as $q) {
    $bankBy[(int) ($q['questionNumber'] ?? 0)] = $q;
}
$bankE55 = (string) (($bankBy[55]['options'] ?? [])[4] ?? '');
$bankP56 = (string) ($bankBy[56]['prompt'] ?? '');
echo 'Banking Q55 E=' . $bankE55 . PHP_EOL;
echo 'Banking Q56 prompt=' . substr(str_replace("\n", ' ', $bankP56), 0, 90) . PHP_EOL;
if (stripos($bankE55, 'Banking Awareness') !== false || stripos($bankE55, 'Sample Questions') !== false) {
    echo "FAIL Banking Awareness leaked into Q55 option E\n";
    $failed++;
}
if ($bankP56 === '' || stripos($bankP56, 'public sector bank') === false) {
    echo "FAIL Banking Awareness Q56 missing/wrong\n";
    $failed++;
}
if (stripos($bankP56, 'stand ovation') !== false) {
    echo "FAIL previous verbal prompt followed into Banking Q56\n";
    $failed++;
}
$cutBank = AptitudeManualQuestionParser::cutAtNextSectionHeading(
    'e) SMTP Banking Awareness (Sample Questions)'
);
if (stripos($cutBank, 'Banking Awareness') !== false) {
    echo "FAIL cutAtNextSectionHeading did not remove Banking Awareness\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
