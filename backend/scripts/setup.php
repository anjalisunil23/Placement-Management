<?php

declare(strict_types=1);

/**
 * Database setup script — creates indexes and default admin user.
 *
 * Usage: php backend/scripts/setup.php
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Missing vendor autoload.\n\n");
    fwrite(STDERR, "Fix:\n");
    fwrite(STDERR, "  1) Install the MongoDB PHP extension (ext-mongodb) for your PHP (XAMPP).\n");
    fwrite(STDERR, "  2) Run: composer install\n");
    fwrite(STDERR, "  3) Then rerun: php backend/scripts/setup.php\n\n");
    fwrite(STDERR, "Details: {$autoload} not found.\n");
    exit(1);
}
require_once $autoload;

if (!extension_loaded('mongodb')) {
    fwrite(STDERR, "Missing PHP extension: mongodb (ext-mongodb).\n\n");
    fwrite(STDERR, "Fix (XAMPP on Windows):\n");
    fwrite(STDERR, "  - Download the matching php_mongodb.dll for PHP " . PHP_VERSION . " (TS / x64) from PECL.\n");
    fwrite(STDERR, "  - Copy it into: C:\\\\xampp\\\\php\\\\ext\\\\\n");
    fwrite(STDERR, "  - Enable it in: C:\\\\xampp\\\\php\\\\php.ini (add: extension=mongodb)\n");
    fwrite(STDERR, "  - Restart Apache / re-open terminals.\n\n");
    fwrite(STDERR, "Then run:\n");
    fwrite(STDERR, "  composer install\n");
    fwrite(STDERR, "  php backend/scripts/setup.php\n");
    exit(1);
}

require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\PlacementNewsModel;
use PMS\Models\PublicPageContentModel;
use PMS\Models\RuleModel;
use PMS\Models\SystemSettingsModel;
use PMS\Models\UserModel;
use PMS\Utils\Security;

echo "PMS Setup — Creating indexes...\n";
Database::setupIndexes();
echo "Indexes created.\n";

$userModel = new UserModel();
$admin = $userModel->findByEmail('admin@college.edu');

if (!$admin) {
    $id = $userModel->createUser([
        'name'     => 'System Administrator',
        'email'    => 'admin@college.edu',
        'password' => 'Admin@123456',
        'role'     => 'admin',
        'status'   => 'active',
        'approved' => true,
    ]);
    echo "Default admin created (admin@college.edu / Admin@123456)\n";
    echo "User ID: {$id}\n";
    echo "IMPORTANT: Change the default password after first login!\n";
} else {
    echo "Admin user already exists.\n";
}

// Default site settings and public page content
(new SystemSettingsModel())->save([
    'placementYear'    => '2025-26',
    'emailFrom'        => 'placement@college.edu',
    'maxUploadMb'      => 10,
    'smtpEnabled'      => true,
    'notifyOnApproval' => true,
]);
echo "System settings initialized.\n";

(new PublicPageContentModel())->save([
    'season'       => '2025-26',
    'placed'       => 2154,
    'companies'    => 142,
    'highestPkg'   => 68,
    'avgPkg'       => 9.4,
    'medianPkg'    => 8.2,
    'lowestPkg'    => 3.5,
    'headline'     => 'Where ambition meets opportunity',
    'achievements' => 'Record ₹68 LPA international offer · 92.5% MCA placement rate',
]);
echo "Public page content initialized.\n";

$newsModel = new PlacementNewsModel();
if ($newsModel->count([]) === 0) {
    $seedNews = [
        ['title' => 'Record-breaking season kicks off', 'summary' => 'Over 142 companies have already confirmed campus visits for 2025–26.', 'date' => '2025-11-12', 'link' => ''],
        ['title' => 'Google announces 28 SDE offers', 'summary' => 'One of the largest cohorts hired from a single drive this year.', 'date' => '2025-10-30', 'link' => ''],
        ['title' => 'New mentorship program launched', 'summary' => 'Alumni from 60+ companies join the placement readiness program.', 'date' => '2025-10-18', 'link' => ''],
    ];
    foreach ($seedNews as $item) {
        $newsModel->createNews($item);
    }
    echo "Placement news seeded.\n";
} else {
    echo "Placement news already exists.\n";
}

if (!(new RuleModel())->getActiveRule()) {
    (new RuleModel())->saveActiveRule([
        'minCgpa' => 7.5,
        'maxBacklog' => 0,
        'maxPlacementChances' => 5,
        'blockPlacedStudents' => true,
        'allowPlacedForSelectedDrives' => false,
        'placementPolicy' => 'Students with active backlogs are ineligible for Tier 1 drives.',
        'policyVersion' => 'v1.0',
    ]);
    echo "Placement rules seeded.\n";
}

// Create upload directories
$config = require dirname(__DIR__) . '/config/app.php';
foreach (['resume_dir', 'reports_dir', 'jd_dir'] as $key) {
    $dir = $config['uploads'][$key];
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: {$dir}\n";
    }
}

echo "Setup complete.\n";
