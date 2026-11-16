<?php
/**
 * Workly Zero-Dependency .env Loader
 */

declare(strict_types=1);

final class EnvLoader
{
    private static bool $loaded = false;

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $path = $envPath ?? (__DIR__ . '/.env');
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));

            if ($name === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false || getenv($name) === '') {
                putenv("{$name}={$value}");
            }
            if (!isset($_ENV[$name]) || $_ENV[$name] === '') {
                $_ENV[$name] = $value;
            }
            if (!isset($_SERVER[$name]) || $_SERVER[$name] === '') {
                $_SERVER[$name] = $value;
            }
        }
    }
}
