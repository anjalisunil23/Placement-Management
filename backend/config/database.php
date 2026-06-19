<?php

declare(strict_types=1);

namespace PMS\Config;

use PDO;
use PDOException;

/**
 * MariaDB connection singleton.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $name = $_ENV['DB_DATABASE'] ?? 'pms_db';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /** Quick connectivity check for health endpoints and install scripts. */
    public static function ping(): bool
    {
        try {
            self::pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ok: bool, version: string|null, error: string|null} */
    public static function status(): array
    {
        try {
            $pdo = self::pdo();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['ok' => true, 'version' => $version, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'version' => null, 'error' => $e->getMessage()];
        }
    }

    /** Create tables from schema.sql (run once on setup). */
    public static function setupIndexes(): void
    {
        $schemaFile = dirname(__DIR__) . '/database/schema.sql';
        if (!is_readable($schemaFile)) {
            throw new \RuntimeException('Schema file not found: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('Schema file is empty.');
        }

        $pdo = self::pdo();
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
