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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com https://www.gstatic.com https://apis.google.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' https: data:; frame-src https://challenges.cloudflare.com https://accounts.google.com; connect-src 'self' https://*.googleapis.com https://oauth2.googleapis.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
    }

    public static function getCookieConsent(): string
    {
        $val = strtolower(trim((string) ($_COOKIE['workly_cookie_consent'] ?? '')));
        if ($val === 'accepted' || $val === 'declined') {
            return $val;
        }
        return 'unset';
    }

    public static function hasDeclinedPersistentCookies(): bool
    {
        return self::getCookieConsent() === 'declined';
    }

    public static function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function cookieSameSite(): string
    {
        return self::isHttpsRequest() ? 'None' : 'Lax';
    }

    public static function setCookieConsentCookie(string $consent): void
    {
        $val = $consent === 'declined' ? 'declined' : 'accepted';
        setcookie('workly_cookie_consent', $val, [
            'expires'  => time() + (180 * 86400),
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttpsRequest(),
            'httponly' => false,
            'samesite' => self::cookieSameSite(),
        ]);
        $_COOKIE['workly_cookie_consent'] = $val;
    }

    public static function configureSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = self::isHttpsRequest();
            $sameSite = self::cookieSameSite();
            $lifetime = self::hasDeclinedPersistentCookies() ? 0 : 2592000;

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', $sameSite);
            ini_set('session.cookie_lifetime', (string) $lifetime);
            ini_set('session.gc_maxlifetime', '2592000');

            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => $sameSite,
            ]);
            @session_start();
        }
    }

    public static function refreshSessionCookieLifetime(bool $persistent): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $expires = $persistent ? (time() + 2592000) : 0;
        setcookie(session_name(), session_id(), [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::isHttpsRequest(),
            'httponly' => true,
            'samesite' => self::cookieSameSite(),
        ]);
    }

    public static function getNormalizedUserAgent(): string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        if ($ua === '') {
            return 'unknown';
        }
        // Keep browser/OS family intact while ignoring minor build patch numbers so auto-updates do not log users out
        $normalized = preg_replace('/(\d+)\.\d+\.\d+(?:\.\d+)?/', '$1', $ua) ?? $ua;
        return mb_strtolower(mb_substr($normalized, 0, 240, 'UTF-8'), 'UTF-8');
    }

    public static function getClientFingerprint(): string
    {
        return hash('sha256', 'workly-ua-v2|' . self::getNormalizedUserAgent());
    }

    public static function verifySessionIntegrity(): bool
    {
        if (!isset($_SESSION['workly_fp'])) {
            $_SESSION['workly_fp'] = self::getClientFingerprint();
            return true;
        }
        return hash_equals((string) $_SESSION['workly_fp'], self::getClientFingerprint());
    }

    public static function ensurePersistentSessionSchema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS user_persistent_sessions (
                    selector VARCHAR(32) PRIMARY KEY,
                    family_id VARCHAR(32) NOT NULL,
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    validator_hash VARCHAR(64) NOT NULL,
                    ua_hash VARCHAR(64) NOT NULL,
                    is_revoked BOOLEAN NOT NULL DEFAULT FALSE,
                    expires_at TIMESTAMPTZ NOT NULL,
                    last_used_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ups_user ON user_persistent_sessions (user_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ups_family ON user_persistent_sessions (family_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_ups_expires ON user_persistent_sessions (expires_at)");
        } catch (Throwable) {
            // Schema already exists or restricted
        }
    }

    private static function setPersistentAuthCookie(string $value, int $expires): void
    {
        if (!headers_sent()) {
            setcookie('workly_keep_session', $value, [
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => self::cookieSameSite(),
            ]);
        }
        if ($expires < time()) {
            unset($_COOKIE['workly_keep_session']);
        } else {
            $_COOKIE['workly_keep_session'] = $value;
        }
    }

    public static function issuePersistentSession(PDO $db, string $userId, ?string $familyId = null): void
    {
        if (self::hasDeclinedPersistentCookies() || $userId === '') {
            self::setPersistentAuthCookie('', time() - 42000);
            return;
        }

        self::ensurePersistentSessionSchema($db);
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(24));
        $validatorHash = hash_hmac('sha256', $validator, Secrets::get('CSRF_SALT'));
        $uaHash = self::getClientFingerprint();
        $fam = ($familyId !== null && $familyId !== '') ? $familyId : bin2hex(random_bytes(12));
        $ttlDays = 30;

        try {
            // Opportunistically prune expired tokens
            $db->exec("DELETE FROM user_persistent_sessions WHERE expires_at < NOW() - INTERVAL '7 days'");

            $stmt = $db->prepare("
                INSERT INTO user_persistent_sessions (selector, family_id, user_id, validator_hash, ua_hash, is_revoked, expires_at, last_used_at)
                VALUES (:sel, :fam, :uid, :vhash, :uahash, FALSE, NOW() + (:days || ' days')::INTERVAL, NOW())
            ");
            $stmt->execute([
                ':sel'    => $selector,
                ':fam'    => $fam,
                ':uid'    => $userId,
                ':vhash'  => $validatorHash,
                ':uahash' => $uaHash,
                ':days'   => (string) $ttlDays,
            ]);

            self::setPersistentAuthCookie("{$selector}.{$validator}", time() + ($ttlDays * 86400));
            $_SESSION['workly_session_family'] = $fam;
        } catch (Throwable) {
            // Keep login resilient even if persistent table fails
        }
    }

    public static function consumePersistentSession(PDO $db): ?string
    {
        $rawCookie = trim((string) ($_COOKIE['workly_keep_session'] ?? ''));
        if ($rawCookie === '') {
            return null;
        }

        if (self::hasDeclinedPersistentCookies()) {
            self::revokeCurrentPersistentSession($db);
            return null;
        }

        $parts = explode('.', $rawCookie, 2);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{16,32}$/', $parts[0]) || !preg_match('/^[a-f0-9]{32,64}$/', $parts[1])) {
            self::setPersistentAuthCookie('', time() - 42000);
            return null;
        }

        [$selector, $validator] = $parts;
        self::ensurePersistentSessionSchema($db);

        try {
            $stmt = $db->prepare("
                SELECT selector, family_id, user_id, validator_hash, ua_hash, is_revoked,
                       (expires_at <= NOW()) AS is_expired,
                       EXTRACT(EPOCH FROM (NOW() - last_used_at)) AS seconds_since_used
                FROM user_persistent_sessions
                WHERE selector = :sel
                LIMIT 1
            ");
            $stmt->execute([':sel' => $selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                self::setPersistentAuthCookie('', time() - 42000);
                return null;
            }

            if (filter_var($row['is_expired'], FILTER_VALIDATE_BOOLEAN)) {
                $del = $db->prepare('DELETE FROM user_persistent_sessions WHERE selector = :sel');
                $del->execute([':sel' => $selector]);
                self::setPersistentAuthCookie('', time() - 42000);
                return null;
            }

            $givenHash = hash_hmac('sha256', $validator, Secrets::get('CSRF_SALT'));
            $validatorMatches = hash_equals((string) $row['validator_hash'], $givenHash);
            $uaMatches = hash_equals((string) $row['ua_hash'], self::getClientFingerprint());
            $isRevoked = filter_var($row['is_revoked'], FILTER_VALIDATE_BOOLEAN);
            $secondsSinceUsed = (float) ($row['seconds_since_used'] ?? 999);

            // Detect token theft / replay or UA hijack
            if (!$validatorMatches || !$uaMatches) {
                $revFam = $db->prepare('UPDATE user_persistent_sessions SET is_revoked = TRUE WHERE family_id = :fam');
                $revFam->execute([':fam' => $row['family_id']]);
                self::setPersistentAuthCookie('', time() - 42000);
                if (class_exists('AuditLogger')) {
                    AuditLogger::log(
                        'SESSION_HIJACK_BLOCKED',
                        'blocked',
                        'Blocked persistent cookie validation mismatch or client mismatch',
                        (string) $row['user_id'],
                        'security',
                        $db
                    );
                }
                return null;
            }

            if ($isRevoked) {
                // Allow a 15-second grace window for concurrent parallel browser fetches during rotation
                if ($secondsSinceUsed <= 15.0) {
                    $chkActive = $db->prepare('SELECT 1 FROM user_persistent_sessions WHERE family_id = :fam AND is_revoked = FALSE AND expires_at > NOW() LIMIT 1');
                    $chkActive->execute([':fam' => $row['family_id']]);
                    if ($chkActive->fetchColumn()) {
                        return (string) $row['user_id'];
                    }
                }
                // Replay of rotated token >15s later indicates theft: revoke entire family
                $revFam = $db->prepare('UPDATE user_persistent_sessions SET is_revoked = TRUE WHERE family_id = :fam');
                $revFam->execute([':fam' => $row['family_id']]);
                self::setPersistentAuthCookie('', time() - 42000);
                if (class_exists('AuditLogger')) {
                    AuditLogger::log(
                        'SESSION_THEFT_DETECTED',
                        'blocked',
                        'Detected replayed persistent session token; revoked token family',
                        (string) $row['user_id'],
                        'security',
                        $db
                    );
                }
                return null;
            }

            // Valid token: revoke current selector and rotate to a fresh selector + validator in same family
            $revCur = $db->prepare('UPDATE user_persistent_sessions SET is_revoked = TRUE, last_used_at = NOW() WHERE selector = :sel');
            $revCur->execute([':sel' => $selector]);

            self::issuePersistentSession($db, (string) $row['user_id'], (string) $row['family_id']);
            return (string) $row['user_id'];
        } catch (Throwable) {
            return null;
        }
    }

    public static function revokeCurrentPersistentSession(PDO $db): void
    {
        $rawCookie = trim((string) ($_COOKIE['workly_keep_session'] ?? ''));
        if ($rawCookie !== '' && str_contains($rawCookie, '.')) {
            [$selector] = explode('.', $rawCookie, 2);
            if (preg_match('/^[a-f0-9]{16,32}$/', $selector)) {
                try {
                    self::ensurePersistentSessionSchema($db);
                    $stmt = $db->prepare('UPDATE user_persistent_sessions SET is_revoked = TRUE WHERE selector = :sel');
                    $stmt->execute([':sel' => $selector]);
                } catch (Throwable) {
                }
            }
        }
        unset($_SESSION['workly_session_family']);
        self::setPersistentAuthCookie('', time() - 42000);
    }

    private static function setCsrfSignatureCookie(string $token): void
    {
        if ($token === '') {
            return;
        }
        $exp = time() + 43200; // 12h window
        $sig = hash_hmac('sha256', $token . '|' . $exp, Secrets::get('CSRF_SALT'));
        $cookieVal = $exp . '.' . $sig;
        if (!headers_sent()) {
            setcookie('workly_csrf_sig', $cookieVal, [
                'expires'  => $exp,
                'path'     => '/',
                'domain'   => '',
                'secure'   => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => self::cookieSameSite(),
            ]);
        }
        $_COOKIE['workly_csrf_sig'] = $cookieVal;
    }

    public static function csrfToken(): string
    {
        self::configureSecureSession();
        if (empty($_SESSION['workly_csrf_token'])) {
            $_SESSION['workly_csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = (string) $_SESSION['workly_csrf_token'];
        self::setCsrfSignatureCookie($token);
        return $token;
    }

    public static function rotateCsrfToken(): string
    {
        self::configureSecureSession();
        $token = bin2hex(random_bytes(32));
        $_SESSION['workly_csrf_token'] = $token;
        self::setCsrfSignatureCookie($token);
        return $token;
    }

    public static function verifyCsrfToken(?string $token): bool
    {
        self::configureSecureSession();
        $given = trim((string) $token);
        if ($given === '' || !preg_match('/^[a-f0-9]{32,128}$/', $given)) {
            return false;
        }

        $sessionToken = (string) ($_SESSION['workly_csrf_token'] ?? '');
        if ($sessionToken !== '' && hash_equals($sessionToken, $given)) {
            return true;
        }

        // Resilient double-submit HMAC fallback if server session expired/restarted while form was open
        $cookieSig = trim((string) ($_COOKIE['workly_csrf_sig'] ?? ''));
        if ($cookieSig !== '' && str_contains($cookieSig, '.')) {
            [$expStr, $sig] = explode('.', $cookieSig, 2);
            $exp = (int) $expStr;
            if ($exp >= time() && preg_match('/^[a-f0-9]{64}$/', $sig)) {
                $expectedSig = hash_hmac('sha256', $given . '|' . $exp, Secrets::get('CSRF_SALT'));
                if (hash_equals($expectedSig, $sig)) {
                    $_SESSION['workly_csrf_token'] = $given;
                    return true;
                }
            }
        }

        return false;
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

    public static function sanitizeRichHtml(?string $html, int $maxLength = 60000): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }
        $raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $raw = preg_replace('#<(script|style|iframe|object|embed|form|input|button|meta|link|base)[^>]*?/?>#is', '', $raw) ?? '';
        $allowed = strip_tags(
            $raw,
            '<p><br><div><span><b><strong><i><em><u><s><strike><del><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><code><pre><hr><table><thead><tbody><tfoot><tr><th><td>'
        );
        $allowed = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';
        $allowed = preg_replace('/\s(id|class|data-[a-z0-9_-]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';

        // Preserve strictly whitelisted CSS properties inside style attributes
        $allowed = preg_replace_callback('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', static function (array $m): string {
            $rawStyle = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            $cleanStyle = self::sanitizeInlineStyle($rawStyle);
            return $cleanStyle !== '' ? ' style="' . self::e($cleanStyle) . '"' : '';
        }, $allowed) ?? '';

        $allowed = preg_replace_callback('/<a\s+[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>/i', static function (array $m): string {
            $href = self::sanitizeUrl($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
            if ($href === '') {
                return '<a>';
            }
            return '<a href="' . self::e($href) . '" target="_blank" rel="noopener noreferrer">';
        }, $allowed) ?? '';
        return mb_substr(trim($allowed), 0, $maxLength, 'UTF-8');
    }

    private static function sanitizeInlineStyle(string $rawStyle): string
    {
        if (trim($rawStyle) === '') {
            return '';
        }
        $allowedProps = [
            'font-size'       => true,
            'font-family'     => true,
            'color'           => true,
            'background-color'=> true,
            'text-align'      => true,
            'text-transform'  => true,
            'font-weight'     => true,
            'font-style'      => true,
            'text-decoration' => true,
            'line-height'     => true,
            'margin-left'     => true,
            'margin-top'      => true,
            'margin-bottom'   => true,
            'padding'         => true,
            'padding-left'    => true,
            'border'          => true,
            'border-collapse' => true,
            'width'           => true,
        ];

        $safeParts = [];
        foreach (explode(';', $rawStyle) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val = trim($val);
            if (!isset($allowedProps[$prop]) || $val === '') {
                continue;
            }
            $valLow = strtolower($val);
            if (
                str_contains($valLow, 'url(') ||
                str_contains($valLow, 'expression(') ||
                str_contains($valLow, 'javascript:') ||
                str_contains($valLow, 'vbscript:') ||
                str_contains($valLow, '@import') ||
                str_contains($valLow, 'behavior:') ||
                preg_match('/[<>{}\\\\]/', $val)
            ) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9\s#%(),.\'"\-_+]+$/', $val)) {
                continue;
            }
            $safeParts[] = $prop . ': ' . $val;
        }
        return implode('; ', $safeParts);
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

    public static function sanitizeEmailImageSrc(?string $src): string
    {
        if ($src === null || trim($src) === '') {
            return '';
        }
        $trimmed = trim($src);
        if (str_starts_with($trimmed, '/api/v1/email/attachments/')) {
            return $trimmed;
        }
        $compact = strtolower(preg_replace('/\s+/', '', $trimmed) ?? '');
        if (str_starts_with($compact, 'javascript:') || str_starts_with($compact, 'vbscript:')) {
            return '';
        }
        if (preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,[a-zA-Z0-9+\/=]+$/i', $compact)) {
            if (strlen($trimmed) <= 262144) {
                return $trimmed;
            }
            return '';
        }
        return self::sanitizeUrl($trimmed);
    }

    public static function sanitizeEmailHtml(?string $html, int $maxLength = 150000): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Remove active content, head/meta containers, stylesheets, scripts, and embedded objects
        $raw = preg_replace('#<(script|style|head|title|iframe|object|embed|applet|form|input|button|textarea|select|svg|math)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $raw = preg_replace('#<(script|style|head|title|iframe|object|embed|applet|form|input|button|meta|link|base|svg)[^>]*?/?>#is', '', $raw) ?? '';
        $raw = preg_replace('/<!--.*?-->/s', '', $raw) ?? '';

        $allowed = strip_tags(
            $raw,
            '<p><br><div><span><b><strong><i><em><u><s><strike><del><h1><h2><h3><h4><h5><h6><ul><ol><li><a><blockquote><code><pre><hr><table><caption><thead><tbody><tfoot><tr><th><td><img><font><center><small><sub><sup><figure><figcaption><header><footer>'
        );

        // Strip all event handlers and dangerous identifiers
        $allowed = preg_replace('/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';
        $allowed = preg_replace('/\s(id|class|srcset|data-[a-z0-9_-]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $allowed) ?? '';

        // Rebuild tags while whitelisting attributes safely per tag
        $allowed = preg_replace_callback('/<([a-z][a-z0-9]*)(\s+[^>]*)?>/i', static function (array $match): string {
            $tag = strtolower($match[1]);
            $rawAttrs = $match[2] ?? '';
            $isSelfClosing = str_ends_with(trim($match[0]), '/>') || $tag === 'br' || $tag === 'hr' || $tag === 'img';

            if ($rawAttrs === '') {
                return $tag === 'a' ? '<a target="_blank" rel="noopener noreferrer">' : '<' . $tag . ($isSelfClosing && $tag !== 'br' && $tag !== 'hr' ? ' />' : '>');
            }

            $attrs = [];
            if (preg_match_all('/([a-z][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $rawAttrs, $am, PREG_SET_ORDER)) {
                foreach ($am as $item) {
                    $k = strtolower($item[1]);
                    $v = html_entity_decode($item[2] !== '' ? $item[2] : ($item[3] !== '' ? $item[3] : ($item[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $attrs[$k] = $v;
                }
            }

            $outAttrs = [];

            if (isset($attrs['style'])) {
                $cleanStyle = self::sanitizeEmailInlineStyle($attrs['style']);
                if ($cleanStyle !== '') {
                    $outAttrs[] = 'style="' . self::e($cleanStyle) . '"';
                }
            }

            if ($tag === 'a') {
                $href = self::sanitizeUrl($attrs['href'] ?? '');
                if ($href !== '') {
                    $outAttrs[] = 'href="' . self::e($href) . '"';
                }
                $outAttrs[] = 'target="_blank"';
                $outAttrs[] = 'rel="noopener noreferrer"';
            } elseif ($tag === 'img') {
                $src = self::sanitizeEmailImageSrc($attrs['src'] ?? '');
                if ($src === '') {
                    return '';
                }
                $outAttrs[] = 'src="' . self::e($src) . '"';
                $alt = self::sanitizePlainText($attrs['alt'] ?? 'Email image', 180);
                $outAttrs[] = 'alt="' . self::e($alt) . '"';
                $outAttrs[] = 'loading="lazy"';
                foreach (['width', 'height'] as $dimKey) {
                    if (isset($attrs[$dimKey]) && preg_match('/^\d{1,4}(?:px|%)?$/', trim($attrs[$dimKey]))) {
                        $outAttrs[] = $dimKey . '="' . self::e(trim($attrs[$dimKey])) . '"';
                    }
                }
            } else {
                $presentational = ['align', 'valign', 'width', 'height', 'bgcolor', 'border', 'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'color', 'face', 'size'];
                foreach ($presentational as $pKey) {
                    if (!isset($attrs[$pKey])) {
                        continue;
                    }
                    $val = trim($attrs[$pKey]);
                    if ($val !== '' && preg_match('/^[a-zA-Z0-9\s#%(),.\-_+]+$/', $val)) {
                        $outAttrs[] = $pKey . '="' . self::e(mb_substr($val, 0, 80, 'UTF-8')) . '"';
                    }
                }
            }

            $attrStr = empty($outAttrs) ? '' : ' ' . implode(' ', $outAttrs);
            return '<' . $tag . $attrStr . '>';
        }, $allowed) ?? '';

        return mb_substr(trim($allowed), 0, $maxLength, 'UTF-8');
    }

    private static function sanitizeEmailInlineStyle(string $rawStyle): string
    {
        if (trim($rawStyle) === '') {
            return '';
        }
        $allowedProps = [
            'font-size'        => true,
            'font-family'      => true,
            'font-weight'      => true,
            'font-style'       => true,
            'color'            => true,
            'background'       => true,
            'background-color' => true,
            'text-align'       => true,
            'text-transform'   => true,
            'text-decoration'  => true,
            'line-height'      => true,
            'letter-spacing'   => true,
            'margin'           => true,
            'margin-top'       => true,
            'margin-right'     => true,
            'margin-bottom'    => true,
            'margin-left'      => true,
            'padding'          => true,
            'padding-top'      => true,
            'padding-right'    => true,
            'padding-bottom'   => true,
            'padding-left'     => true,
            'border'           => true,
            'border-top'       => true,
            'border-right'     => true,
            'border-bottom'    => true,
            'border-left'      => true,
            'border-color'     => true,
            'border-radius'    => true,
            'border-collapse'  => true,
            'border-spacing'   => true,
            'width'            => true,
            'max-width'        => true,
            'min-width'        => true,
            'height'           => true,
            'display'          => true,
            'vertical-align'   => true,
            'white-space'      => true,
            'list-style'       => true,
            'list-style-type'  => true,
        ];

        $safeParts = [];
        foreach (explode(';', $rawStyle) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val = trim($val);
            if (!isset($allowedProps[$prop]) || $val === '') {
                continue;
            }
            $valLow = strtolower($val);
            if (
                str_contains($valLow, 'url(') ||
                str_contains($valLow, 'expression(') ||
                str_contains($valLow, 'javascript:') ||
                str_contains($valLow, 'vbscript:') ||
                str_contains($valLow, '@import') ||
                str_contains($valLow, 'behavior:') ||
                str_contains($valLow, '-moz-binding') ||
                preg_match('/[<>{}\\\\]/', $val)
            ) {
                continue;
            }
            if ($prop === 'display' && in_array($valLow, ['fixed', 'sticky'], true)) {
                continue;
            }
            if (!preg_match('/^[a-zA-Z0-9\s#%(),.\'"\-_+!\/]+$/', $val)) {
                continue;
            }
            $safeParts[] = $prop . ': ' . mb_substr($val, 0, 160, 'UTF-8');
        }
        return implode('; ', $safeParts);
    }

    public static function htmlToPlainText(string $html, int $maxLength = 16000): string
    {
        if (trim($html) === '') {
            return '';
        }
        $clean = preg_replace('#<(script|style|head|title)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $clean = preg_replace('/<\s*br\s*\/?>/i', "\n", $clean) ?? $clean;
        $clean = preg_replace('/<\/\s*(p|div|li|tr|h[1-6]|blockquote|table|section|article|header|footer)\s*>/i', "\n", $clean) ?? $clean;
        $clean = preg_replace('/<\/\s*(td|th)\s*>/i', "  ", $clean) ?? $clean;
        $text = strip_tags($clean);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\r\n|\r/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return self::sanitizePlainText($text, $maxLength);
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

        $capHash = hash_hmac('sha256', $code, Secrets::get('CSRF_SALT'));
        $capExp = time() + 600;
        $_SESSION['workly_captcha_hash'] = $capHash;
        $_SESSION['workly_captcha_exp'] = $capExp;

        $capCookieSig = hash_hmac('sha256', $capHash . '|' . $capExp, Secrets::get('CSRF_SALT'));
        if (!headers_sent()) {
            setcookie('workly_captcha_sig', $capExp . '.' . $capHash . '.' . $capCookieSig, [
                'expires'  => $capExp,
                'path'     => '/',
                'domain'   => '',
                'secure'   => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => self::cookieSameSite(),
            ]);
        }

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

        if ($expected === '' || $exp < time()) {
            $rawCookie = trim((string) ($_COOKIE['workly_captcha_sig'] ?? ''));
            if ($rawCookie !== '' && substr_count($rawCookie, '.') === 2) {
                [$cExpStr, $cHash, $cSig] = explode('.', $rawCookie, 3);
                $cExp = (int) $cExpStr;
                $checkSig = hash_hmac('sha256', $cHash . '|' . $cExp, Secrets::get('CSRF_SALT'));
                if ($cExp >= time() && hash_equals($checkSig, $cSig)) {
                    $expected = $cHash;
                    $exp = $cExp;
                }
            }
        }
        if (!headers_sent()) {
            setcookie('workly_captcha_sig', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => self::cookieSameSite(),
            ]);
        }

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
