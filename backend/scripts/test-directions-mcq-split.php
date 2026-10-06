<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\AptitudeManualQuestionParser;
use PMS\Services\JdTextExtractionService;

$raw = <<<'TXT'
6) 8kg of rice costing Rs. 16 per kg is mixed with 4kg of rice costing Rs. 22 per kg. What is the average price of the mixture?
a) 20 b) 18 c) 16 d) 19 e) 17 Directions (7 - 11): Study the following information carefully and answer the given questions: Eight friends P, Q, R, S, T, V, W and Y are sitting around a square table in such a way that four of them sit at four corners of the square while four fit in the middle of each of the four sides. The ones who sit at the four corners face the centre while those who sit in the middle of the sides face outside. P who faces the centre sits third to the right of V. T, who faces the centre, is not an immediate neighbor of V. Only one person sits between V and W. S sits second to right of Q. Q faces the centre. R is not an immediate neighbor of P.
7) Who sits second to the left of Q?
a) V b) P c) T d) Y e) Cannot be determined
8) What is the position of T with respect to V?
a) Fourth to the left b) Second to the left c) Third to the left d) Third to the right e) Second to the right
TXT;

$san = (new JdTextExtractionService())->sanitizeManualText($raw);
if (($argv[1] ?? '') === '--debug') {
    echo "SAN:\n" . $san . "\n---\n";
}
$p = new AptitudeManualQuestionParser();
$r = $p->parse($san);

echo 'count=' . count($r) . PHP_EOL;
foreach ($r as $i => $q) {
    $opts = $q['options'] ?? [];
    $e = $opts[4] ?? $opts['E'] ?? '';
    echo ($i + 1) . ' E=' . json_encode($e) . ' prompt=' . substr((string) ($q['prompt'] ?? ''), 0, 80) . '...' . PHP_EOL;
}
