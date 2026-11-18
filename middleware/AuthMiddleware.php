<?php
/**
 * Workly Strict Authentication Middleware (PHP 8 + PostgreSQL)
 * Zero hardcoded fallback accounts; authenticates strictly against PostgreSQL users table.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../services/SecurityHelper.php';
require_once __DIR__ . '/../services/AuditLogger.php';

final class AuthMiddleware
{
    public static function userCount(PDO $db): int
    {
        try {
            $row = $db->query('SELECT COUNT(*)::INTEGER AS total FROM users')->fetch(PDO::FETCH_ASSOC);
            return (int) ($row['total'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function currentUser(PDO $db): ?array
    {
        SecurityHelper::configureSecureSession();

        if (!SecurityHelper::verifySessionIntegrity()) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        }

        $userId = null;

        if (!empty($_SESSION['workly_user_id'])) {
            $userId = (string) $_SESSION['workly_user_id'];
        } else {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $payload = self::verifyJwt($matches[1]);
                if ($payload && !empty($payload['id'])) {
                    $userId = (string) $payload['id'];
                }
            }

            if ($userId === null && !SecurityHelper::hasDeclinedPersistentCookies()) {
                $restoredId = SecurityHelper::consumePersistentSession($db);
                if ($restoredId !== null && $restoredId !== '') {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_regenerate_id(true);
                    }
                    $_SESSION['workly_user_id'] = $restoredId;
                    $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
                    SecurityHelper::refreshSessionCookieLifetime(true);
                    $userId = $restoredId;
                }
            }
        }

        if ($userId === null || $userId === '') {
            return null;
        }

        $user = null;
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.email, COALESCE(ur.role_slug, u.role) AS role,
                       u.avatar_url, u.phone, u.bio, u.hire_date,
                       u.hourly_rate, u.total_earnings, u.pto_balance_days, u.pto_taken_days,
                       u.team_leader_id, u.is_active, u.is_suspended, u.suspended_reason
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                WHERE u.id = :id
                ORDER BY ur.assigned_at DESC NULLS LAST
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $stmt = $db->prepare("
                SELECT id, name, email, role, avatar_url, phone, bio, hire_date,
                       hourly_rate, total_earnings, pto_balance_days, pto_taken_days,
                       team_leader_id, is_active, is_suspended, suspended_reason
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$user || !$user['is_active'] || $user['is_suspended']) {
            $_SESSION = [];
            SecurityHelper::revokeCurrentPersistentSession($db);
            return null;
        }

        // Slide active session cookie if persistent cookies are allowed and user has not yet received a rotating keep cookie
        if (!empty($_SESSION['workly_user_id']) && !SecurityHelper::hasDeclinedPersistentCookies() && empty($_COOKIE['workly_keep_session'])) {
            SecurityHelper::issuePersistentSession($db, (string) $user['id']);
            SecurityHelper::refreshSessionCookieLifetime(true);
        }

        return $user;
    }

    public static function requirePageAuth(PDO $db): array
    {
        if (self::userCount($db) === 0) {
            header('Location: /setup');
            exit;
        }

        $user = self::currentUser($db);
        if ($user === null) {
            header('Location: /login');
            exit;
        }

        return $user;
    }

    public static function authenticateApi(PDO $db): array
    {
        $user = self::currentUser($db);
        if ($user === null) {
            http_response_code(401);
            echo json_encode([
                'error' => 'Authentication required',
                'code'  => 'WORKLY_AUTH_REQUIRED',
            ]);
            exit;
        }

        return $user;
    }

    public static function requireRoles(array $allowedRoles, array $user, bool $isApi = true): void
    {
        if (!in_array($user['role'] ?? '', $allowedRoles, true)) {
            AuditLogger::log(
                'RBAC_ACCESS_DENIED',
                'blocked',
                'Unauthorized access blocked for role ' . ($user['role'] ?? 'none'),
                (string) ($user['id'] ?? 'unknown'),
                (string) ($user['role'] ?? 'unknown')
            );

            if ($isApi) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'Forbidden: Insufficient permissions',
                    'code'  => 'WORKLY_AUTH_FORBIDDEN',
                ]);
                exit;
            }

            header('Location: /dashboard');
            exit;
        }
    }

    public static function issueJwt(array $user): string
    {
        $secret = Secrets::get('JWT_SECRET');
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $now = time();
        $payload = rtrim(strtr(base64_encode(json_encode([
            'id'    => $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'iat'   => $now,
            'exp'   => $now + 86400,
        ])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true)), '+/', '-_'), '=');
        return "{$header}.{$payload}.{$sig}";
    }

    public static function verifyJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $secret = Secrets::get('JWT_SECRET');
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || empty($payload['id'])) {
            return null;
        }
        if (isset($payload['exp']) && time() > (int) $payload['exp']) {
            return null;
        }
        return $payload;
    }
}
