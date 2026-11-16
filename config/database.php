<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
    error_log('PDO: ' . (extension_loaded('pdo') ? 'YES' : 'NO'));

    error_log(
        'PDO_PGSQL: ' .
        (extension_loaded('pdo_pgsql') ? 'YES' : 'NO')
    );

    error_log(
        'DATABASE_URL: ' .
        (getenv('DATABASE_URL') ? 'AVAILABLE' : 'MISSING')
    );


        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        // Neon / Vercel connection strings.
        $databaseUrl =
            getenv('DATABASE_URL')
            ?: getenv('POSTGRES_URL')
            ?: getenv('POSTGRES_URL_NON_POOLING');

        if (!$databaseUrl) {
            throw new RuntimeException(
                'No PostgreSQL connection URL configured.'
            );
        }

        $url = parse_url($databaseUrl);

        if ($url === false || empty($url['host'])) {
            throw new RuntimeException(
                'Invalid PostgreSQL connection URL.'
            );
        }

        $host = $url['host'];
        $port = $url['port'] ?? 5432;
        $dbname = rawurldecode(ltrim($url['path'] ?? '', '/'));

        $username = rawurldecode($url['user'] ?? '');
        $password = rawurldecode($url['pass'] ?? '');

        // Neon endpoint ID: first hostname segment.
        $endpointId = explode('.', $host)[0];

        // Remove pooler suffix if present.
        $endpointId = preg_replace('/-pooler$/', '', $endpointId);

        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;sslmode=require;options='endpoint=%s'",
            $host,
            $port,
            $dbname,
            $endpointId
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ]
            );

            self::$connection->exec(
                "SET TIME ZONE 'UTC'"
            );

            return self::$connection;

        } catch (PDOException $e) {
            error_log(
                '[Workly Database] ' . $e->getMessage()
            );

            throw $e;
        }
    }
}
