<?php

declare(strict_types=1);

/**
 * Ensure all schema tables exist (safe to run on every deploy).
 *
 * Usage: php backend/scripts/ensure-schema.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "Missing vendor/autoload.php — run composer install first.\n");
    exit(1);
}

require_once $autoload;

$dotenv = Dotenv\Dotenv::createImmutable($root);
$dotenv->safeLoad();

use PMS\Config\Database;
use PMS\Schemas\Collections;

try {
    Database::setupIndexes();
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$required = [
    Collections::USERS,
    Collections::STUDENTS,
    Collections::STAFF,
    Collections::PLACEMENT_OFFICERS,
    Collections::COMPANIES,
    Collections::ALUMNI,
    Collections::DEPARTMENTS,
    Collections::DRIVES,
    Collections::APPLICATIONS,
    Collections::JOBS,
    Collections::NOTIFICATIONS,
    Collections::RESUMES,
    Collections::BLACKLIST,
    Collections::RULES,
    Collections::REPORTS,
    Collections::RECOMMENDATIONS,
    Collections::ALUMNI_REFERRALS,
    Collections::ALUMNI_JOB_POSTS,
    Collections::RECRUITMENT_RESULTS,
    Collections::SYSTEM_SETTINGS,
    Collections::PUBLIC_PAGE_CONTENT,
    Collections::PLACEMENT_NEWS,
    Collections::SUCCESS_STORIES,
    Collections::BROADCAST_LOGS,
];

$existing = Database::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($required, $existing));

if ($missing !== []) {
    fwrite(STDERR, 'Missing tables after setup: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "Schema OK (" . count($existing) . " tables).\n";
