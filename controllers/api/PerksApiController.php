<?php
/**
 * Workly Perks, Benefits & Recognition Badges REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class PerksApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/perks/{seg1}/{seg2}
        $seg1 = $parts[3] ?? null;
        $seg2 = $parts[4] ?? null;

        if ($method === 'GET' && !$seg1) {
            $this->listAll($user);
            return;
        }
        if ($method === 'POST' && !$seg1) {
            AuthMiddleware::requireRoles(['admin', 'hrm'], $user);
            $this->createPerk($user);
            return;
        }
        if ($method === 'POST' && $seg1 && $seg1 !== 'badges' && $seg2 === 'grant') {
            AuthMiddleware::requireRoles(['admin', 'hrm'], $user);
            $this->grantPerk($seg1, $user);
            return;
        }
        if ($method === 'DELETE' && $seg1 && $seg1 !== 'badges' && $seg2 === 'grant') {
            AuthMiddleware::requireRoles(['admin', 'hrm'], $user);
            $this->revokePerk($seg1, $user);
            return;
        }
        if ($method === 'DELETE' && $seg1 && $seg1 !== 'badges' && !$seg2) {
            AuthMiddleware::requireRoles(['admin', 'hrm'], $user);
            $this->deletePerk($seg1, $user);
            return;
        }
        if ($method === 'POST' && $seg1 === 'badges' && !$seg2) {
            AuthMiddleware::requireRoles(['admin', 'hrm', 'team_leader'], $user);
            $this->createBadge($user);
            return;
        }
        if ($method === 'POST' && $seg1 === 'badges' && $seg2 && ($parts[5] ?? '') === 'award') {
            AuthMiddleware::requireRoles(['admin', 'hrm', 'team_leader'], $user);
            $this->awardBadge($seg2, $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Perks API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'perks:');
        CacheService::invalidatePrefix($this->db, 'users:');
    }

    private function listAll(array $user): void
    {
        $hit = false;
        $payload = CacheService::remember($this->db, 'perks:all', 45, function (): array {
            $perks = $this->db->query('SELECT * FROM perks ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
            $grants = $this->db->query("
                SELECT up.perk_id, up.user_id, u.name AS va_name, up.granted_at
                FROM user_perks up
                JOIN users u ON up.user_id = u.id
                ORDER BY up.granted_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            $badges = $this->db->query('SELECT * FROM badges ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
            $awards = $this->db->query("
                SELECT ub.badge_id, ub.user_id, u.name AS va_name, b.title AS badge_title, b.tier, ub.earned_at
                FROM user_badges ub
                JOIN users u ON ub.user_id = u.id
                JOIN badges b ON ub.badge_id = b.id
                ORDER BY ub.earned_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            return [
                'perks'  => $perks,
                'grants' => $grants,
                'badges' => $badges,
                'awards' => $awards,
            ];
        }, $hit);

        if ($user['role'] === 'va') {
            $payload['grants'] = array_values(array_filter(
                $payload['grants'],
                static fn(array $g) => $g['user_id'] === $user['id']
            ));
            $payload['awards'] = array_values(array_filter(
                $payload['awards'],
                static fn(array $a) => $a['user_id'] === $user['id']
            ));
        }

        CacheService::sendJsonWithEtag($payload, $hit, 30);
    }

    private function createPerk(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 191);
        $category = SecurityHelper::sanitizePlainText($input['category'] ?? 'Equipment & Hardware', 64);
        $value = max(0.0, (float) ($input['value'] ?? 0));
        $description = SecurityHelper::sanitizePlainText($input['description'] ?? '', 2000);

        if ($title === '' || $description === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title and benefit description are required']);
            return;
        }

        $id = 'perk-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO perks (id, title, category, value, description, active)
            VALUES (:id, :title, :cat, :val, :desc, TRUE)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':title' => $title,
            ':cat'   => $category,
            ':val'   => $value,
            ':desc'  => $description,
        ]);

        $this->invalidate();
        AuditLogger::log('PERK_CREATED', 'success', "Created benefit {$title}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function deletePerk(string $perkId, array $user): void
    {
        $this->db->prepare('DELETE FROM perks WHERE id = :id')->execute([':id' => $perkId]);
        $this->invalidate();
        AuditLogger::log('PERK_DELETED', 'warning', "Deleted perk {$perkId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $perkId]);
    }

    private function grantPerk(string $perkId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $vaId = SecurityHelper::sanitizePlainText($input['user_id'] ?? '', 64);
        if ($vaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Target user_id is required']);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO user_perks (perk_id, user_id) VALUES (:pid, :uid) ON CONFLICT DO NOTHING');
        $stmt->execute([':pid' => $perkId, ':uid' => $vaId]);

        $this->invalidate();
        AuditLogger::log('PERK_GRANTED', 'success', "Granted perk {$perkId} to {$vaId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true]);
    }

    private function revokePerk(string $perkId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $vaId = SecurityHelper::sanitizePlainText($input['user_id'] ?? ($_GET['user_id'] ?? ''), 64);
        if ($vaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Target user_id is required']);
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM user_perks WHERE perk_id = :pid AND user_id = :uid');
        $stmt->execute([':pid' => $perkId, ':uid' => $vaId]);

        $this->invalidate();
        AuditLogger::log('PERK_REVOKED', 'warning', "Revoked perk {$perkId} from {$vaId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true]);
    }

    private function createBadge(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 191);
        $description = SecurityHelper::sanitizePlainText($input['description'] ?? '', 1000);
        $tier = in_array($input['tier'] ?? '', ['bronze', 'silver', 'gold', 'diamond'], true) ? $input['tier'] : 'silver';
        $trigger = in_array($input['trigger_type'] ?? '', ['tenure_months', 'tasks_completed', 'on_time_streak', 'client_praise', 'sop_authored', 'custom'], true)
            ? $input['trigger_type']
            : 'custom';
        $threshold = max(0, (int) ($input['required_threshold'] ?? 0));

        if ($title === '' || $description === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Badge title and description are required']);
            return;
        }

        $id = 'bdg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO badges (id, title, description, icon_name, tier, trigger_type, required_threshold)
            VALUES (:id, :title, :desc, 'Award', :tier, :trig, :thresh)
        ");
        $stmt->execute([
            ':id'     => $id,
            ':title'  => $title,
            ':desc'   => $description,
            ':tier'   => $tier,
            ':trig'   => $trigger,
            ':thresh' => $threshold,
        ]);

        $this->invalidate();
        AuditLogger::log('BADGE_CREATED', 'success', "Created badge {$title} ({$tier})", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function awardBadge(string $badgeId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $vaId = SecurityHelper::sanitizePlainText($input['user_id'] ?? '', 64);
        if ($vaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Target VA user_id is required']);
            return;
        }

        $id = 'ubdg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO user_badges (id, user_id, badge_id, earned_at)
            VALUES (:id, :uid, :bid, CURRENT_DATE)
            ON CONFLICT (user_id, badge_id) DO NOTHING
        ");
        $stmt->execute([':id' => $id, ':uid' => $vaId, ':bid' => $badgeId]);

        $this->invalidate();
        AuditLogger::log('BADGE_AWARDED', 'success', "Awarded badge {$badgeId} to {$vaId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true]);
    }
}
