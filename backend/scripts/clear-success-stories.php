<?php

declare(strict_types=1);

/**
 * Delete all success stories (clears seeded / current public portal stories).
 *
 * Usage: php backend/scripts/clear-success-stories.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor autoload at {$autoload}\n");
    exit(1);
}
require_once $autoload;
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\SuccessStoryModel;

$deleted = (new SuccessStoryModel())->deleteAll();
echo "Deleted {$deleted} success story/stories.\n";
