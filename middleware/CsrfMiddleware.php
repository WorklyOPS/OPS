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
        $token = (string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_POST['_csrf'] ?? ''));

        if (!SecurityHelper::verifyCsrfToken($token)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Invalid or missing CSRF token',
                'code'  => 'WORKLY_CSRF_INVALID',
            ]);
            exit;
        }
    }

    public static function checkFormCsrf(): bool
    {
        SecurityHelper::configureSecureSession();
        $token = (string) ($_POST['_csrf'] ?? '');
        return SecurityHelper::verifyCsrfToken($token);
    }

    public static function validateForm(string $fallbackRedirect = '/login'): void
    {
        if (!self::checkFormCsrf()) {
            SecurityHelper::rotateCsrfToken();
            $_SESSION['workly_flash_error'] = 'Your security session expired. Please verify your details and try again.';
            $target = $fallbackRedirect;
            $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
            if ($referer !== '') {
                $refPath = (string) (parse_url($referer, PHP_URL_PATH) ?: '');
                if ($refPath !== '' && str_starts_with($refPath, '/')) {
                    $target = $refPath;
                }
            }
            header('Location: ' . $target, true, 303);
            exit;
        }
    }
}
