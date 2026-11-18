<?php
/**
 * Workly Perks, Benefits & Recognition Badges REST API Controller
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/NotificationApiController.php';

final class PerksApiController
{
    public const VALID_ICONS = ['Award', 'Medal', 'Trophy', 'Star', 'Shield', 'Crown', 'Zap', 'Heart'];

    public function __construct(private PDO $db) {}

    public static function ensurePerksSchema(PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE perks ADD COLUMN IF NOT EXISTS icon_name VARCHAR(64) NOT NULL DEFAULT 'Award'");
            $db->exec("ALTER TABLE badges ADD COLUMN IF NOT EXISTS icon_name VARCHAR(64) NOT NULL DEFAULT 'Medal'");
            $db->exec("
                CREATE TABLE IF NOT EXISTS perk_badge_history (
                    id VARCHAR(64) PRIMARY KEY,
                    event_type VARCHAR(32) NOT NULL CHECK (event_type IN ('perk_granted', 'perk_revoked', 'badge_awarded', 'perk_created', 'perk_deleted', 'badge_created')),
                    item_type VARCHAR(16) NOT NULL CHECK (item_type IN ('perk', 'badge')),
                    item_id VARCHAR(64) NOT NULL,
                    item_title VARCHAR(191) NOT NULL,
                    item_meta VARCHAR(128) DEFAULT NULL,
                    va_id VARCHAR(64) DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
                    va_name VARCHAR(191) DEFAULT NULL,
                    actor_id VARCHAR(64) DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
                    actor_name VARCHAR(191) NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pb_history_created ON perk_badge_history (created_at DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pb_history_va ON perk_badge_history (va_id, created_at DESC)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pb_history_item ON perk_badge_history (item_type, item_id, created_at DESC)");
        } catch (Throwable) {
        }
    }

    public static function fetchBoardPayload(PDO $db, array $user): array
    {
        self::ensurePerksSchema($db);

        $perks = $db->query("
            SELECT id, title, category, COALESCE(icon_name, 'Award') AS icon_name, value, description, active, created_at
            FROM perks
            ORDER BY created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $grants = $db->query("
            SELECT up.perk_id, up.user_id, u.name AS va_name, COALESCE(u.avatar_url, '') AS va_avatar,
                   p.title AS perk_title, p.category, COALESCE(p.icon_name, 'Award') AS icon_name, up.granted_at
            FROM user_perks up
            JOIN users u ON up.user_id = u.id
            JOIN perks p ON up.perk_id = p.id
            ORDER BY up.granted_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $badges = $db->query("
            SELECT id, title, description, COALESCE(icon_name, 'Medal') AS icon_name, tier, trigger_type, required_threshold, created_at
            FROM badges
            ORDER BY created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $awards = $db->query("
            SELECT ub.badge_id, ub.user_id, u.name AS va_name, COALESCE(u.avatar_url, '') AS va_avatar,
                   b.title AS badge_title, b.tier, COALESCE(b.icon_name, 'Medal') AS icon_name, ub.earned_at
            FROM user_badges ub
            JOIN users u ON ub.user_id = u.id
            JOIN badges b ON ub.badge_id = b.id
            ORDER BY ub.earned_at DESC, ub.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        try {
            $history = $db->query("
                SELECT h.id, h.event_type, h.item_type, h.item_id, h.item_title, h.item_meta,
                       h.va_id, COALESCE(u.name, h.va_name) AS va_name, COALESCE(u.avatar_url, '') AS va_avatar,
                       h.actor_id, h.actor_name, h.created_at
                FROM perk_badge_history h
                LEFT JOIN users u ON h.va_id = u.id
                ORDER BY h.created_at DESC
                LIMIT 80
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $history = [];
        }

        $role = (string) ($user['role'] ?? 'va');
        $uid = (string) ($user['id'] ?? '');
        if ($role === 'va' && $uid !== '') {
            $grants = array_values(array_filter(
                $grants,
                static fn(array $g) => (string) ($g['user_id'] ?? '') === $uid
            ));
            $awards = array_values(array_filter(
                $awards,
                static fn(array $a) => (string) ($a['user_id'] ?? '') === $uid
            ));
            $history = array_values(array_filter(
                $history,
                static fn(array $h) => (string) ($h['va_id'] ?? '') === $uid
            ));
        }

        return [
            'perks'      => $perks,
            'grants'     => $grants,
            'badges'     => $badges,
            'awards'     => $awards,
            'history'    => $history,
            'can_manage' => in_array($role, ['admin', 'team_leader', 'hrm'], true),
        ];
    }

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);
        self::ensurePerksSchema($this->db);

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/perks/{seg1}/{seg2}
        $seg1 = $parts[3] ?? null;
        $seg2 = $parts[4] ?? null;

        if ($method === 'GET' && !$seg1) {
            $this->listAll($user);
            return;
        }
        if ($method === 'POST' && !$seg1) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->createPerk($user);
            return;
        }
        if ($method === 'POST' && $seg1 && $seg1 !== 'badges' && $seg2 === 'grant') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->grantPerk($seg1, $user);
            return;
        }
        if ($method === 'DELETE' && $seg1 && $seg1 !== 'badges' && $seg2 === 'grant') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->revokePerk($seg1, $user);
            return;
        }
        if ($method === 'DELETE' && $seg1 && $seg1 !== 'badges' && !$seg2) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->deletePerk($seg1, $user);
            return;
        }
        if ($method === 'POST' && $seg1 === 'badges' && !$seg2) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->createBadge($user);
            return;
        }
        if ($method === 'POST' && $seg1 === 'badges' && $seg2 && ($parts[5] ?? '') === 'award') {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
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
        CacheService::invalidatePrefix($this->db, 'alerts:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
    }

    private function extractTargetUserIds(array $input): array
    {
        $ids = [];
        if (!empty($input['user_ids']) && is_array($input['user_ids'])) {
            foreach ($input['user_ids'] as $id) {
                $clean = SecurityHelper::sanitizePlainText((string) $id, 64);
                if ($clean !== '') {
                    $ids[] = $clean;
                }
            }
        }
        $single = SecurityHelper::sanitizePlainText((string) ($input['user_id'] ?? ''), 64);
        if ($single !== '') {
            $ids[] = $single;
        }
        return array_values(array_unique($ids));
    }

    /**
     * Resolve active VA recipients across both legacy users.role and user_roles table.
     *
     * @param string[] $userIds
     * @return array<int, array{id: string, name: string}>
     */
    private function resolveTargetVas(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        try {
            $stmt = $this->db->prepare("
                SELECT u.id, u.name
                FROM users u
                WHERE u.id IN ({$ph})
                  AND u.is_active = TRUE
                  AND (
                    u.role = 'va'
                    OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_slug = 'va')
                  )
                ORDER BY u.name ASC
            ");
            $stmt->execute($userIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $stmt = $this->db->prepare("
                SELECT id, name
                FROM users
                WHERE id IN ({$ph})
                  AND is_active = TRUE
                  AND role = 'va'
                ORDER BY name ASC
            ");
            $stmt->execute($userIds);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    private function recordHistory(
        string $eventType,
        string $itemType,
        string $itemId,
        string $itemTitle,
        ?string $itemMeta,
        ?string $vaId,
        ?string $vaName,
        array $actor
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO perk_badge_history (
                    id, event_type, item_type, item_id, item_title, item_meta,
                    va_id, va_name, actor_id, actor_name, created_at
                ) VALUES (
                    :id, :etype, :itype, :iid, :ititle, :imeta,
                    :vid, :vname, :aid, :aname, NOW()
                )
            ");
            $stmt->execute([
                ':id'     => 'pbh-' . bin2hex(random_bytes(6)),
                ':etype'  => $eventType,
                ':itype'  => $itemType,
                ':iid'    => $itemId,
                ':ititle' => mb_substr($itemTitle, 0, 191, 'UTF-8'),
                ':imeta'  => $itemMeta !== null ? mb_substr($itemMeta, 0, 128, 'UTF-8') : null,
                ':vid'    => $vaId,
                ':vname'  => $vaName !== null ? mb_substr($vaName, 0, 191, 'UTF-8') : null,
                ':aid'    => $actor['id'] ?? null,
                ':aname'  => mb_substr((string) ($actor['name'] ?? 'Staff'), 0, 191, 'UTF-8'),
            ]);
        } catch (Throwable) {
        }
    }

    private function listAll(array $user): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode(self::fetchBoardPayload($this->db, $user));
    }

    private function createPerk(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 191);
        $category = SecurityHelper::sanitizePlainText($input['category'] ?? 'Equipment & Hardware', 64);
        $icon = in_array($input['icon_name'] ?? '', self::VALID_ICONS, true) ? (string) $input['icon_name'] : 'Award';
        $value = max(0.0, (float) ($input['value'] ?? 0));
        $description = SecurityHelper::sanitizePlainText($input['description'] ?? '', 2000);

        if ($title === '' || $description === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title and benefit description are required']);
            return;
        }

        $id = 'perk-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO perks (id, title, category, icon_name, value, description, active)
            VALUES (:id, :title, :cat, :icon, :val, :desc, TRUE)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':title' => $title,
            ':cat'   => $category,
            ':icon'  => $icon,
            ':val'   => $value,
            ':desc'  => $description,
        ]);

        $this->recordHistory('perk_created', 'perk', $id, $title, $category, null, null, $user);
        $this->invalidate();
        AuditLogger::log('PERK_CREATED', 'success', "Created benefit {$title} ({$icon})", $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id'      => $id,
            'board'   => self::fetchBoardPayload($this->db, $user),
        ]);
    }

    private function deletePerk(string $perkId, array $user): void
    {
        $pStmt = $this->db->prepare('SELECT title, category FROM perks WHERE id = :id LIMIT 1');
        $pStmt->execute([':id' => $perkId]);
        $perkRow = $pStmt->fetch(PDO::FETCH_ASSOC);

        $this->db->prepare('DELETE FROM perks WHERE id = :id')->execute([':id' => $perkId]);
        if ($perkRow) {
            $this->recordHistory('perk_deleted', 'perk', $perkId, (string) $perkRow['title'], (string) ($perkRow['category'] ?? ''), null, null, $user);
        }

        $this->invalidate();
        AuditLogger::log('PERK_DELETED', 'warning', "Deleted perk {$perkId}", $user['id'], $user['role'], $this->db);
        echo json_encode([
            'success' => true,
            'id'      => $perkId,
            'board'   => self::fetchBoardPayload($this->db, $user),
        ]);
    }

    private function grantPerk(string $perkId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $syncMode = !empty($input['sync_recipients']);
        $userIds = $this->extractTargetUserIds($input);
        if (!$syncMode && empty($userIds)) {
            http_response_code(422);
            echo json_encode(['error' => 'Select at least one VA recipient']);
            return;
        }

        $pStmt = $this->db->prepare('SELECT id, title, category FROM perks WHERE id = :id LIMIT 1');
        $pStmt->execute([':id' => $perkId]);
        $perkRow = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$perkRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Selected perk program does not exist']);
            return;
        }
        $perkTitle = (string) $perkRow['title'];
        $perkCategory = (string) ($perkRow['category'] ?? 'Benefit');

        $targets = !empty($userIds) ? $this->resolveTargetVas($userIds) : [];
        if (!$syncMode && empty($targets)) {
            http_response_code(422);
            echo json_encode(['error' => 'No active Virtual Assistants matched the selected recipients']);
            return;
        }

        $targetMap = [];
        foreach ($targets as $t) {
            $targetMap[(string) $t['id']] = (string) $t['name'];
        }

        $newlyGranted = [];
        $revokedList = [];

        try {
            $this->db->beginTransaction();

            $curStmt = $this->db->prepare("
                SELECT up.user_id, u.name
                FROM user_perks up
                JOIN users u ON up.user_id = u.id
                WHERE up.perk_id = :pid
            ");
            $curStmt->execute([':pid' => $perkId]);
            $currentMap = [];
            foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $currentMap[(string) $cr['user_id']] = (string) $cr['name'];
            }

            $insStmt = $this->db->prepare('INSERT INTO user_perks (perk_id, user_id, granted_at) VALUES (:pid, :uid, NOW())');
            $updStmt = $this->db->prepare('UPDATE user_perks SET granted_at = NOW() WHERE perk_id = :pid AND user_id = :uid');
            $delStmt = $this->db->prepare('DELETE FROM user_perks WHERE perk_id = :pid AND user_id = :uid');

            foreach ($targets as $va) {
                $vid = (string) $va['id'];
                if (isset($currentMap[$vid])) {
                    if (!$syncMode) {
                        $updStmt->execute([':pid' => $perkId, ':uid' => $vid]);
                    }
                    continue;
                }
                $insStmt->execute([':pid' => $perkId, ':uid' => $vid]);
                $newlyGranted[] = $va;
                $this->recordHistory(
                    'perk_granted',
                    'perk',
                    $perkId,
                    $perkTitle,
                    $perkCategory,
                    $vid,
                    (string) $va['name'],
                    $user
                );
            }

            if ($syncMode) {
                foreach ($currentMap as $curUid => $curName) {
                    if (!isset($targetMap[$curUid])) {
                        $delStmt->execute([':pid' => $perkId, ':uid' => $curUid]);
                        $revokedList[] = ['id' => $curUid, 'name' => $curName];
                        $this->recordHistory(
                            'perk_revoked',
                            'perk',
                            $perkId,
                            $perkTitle,
                            $perkCategory,
                            $curUid,
                            $curName,
                            $user
                        );
                    }
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Unable to persist perk assignment']);
            return;
        }

        foreach ($newlyGranted as $va) {
            try {
                NotificationApiController::notifyUser(
                    $this->db,
                    (string) $va['id'],
                    'perk',
                    "Perk Unlocked: {$perkTitle}",
                    "Granted to your profile by {$user['name']}",
                    '/profile',
                    $perkId
                );
            } catch (Throwable) {
            }
        }

        $this->invalidate();
        AuditLogger::log(
            'PERK_GRANTED',
            'success',
            "Synced perk {$perkTitle} ({$perkId}): +" . count($newlyGranted) . ' granted, -' . count($revokedList) . ' revoked',
            $user['id'],
            $user['role'],
            $this->db
        );

        echo json_encode([
            'success'        => true,
            'count'          => count($targets),
            'granted_count'  => count($newlyGranted),
            'revoked_count'  => count($revokedList),
            'item_title'     => $perkTitle,
            'assigned_names' => array_column($targets, 'name'),
            'board'          => self::fetchBoardPayload($this->db, $user),
        ]);
    }

    private function revokePerk(string $perkId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $vaId = SecurityHelper::sanitizePlainText((string) ($input['user_id'] ?? ($_GET['user_id'] ?? '')), 64);
        if ($vaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Target user_id is required']);
            return;
        }

        $pStmt = $this->db->prepare('SELECT title, category FROM perks WHERE id = :id LIMIT 1');
        $pStmt->execute([':id' => $perkId]);
        $perkRow = $pStmt->fetch(PDO::FETCH_ASSOC);

        $uStmt = $this->db->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $uStmt->execute([':id' => $vaId]);
        $vaName = (string) ($uStmt->fetchColumn() ?: $vaId);

        $stmt = $this->db->prepare('DELETE FROM user_perks WHERE perk_id = :pid AND user_id = :uid');
        $stmt->execute([':pid' => $perkId, ':uid' => $vaId]);

        $this->recordHistory(
            'perk_revoked',
            'perk',
            $perkId,
            (string) ($perkRow['title'] ?? $perkId),
            (string) ($perkRow['category'] ?? 'Revoked'),
            $vaId,
            $vaName,
            $user
        );

        $this->invalidate();
        AuditLogger::log('PERK_REVOKED', 'warning', "Revoked perk {$perkId} from {$vaId}", $user['id'], $user['role'], $this->db);
        echo json_encode([
            'success' => true,
            'board'   => self::fetchBoardPayload($this->db, $user),
        ]);
    }

    private function createBadge(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 191);
        $description = SecurityHelper::sanitizePlainText($input['description'] ?? '', 1000);
        $tier = in_array($input['tier'] ?? '', ['bronze', 'silver', 'gold', 'diamond'], true) ? $input['tier'] : 'silver';
        $icon = in_array($input['icon_name'] ?? '', self::VALID_ICONS, true) ? (string) $input['icon_name'] : 'Medal';
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
            VALUES (:id, :title, :desc, :icon, :tier, :trig, :thresh)
        ");
        $stmt->execute([
            ':id'     => $id,
            ':title'  => $title,
            ':desc'   => $description,
            ':icon'   => $icon,
            ':tier'   => $tier,
            ':trig'   => $trigger,
            ':thresh' => $threshold,
        ]);

        $this->recordHistory('badge_created', 'badge', $id, $title, ucfirst($tier) . ' Tier', null, null, $user);
        $this->invalidate();
        AuditLogger::log('BADGE_CREATED', 'success', "Created badge {$title} ({$tier}, {$icon})", $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id'      => $id,
            'board'   => self::fetchBoardPayload($this->db, $user),
        ]);
    }

    private function awardBadge(string $badgeId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $syncMode = !empty($input['sync_recipients']);
        $userIds = $this->extractTargetUserIds($input);
        if (!$syncMode && empty($userIds)) {
            http_response_code(422);
            echo json_encode(['error' => 'Select at least one VA recipient']);
            return;
        }

        $bStmt = $this->db->prepare('SELECT id, title, tier FROM badges WHERE id = :id LIMIT 1');
        $bStmt->execute([':id' => $badgeId]);
        $badgeRow = $bStmt->fetch(PDO::FETCH_ASSOC);
        if (!$badgeRow) {
            http_response_code(404);
            echo json_encode(['error' => 'Selected recognition badge does not exist']);
            return;
        }
        $badgeTitle = (string) $badgeRow['title'];
        $badgeTier = ucfirst(strtolower((string) ($badgeRow['tier'] ?? 'Silver'))) . ' Tier';

        $targets = !empty($userIds) ? $this->resolveTargetVas($userIds) : [];
        if (!$syncMode && empty($targets)) {
            http_response_code(422);
            echo json_encode(['error' => 'No active Virtual Assistants matched the selected recipients']);
            return;
        }

        $targetMap = [];
        foreach ($targets as $t) {
            $targetMap[(string) $t['id']] = (string) $t['name'];
        }

        $newlyAwarded = [];
        $revokedCount = 0;

        try {
            $this->db->beginTransaction();

            $curStmt = $this->db->prepare("
                SELECT ub.user_id, u.name
                FROM user_badges ub
                JOIN users u ON ub.user_id = u.id
                WHERE ub.badge_id = :bid
            ");
            $curStmt->execute([':bid' => $badgeId]);
            $currentMap = [];
            foreach ($curStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $currentMap[(string) $cr['user_id']] = (string) $cr['name'];
            }

            $insStmt = $this->db->prepare("
                INSERT INTO user_badges (id, user_id, badge_id, earned_at)
                VALUES (:id, :uid, :bid, CURRENT_DATE)
            ");
            $delStmt = $this->db->prepare('DELETE FROM user_badges WHERE badge_id = :bid AND user_id = :uid');

            foreach ($targets as $va) {
                $vid = (string) $va['id'];
                if (isset($currentMap[$vid])) {
                    continue;
                }
                $insStmt->execute([
                    ':id'  => 'ubdg-' . bin2hex(random_bytes(5)),
                    ':uid' => $vid,
                    ':bid' => $badgeId,
                ]);
                $newlyAwarded[] = $va;
                $this->recordHistory(
                    'badge_awarded',
                    'badge',
                    $badgeId,
                    $badgeTitle,
                    $badgeTier,
                    $vid,
                    (string) $va['name'],
                    $user
                );
            }

            if ($syncMode) {
                foreach ($currentMap as $curUid => $curName) {
                    if (!isset($targetMap[$curUid])) {
                        $delStmt->execute([':bid' => $badgeId, ':uid' => $curUid]);
                        $revokedCount++;
                    }
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Unable to persist badge award']);
            return;
        }

        foreach ($newlyAwarded as $va) {
            try {
                NotificationApiController::notifyUser(
                    $this->db,
                    (string) $va['id'],
                    'perk',
                    "Badge Earned: {$badgeTitle}",
                    "Awarded by {$user['name']}",
                    '/profile',
                    $badgeId
                );
            } catch (Throwable) {
            }
        }

        $this->invalidate();
        AuditLogger::log(
            'BADGE_AWARDED',
            'success',
            "Synced badge {$badgeTitle} ({$badgeId}): +" . count($newlyAwarded) . ' awarded, -' . $revokedCount . ' removed',
            $user['id'],
            $user['role'],
            $this->db
        );

        echo json_encode([
            'success'        => true,
            'count'          => count($targets),
            'granted_count'  => count($newlyAwarded),
            'revoked_count'  => $revokedCount,
            'item_title'     => $badgeTitle,
            'assigned_names' => array_column($targets, 'name'),
            'board'          => self::fetchBoardPayload($this->db, $user),
        ]);
    }
}
