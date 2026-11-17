<?php
/**
 * Workly Virtual Assistant Management Platform
 * Production Root Entry Router (100% Native PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/secrets.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/SecurityHelper.php';
require_once __DIR__ . '/services/CacheService.php';
require_once __DIR__ . '/services/AuditLogger.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

EnvLoader::load();

$rawUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = '/' . ltrim($rawUri, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Health Probe Endpoint (no sensitive leak)
if ($uri === '/health' || $uri === '/api/v1/health') {
    SecurityHelper::sendApiHeaders();
    try {
        $db = Database::getConnection();
        $db->query('SELECT 1');
        echo json_encode([
            'status'   => 'healthy',
            'database' => 'connected',
        ]);
    } catch (Throwable) {
        http_response_code(503);
        echo json_encode([
            'status'   => 'unconfigured',
            'database' => 'disconnected',
        ]);
    }
    exit;
}

try {
    $db = Database::getConnection();
} catch (Throwable $e) {
    if (str_starts_with($uri, '/api/')) {
        SecurityHelper::sendApiHeaders();
        http_response_code(503);
        echo json_encode(['error' => 'Database connection is not yet configured. Check environment configuration.']);
        exit;
    }
    SecurityHelper::sendHtmlHeaders();
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Workly — Workspace Configuration Required</title>
  <link rel="stylesheet" href="/assets/css/workly.css">
  <script src="/assets/js/localtheme.js"></script>
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="brand-mark">W</div>
    <h1>Workspace Setup Required</h1>
    <p class="muted">Workly could not establish a database connection. Verify your environment credentials and initialize the workspace schema before continuing.</p>
    <div class="notice notice-warn">System Status: Database Connection Pending</div>
  </div>
  <script src="/assets/js/theme-mode.js"></script>
</body>
</html>
    <?php
    exit;
}

// REST API v1 Routing (Hardened against HTML PHP warning leakage)
if (str_starts_with($uri, '/api/v1/')) {
    @ini_set('display_errors', '0');
    if (ob_get_level() === 0) {
        ob_start();
    }
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        error_log("Workly API PHP Warning [{$severity}]: {$message} in {$file}:{$line}");
        return true;
    });
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code(500);
                SecurityHelper::sendApiHeaders();
            }
            echo json_encode(['error' => 'Server processing error while handling request.']);
        }
    });
    SecurityHelper::sendApiHeaders();

    if ($uri === '/api/v1/auth/login' && $method === 'POST') {
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController($db))->handleApiLogin();
        exit;
    }

    if (str_starts_with($uri, '/api/v1/tasks')) {
        require_once __DIR__ . '/controllers/api/TaskApiController.php';
        (new TaskApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/clients')) {
        require_once __DIR__ . '/controllers/api/ClientApiController.php';
        (new ClientApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/users')) {
        require_once __DIR__ . '/controllers/api/UserApiController.php';
        (new UserApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/pto')) {
        require_once __DIR__ . '/controllers/api/PtoApiController.php';
        (new PtoApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/meetings')) {
        require_once __DIR__ . '/controllers/api/MeetingApiController.php';
        (new MeetingApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/email')) {
        require_once __DIR__ . '/controllers/api/EmailApiController.php';
        (new EmailApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/knowledge')) {
        require_once __DIR__ . '/controllers/api/KnowledgeApiController.php';
        (new KnowledgeApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/perks')) {
        require_once __DIR__ . '/controllers/api/PerksApiController.php';
        (new PerksApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/reports')) {
        require_once __DIR__ . '/controllers/api/ReportApiController.php';
        (new ReportApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/uploads')) {
        require_once __DIR__ . '/controllers/api/UploadApiController.php';
        (new UploadApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/logs')) {
        require_once __DIR__ . '/controllers/api/AuditLogApiController.php';
        (new AuditLogApiController($db))->handle($method, $uri);
        exit;
    }
    if (str_starts_with($uri, '/api/v1/notifications')) {
        require_once __DIR__ . '/controllers/api/NotificationApiController.php';
        (new NotificationApiController($db))->handle($method, $uri);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'API endpoint not found']);
    exit;
}

// CAPTCHA SVG Challenge Endpoint
if ($uri === '/captcha.svg') {
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo SecurityHelper::renderSvgCaptcha();
    exit;
}

// PostgreSQL Fallback Media Serving Endpoint (for restricted filesystems)
if (preg_match('#^/media/([a-zA-Z0-9_-]+)/?$#', $uri, $mMedia)) {
    try {
        $mStmt = $db->prepare('SELECT mime_type, data_base64 FROM uploaded_assets WHERE id = :id LIMIT 1');
        $mStmt->execute([':id' => $mMedia[1]]);
        $asset = $mStmt->fetch(PDO::FETCH_ASSOC);
        if ($asset && in_array($asset['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $raw = base64_decode((string) $asset['data_base64'], true);
            if ($raw !== false) {
                header('Content-Type: ' . $asset['mime_type']);
                header('Content-Length: ' . strlen($raw));
                header('Cache-Control: public, max-age=86400');
                header('X-Content-Type-Options: nosniff');
                echo $raw;
                exit;
            }
        }
    } catch (Throwable) {
        // Fallback 404
    }
    http_response_code(404);
    exit;
}

// Authentication & One-Time Setup Routes
require_once __DIR__ . '/controllers/AuthController.php';
$auth = new AuthController($db);

if ($uri === '/setup') {
    if ($method === 'POST') {
        $auth->handleSetup();
    } else {
        $auth->showSetup();
    }
    exit;
}

if ($uri === '/login') {
    if ($method === 'POST') {
        $auth->handleLogin();
    } else {
        $auth->showLogin();
    }
    exit;
}

if ($uri === '/logout') {
    $auth->handleLogout();
    exit;
}

// Authenticated Platform Pages
require_once __DIR__ . '/controllers/PageController.php';
$pages = new PageController($db);

$sectionMap = [
    '/'                 => 'home',
    '/tasks'            => 'tasks',
    '/dashboard'        => 'dashboard',
    '/va-directory'     => 'va_directory',
    '/profile'          => 'profile',
    '/clients'          => 'clients',
    '/calendar-reports' => 'calendar_reports',
    '/pto'              => 'pto',
    '/email'            => 'email',
    '/knowledge'        => 'knowledge',
    '/perks'            => 'perks',
];

if ($uri === '/' || $uri === '') {
    if (AuthMiddleware::userCount($db) === 0) {
        header('Location: /setup');
        exit;
    }
    $current = AuthMiddleware::currentUser($db);
    if ($current === null) {
        header('Location: /login');
        exit;
    }
    header('Location: ' . ($current['role'] === 'va' ? '/tasks' : '/dashboard'));
    exit;
}

if (isset($sectionMap[$uri])) {
    $pages->render($sectionMap[$uri]);
    exit;
}

http_response_code(404);
SecurityHelper::sendHtmlHeaders();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>404 — Page Not Found | Workly</title>
  <link rel="stylesheet" href="/assets/css/workly.css">
  <script src="/assets/js/localtheme.js"></script>
</head>
<body class="auth-body">
  <div class="auth-card">
    <div class="brand-mark">W</div>
    <h1>Page Not Found</h1>
    <p class="muted">The requested workspace route does not exist.</p>
    <a class="btn btn-primary" href="/">Return to Workspace</a>
  </div>
</body>
</html>
