<?php

declare(strict_types=1);

/**
 * Drop the retired student_details table.
 *
 * Usage: php backend/scripts/drop-student-details-table.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;

$migration = dirname(__DIR__) . '/database/migrations/003_drop_student_details.sql';
if (!is_readable($migration)) {
    fwrite(STDERR, "Migration file not found: {$migration}\n");
    exit(1);
}

$sql = trim((string) file_get_contents($migration));
if ($sql === '') {
    fwrite(STDERR, "Migration file is empty.\n");
    exit(1);
}

echo "=== Drop student_details ===\n\n";

try {
    $pdo = Database::pdo();
    $pdo->exec($sql);
    echo "OK    student_details dropped (or was already absent).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL  ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nCampus directory reads now use AES sync snapshot files only.\n";
