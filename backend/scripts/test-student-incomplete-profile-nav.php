<?php

declare(strict_types=1);

/**
 * Incomplete profile must open Profile & Resumes; complete students go to dashboard.
 *
 * Usage: php backend/scripts/test-student-incomplete-profile-nav.php
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

$api = (string) file_get_contents($root . '/js/api.js');
$app = (string) file_get_contents($root . '/js/app.js');
$svc = (string) file_get_contents($root . '/backend/services/StudentProfileEditService.php');
$settings = (string) file_get_contents($root . '/settings.html');

$assert(
    preg_match('/if\s*\(\s*r\s*===\s*[\'"]student[\'"]\s*&&\s*this\._profileIncomplete\s*\)/', $api) === 1,
    'homePage routes incomplete students to settings'
);
$assert(
    str_contains($api, "return 'settings.html';")
        && str_contains($api, '_profileIncomplete'),
    'homePage returns settings.html when profile is incomplete'
);
$assert(
    str_contains($api, "window.location.replace('settings.html')"),
    'enrichFromProfile hard-redirects incomplete students to settings'
);
$assert(
    str_contains($app, 'Auth._profileIncomplete')
        && str_contains($app, "window.location.replace('settings.html')"),
    'app.js gates non-settings pages when profile is incomplete'
);
$assert(str_contains($app, 'studentNeedsPlacementRegistration'), 'app.js still enforces policy registration gate');

$assert(
    str_contains($svc, 'Lock only after an explicit student/staff save'),
    'Academic locks require profileFieldLocks (AES prefill stays editable first visit)'
);
$assert(
    str_contains($svc, 'lockFilledAcademicFields'),
    'First student save locks filled academic fields'
);
$assert(
    str_contains($settings, 'lockedSet.has(path)'),
    'Settings UI freezes academic fields from lockedFields, not mere hasValue'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
