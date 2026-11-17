<?php
/**
 * Workly Header Notifications Center API Controller
 * Strictly user-scoped alerts: direct mentions, task comments/notes on own tasks,
 * direct messages, PTO status transitions, and earned perks/badges.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class NotificationApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);
        self::ensureNotificationsSchema($this->db);

        $parts = explode('/', trim($uri, '/'));
        $sub = $parts[3] ?? '';

        if ($method === 'GET' && $sub === '') {
            echo json_encode(['data' => self::fetchForUser($this->db, $user)]);
            return;
        }

        if (($method === 'PATCH' || $method === 'POST') && $sub === 'read') {
            $this->markAllRead($user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Notifications endpoint not found']);
    }

    public static function ensureNotificationsSchema(PDO $db): void
    {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS user_notifications (
                    id VARCHAR(64) PRIMARY KEY,
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    category VARCHAR(32) NOT NULL DEFAULT 'alert',
                    title VARCHAR(255) NOT NULL,
                    subtitle TEXT DEFAULT NULL,
                    href VARCHAR(512) NOT NULL DEFAULT '/',
                    related_id VARCHAR(64) DEFAULT NULL,
                    is_read BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    read_at TIMESTAMPTZ DEFAULT NULL
                )
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS notification_reads (
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    notif_key VARCHAR(128) NOT NULL,
                    read_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (user_id, notif_key)
                )
            ");
        } catch (Throwable) {
        }
    }

    public static function notifyUser(
        PDO $db,
        string $userId,
        string $category,
        string $title,
        string $subtitle,
        string $href,
        ?string $relatedId = null
    ): void {
        if (trim($userId) === '') {
            return;
        }
        self::ensureNotificationsSchema($db);
        $validCat = in_array($category, ['message', 'pto', 'comment', 'perk', 'task', 'alert'], true) ? $category : 'alert';
        try {
            $stmt = $db->prepare("
                INSERT INTO user_notifications (id, user_id, category, title, subtitle, href, related_id, is_read)
                VALUES (:id, :uid, :cat, :title, :sub, :href, :rel, FALSE)
            ");
            $stmt->execute([
                ':id'    => 'ntf-' . bin2hex(random_bytes(5)),
                ':uid'   => $userId,
                ':cat'   => $validCat,
                ':title' => mb_substr($title, 0, 250, 'UTF-8'),
                ':sub'   => mb_substr($subtitle, 0, 400, 'UTF-8'),
                ':href'  => $href,
                ':rel'   => $relatedId,
            ]);
            CacheService::invalidatePrefix($db, 'alerts:');
        } catch (Throwable) {
        }
    }

    public static function fetchForUser(PDO $db, array $user): array
    {
        self::ensureNotificationsSchema($db);
        $uid = (string) $user['id'];
        $role = (string) $user['role'];
        $email = (string) $user['email'];
        $name = trim((string) ($user['name'] ?? ''));
        $firstName = trim(explode(' ', $name)[0] ?? '');

        $items = [];
        $coveredRelatedIds = [];

        $readMap = [];
        try {
            $rStmt = $db->prepare('SELECT notif_key FROM notification_reads WHERE user_id = :uid');
            $rStmt->execute([':uid' => $uid]);
            foreach ($rStmt->fetchAll(PDO::FETCH_COLUMN) as $rk) {
                $readMap[(string) $rk] = true;
            }
        } catch (Throwable) {
        }

        // 0. Direct user-scoped persistent notifications
        try {
            $uStmt = $db->prepare("
                SELECT id, category, title, subtitle, href, related_id, is_read, created_at
                FROM user_notifications
                WHERE user_id = :uid
                ORDER BY is_read ASC, created_at DESC
                LIMIT 12
            ");
            $uStmt->execute([':uid' => $uid]);
            foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $nid = (string) $row['id'];
                if (!empty($row['related_id'])) {
                    $coveredRelatedIds[(string) $row['related_id']] = true;
                }
                $items[] = [
                    'id'         => $nid,
                    'category'   => (string) $row['category'],
                    'title'      => (string) $row['title'],
                    'subtitle'   => (string) ($row['subtitle'] ?? ''),
                    'href'       => (string) $row['href'],
                    'created_at' => (string) $row['created_at'],
                    'unread'     => !$row['is_read'] && !isset($readMap[$nid]),
                ];
            }
        } catch (Throwable) {
        }

        // 1. Direct incoming emails / messages addressed to this user
        try {
            $eStmt = $db->prepare("
                SELECT id, thread_id, sender_name, subject, created_at
                FROM email_messages
                WHERE is_read = FALSE
                  AND (LOWER(recipient_email) = LOWER(:em) OR LOWER(COALESCE(cc_email, '')) LIKE LOWER(:cc))
                ORDER BY created_at DESC
                LIMIT 6
            ");
            $eStmt->execute([':em' => $email, ':cc' => '%' . $email . '%']);
            foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $nid = 'mail-' . $row['id'];
                $items[] = [
                    'id'         => $nid,
                    'category'   => 'message',
                    'title'      => 'Message from ' . $row['sender_name'],
                    'subtitle'   => (string) $row['subject'],
                    'href'       => '/email?thread=' . rawurlencode((string) $row['thread_id']),
                    'created_at' => (string) $row['created_at'],
                    'unread'     => !isset($readMap[$nid]),
                ];
            }
        } catch (Throwable) {
        }

        // 2. Role-scoped PTO alerts (strictly belonging to this TL, VA, or HRM reviewer)
        try {
            if ($role === 'team_leader') {
                $tStmt = $db->prepare("
                    SELECT id, title, message, event_type, is_read, created_at
                    FROM team_lead_notifications
                    WHERE team_leader_id = :tl
                    ORDER BY is_read ASC, created_at DESC
                    LIMIT 6
                ");
                $tStmt->execute([':tl' => $uid]);
                foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $nid = 'pto-tl-' . $row['id'];
                    $items[] = [
                        'id'         => $nid,
                        'category'   => 'pto',
                        'title'      => (string) $row['title'],
                        'subtitle'   => mb_substr((string) $row['message'], 0, 95, 'UTF-8'),
                        'href'       => '/pto',
                        'created_at' => (string) $row['created_at'],
                        'unread'     => !$row['is_read'] && !isset($readMap[$nid]),
                    ];
                }
            }
            if ($role === 'va') {
                $pStmt = $db->prepare("
                    SELECT id, start_date, end_date, status, COALESCE(reviewed_at, requested_at) AS ev_at
                    FROM pto_requests
                    WHERE va_id = :va
                    ORDER BY ev_at DESC
                    LIMIT 4
                ");
                $pStmt->execute([':va' => $uid]);
                foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $nid = 'pto-va-' . $row['id'];
                    if (isset($coveredRelatedIds[$row['id']])) {
                        continue;
                    }
                    $stLabel = ucfirst((string) $row['status']);
                    $items[] = [
                        'id'         => $nid,
                        'category'   => 'pto',
                        'title'      => "PTO {$stLabel}: {$row['start_date']}",
                        'subtitle'   => "Dates: {$row['start_date']} to {$row['end_date']}",
                        'href'       => '/pto',
                        'created_at' => (string) $row['ev_at'],
                        'unread'     => $row['status'] !== 'pending' && !isset($readMap[$nid]),
                    ];
                }
            } elseif ($role === 'hrm') {
                $hStmt = $db->query("
                    SELECT id, va_name, start_date, end_date, requested_at
                    FROM pto_requests
                    WHERE status = 'pending'
                    ORDER BY requested_at DESC
                    LIMIT 5
                ");
                foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $nid = 'pto-hrm-' . $row['id'];
                    $items[] = [
                        'id'         => $nid,
                        'category'   => 'pto',
                        'title'      => 'Pending PTO: ' . $row['va_name'],
                        'subtitle'   => "{$row['start_date']} to {$row['end_date']}",
                        'href'       => '/pto',
                        'created_at' => (string) $row['requested_at'],
                        'unread'     => !isset($readMap[$nid]),
                    ];
                }
            }
        } catch (Throwable) {
        }

        // 3. Strictly related or @mentioned task comments (never unrelated users)
        try {
            $mFull = $name !== '' ? '%@' . $name . '%' : '__never_match__';
            $mFirst = strlen($firstName) >= 2 ? '%@' . $firstName . '%' : '__never_match__';

            $cStmt = $db->prepare("
                SELECT c.id, c.task_id, c.author_name, c.author_role, c.message, c.created_at, t.title AS task_title
                FROM task_comments c
                JOIN tasks t ON c.task_id = t.id
                LEFT JOIN users u ON t.assigned_va_id = u.id
                WHERE c.author_id <> :uid
                  AND (
                      t.assigned_va_id = :uid2
                      OR t.created_by_id = :uid3
                      OR u.team_leader_id = :uid4
                      OR LOWER(c.message) LIKE LOWER(:m_full)
                      OR LOWER(c.message) LIKE LOWER(:m_first)
                  )
                ORDER BY c.created_at DESC
                LIMIT 6
            ");
            $cStmt->execute([
                ':uid'     => $uid,
                ':uid2'    => $uid,
                ':uid3'    => $uid,
                ':uid4'    => $uid,
                ':m_full'  => $mFull,
                ':m_first' => $mFirst,
            ]);
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($coveredRelatedIds[$row['id']])) {
                    continue;
                }
                $nid = 'cmt-' . $row['id'];
                $items[] = [
                    'id'         => $nid,
                    'category'   => 'comment',
                    'title'      => $row['author_name'] . ' commented on "' . mb_substr((string) $row['task_title'], 0, 32, 'UTF-8') . '"',
                    'subtitle'   => mb_substr((string) $row['message'], 0, 85, 'UTF-8'),
                    'href'       => '/tasks?open_task=' . rawurlencode((string) $row['task_id']) . '&tab=combined',
                    'created_at' => (string) $row['created_at'],
                    'unread'     => (strtotime((string) $row['created_at']) >= (time() - 604800)) && !isset($readMap[$nid]),
                ];
            }
        } catch (Throwable) {
        }

        // 4. User's own earned perks & badges
        try {
            $bStmt = $db->prepare("
                SELECT ub.id, ub.badge_id, b.title, b.tier, ub.created_at
                FROM user_badges ub
                JOIN badges b ON ub.badge_id = b.id
                WHERE ub.user_id = :uid
                ORDER BY ub.created_at DESC
                LIMIT 4
            ");
            $bStmt->execute([':uid' => $uid]);
            foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($coveredRelatedIds[$row['badge_id']])) {
                    continue;
                }
                $nid = 'bdg-' . $row['id'];
                $items[] = [
                    'id'         => $nid,
                    'category'   => 'perk',
                    'title'      => 'Badge Earned: ' . $row['title'],
                    'subtitle'   => ucfirst((string) $row['tier']) . ' tier achievement',
                    'href'       => '/profile',
                    'created_at' => (string) $row['created_at'],
                    'unread'     => (strtotime((string) $row['created_at']) >= (time() - 604800)) && !isset($readMap[$nid]),
                ];
            }

            $pkStmt = $db->prepare("
                SELECT up.id, up.perk_id, p.title, p.category, up.granted_at
                FROM user_perks up
                JOIN perks p ON up.perk_id = p.id
                WHERE up.user_id = :uid
                ORDER BY up.granted_at DESC
                LIMIT 4
            ");
            $pkStmt->execute([':uid' => $uid]);
            foreach ($pkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($coveredRelatedIds[$row['perk_id']])) {
                    continue;
                }
                $nid = 'prk-' . $row['id'];
                $items[] = [
                    'id'         => $nid,
                    'category'   => 'perk',
                    'title'      => 'Perk Unlocked: ' . $row['title'],
                    'subtitle'   => (string) $row['category'],
                    'href'       => '/profile',
                    'created_at' => (string) $row['granted_at'],
                    'unread'     => (strtotime((string) $row['granted_at']) >= (time() - 604800)) && !isset($readMap[$nid]),
                ];
            }
        } catch (Throwable) {
        }

        usort($items, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return array_slice($items, 0, 15);
    }

    private function markAllRead(array $user): void
    {
        $uid = (string) $user['id'];
        $current = self::fetchForUser($this->db, $user);

        try {
            $uStmt = $this->db->prepare("UPDATE user_notifications SET is_read = TRUE, read_at = NOW() WHERE user_id = :uid AND is_read = FALSE");
            $uStmt->execute([':uid' => $uid]);
        } catch (Throwable) {
        }
        try {
            $stmt = $this->db->prepare("UPDATE team_lead_notifications SET is_read = TRUE, read_at = NOW() WHERE team_leader_id = :tl AND is_read = FALSE");
            $stmt->execute([':tl' => $uid]);
        } catch (Throwable) {
        }
        try {
            $eStmt = $this->db->prepare("UPDATE email_messages SET is_read = TRUE WHERE LOWER(recipient_email) = LOWER(:em) AND is_read = FALSE");
            $eStmt->execute([':em' => $user['email']]);
        } catch (Throwable) {
        }

        try {
            $ins = $this->db->prepare("INSERT INTO notification_reads (user_id, notif_key) VALUES (:uid, :key) ON CONFLICT (user_id, notif_key) DO NOTHING");
            foreach ($current as $item) {
                $ins->execute([':uid' => $uid, ':key' => (string) $item['id']]);
            }
        } catch (Throwable) {
        }

        CacheService::invalidatePrefix($this->db, 'alerts:');
        echo json_encode(['success' => true]);
    }
}
