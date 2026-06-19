<?php
declare(strict_types=1);

/**
 * Full install: verify MariaDB connection, create tables, seed data.
 * Usage: php backend/scripts/install.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;
use PMS\Models\UserModel;

echo "=== PMS MariaDB Install ===\n\n";

$status = Database::status();
if (!$status['ok']) {
    fwrite(STDERR, "Database connection FAILED.\n");
    fwrite(STDERR, $status['error'] . "\n\n");
    fwrite(STDERR, "Check .env values:\n");
    fwrite(STDERR, "  DB_HOST=" . ($_ENV['DB_HOST'] ?? '') . "\n");
    fwrite(STDERR, "  DB_DATABASE=" . ($_ENV['DB_DATABASE'] ?? '') . "\n");
    fwrite(STDERR, "  DB_USERNAME=" . ($_ENV['DB_USERNAME'] ?? '') . "\n");
    if (PHP_OS_FAMILY === 'Windows' && ($_ENV['DB_HOST'] ?? '') === 'localhost') {
        fwrite(STDERR, "\nOn Windows, cPanel DB_HOST=localhost only works ON the server.\n");
        fwrite(STDERR, "For local dev use DB_HOST=127.0.0.1 with your local MySQL password.\n");
    }
    exit(1);
}

echo "Connected: MariaDB/MySQL {$status['version']}\n";
echo "Database:  {$_ENV['DB_DATABASE']}\n\n";

passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/setup.php'), $code);
if ($code !== 0) {
    exit($code);
}

$admin = (new UserModel())->findByEmail('admin@college.edu');
if ($admin) {
    echo "\nInstall complete. Admin: admin@college.edu / Admin@123456\n";
    exit(0);
}

fwrite(STDERR, "\nInstall finished but admin user was not found.\n");
exit(1);
