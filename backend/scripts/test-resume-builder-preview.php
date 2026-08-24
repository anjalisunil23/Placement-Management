<?php

declare(strict_types=1);

/**
 * Resume Builder Jake-style preview + professional links checks.
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
$assert(str_contains($js, 'data-rb-link-save'), 'professional links save button');
$assert(str_contains($js, 'loadContactLinks'), 'contact links loader exists');
$assert(str_contains($js, '/student/resume-builder/contact-links'), 'contact links API path');
$assert(!str_contains(substr($js, strpos($js, 'function previewContactLine'), 800), 'registerNumber'), 'header contact omits register number');
$assert(str_contains($js, 'Professional Experience'), 'experience heading');
$assert(str_contains($js, 'Activities / Leadership'), 'activities/leadership heading');
$assert(str_contains($js, "activityType === 'Achievement'"), 'achievements filtered separately');
$assert(str_contains($js, 'previewTechnicalSkillsHtml'), 'technical skills section');
$assert(str_contains($js, 'previewSoftSkillsHtml'), 'soft skills section at end');
$assert(str_contains($js, 'Programming Languages'), 'programming languages label');
$assert(str_contains($js, 'Tools & Platforms'), 'tools category label');
$assert(str_contains($js, 'rb-resume-edu-row'), 'education two-column rows');
$assert(str_contains($js, 'rb-resume-right-bold'), 'bold right-aligned dates/scores');
$assert(str_contains($js, 'rb-resume-tech'), 'italic project technologies');
$assert(str_contains($js, 'rb-resume-rule'), 'section horizontal rules');
$assert(str_contains($js, 'resumeSection('), 'Jake-style section helper');
$assert(str_contains($js, 'previewBulletsHtml'), 'bullet formatting helper');
$assert(str_contains($css, 'Times New Roman'), 'Times New Roman font');
$assert(str_contains($css, 'rb-resume-rule'), 'section rule CSS');
$assert(str_contains($css, '210mm'), 'A4 width');
$assert(str_contains($css, '297mm'), 'A4 height');
$assert(str_contains($css, '@media print'), 'print CSS');
$assert(str_contains($css, 'size: A4'), 'print page A4');
$assert(!str_contains($css, 'rb-resume-skill-tag'), 'skill chips removed from resume CSS');
$assert(str_contains($js, 'previewEducationDuration'), 'education duration helper');
$assert(!str_contains($js, 'educationProgramYears'), 'no invented program-year durations');
$assert(!str_contains($js, 'start + duration'), 'no start+duration calculation');
$assert(str_contains($css, 'text-align: justify'), 'career objective justified');
$assert(str_contains($css, 'screen and (max-width: 767.98px)'), 'mobile stack scoped to screen only');
$assert(str_contains($settings, 'resume-builder.css?v=20260824rb19'), 'CSS cache bust');
$assert(str_contains($settings, 'resume-builder.js?v=20260824rb19'), 'JS cache bust');
$assert(str_contains($schema, 'resume_contact_links'), 'contact links table in schema');
$assert(str_contains($index, '/student/resume-builder/contact-links'), 'contact links API route');
$assert(!str_contains($schema, 'resume_preview'), 'no preview table');
$assert(!str_contains($index, '/student/resume-builder/preview'), 'no preview API');

// Career objective appears before education in builder output order.
$objPos = strpos($js, "resumeSection('Career Objective'");
$eduPos = strpos($js, 'previewEducationHtml(),');
$assert($objPos !== false && $eduPos !== false && $objPos < $eduPos, 'section order objective before education');

// Soft skills appear after certifications/activities.
$softPos = strpos($js, 'previewSoftSkillsHtml(),');
$certPos = strpos($js, 'previewCertificationsHtml(),');
$assert($softPos !== false && $certPos !== false && $softPos > $certPos, 'soft skills after certifications');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
