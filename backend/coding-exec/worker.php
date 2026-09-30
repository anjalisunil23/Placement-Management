<?php

declare(strict_types=1);

/**
 * Coding execution worker — student code runs only in this CLI process + sandbox.
 * Web PHP must spawn this script; it must not be web-accessible.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/bootstrap.php';

use PMS\Services\CodingSandboxEngine;

$jobPath = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $jobPath = substr($arg, 6);
    }
}
if ($jobPath === '' || !is_readable($jobPath)) {
    fwrite(STDERR, "Missing --job= path\n");
    exit(2);
}

$raw = file_get_contents($jobPath);
$job = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($job)) {
    fwrite(STDERR, "Invalid job JSON\n");
    exit(2);
}

$resultPath = (string) ($job['result_path'] ?? '');
$engine = new CodingSandboxEngine();
$result = $engine->execute($job);

$encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    exit(3);
}
if ($resultPath !== '') {
    file_put_contents($resultPath, $encoded);
    @chmod($resultPath, 0600);
}
echo $encoded;
