<?php
/**
 * Workly User & Staff Governance API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class UserApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $actor = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        $targetId = $parts[3] ?? ($_GET['id'] ?? null);
        $action = $parts[4] ?? null;

        if ($method === 'GET' && !$targetId) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $actor);
            $this->listUsers();
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $targetId === 'me' && !$action) {
            $this->updateSelfProfile($actor);
            return;
        }

        AuthMiddleware::requireRoles(['admin'], $actor);

        if ($method === 'POST' && !$targetId) {
            $this->createUser($actor);
            return;
        }
        if ($method === 'PATCH' && $targetId && $action === 'suspend') {
            $this->suspendUser($targetId, $actor);
            return;
        }
        if ($method === 'PATCH' && $targetId && $action === 'reactivate') {
            $this->reactivateUser($targetId, $actor);
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $targetId && !$action) {
            $this->updateUser($targetId, $actor);
            return;
        }
        if ($method === 'DELETE' && $targetId) {
            $this->deleteUser($targetId, $actor);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'User API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'users:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
    }

    private function listUsers(): void
    {
        $hit = false;
        $payload = CacheService::remember($this->db, 'users:all', 45, function (): array {
            $stmt = $this->db->query("
                SELECT id, name, email, role, avatar_url, phone, bio, hire_date,
                       hourly_rate, total_earnings, pto_balance_days, pto_taken_days,
                       team_leader_id, is_active, is_suspended, suspended_reason
                FROM users
                ORDER BY role ASC, name ASC
            ");
            return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }, $hit);
        CacheService::sendJsonWithEtag($payload, $hit, 30);
    }

    private function createUser(array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $name = SecurityHelper::sanitizePlainText($input['name'] ?? '', 191);
        $email = strtolower(filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '');
        $password = (string) ($input['password'] ?? '');
        $role = in_array($input['role'] ?? '', ['admin', 'team_leader', 'hrm', 'va'], true) ? $input['role'] : 'va';

        if ($name === '' || $email === '' || strlen($password) < 8) {
            http_response_code(422);
            echo json_encode(['error' => 'Valid name, unique email, and password (min 8 chars) are required']);
            return;
        }

        $check = $this->db->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute([':email' => $email]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'An account with this email already exists']);
            return;
        }

        $id = 'user-' . bin2hex(random_bytes(5));
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
        $tlId = SecurityHelper::sanitizePlainText($input['team_leader_id'] ?? '', 64) ?: null;

        $stmt = $this->db->prepare("
            INSERT INTO users (id, name, email, password_hash, role, phone, bio, hire_date, hourly_rate, team_leader_id, is_active, is_suspended)
            VALUES (:id, :name, :email, :hash, :role, :phone, :bio, CURRENT_DATE, :rate, :tl, TRUE, FALSE)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':name'  => $name,
            ':email' => $email,
            ':hash'  => $hash,
            ':role'  => $role,
            ':phone' => SecurityHelper::sanitizePlainText($input['phone'] ?? '', 64),
            ':bio'   => SecurityHelper::sanitizePlainText($input['bio'] ?? '', 2000),
            ':rate'  => max(0.0, (float) ($input['hourly_rate'] ?? 0)),
            ':tl'    => $role === 'va' ? $tlId : null,
        ]);

        if (!empty($input['specialties']) && is_array($input['specialties'])) {
            $ins = $this->db->prepare('INSERT INTO va_specialties (va_id, specialty) VALUES (:va_id, :spec) ON CONFLICT DO NOTHING');
            foreach ($input['specialties'] as $sp) {
                $clean = SecurityHelper::sanitizePlainText((string) $sp, 64);
                if ($clean !== '') {
                    $ins->execute([':va_id' => $id, ':spec' => $clean]);
                }
            }
        }

        if (!empty($input['responsibilities']) && is_array($input['responsibilities'])) {
            $insResp = $this->db->prepare('INSERT INTO va_responsibilities (va_id, responsibility) VALUES (:va_id, :resp)');
            foreach ($input['responsibilities'] as $rp) {
                $clean = SecurityHelper::sanitizePlainText((string) $rp, 255);
                if ($clean !== '') {
                    $insResp->execute([':va_id' => $id, ':resp' => $clean]);
                }
            }
        }

        if ($role === 'va') {
            $mStmt = $this->db->prepare('INSERT INTO va_task_metrics (va_id) VALUES (:va_id) ON CONFLICT DO NOTHING');
            $mStmt->execute([':va_id' => $id]);
        }

        $this->invalidate();
        AuditLogger::log('USER_CREATED', 'success', "Admin created user {$email} ({$role})", $actor['id'], $actor['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function updateUser(string $targetId, array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $allowed = ['name', 'email', 'role', 'phone', 'bio', 'hourly_rate', 'pto_balance_days', 'team_leader_id'];
        $fields = [];
        $params = [':id' => $targetId];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $input)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = is_string($input[$col])
                    ? (SecurityHelper::sanitizePlainText($input[$col]) ?: null)
                    : $input[$col];
            }
        }

        if (!empty($input['password']) && strlen((string) $input['password']) >= 8) {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash((string) $input['password'], PASSWORD_BCRYPT, ['cost' => 11]);
        }

        if (!empty($fields)) {
            $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id');
            $stmt->execute($params);
        }

        if (isset($input['specialties']) && is_array($input['specialties'])) {
            $this->db->prepare('DELETE FROM va_specialties WHERE va_id = :va')->execute([':va' => $targetId]);
            $ins = $this->db->prepare('INSERT INTO va_specialties (va_id, specialty) VALUES (:va, :sp) ON CONFLICT DO NOTHING');
            foreach ($input['specialties'] as $sp) {
                $clean = SecurityHelper::sanitizePlainText((string) $sp, 64);
                if ($clean !== '') {
                    $ins->execute([':va' => $targetId, ':sp' => $clean]);
                }
            }
        }

        if (isset($input['responsibilities']) && is_array($input['responsibilities'])) {
            $this->db->prepare('DELETE FROM va_responsibilities WHERE va_id = :va')->execute([':va' => $targetId]);
            $ins = $this->db->prepare('INSERT INTO va_responsibilities (va_id, responsibility) VALUES (:va, :rp)');
            foreach ($input['responsibilities'] as $rp) {
                $clean = SecurityHelper::sanitizePlainText((string) $rp, 255);
                if ($clean !== '') {
                    $ins->execute([':va' => $targetId, ':rp' => $clean]);
                }
            }
        }

        $this->invalidate();
        AuditLogger::log('USER_UPDATED', 'success', "Admin updated account {$targetId}", $actor['id'], $actor['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $targetId]);
    }

    private function suspendUser(string $targetId, array $actor): void
    {
        if ($targetId === $actor['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot suspend your own administrator account']);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $reason = SecurityHelper::sanitizePlainText($input['reason'] ?? 'Suspended by System Administrator', 255);

        $stmt = $this->db->prepare('UPDATE users SET is_active = FALSE, is_suspended = TRUE, suspended_reason = :reason, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':reason' => $reason, ':id' => $targetId]);

        $this->invalidate();
        AuditLogger::log('USER_SUSPENDED', 'warning', "Suspended account {$targetId}: {$reason}", $actor['id'], $actor['role'], $this->db);
        echo json_encode(['success' => true, 'status' => 'suspended']);
    }

    private function reactivateUser(string $targetId, array $actor): void
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = TRUE, is_suspended = FALSE, suspended_reason = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $targetId]);

        $this->invalidate();
        AuditLogger::log('USER_REACTIVATED', 'success', "Reactivated account {$targetId}", $actor['id'], $actor['role'], $this->db);
        echo json_encode(['success' => true, 'status' => 'active']);
    }

    private function updateSelfProfile(array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $phone = SecurityHelper::sanitizePlainText($input['phone'] ?? '', 64);
        $bio = SecurityHelper::sanitizePlainText($input['bio'] ?? '', 2000);

        $stmt = $this->db->prepare('UPDATE users SET phone = :phone, bio = :bio, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':phone' => $phone,
            ':bio'   => $bio,
            ':id'    => $actor['id'],
        ]);

        if (isset($input['specialties']) && is_array($input['specialties'])) {
            $this->db->prepare('DELETE FROM va_specialties WHERE va_id = :va')->execute([':va' => $actor['id']]);
            $ins = $this->db->prepare('INSERT INTO va_specialties (va_id, specialty) VALUES (:va, :sp) ON CONFLICT DO NOTHING');
            foreach ($input['specialties'] as $sp) {
                $clean = SecurityHelper::sanitizePlainText((string) $sp, 64);
                if ($clean !== '') {
                    $ins->execute([':va' => $actor['id'], ':sp' => $clean]);
                }
            }
        }

        $this->invalidate();
        AuditLogger::log('PROFILE_UPDATED', 'success', 'User updated personal profile & specialties', (string) $actor['id'], (string) $actor['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $actor['id']]);
    }

    private function deleteUser(string $targetId, array $actor): void
    {
        if ($targetId === $actor['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete your own administrator account']);
            return;
        }
        $this->db->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetId]);
        $this->invalidate();
        AuditLogger::log('USER_DELETED', 'warning', "Deleted user {$targetId}", $actor['id'], $actor['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $targetId]);
    }
}
