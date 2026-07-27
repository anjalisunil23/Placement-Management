<?php

declare(strict_types=1);

/**
 * Delete all placement news items.
 *
 * Usage: php backend/scripts/clear-placement-news.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor autoload at {$autoload}\n");
    exit(1);
}
require_once $autoload;
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\PlacementNewsModel;

$deleted = (new PlacementNewsModel())->deleteAll();
echo "Deleted {$deleted} placement news item(s).\n";
