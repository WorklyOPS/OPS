<?php
/**
 * Workly Security Helper (PHP 8 Production)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';

final class SecurityHelper
{
    public static function sendApiHeaders(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        self::sendCommonSecurityHeaders();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    public static function sendHtmlHeaders(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        self::sendCommonSecurityHeaders();
    }

    private static function sendCommonSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data:; frame-src https://challenges.cloudflare.com; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
    }

    public static function configureSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');

            session_set_cookie_params([
                'lifetime' => 86400,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            @session_start();
        }
    }

    public static function getClientFingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $firstIp = trim(explode(',', $ip)[0]);
        $ipSubnet = implode('.', array_slice(explode('.', $firstIp), 0, 3));
        return hash('sha256', $ua . '|' . $ipSubnet);
    }

    public static function verifySessionIntegrity(): bool
    {
        if (!isset($_SESSION['workly_fp'])) {
            $_SESSION['workly_fp'] = self::getClientFingerprint();
            return true;
        }
        return hash_equals((string) $_SESSION['workly_fp'], self::getClientFingerprint());
    }

    public static function csrfToken(): string
    {
        self::configureSecureSession();
        if (empty($_SESSION['workly_csrf_token'])) {
            $_SESSION['workly_csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['workly_csrf_token'];
    }

    public static function rotateCsrfToken(): string
    {
        self::configureSecureSession();
        $_SESSION['workly_csrf_token'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['workly_csrf_token'];
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public static function sanitizePlainText(mixed $value, int $maxLength = 4000): string
    {
        if ($value === null) {
            return '';
        }
        $stripped = strip_tags((string) $value);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $stripped) ?? '';
        return mb_substr(trim($cleaned), 0, $maxLength, 'UTF-8');
    }

    public static function sanitizeUrl(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return '';
        }
        $trimmed = trim($url);
        if (str_starts_with($trimmed, '/')) {
            return $trimmed;
        }
        $lower = strtolower(preg_replace('/\s+/', '', $trimmed) ?? '');
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'vbscript:') || str_starts_with($lower, 'data:')) {
            return '';
        }
        $sanitized = filter_var($trimmed, FILTER_SANITIZE_URL);
        if (!$sanitized) {
            return '';
        }
        $scheme = parse_url($sanitized, PHP_URL_SCHEME);
        if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }
        return $sanitized;
    }

    public static function verifyTurnstile(string $token): bool
    {
        $secret = Secrets::get('CLOUDFLARE_TURNSTILE_SECRET_KEY');
        if ($secret === '') {
            return true;
        }
        if ($token === '') {
            return false;
        }

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if (!is_string($resp)) {
            return false;
        }
        $decoded = json_decode($resp, true);
        return !empty($decoded['success']);
    }
}
