<?php
/**
 * Workly CSRF Middleware
 */

declare(strict_types=1);

require_once __DIR__ . '/../services/SecurityHelper.php';

final class CsrfMiddleware
{
    public static function validateApi(string $method): void
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        SecurityHelper::configureSecureSession();
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_POST['_csrf'] ?? '');

        if (empty($_SESSION['workly_csrf_token']) || empty($token) || !hash_equals((string) $_SESSION['workly_csrf_token'], (string) $token)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Invalid or missing CSRF token',
                'code'  => 'WORKLY_CSRF_INVALID',
            ]);
            exit;
        }
    }

    public static function validateForm(): void
    {
        SecurityHelper::configureSecureSession();
        $token = (string) ($_POST['_csrf'] ?? '');
        if (empty($_SESSION['workly_csrf_token']) || $token === '' || !hash_equals((string) $_SESSION['workly_csrf_token'], $token)) {
            http_response_code(403);
            exit('Security check failed (CSRF mismatch). Please go back and reload the page.');
        }
    }
}
