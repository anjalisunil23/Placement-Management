<?php

declare(strict_types=1);

/**
 * Resume Builder → Resume Bucket integration wiring checks.
 *
 * Usage: php backend/scripts/test-resume-builder-bucket.php
 */

$root = dirname(__DIR__, 2);
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

$js = (string) file_get_contents($root . '/js/resume-builder.js');
$index = (string) file_get_contents($root . '/backend/api/index.php');
$controller = (string) file_get_contents($root . '/backend/student/ResumeBuilderController.php');
$service = (string) file_get_contents($root . '/backend/services/ResumeBuilderPdfService.php');
$settings = (string) file_get_contents($root . '/settings.html');

$assert(str_contains($js, 'data-rb-add-to-bucket'), 'Add to Resume Bucket button marker');
$assert(str_contains($js, 'Add to Resume Bucket'), 'Add to Resume Bucket label');
$assert(str_contains($js, 'addToResumeBucket'), 'addToResumeBucket helper exists');
$assert(str_contains($js, '/student/resume-builder/add-to-bucket'), 'bucket API path in frontend');
$assert(str_contains($js, 'bucketSubmitting'), 'duplicate submission guard');
$assert(str_contains($js, 'Adding...'), 'adding button state');
$assert(str_contains($js, 'Added to Resume Bucket'), 'added button state');
$assert(str_contains($index, '/student/resume-builder/add-to-bucket'), 'bucket API route registered');
$assert(str_contains($controller, 'function addToResumeBucket'), 'addToResumeBucket controller action');
$assert(str_contains($controller, 'ResumeModel'), 'existing ResumeModel reused');
$assert(str_contains($controller, 'ObjectStorageService'), 'existing S3 storage reused');
$assert(str_contains($controller, 'renderPdfBytes'), 'PDF bytes generated from preview HTML');
$assert(str_contains($controller, 'contentHash'), 'duplicate resume content detection');
$assert(str_contains($controller, 'Your current resume is already in the Resume Bucket.'), 'duplicate message');
$assert(str_contains($controller, "profileType = 'General'"), 'General job profile default');
$assert(str_contains($controller, 'buildResumeBucketLabel'), 'dynamic resume bucket label');
$assert(str_contains($controller, 'currentStudentId'), 'authenticated student only');
$assert(str_contains($service, 'function renderPdfBytes'), 'shared PDF byte renderer');
$assert(str_contains($settings, 'resume-builder.js?v=20260924rb27'), 'JS cache bust for bucket flow');

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
