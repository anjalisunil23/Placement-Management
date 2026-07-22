<?php

declare(strict_types=1);

/**
 * Guards against incomplete-profile redirect traps that blink student pages.
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

// homePage / resolveRedirect must not force incomplete students onto settings.
$assert(
    !preg_match('/if\s*\(\s*r\s*===\s*[\'"]student[\'"]\s*&&\s*this\._profileIncomplete\s*\)/', $api),
    'homePage does not branch on _profileIncomplete'
);
$assert(
    !preg_match('/if\s*\(\s*this\.role\(\)\s*===\s*[\'"]student[\'"]\s*&&\s*this\._profileIncomplete\s*\)/', $api),
    'resolveRedirect does not branch on _profileIncomplete'
);
$assert(
    !str_contains($api, "return 'settings.html';\n    }\n    if (u?.dashboard)"),
    'homePage no longer returns settings.html for incomplete profile'
);

$assert(str_contains($api, 'ph_incomplete_profile_nudged'), 'One-time incomplete-profile nudge key exists');
$assert(str_contains($api, 'canNudge'), 'enrichFromProfile guards redirect with canNudge');
$assert(
    str_contains($api, "sessionStorage.setItem('ph_incomplete_profile_nudged'"),
    'Nudge flag is stored in sessionStorage'
);
$assert(
    str_contains($api, "window.location.href = 'settings.html'"),
    'Soft nudge still opens profile page once'
);
$assert(
    str_contains($api, 'ph_missing_fields_reminded')
        && str_contains($api, 'ph_incomplete_profile_nudged'),
    'Logout clears incomplete-profile session keys'
);

$assert(str_contains($app, 'studentNeedsPlacementRegistration'), 'app.js still enforces policy registration gate');
$assert(!str_contains($app, '_profileIncomplete'), 'app.js does not force navigation from _profileIncomplete');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
