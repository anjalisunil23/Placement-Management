<?php

declare(strict_types=1);

/**
 * Resume Builder PDF route + UI wiring checks.
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
$settings = (string) file_get_contents($root . '/settings.html');

$assert(str_contains($js, 'function generateResumePdf'), 'generateResumePdf helper exists');
$assert(str_contains($js, 'data-rb-generate-pdf'), 'Generate Resume PDF button marker');
$assert(!str_contains($js, 'Print Preview'), 'Print Preview removed from Resume Builder flow');
$assert(!str_contains($js, 'data-rb-preview-print'), 'print preview action removed');
$assert(!str_contains($js, 'window.print()'), 'browser print dialog not used');
$assert(!str_contains($js, 'rb-printing-resume'), 'print-class PDF path removed from JS');
$assert(str_contains($js, '/student/resume-builder/pdf'), 'frontend posts to PDF endpoint');
$assert(str_contains($js, 'Generating PDF...'), 'loading state on generate button');
$assert(str_contains($js, 'documentHtml'), 'preview HTML posted to converter');
$assert(str_contains($js, 'resumePdfFilename'), 'client filename fallback helper');
$assert(str_contains($js, 'pdfGenerationAllowed'), 'PDF availability helper');
$assert(str_contains($js, 'hasPdfMinimumPersonal'), 'PDF minimum personal check');
$assert(str_contains($js, 'hasPdfMinimumEducation'), 'PDF minimum education check');
$assert(!str_contains($js, 'Complete required sections first'), 'generic completion block removed from JS');
$assert(str_contains($js, 'Please complete your basic profile and education details before generating your resume.'), 'precise PDF block message in JS');
$assert(str_contains($index, '/student/resume-builder/pdf'), 'PDF API route registered');
$assert(str_contains($controller, 'function downloadPdf'), 'downloadPdf controller action');
$assert(str_contains($controller, 'findByUserId'), 'PDF uses authenticated student only');
$assert(!str_contains($controller, 'student_id'), 'no client student_id accepted');
$assert(str_contains($service, 'use TCPDF'), 'TCPDF library used');
$assert(str_contains($service, '_Resume.pdf'), 'filename pattern FirstName_LastName_Resume.pdf');
$assert(str_contains($service, 'assertGenerationAllowed'), 'server-side PDF gate');
$assert(str_contains($service, 'documentMeetsPdfMinimum'), 'server validates preview HTML minimum');
$assert(str_contains($service, 'prepareDocumentHtmlForPdf'), 'PDF HTML transform for TCPDF');
$assert(str_contains($service, 'replaceFlexRowWithTable'), 'flex rows converted to PDF-safe tables');
$assert(str_contains($service, "SetFont('times'"), 'Times font for PDF parity');
$assert(!str_contains($service, 'Complete required sections first'), 'generic completion block removed from service');
$assert(str_contains($service, 'Please complete your basic profile and education details before generating your resume.'), 'precise PDF block message in service');
$assert(str_contains($settings, 'resume-builder.js?v=20260924rb29'), 'JS cache bust for PDF flow');

$sampleHtml = <<<'HTML'
<article class="rb-resume-doc"><header class="rb-resume-header"><h1 class="rb-resume-name">Adonia Cyrus</h1><p class="rb-resume-contact">9876543210 | adonia@example.com</p></header><section class="rb-resume-section"><h2 class="rb-resume-h2">Education</h2><div class="rb-resume-edu"></div></section></article>
HTML;
$assert((bool) preg_match('/class="rb-resume-name"[^>]*>\s*[^<\s]/', $sampleHtml), 'sample HTML has name');
$assert((bool) preg_match('/class="rb-resume-contact"[^>]*>\s*[^<\s]/', $sampleHtml), 'sample HTML has contact');
$assert((bool) preg_match('/class="rb-resume-h2"[^>]*>\s*Education\s*<\/h2>/i', $sampleHtml), 'sample HTML has education heading');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
