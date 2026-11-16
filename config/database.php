<?php

declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
EnvLoader::load();

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $required = [
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASS',
        ];

        $config = [];

        foreach ($required as $key) {
            $value = getenv($key);

            if ($value === false || $value === '') {
                $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
            }

            if ($value === '') {
                throw new RuntimeException(
                    "Missing database environment variable: {$key}"
                );
            }

            $config[$key] = $value;
        }

        $sslMode = getenv('DB_SSLMODE');

        if ($sslMode === false || $sslMode === '') {
            $sslMode = $_ENV['DB_SSLMODE'] ?? 'prefer';
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $config['DB_HOST'],
            $config['DB_PORT'],
            $config['DB_NAME'],
            $sslMode
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        self::$connection = new PDO(
            $dsn,
            $config['DB_USER'],
            $config['DB_PASS'],
            $options
        );

        self::$connection->exec("SET CLIENT_ENCODING TO 'UTF8'");
        self::$connection->exec("SET TIME ZONE 'UTC'");

        return self::$connection;
    }
}
