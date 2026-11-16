<?php
/**
 * Workly PostgreSQL PDO Connection Factory (PHP 8+ / Hostinger)
 */

declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
EnvLoader::load();

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
        $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'workly_prod';
        $username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'workly_pg_user';
        $password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
        $sslMode = $_ENV['DB_SSLMODE'] ?? getenv('DB_SSLMODE') ?: 'prefer';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$sslMode}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        self::$connection = new PDO($dsn, $username, $password, $options);
        self::$connection->exec("SET CLIENT_ENCODING TO 'UTF8'");
        self::$connection->exec("SET TIME ZONE 'UTC'");

        return self::$connection;
    }
}
