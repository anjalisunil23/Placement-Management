<?php

declare(strict_types=1);

/**
 * Database setup script — creates indexes and default admin user.
 *
 * Usage: php backend/scripts/setup.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
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
