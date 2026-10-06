<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$failed = 0;

// Glued fill-in stem (common in Computer/Banking sections): previous E + "69) ……"
$glued = <<<'TXT'
68) World Wide Web is accessed through ..........
a) HTML
b) FTP
c) UDP
d) HTTP
e) SMTP 69) .................. is used in second-generation computers.
a) Transistors
b) Vacuum tubes
c) Microprocessor
d) Integrated circuit
e) None of these
70) Which of the following is a public sector bank?
a) HDFC Bank
b) Axis Bank
c) SBI
d) Yes Bank
e) None of these
TXT;

$san = (new JdTextExtractionService())->sanitizeManualText($glued);
$r = (new AptitudeManualQuestionParser())->parse($san !== '' ? $san : $glued);
$by = [];
foreach ($r as $q) {
    $by[(int) ($q['questionNumber'] ?? 0)] = $q;
}
echo 'nums=' . implode(',', array_keys($by)) . PHP_EOL;

foreach ([68, 69, 70] as $n) {
    if (!isset($by[$n])) {
        echo "FAIL missing Q{$n}\n";
        $failed++;
        continue;
    }
    $e = (string) (($by[$n]['options'] ?? [])[4] ?? '');
    if (preg_match('/\b\d{1,3}\)\s/u', $e) === 1) {
        echo "FAIL Q{$n} option E still contains next question number: {$e}\n";
        $failed++;
    }
}

$p69 = (string) ($by[69]['prompt'] ?? '');
echo 'Q69 prompt=' . substr(str_replace("\n", ' ', $p69), 0, 80) . PHP_EOL;
if ($p69 === '' || stripos($p69, 'second-generation') === false) {
    echo "FAIL Q69 prompt wrong\n";
    $failed++;
}
$e68 = (string) (($by[68]['options'] ?? [])[4] ?? '');
if (stripos($e68, '69)') !== false || stripos($e68, 'second-generation') !== false) {
    echo "FAIL Q69 leaked into Q68 E: {$e68}\n";
    $failed++;
}
if (trim($e68) !== 'SMTP') {
    echo "FAIL Q68 E expected SMTP got [{$e68}]\n";
    $failed++;
}

// Flat one-line OCR blob ending with fill-in Q69
$flat = '68) WWW is accessed through .......... a) HTML b) FTP c) UDP d) HTTP e) SMTP '
    . '69) .................. is used in second-generation computers. a) Transistors b) Vacuum tubes '
    . 'c) Microprocessor d) Integrated circuit e) None of these';
$san2 = (new JdTextExtractionService())->sanitizeManualText($flat);
$r2 = (new AptitudeManualQuestionParser())->parse($san2 !== '' ? $san2 : $flat);
$has69 = false;
foreach ($r2 as $q) {
    if ((int) ($q['questionNumber'] ?? 0) === 69) {
        $has69 = true;
    }
}
echo 'flat has69=' . ($has69 ? 'yes' : 'no') . PHP_EOL;
if (!$has69) {
    echo "FAIL flat OCR missing Q69\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
