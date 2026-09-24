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
$assert(str_contains($js, '/student/resume-builder/pdf'), 'PDF API path in frontend');
$assert(str_contains($js, 'resumePdfFilename'), 'client filename fallback helper');
$assert(str_contains($js, 'pdfGenerationAllowed'), 'PDF availability helper');
$assert(str_contains($index, '/student/resume-builder/pdf'), 'PDF API route registered');
$assert(str_contains($controller, 'function downloadPdf'), 'downloadPdf controller action');
$assert(str_contains($controller, 'currentStudentId'), 'PDF uses authenticated student only');
$assert(str_contains($service, 'use TCPDF'), 'TCPDF library used');
$assert(str_contains($service, '_Resume.pdf'), 'filename pattern FirstName_LastName_Resume.pdf');
$assert(str_contains($service, 'assertGenerationAllowed'), 'server-side completion gate');
$assert(str_contains($settings, 'resume-builder.js?v=20260924rb22'), 'JS cache bust for PDF flow');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
