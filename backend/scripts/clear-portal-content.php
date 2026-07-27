<?php

declare(strict_types=1);

/**
 * Clear placement news + success stories data only (keeps UI/fields).
 *
 * Usage: php backend/scripts/clear-portal-content.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor autoload. Run: composer install\n");
    exit(1);
}
require_once $autoload;
require dirname(__DIR__) . '/config/app.php';

use PMS\Models\PlacementNewsModel;
use PMS\Models\SuccessStoryModel;

$newsDeleted = (new PlacementNewsModel())->deleteAll();
$storiesDeleted = (new SuccessStoryModel())->deleteAll();

echo "Cleared placement news: {$newsDeleted}\n";
echo "Cleared success stories: {$storiesDeleted}\n";
echo "Done. Forms/fields unchanged.\n";
