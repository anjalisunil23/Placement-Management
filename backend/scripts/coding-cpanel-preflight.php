<?php

declare(strict_types=1);

/**
 * Run on production (SSH or cPanel Terminal) after updating .env:
 *   php backend/scripts/coding-cpanel-preflight.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (file_exists($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Services\CodeExecutionService;

$fail = 0;
$ok = static function (string $msg) use (&$fail): void {
    echo '[OK] ' . $msg . PHP_EOL;
};
$bad = static function (string $msg) use (&$fail): void {
    echo '[FAIL] ' . $msg . PHP_EOL;
    $fail += 1;
};

$env = static function (string $key, string $default = ''): string {
    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }

    return $default;
};

$executor = strtolower(trim($env('CODING_EXECUTOR')));
$backends = strtolower(trim($env('CODING_REMOTE_BACKENDS')));
$wandbox = strtolower(trim($env('CODING_WANDBOX_ENABLED', 'true')));
$pistonUrl = trim($env('CODING_PISTON_URL'));

if (in_array($executor, ['remote_only', 'wandbox_only', 'remote', 'wandbox'], true)) {
    $ok('CODING_EXECUTOR=' . $executor);
} else {
    $bad('CODING_EXECUTOR should be remote_only (got: ' . ($executor !== '' ? $executor : '(empty)') . ')');
}

if ($backends === 'wandbox') {
    $ok('CODING_REMOTE_BACKENDS=wandbox');
} else {
    $bad('CODING_REMOTE_BACKENDS should be wandbox (got: ' . ($backends !== '' ? $backends : '(empty)') . ')');
}

if (!in_array($wandbox, ['false', '0', 'off'], true)) {
    $ok('CODING_WANDBOX_ENABLED is on');
} else {
    $bad('CODING_WANDBOX_ENABLED must not be false for Wandbox-only hosting');
}

if ($pistonUrl === '') {
    $ok('CODING_PISTON_URL is unset (correct for Wandbox-only)');
} elseif (str_contains(strtolower($pistonUrl), 'emkc.org')) {
    $bad('Remove CODING_PISTON_URL emkc.org — use Wandbox only or a self-hosted Piston URL');
} else {
    $ok('CODING_PISTON_URL set to self-hosted (optional)');
}

if (function_exists('curl_init')) {
    $ok('PHP curl extension loaded');
} else {
    $bad('Enable PHP curl in cPanel → Select PHP Version → Extensions');
}

if ($fail === 0) {
    $svc = new CodeExecutionService();
    $out = $svc->run('Python', 'print(42)', '', 8000);
    if (($out['ok'] ?? false) === true && trim((string) ($out['stdout'] ?? '')) === '42') {
        $engine = (string) ($out['execEngine'] ?? $out['execBackend'] ?? '');
        $ok('Execute path works (engine=' . ($engine !== '' ? $engine : 'wandbox') . ')');
    } else {
        $bad('CodeExecutionService run failed: ' . json_encode([
            'status' => $out['status'] ?? '',
            'stderr' => substr((string) ($out['stderr'] ?? ''), 0, 200),
        ]));
    }
}

exit($fail === 0 ? 0 : 1);
