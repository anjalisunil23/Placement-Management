<?php

declare(strict_types=1);

/**
 * Refresh student_details.registration_status from students policy acceptance.
 *
 * Usage: php backend/scripts/refresh-student-registration-status.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
require dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/bootstrap-services.php';
pms_load_backend_services(dirname(__DIR__));

use PMS\Models\StudentDetailsModel;

echo "=== Refresh student_details registration_status ===\n\n";

$model = new StudentDetailsModel();
if (!$model->isAvailable()) {
    fwrite(STDERR, "student_details table is not available.\n");
    exit(1);
}

$stats = $model->refreshRegistrationStatuses();
echo 'Updated: ' . ($stats['updated'] ?? 0) . "\n";
echo 'Unchanged: ' . ($stats['unchanged'] ?? 0) . "\n";
echo 'Skipped: ' . ($stats['skipped'] ?? 0) . "\n";
echo "\nDone.\n";
