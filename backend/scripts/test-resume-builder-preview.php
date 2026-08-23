<?php

declare(strict_types=1);

/**
 * Resume Builder ATS preview redesign checks (no database / API changes).
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
$assert(str_contains($js, 'function openLivePreview'), 'live preview page opener exists');
$assert(str_contains($js, 'data-rb-preview-back'), 'Back to Resume Builder button');
$assert(str_contains($js, 'Print Preview'), 'Print Preview label');
$assert(str_contains($js, "parts.join(' | ')"), 'contact line uses pipe separators');
$assert(!str_contains(substr($js, strpos($js, 'function previewContactLine'), 800), 'registerNumber'), 'header contact omits register number');
$assert(str_contains($js, 'Professional Experience'), 'experience heading');
$assert(str_contains($js, 'Activities / Leadership'), 'activities/leadership heading');
$assert(str_contains($js, "activityType === 'Achievement'"), 'achievements filtered separately');
$assert(str_contains($js, 'Technical Skills'), 'skills category labels');
$assert(str_contains($js, 'Tools & Platforms'), 'tools category label');
$assert(str_contains($js, 'rb-resume-edu-row'), 'education two-column rows');
$assert(str_contains($js, 'previewBulletsHtml'), 'bullet formatting helper');
$assert(str_contains($js, 'previewEducationHtml()'), 'education before objective order');
$assert(str_contains($css, '210mm'), 'A4 width');
$assert(str_contains($css, '297mm'), 'A4 height');
$assert(str_contains($css, '@media print'), 'print CSS');
$assert(str_contains($css, 'size: A4'), 'print page A4');
$assert(!str_contains($css, 'rb-resume-skill-tag'), 'skill chips removed from resume CSS');
$assert(str_contains($settings, 'resume-builder.css?v=20260824rb16'), 'CSS cache bust');
$assert(str_contains($settings, 'resume-builder.js?v=20260824rb16'), 'JS cache bust');
$assert(!str_contains($schema, 'resume_preview'), 'no preview table');
$assert(!str_contains($index, '/student/resume-builder/preview'), 'no preview API');

// Ensure education section appears before career objective in builder output order.
$eduPos = strpos($js, 'previewEducationHtml(),');
$objPos = strpos($js, 'objective ?');
$assert($eduPos !== false && $objPos !== false && $eduPos < $objPos, 'section order education before objective');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
