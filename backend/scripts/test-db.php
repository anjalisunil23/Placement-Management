<?php
declare(strict_types=1);

/**
 * Test MariaDB connection and list tables.
 * Usage: php backend/scripts/test-db.php
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/app.php';

use PMS\Config\Database;

echo "PMS Database connection test\n";
echo "Host:     " . ($_ENV['DB_HOST'] ?? '?') . "\n";
echo "Database: " . ($_ENV['DB_DATABASE'] ?? '?') . "\n";
echo "User:     " . ($_ENV['DB_USERNAME'] ?? '?') . "\n\n";

try {
    $pdo = Database::pdo();
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "OK  Connected to MariaDB/MySQL {$version}\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'Tables: ' . (count($tables) ? implode(', ', $tables) : '(none — run setup.php)') . "\n";

    if (in_array('users', $tables, true)) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        echo "users rows: {$count}\n";
    }
    exit(0);
} catch (Throwable $e) {
    echo "FAIL " . $e->getMessage() . "\n";
    if (($_ENV['DB_HOST'] ?? '') === 'localhost' && PHP_OS_FAMILY === 'Windows') {
        echo "\nNote: cPanel DB_HOST=localhost only works ON the server.\n";
        echo "For local dev, use DB_HOST=127.0.0.1 with local MySQL/MariaDB credentials.\n";
    }
    exit(1);
}
