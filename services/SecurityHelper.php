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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com https://www.gstatic.com https://apis.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data:; frame-src https://challenges.cloudflare.com https://*.firebaseapp.com https://accounts.google.com; connect-src 'self' https://*.googleapis.com https://identitytoolkit.googleapis.com https://securetoken.googleapis.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
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
            ini_set('session.gc_maxlifetime', '604800');

            session_set_cookie_params([
                'lifetime' => 604800,
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
        return hash('sha256', 'workly-ua|' . $ua);
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

    public static function sanitizePlainText(mixed $value, int $maxLength = 4000): string
    {
        if ($value === null) {
            return '';
        }
        $stripped = strip_tags((string) $value);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $stripped) ?? '';
        return mb_substr(trim($cleaned), 0, $maxLength, 'UTF-8');
    }

    public static function sanitizeRichHtml(?string $html, int $maxLength = 30000): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }
        $raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)[^>]*?/?>#is', '', $raw) ?? '';
        $allowed = strip_tags($raw, '<p><br><b><strong><i><em><u><h3><h4><ul><ol><li><a><blockquote><code><pre>');
        $allowed = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';
        $allowed = preg_replace('/\s(style|id|class)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';
        $allowed = preg_replace_callback('/<a\s+[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>/i', static function (array $m): string {
            $href = self::sanitizeUrl($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
            if ($href === '') {
                return '<a>';
            }
            return '<a href="' . self::e($href) . '" target="_blank" rel="noopener noreferrer">';
        }, $allowed) ?? '';
        return mb_substr(trim($allowed), 0, $maxLength, 'UTF-8');
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

    public static function renderSvgCaptcha(): string
    {
        self::configureSecureSession();
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $_SESSION['workly_captcha_hash'] = hash_hmac('sha256', $code, Secrets::get('CSRF_SALT'));
        $_SESSION['workly_captcha_exp'] = time() + 600;

        $lines = '';
        for ($i = 0; $i < 5; $i++) {
            $x1 = random_int(0, 40);
            $y1 = random_int(6, 42);
            $x2 = random_int(120, 170);
            $y2 = random_int(6, 42);
            $lines .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#D8B4FE" stroke-width="1.3" opacity="0.75"/>';
        }

        $chars = '';
        for ($i = 0; $i < 5; $i++) {
            $x = 18 + ($i * 29);
            $y = random_int(30, 36);
            $rot = random_int(-14, 14);
            $ch = htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8');
            $chars .= '<text x="' . $x . '" y="' . $y . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')" font-family="monospace" font-size="23" font-weight="700" fill="#6B21A8">' . $ch . '</text>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="170" height="48" viewBox="0 0 170 48">'
            . '<rect width="170" height="48" rx="8" fill="#FAF5FF" stroke="#E9D5FF"/>'
            . $lines
            . $chars
            . '</svg>';
    }

    public static function verifyLoginCaptcha(string $captchaInput, string $turnstileToken = ''): bool
    {
        $hasTurnstile = Secrets::isConfigured('CLOUDFLARE_TURNSTILE_SITE_KEY')
            && Secrets::isConfigured('CLOUDFLARE_TURNSTILE_SECRET_KEY');

        if ($hasTurnstile) {
            return self::verifyTurnstile($turnstileToken);
        }

        self::configureSecureSession();
        $expected = (string) ($_SESSION['workly_captcha_hash'] ?? '');
        $exp = (int) ($_SESSION['workly_captcha_exp'] ?? 0);
        unset($_SESSION['workly_captcha_hash'], $_SESSION['workly_captcha_exp']);

        if ($expected === '' || time() > $exp) {
            return false;
        }

        $clean = strtoupper(trim($captchaInput));
        if ($clean === '') {
            return false;
        }

        $given = hash_hmac('sha256', $clean, Secrets::get('CSRF_SALT'));
        return hash_equals($expected, $given);
    }

    public static function checkLoginRateLimit(): ?int
    {
        self::configureSecureSession();
        $lockedUntil = (int) ($_SESSION['workly_lock_until'] ?? 0);
        if ($lockedUntil > time()) {
            return $lockedUntil - time();
        }
        return null;
    }

    public static function recordLoginFailure(): void
    {
        self::configureSecureSession();
        $fails = (int) ($_SESSION['workly_login_fails'] ?? 0) + 1;
        $_SESSION['workly_login_fails'] = $fails;
        if ($fails >= 5) {
            $_SESSION['workly_lock_until'] = time() + 300;
            $_SESSION['workly_login_fails'] = 0;
        }
    }

    public static function clearLoginFailures(): void
    {
        self::configureSecureSession();
        unset($_SESSION['workly_login_fails'], $_SESSION['workly_lock_until']);
    }
}
