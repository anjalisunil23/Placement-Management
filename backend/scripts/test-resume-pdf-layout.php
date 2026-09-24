<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PMS\Services\ResumeBuilderPdfService;

$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};

$html = <<<'HTML'
<article class="rb-resume-doc">
<section class="rb-resume-section">
<h2 class="rb-resume-h2">Education</h2>
<hr class="rb-resume-rule" aria-hidden="true" />
<div class="rb-resume-edu">
  <div class="rb-resume-edu-row">
    <div class="rb-resume-edu-degree">Master of Computer Applications</div>
    <div class="rb-resume-edu-year rb-resume-right-bold">2025 - 2027</div>
  </div>
  <div class="rb-resume-edu-row">
    <div class="rb-resume-edu-inst">Amal Jyothi College of Engineering</div>
    <div class="rb-resume-edu-score rb-resume-right-bold">CGPA: 9.07</div>
  </div>
</div>
<div class="rb-resume-edu">
  <div class="rb-resume-edu-row">
    <div class="rb-resume-edu-degree">Bachelor of Computer Applications</div>
    <div class="rb-resume-edu-year rb-resume-right-bold">2022 - 2025</div>
  </div>
  <div class="rb-resume-edu-row">
    <div class="rb-resume-edu-inst">St. Antony's College, Peruvanthanam</div>
    <div class="rb-resume-edu-score rb-resume-right-bold">CGPA: 8.60</div>
  </div>
</div>
</section>
<div class="rb-resume-entry">
  <div class="rb-resume-entry-top">
    <div class="rb-resume-entry-title">Intern</div>
    <div class="rb-resume-entry-right rb-resume-right-bold">Jan 2024 – Present</div>
  </div>
</div>
</article>
HTML;

$ref = new ReflectionClass(ResumeBuilderPdfService::class);
$service = $ref->newInstanceWithoutConstructor();
$method = $ref->getMethod('prepareDocumentHtmlForPdf');
$method->setAccessible(true);
$out = (string) $method->invoke($service, $html);

$tableCount = substr_count($out, '<table');
$eduTableCount = substr_count($out, 'rb-resume-edu-table');

$assert(str_contains($out, 'rb-resume-edu-table'), 'education blocks become merged tables');
$assert($eduTableCount === 2, 'one merged table per education entry');
$assert($tableCount === 3, 'experience row uses a single additional table');
$assert(!str_contains($out, 'rb-resume-edu-row'), 'flex edu rows removed');
$assert(!str_contains($out, 'rb-resume-entry-top'), 'flex entry rows removed');
$assert(!str_contains($out, '<hr'), 'hr replaced for TCPDF');
$assert(str_contains($out, 'rb-resume-rule'), 'section rule preserved as div');
$assert(!str_contains($out, '<section'), 'section tags flattened to div');
$assert(str_contains($out, 'border:none'), 'inline border removal on table cells');
$assert(substr_count($out, '<tr') === 5, 'two rows per education entry plus one experience row');

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
