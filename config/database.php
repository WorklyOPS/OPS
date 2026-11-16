<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $databaseUrl = getenv('DATABASE_URL');

        if (!$databaseUrl) {
            throw new RuntimeException('DATABASE_URL is not configured.');
        }

        $url = parse_url($databaseUrl);

        if ($url === false) {
            throw new RuntimeException('Invalid DATABASE_URL.');
        }

        $host = $url['host'] ?? '';
        $port = $url['port'] ?? 5432;
        $dbname = ltrim($url['path'] ?? '', '/');
        $username = $url['user'] ?? '';
        $password = $url['pass'] ?? '';

        if (!$host || !$dbname || !$username) {
            throw new RuntimeException('Incomplete database configuration.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
            $host,
            $port,
            $dbname
        );

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);

        self::$connection->exec("SET TIME ZONE 'UTC'");

        return self::$connection;
    }
}