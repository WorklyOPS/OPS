<?php
/**
 * Workly Authentication & One-Time Production Bootstrap Controller (PHP 8 + PostgreSQL)
 * Zero demo presets, zero hardcoded accounts, zero auto-login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../services/SecurityHelper.php';
require_once __DIR__ . '/../services/AuditLogger.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';

require_once __DIR__ . '/api/UserApiController.php';

final class AuthController
{
    public function __construct(private PDO $db) {}

    public function showLogin(?string $error = null): void
    {
        SecurityHelper::sendHtmlHeaders();
        SecurityHelper::configureSecureSession();

        if (AuthMiddleware::userCount($this->db) === 0) {
            header('Location: /setup');
            exit;
        }

        $current = AuthMiddleware::currentUser($this->db);
        if ($current !== null) {
            $this->redirectForRole($current['role']);
        }

        $csrfToken = SecurityHelper::csrfToken();
        $secretsStatus = Secrets::publicStatus();
        $pageError = $error ?? ($_SESSION['workly_flash_error'] ?? null);
        unset($_SESSION['workly_flash_error']);

        require __DIR__ . '/../views/auth/login.php';
    }

    public function handleLogin(): void
    {
        SecurityHelper::configureSecureSession();
        CsrfMiddleware::validateForm();

        $waitSeconds = SecurityHelper::checkLoginRateLimit();
        if ($waitSeconds !== null) {
            $mins = max(1, (int) ceil($waitSeconds / 60));
            $this->showLogin("Too many failed login attempts. Try again in {$mins} minute(s).");
            return;
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $turnstileToken = (string) ($_POST['cf-turnstile-response'] ?? '');
        $captchaCode = (string) ($_POST['captcha_code'] ?? '');

        if (!SecurityHelper::verifyLoginCaptcha($captchaCode, $turnstileToken)) {
            SecurityHelper::recordLoginFailure();
            AuditLogger::log('LOGIN_CAPTCHA_BLOCKED', 'blocked', "Security CAPTCHA verification failed for {$email}", 'anonymous', 'guest', $this->db);
            $this->showLogin('Security CAPTCHA verification failed. Please enter the updated characters and try again.');
            return;
        }

        if ($email === '' || $password === '') {
            $this->showLogin('Please enter both your email address and password.');
            return;
        }

        UserApiController::ensureRolesSchema($this->db);
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.email, u.password_hash,
                   COALESCE(ur.role_slug, u.role) AS role,
                   u.is_active, u.is_suspended, u.suspended_reason
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            WHERE LOWER(u.email) = LOWER(:email)
            ORDER BY ur.assigned_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            SecurityHelper::recordLoginFailure();
            AuditLogger::log('LOGIN_FAILED', 'warning', "Failed sign-in attempt for {$email}", 'anonymous', 'guest', $this->db);
            $this->showLogin('Invalid email address or password.');
            return;
        }

        if (!$user['is_active'] || $user['is_suspended']) {
            $reason = $user['suspended_reason'] ? (' (' . $user['suspended_reason'] . ')') : '';
            AuditLogger::log('LOGIN_SUSPENDED_BLOCKED', 'blocked', "Suspended account sign-in blocked for {$email}", (string) $user['id'], (string) $user['role'], $this->db);
            $this->showLogin('This account is currently suspended or inactive' . $reason . '. Contact your Administrator.');
            return;
        }

        SecurityHelper::clearLoginFailures();
        session_regenerate_id(true);
        $_SESSION['workly_user_id'] = $user['id'];
        $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        SecurityHelper::rotateCsrfToken();
        if (!SecurityHelper::hasDeclinedPersistentCookies()) {
            SecurityHelper::issuePersistentSession($this->db, (string) $user['id']);
            SecurityHelper::refreshSessionCookieLifetime(true);
        }

        AuditLogger::log('LOGIN_SUCCESS', 'success', "Signed in {$email} ({$user['role']})", (string) $user['id'], (string) $user['role'], $this->db);
        $this->redirectForRole((string) $user['role']);
    }

    public function handleApiLogin(): void
    {
        SecurityHelper::configureSecureSession();
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $turnstileToken = (string) ($input['turnstile_token'] ?? '');

        if (!SecurityHelper::verifyTurnstile($turnstileToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Security challenge failed']);
            return;
        }

        if ($email === '' || $password === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        UserApiController::ensureRolesSchema($this->db);
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.email, u.password_hash,
                   COALESCE(ur.role_slug, u.role) AS role,
                   u.is_active, u.is_suspended
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            WHERE LOWER(u.email) = LOWER(:email)
            ORDER BY ur.assigned_at DESC NULLS LAST
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            AuditLogger::log('API_LOGIN_FAILED', 'warning', "API sign-in failed for {$email}", 'anonymous', 'guest', $this->db);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }

        if (!$user['is_active'] || $user['is_suspended']) {
            http_response_code(403);
            echo json_encode(['error' => 'Account suspended or inactive']);
            return;
        }

        session_regenerate_id(true);
        $_SESSION['workly_user_id'] = $user['id'];
        $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        $csrf = SecurityHelper::rotateCsrfToken();
        $jwt = AuthMiddleware::issueJwt($user);
        if (!SecurityHelper::hasDeclinedPersistentCookies()) {
            SecurityHelper::issuePersistentSession($this->db, (string) $user['id']);
            SecurityHelper::refreshSessionCookieLifetime(true);
        }

        AuditLogger::log('API_LOGIN_SUCCESS', 'success', "API login for {$email}", (string) $user['id'], (string) $user['role'], $this->db);
        echo json_encode([
            'success'    => true,
            'token'      => $jwt,
            'csrf_token' => $csrf,
            'user'       => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ]);
    }

    public function showSetup(?string $error = null): void
    {
        SecurityHelper::sendHtmlHeaders();
        SecurityHelper::configureSecureSession();

        if (AuthMiddleware::userCount($this->db) > 0) {
            header('Location: /login');
            exit;
        }

        $csrfToken = SecurityHelper::csrfToken();
        $pageError = $error;
        require __DIR__ . '/../views/auth/setup.php';
    }

    public function handleSetup(): void
    {
        SecurityHelper::configureSecureSession();
        CsrfMiddleware::validateForm();

        // Permanently locked once any user exists
        if (AuthMiddleware::userCount($this->db) > 0) {
            header('Location: /login');
            exit;
        }

        $name = SecurityHelper::sanitizePlainText($_POST['name'] ?? '', 191);
        $email = strtolower(filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '' || $email === '') {
            $this->showSetup('Full name and a valid administrator email address are required.');
            return;
        }

        if (strlen($password) < 8) {
            $this->showSetup('Administrator password must be at least 8 characters long.');
            return;
        }

        if ($password !== $confirm) {
            $this->showSetup('Passwords do not match.');
            return;
        }

        UserApiController::ensureRolesSchema($this->db);
        $id = UserApiController::nextNumericUserId($this->db);
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);

        $stmt = $this->db->prepare("
            INSERT INTO users (id, name, email, password_hash, role, hire_date, is_active, is_suspended)
            VALUES (:id, :name, :email, :hash, 'admin', CURRENT_DATE, TRUE, FALSE)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':name'  => $name,
            ':email' => $email,
            ':hash'  => $hash,
        ]);

        $rStmt = $this->db->prepare("INSERT INTO user_roles (user_id, role_slug) VALUES (:uid, 'admin') ON CONFLICT (user_id, role_slug) DO NOTHING");
        $rStmt->execute([':uid' => $id]);

        session_regenerate_id(true);
        $_SESSION['workly_user_id'] = $id;
        $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        SecurityHelper::rotateCsrfToken();
        if (!SecurityHelper::hasDeclinedPersistentCookies()) {
            SecurityHelper::issuePersistentSession($this->db, (string) $id);
            SecurityHelper::refreshSessionCookieLifetime(true);
        }

        AuditLogger::log('INITIAL_ADMIN_CREATED', 'success', "Initial production administrator {$email} bootstrapped via /setup", $id, 'admin', $this->db);
        header('Location: /dashboard');
        exit;
    }

    public function handleCookieConsent(): void
    {
        SecurityHelper::configureSecureSession();
        CsrfMiddleware::validateApi('POST');

        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $consent = strtolower(trim((string) ($input['consent'] ?? '')));
        if (!in_array($consent, ['accepted', 'declined'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid consent preference']);
            return;
        }

        SecurityHelper::setCookieConsentCookie($consent);

        $uid = (string) ($_SESSION['workly_user_id'] ?? '');
        if ($consent === 'accepted') {
            if ($uid !== '') {
                SecurityHelper::issuePersistentSession($this->db, $uid);
                SecurityHelper::refreshSessionCookieLifetime(true);
            }
        } else {
            SecurityHelper::revokeCurrentPersistentSession($this->db);
            SecurityHelper::refreshSessionCookieLifetime(false);
        }

        echo json_encode([
            'success' => true,
            'consent' => $consent,
        ]);
    }

    public function handleLogout(): void
    {
        SecurityHelper::configureSecureSession();
        $uid = (string) ($_SESSION['workly_user_id'] ?? 'anonymous');
        if ($uid !== 'anonymous') {
            AuditLogger::log('LOGOUT', 'success', "User {$uid} signed out", $uid, 'user', $this->db);
        }

        SecurityHelper::revokeCurrentPersistentSession($this->db);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();

        header('Location: /login');
        exit;
    }

    private function redirectForRole(string $role): never
    {
        if ($role === 'va') {
            header('Location: /tasks');
        } else {
            header('Location: /dashboard');
        }
        exit;
    }
}
