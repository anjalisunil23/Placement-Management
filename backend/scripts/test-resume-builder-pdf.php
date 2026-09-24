<?php

declare(strict_types=1);

/**
 * Resume Builder PDF route + Chromium export wiring checks.
 *
 * Usage: php backend/scripts/test-resume-builder-pdf.php
 */

$root = dirname(__DIR__, 2);
$failed = 0;
$passed = 0;

$assert = static function (bool $ok, string $label) use (&$failed, &$passed): void {
    if ($ok) {
        echo "PASS  {$label}\n";
        $passed++;
        return;
    }
    echo "FAIL  {$label}\n";
    $failed++;
};

$js = (string) file_get_contents($root . '/js/resume-builder.js');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');
$service = (string) file_get_contents($root . '/backend/services/ResumeBuilderPdfService.php');
$css = (string) file_get_contents($root . '/css/resume-builder.css');
$settings = (string) file_get_contents($root . '/settings.html');
$composer = (string) file_get_contents($root . '/composer.json');

$assert(str_contains($js, 'function generateResumePdf'), 'generateResumePdf helper exists');
$assert(str_contains($js, 'data-rb-generate-pdf'), 'Generate Resume PDF button marker');
$assert(!str_contains($js, 'Print Preview'), 'Print Preview removed from Resume Builder flow');
$assert(!str_contains($js, 'window.print()'), 'browser print dialog not used');
$assert(str_contains($js, '/student/resume-builder/pdf'), 'frontend posts to PDF endpoint');
$assert(str_contains($js, 'Generating PDF...'), 'loading state on generate button');
$assert(str_contains($js, 'documentHtml'), 'preview HTML posted to converter');
$assert(str_contains($js, 'id="rbResumePrintRoot"'), 'Live Preview root id');
$assert(str_contains($css, 'Times New Roman'), 'Live Preview uses Times New Roman');
$assert(str_contains($css, 'rb-resume-edu-row'), 'education flex rows in CSS');
$assert(str_contains($index, '/student/resume-builder/pdf'), 'PDF API route registered');
$assert(str_contains($controller, 'function downloadPdf'), 'downloadPdf controller action');
$assert(str_contains($controller, 'findByUserId'), 'PDF uses authenticated student only');
$assert(str_contains($service, 'wrapLivePreviewDocument'), 'standalone Live Preview wrapper');
$assert(str_contains($service, 'resume-builder.css'), 'existing resume CSS reused');
$assert(str_contains($service, 'printHtmlToPdfWithChromium'), 'Chromium HTML-to-PDF');
$assert(str_contains($service, '--print-to-pdf='), 'Chrome print-to-pdf');
$assert(str_contains($service, 'printHtmlToPdfWithTcpdf'), 'TCPDF fallback when Chromium is unavailable');
$assert(!str_contains($service, 'replaceFlexRowWithTable'), 'no PDF-specific flex rewrite');
$assert(str_contains($service, '_Resume.pdf'), 'filename pattern FirstName_LastName_Resume.pdf');
$assert(str_contains($service, 'assertGenerationAllowed'), 'server-side PDF gate');
$assert(str_contains($service, 'size: A4 portrait'), 'A4 page rule in wrapper');
$assert(str_contains($composer, 'tecnickcom/tcpdf'), 'TCPDF remains for other reports');
$assert(str_contains($settings, 'resume-builder.js?v=20260924rb31'), 'JS cache bust');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
