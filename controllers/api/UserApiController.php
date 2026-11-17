<?php
/**
 * Workly User, VA Specialties & Pod Roster Governance API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class UserApiController
{
    public const DEFAULT_SPECIALTIES = [
        'Development',
        'Marketing',
        'Legal',
        'Insurance',
        'Healthcare',
        'Administrative',
        'Management',
        'Accounting',
    ];

    public const DEFAULT_ROLES = [
        ['slug' => 'va', 'label' => 'Virtual Assistant (VA)', 'description' => 'Operational tasks, assigned clients, calendar & PTO submissions'],
        ['slug' => 'team_leader', 'label' => 'Team Leader', 'description' => 'Pod supervision, task review, client orchestration & badges'],
        ['slug' => 'hrm', 'label' => 'HR Manager', 'description' => 'PTO approval workflow, balance governance & compliance audits'],
        ['slug' => 'admin', 'label' => 'System Admin', 'description' => 'Full governance, staff provisioning & security controls'],
    ];

    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $actor = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);
        self::ensureSpecialtyCategoriesTable($this->db);
        self::ensureRolesSchema($this->db);

        $parts = explode('/', trim($uri, '/'));
        $targetId = $parts[3] ?? ($_GET['id'] ?? null);
        $action = $parts[4] ?? null;

        if ($method === 'GET' && $targetId === 'roles') {
            echo json_encode(['data' => self::listRoleCategories($this->db)]);
            return;
        }
        if ($method === 'GET' && $targetId === 'specialties') {
            echo json_encode(['data' => self::listSpecialtyCategories($this->db)]);
            return;
        }
        if ($method === 'POST' && $targetId === 'specialties') {
            AuthMiddleware::requireRoles(['admin'], $actor);
            $this->createSpecialtyCategory($actor);
            return;
        }
        if ($method === 'PATCH' && $targetId === 'pod-vas') {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $actor);
            $this->updatePodVas($actor);
            return;
        }

        if ($method === 'GET' && !$targetId) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $actor);
            $this->listUsers();
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $targetId === 'me' && !$action) {
            $this->updateSelfProfile($actor);
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $targetId && !$action) {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $actor);
            $this->updateUser($targetId, $actor);
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
        if ($method === 'DELETE' && $targetId) {
            $this->deleteUser($targetId, $actor);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'User API route not found']);
    }

    public static function ensureSpecialtyCategoriesTable(PDO $db): void
    {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS va_specialty_categories (
                    name VARCHAR(64) PRIMARY KEY,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $ins = $db->prepare("INSERT INTO va_specialty_categories (name) VALUES (:n) ON CONFLICT (name) DO NOTHING");
            foreach (self::DEFAULT_SPECIALTIES as $cat) {
                $ins->execute([':n' => $cat]);
            }
        } catch (Throwable) {
            // Schema already aligned
        }
    }

    public static function ensureRolesSchema(PDO $db): void
    {
        try {
            $db->exec("CREATE SEQUENCE IF NOT EXISTS user_numeric_id_seq START WITH 1 INCREMENT BY 1");
            $db->exec("
                CREATE TABLE IF NOT EXISTS roles (
                    slug VARCHAR(32) PRIMARY KEY,
                    label VARCHAR(64) NOT NULL,
                    description VARCHAR(255) NOT NULL DEFAULT '',
                    is_default BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $ins = $db->prepare("
                INSERT INTO roles (slug, label, description, is_default)
                VALUES (:slug, :label, :desc, TRUE)
                ON CONFLICT (slug) DO NOTHING
            ");
            foreach (self::DEFAULT_ROLES as $r) {
                $ins->execute([':slug' => $r['slug'], ':label' => $r['label'], ':desc' => $r['description']]);
            }
            $db->exec("
                CREATE TABLE IF NOT EXISTS user_roles (
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    role_slug VARCHAR(32) NOT NULL REFERENCES roles(slug) ON DELETE RESTRICT,
                    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (user_id, role_slug)
                )
            ");
            $db->exec("INSERT INTO user_roles (user_id, role_slug) SELECT id, role FROM users ON CONFLICT (user_id, role_slug) DO NOTHING");
        } catch (Throwable) {
            // Schema already aligned
        }
    }

    public static function listRoleCategories(PDO $db): array
    {
        self::ensureRolesSchema($db);
        try {
            return $db->query('SELECT slug, label, description, is_default FROM roles ORDER BY is_default DESC, label ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return self::DEFAULT_ROLES;
        }
    }

    public static function nextNumericUserId(PDO $db): string
    {
        self::ensureRolesSchema($db);
        try {
            $maxNum = (int) $db->query("SELECT COALESCE(MAX(id::BIGINT), 0) FROM users WHERE id ~ '^[0-9]+$'")->fetchColumn();
            $candidate = (int) $db->query("SELECT nextval('user_numeric_id_seq')")->fetchColumn();
            if ($candidate <= $maxNum) {
                $target = $maxNum + 1;
                $db->query("SELECT setval('user_numeric_id_seq', {$target}, TRUE)");
                $candidate = $target;
            }
            return (string) $candidate;
        } catch (Throwable) {
            try {
                $maxNum = (int) $db->query("SELECT COALESCE(MAX(id::BIGINT), 0) FROM users WHERE id ~ '^[0-9]+$'")->fetchColumn();
                return (string) ($maxNum + 1);
            } catch (Throwable) {
                return (string) random_int(1000, 999999);
            }
        }
    }

    public static function listSpecialtyCategories(PDO $db): array
    {
        self::ensureSpecialtyCategoriesTable($db);
        try {
            return $db->query('SELECT name FROM va_specialty_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return self::DEFAULT_SPECIALTIES;
        }
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'users:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
    }

    private function createSpecialtyCategory(array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $name = SecurityHelper::sanitizePlainText($input['name'] ?? '', 64);
        if ($name === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Specialty category name is required']);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO va_specialty_categories (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');
        $stmt->execute([':name' => $name]);

        $this->invalidate();
        AuditLogger::log('VA_SPECIALTY_CREATED', 'success', "Admin added VA specialty category: {$name}", $actor['id'], $actor['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'name' => $name, 'categories' => self::listSpecialtyCategories($this->db)]);
    }

    private function updatePodVas(array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $tlId = $actor['role'] === 'admin'
            ? SecurityHelper::sanitizePlainText($input['team_leader_id'] ?? $actor['id'], 64)
            : (string) $actor['id'];

        $selectedIds = isset($input['va_ids']) && is_array($input['va_ids'])
            ? array_values(array_unique(array_filter(array_map(
                static fn($v) => SecurityHelper::sanitizePlainText((string) $v, 64),
                $input['va_ids']
            ))))
            : [];

        // Clear VAs previously assigned to this TL if they were unchecked
        $clr = $this->db->prepare("UPDATE users SET team_leader_id = NULL, updated_at = NOW() WHERE role = 'va' AND team_leader_id = :tl");
        $clr->execute([':tl' => $tlId]);

        if (!empty($selectedIds)) {
            $ph = implode(',', array_fill(0, count($selectedIds), '?'));
            $params = array_merge([$tlId], $selectedIds);
            $upd = $this->db->prepare("UPDATE users SET team_leader_id = ?, updated_at = NOW() WHERE role = 'va' AND id IN ({$ph})");
            $upd->execute($params);
        }

        $this->invalidate();
        AuditLogger::log('POD_VAS_UPDATED', 'success', "Updated assigned VAs for Team Leader {$tlId}", $actor['id'], $actor['role'], $this->db);
        echo json_encode(['success' => true, 'team_leader_id' => $tlId, 'va_ids' => $selectedIds]);
    }

    private function listUsers(): void
    {
        $hit = false;
        $payload = CacheService::remember($this->db, 'users:all', 45, function (): array {
            $stmt = $this->db->query("
                SELECT u.id, u.name, u.email, COALESCE(MAX(ur.role_slug), u.role) AS role, u.avatar_url, u.phone, u.bio, u.hire_date,
                       u.hourly_rate, u.total_earnings, u.pto_balance_days, u.pto_taken_days,
                       u.team_leader_id, u.is_active, u.is_suspended, u.suspended_reason,
                       COALESCE(STRING_AGG(DISTINCT s.specialty, '||'), '') AS specialties_joined
                FROM users u
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN va_specialties s ON u.id = s.va_id
                GROUP BY u.id
                ORDER BY role ASC, u.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['specialties'] = ($r['role'] === 'va' && $r['specialties_joined'] !== '')
                    ? explode('||', (string) $r['specialties_joined'])
                    : [];
                unset($r['specialties_joined']);
            }
            unset($r);
            return ['data' => $rows];
        }, $hit);
        CacheService::sendJsonWithEtag($payload, $hit, 30);
    }

    private function createUser(array $actor): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $name = SecurityHelper::sanitizePlainText($input['name'] ?? '', 191);
        $email = strtolower(filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '');
        $password = (string) ($input['password'] ?? '');
        $validSlugs = array_column(self::listRoleCategories($this->db), 'slug');
        $requestedRole = SecurityHelper::sanitizePlainText((string) ($input['role'] ?? 'va'), 32);
        $role = in_array($requestedRole, $validSlugs, true) ? $requestedRole : 'va';

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

        $id = self::nextNumericUserId($this->db);
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

        // Assign role row linked to numeric user ID (automatic 'va' role when creating a VA)
        $roleAssign = $this->db->prepare("
            INSERT INTO user_roles (user_id, role_slug, assigned_at)
            VALUES (:uid, :slug, NOW())
            ON CONFLICT (user_id, role_slug) DO UPDATE SET assigned_at = NOW()
        ");
        $roleAssign->execute([':uid' => $id, ':slug' => $role]);

        // Specialties strictly reserved for VA accounts
        if ($role === 'va' && !empty($input['specialties']) && is_array($input['specialties'])) {
            $validCats = self::listSpecialtyCategories($this->db);
            $ins = $this->db->prepare('INSERT INTO va_specialties (va_id, specialty) VALUES (:va_id, :spec) ON CONFLICT DO NOTHING');
            foreach ($input['specialties'] as $sp) {
                $clean = SecurityHelper::sanitizePlainText((string) $sp, 64);
                if ($clean !== '' && in_array($clean, $validCats, true)) {
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
        $uStmt = $this->db->prepare('SELECT id, role, team_leader_id FROM users WHERE id = :id LIMIT 1');
        $uStmt->execute([':id' => $targetId]);
        $targetRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Target user account not found']);
            return;
        }

        if ($actor['role'] === 'team_leader') {
            $isSelf = (string) $targetRow['id'] === (string) $actor['id'];
            $isMyVa = (string) $targetRow['role'] === 'va' && (string) ($targetRow['team_leader_id'] ?? '') === (string) $actor['id'];
            if (!$isSelf && !$isMyVa) {
                AuditLogger::log(
                    'USER_PROFILE_SCOPE_DENIED',
                    'blocked',
                    "Team Leader {$actor['id']} denied edit access to out-of-pod user {$targetId}",
                    (string) $actor['id'],
                    (string) $actor['role'],
                    $this->db
                );
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: You can only inspect or update your own profile and VAs assigned to your team']);
                return;
            }
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        // Team Leaders can edit profiles (name, phone, bio, team assignment, responsibilities, VA specialties)
        // Admins can edit all fields including role, hourly_rate, pto_balance_days, password.
        $allowed = $actor['role'] === 'admin'
            ? ['name', 'email', 'role', 'phone', 'bio', 'hourly_rate', 'pto_balance_days', 'team_leader_id']
            : ['name', 'phone', 'bio', 'team_leader_id'];

        $fields = [];
        $params = [':id' => $targetId];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $input)) {
                $fields[] = "{$col} = :{$col}";
                if ($col === 'email') {
                    $params[':email'] = strtolower(filter_var($input[$col], FILTER_VALIDATE_EMAIL) ?: '');
                } elseif (is_string($input[$col])) {
                    $params[":{$col}"] = SecurityHelper::sanitizePlainText($input[$col]) ?: null;
                } else {
                    $params[":{$col}"] = $input[$col];
                }
            }
        }

        if ($actor['role'] === 'admin' && !empty($input['password']) && strlen((string) $input['password']) >= 8) {
            $fields[] = 'password_hash = :password_hash';
            $params[':password_hash'] = password_hash((string) $input['password'], PASSWORD_BCRYPT, ['cost' => 11]);
        }

        if (!empty($fields)) {
            $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id');
            $stmt->execute($params);
        }

        $effectiveRole = ($actor['role'] === 'admin' && !empty($input['role']))
            ? (string) $input['role']
            : (string) $targetRow['role'];

        if ($actor['role'] === 'admin' && !empty($input['role'])) {
            $validSlugs = array_column(self::listRoleCategories($this->db), 'slug');
            if (in_array($effectiveRole, $validSlugs, true)) {
                $this->db->prepare('DELETE FROM user_roles WHERE user_id = :uid')->execute([':uid' => $targetId]);
                $this->db->prepare('INSERT INTO user_roles (user_id, role_slug, assigned_at) VALUES (:uid, :slug, NOW()) ON CONFLICT DO NOTHING')
                    ->execute([':uid' => $targetId, ':slug' => $effectiveRole]);
            }
        }

        if ($effectiveRole !== 'va') {
            $this->db->prepare('DELETE FROM va_specialties WHERE va_id = :va')->execute([':va' => $targetId]);
        } elseif (isset($input['specialties']) && is_array($input['specialties'])) {
            $validCats = self::listSpecialtyCategories($this->db);
            $this->db->prepare('DELETE FROM va_specialties WHERE va_id = :va')->execute([':va' => $targetId]);
            $ins = $this->db->prepare('INSERT INTO va_specialties (va_id, specialty) VALUES (:va, :sp) ON CONFLICT DO NOTHING');
            foreach ($input['specialties'] as $sp) {
                $clean = SecurityHelper::sanitizePlainText((string) $sp, 64);
                if ($clean !== '' && in_array($clean, $validCats, true)) {
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
        AuditLogger::log('USER_UPDATED', 'success', "{$actor['role']} updated account {$targetId}", $actor['id'], $actor['role'], $this->db);
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
        if ($actor['role'] === 'va') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: VAs have read-only profile access. Contact your Team Leader or Administrator.']);
            return;
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $phone = SecurityHelper::sanitizePlainText($input['phone'] ?? '', 64);
        $bio = SecurityHelper::sanitizePlainText($input['bio'] ?? '', 2000);

        $stmt = $this->db->prepare('UPDATE users SET phone = :phone, bio = :bio, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':phone' => $phone,
            ':bio'   => $bio,
            ':id'    => $actor['id'],
        ]);

        $this->invalidate();
        AuditLogger::log('PROFILE_UPDATED', 'success', 'Staff member updated own profile details', (string) $actor['id'], (string) $actor['role'], $this->db);
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
