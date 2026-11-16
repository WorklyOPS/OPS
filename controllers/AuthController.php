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

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $turnstileToken = (string) ($_POST['cf-turnstile-response'] ?? '');

        if (!SecurityHelper::verifyTurnstile($turnstileToken)) {
            AuditLogger::log('LOGIN_TURNSTILE_BLOCKED', 'blocked', "Turnstile verification failed for {$email}", 'anonymous', 'guest', $this->db);
            $this->showLogin('Security verification failed. Please complete the challenge and try again.');
            return;
        }

        if ($email === '' || $password === '') {
            $this->showLogin('Please enter both your email address and password.');
            return;
        }

        $stmt = $this->db->prepare("
            SELECT id, name, email, password_hash, role, is_active, is_suspended, suspended_reason
            FROM users
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
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

        session_regenerate_id(true);
        $_SESSION['workly_user_id'] = $user['id'];
        $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        SecurityHelper::rotateCsrfToken();

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

        $stmt = $this->db->prepare("
            SELECT id, name, email, password_hash, role, is_active, is_suspended
            FROM users
            WHERE LOWER(email) = LOWER(:email)
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

        // Permanently locked once any user exists in PostgreSQL
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

        $id = 'admin-' . bin2hex(random_bytes(5));
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

        session_regenerate_id(true);
        $_SESSION['workly_user_id'] = $id;
        $_SESSION['workly_fp'] = SecurityHelper::getClientFingerprint();
        SecurityHelper::rotateCsrfToken();

        AuditLogger::log('INITIAL_ADMIN_CREATED', 'success', "Initial production administrator {$email} bootstrapped via /setup", $id, 'admin', $this->db);
        header('Location: /dashboard');
        exit;
    }

    public function handleLogout(): void
    {
        SecurityHelper::configureSecureSession();
        $uid = (string) ($_SESSION['workly_user_id'] ?? 'anonymous');
        if ($uid !== 'anonymous') {
            AuditLogger::log('LOGOUT', 'success', "User {$uid} signed out", $uid, 'user', $this->db);
        }

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
