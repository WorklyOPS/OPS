<?php
/**
 * Workly Production Secrets Registry
 */

declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';
EnvLoader::load();

final class Secrets
{
    public static function get(string $key, string $default = ''): string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val !== false && $val !== null && $val !== '') {
            return (string) $val;
        }

        if ($key === 'JWT_SECRET' || $key === 'CSRF_SALT') {
            $entropy = ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '|' . php_uname('n');
            return hash('sha256', 'workly_runtime_' . $key . '_' . $entropy);
        }

        return $default;
    }

    public static function isConfigured(string $key): bool
    {
        $val = self::get($key, '');
        return $val !== '' && !str_starts_with($val, 'REPLACE_WITH_');
    }

    public static function publicStatus(): array
    {
        return [
            'turnstile_configured' => self::isConfigured('CLOUDFLARE_TURNSTILE_SITE_KEY') && self::isConfigured('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
            'turnstile_site_key'   => self::get('CLOUDFLARE_TURNSTILE_SITE_KEY'),
            'google_configured'    => self::isConfigured('GOOGLE_CLIENT_ID') && self::isConfigured('GOOGLE_CLIENT_SECRET'),
            'smtp_configured'      => self::isConfigured('SMTP_HOST') && self::isConfigured('SMTP_PASS'),
            'smtp_host'            => self::get('SMTP_HOST'),
            'app_url'              => self::get('APP_URL'),
        ];
    }
}
