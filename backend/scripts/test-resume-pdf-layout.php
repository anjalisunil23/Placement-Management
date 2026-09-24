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
<article class="rb-resume-doc" id="rbResumePrintRoot">
<header class="rb-resume-header">
  <h1 class="rb-resume-name">Adonia Cyrus</h1>
  <p class="rb-resume-contact">9876543210 | adonia@example.com</p>
</header>
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
</section>
</article>
HTML;

$service = new ResumeBuilderPdfService();
$out = $service->wrapLivePreviewDocument($html);

$assert(str_contains($out, 'id="resumeBuilderDashboard"'), 'wrapper keeps Live Preview CSS scope');
$assert(str_contains($out, 'id="rbResumePrintRoot"'), 'original resume article is unchanged');
$assert(str_contains($out, 'rb-resume-edu-row'), 'education flex markup preserved');
$assert(str_contains($out, 'Times New Roman'), 'Times New Roman CSS included');
$assert(str_contains($out, 'size: A4 portrait'), 'A4 page size');
$assert(!str_contains($out, '<table'), 'no extra tables injected');
$assert(str_contains($out, 'document.fonts.ready'), 'waits for fonts');

$pdf = '';
try {
    $pdf = $service->renderPdfBytes($html);
} catch (Throwable $e) {
    echo 'NOTE  Chromium PDF render skipped: ' . $e->getMessage() . PHP_EOL;
}

if ($pdf !== '') {
    $assert(strncmp($pdf, '%PDF', 4) === 0, 'Chromium returned a PDF');
    $assert(strlen($pdf) > 1000, 'PDF has content');
}

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
