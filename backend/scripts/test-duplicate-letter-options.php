<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;

$raw = <<<'TXT'
44) In a certain code ACQUIRE is coded as EIRUQAC, then what is the code for DENSITY?
a) YTISNDE
b) YTISNED
c) YTISNED
d) YITSNDE
e) None of these
TXT;

$r = (new AptitudeManualQuestionParser())->parse($raw);
$failed = 0;
$q = $r[0] ?? null;
if ($q === null) {
    echo "FAIL no question\n";
    exit(1);
}
$opts = $q['options'] ?? [];
echo 'opts=' . json_encode($opts) . PHP_EOL;
if (($opts[1] ?? '') === ($opts[2] ?? '')) {
    echo "FAIL B and C still identical\n";
    $failed++;
}
if (!in_array('YITSNED', $opts, true) || !in_array('YTISNED', $opts, true)) {
    echo "FAIL expected both YITSNED and YTISNED\n";
    $failed++;
}
$direct = AptitudeManualQuestionParser::repairDuplicateLetterCodeOptions([
    'YTISNDE', 'YTISNED', 'YTISNED', 'YITSNDE', 'None of these',
]);
echo 'direct=' . json_encode($direct) . PHP_EOL;
if ($direct[1] === $direct[2]) {
    echo "FAIL direct repair left duplicates\n";
    $failed++;
}
exit($failed > 0 ? 1 : 0);
