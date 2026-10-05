<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\JdTextExtractionService;

$raw = <<<'TXT'
--- PAGE 1 ---
MAAN Aptitude Materials
www.example.com
Directions: Each question has two statements.
16) Who is shortest.
i) O is shorter than P.
ii) M is not as tall as L.
Page 1

--- PAGE 2 ---
MAAN Aptitude Materials
www.example.com
17) What is 2+2.
i) Sum is four.
ii) Product is four.
Page 2
TXT;

$out = JdTextExtractionService::stripManualPageHeaderFooter($raw);
$failed = 0;
foreach (['MAAN Aptitude Materials', 'www.example.com', 'Page 1', 'Page 2'] as $noise) {
    if (str_contains($out, $noise)) {
        echo "FAIL still contains: {$noise}\n";
        $failed++;
    }
}
foreach (['16) Who is shortest', '17) What is 2+2', 'Directions:'] as $keep) {
    if (!str_contains($out, $keep)) {
        echo "FAIL missing: {$keep}\n";
        $failed++;
    }
}
echo $failed === 0 ? "PASS header/footer strip\n" : "FAIL count={$failed}\n";
exit($failed > 0 ? 1 : 0);
