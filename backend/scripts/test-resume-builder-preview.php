<?php

declare(strict_types=1);

/**
 * Resume Builder preview isolation checks (no database changes).
 *
 * Usage: php backend/scripts/test-resume-builder-preview.php
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
$css = (string) file_get_contents($root . '/css/resume-builder.css');
$settings = (string) file_get_contents($root . '/settings.html');
$schema = (string) file_get_contents($root . '/backend/database/schema.sql');
$index = (string) file_get_contents($root . '/backend/api/index.php');

$assert(str_contains($js, 'function buildResumeDocumentHtml'), 'resume document builder exists');
$assert(str_contains($js, 'function previewCardBody'), 'preview card body exists');
$assert(str_contains($js, 'data-rb-live-preview'), 'Live Preview button wired');
$assert(str_contains($js, 'data-rb-preview-refresh'), 'Refresh Preview button wired');
$assert(str_contains($js, 'data-rb-preview-print'), 'Print button wired');
$assert(str_contains($js, 'This preview represents how your resume will appear when exported.'), 'export notice present');
$assert(str_contains($js, "section.id === 'preview'"), 'preview section card rendered');
$assert(str_contains($js, 'Career Objective'), 'objective section in preview');
$assert(str_contains($js, 'Achievements &amp; Activities') || str_contains($js, 'Achievements & Activities'), 'activities section in preview');
$assert(str_contains($js, 'rb-resume-skill-tag'), 'skills rendered as tags');
$assert(str_contains($js, 'localeCompare(aKey)'), 'experience chronological sort');
$assert(str_contains($js, 'if (!state.projects.length) return \'\''), 'empty projects section hidden');
$assert(str_contains($js, 'if (!state.skills.length) return \'\''), 'empty skills section hidden');
$assert(str_contains($css, 'rb-preview-paper'), 'paper-style container CSS');
$assert(str_contains($css, '210mm'), 'A4 width styling');
$assert(str_contains($css, '297mm'), 'A4 height styling');
$assert(str_contains($css, '@media print'), 'print-friendly styles');
$assert(str_contains($css, 'size: A4'), 'print page size A4');
$assert(str_contains($settings, 'resume-builder.css?v=20260824rb15'), 'CSS cache bust updated');
$assert(str_contains($settings, 'resume-builder.js?v=20260824rb15'), 'JS cache bust updated');
$assert(!str_contains($schema, 'resume_preview'), 'no preview table added to schema');
$assert(!str_contains($index, '/student/resume-builder/preview'), 'no new preview API route');
$assert(!str_contains($js, 'Generate PDF') || str_contains($js, 'Generate Resume PDF'), 'PDF generation still placeholder only');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
