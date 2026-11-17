<?php
/**
 * Workly Header Notifications Center API Controller (PHP 8 + PostgreSQL)
 * Aggregates incoming messages, PTO alerts, task comments, and earned perks/badges.
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

    public static function fetchForUser(PDO $db, array $user): array
    {
        $uid = (string) $user['id'];
        $role = (string) $user['role'];
        $email = (string) $user['email'];
        $items = [];

        // 1. Unread incoming emails / messages
        try {
            $eStmt = $db->prepare("
                SELECT id, thread_id, sender_name, subject, created_at
                FROM email_messages
                WHERE is_read = FALSE AND LOWER(recipient_email) = LOWER(:em)
                ORDER BY created_at DESC
                LIMIT 6
            ");
            $eStmt->execute([':em' => $email]);
            foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'id'         => 'mail-' . $row['id'],
                    'category'   => 'message',
                    'title'      => 'Message from ' . $row['sender_name'],
                    'subtitle'   => (string) $row['subject'],
                    'href'       => '/email?thread=' . rawurlencode((string) $row['thread_id']),
                    'created_at' => (string) $row['created_at'],
                    'unread'     => true,
                ];
            }
        } catch (Throwable) {
        }

        // 2. PTO alerts (unread TL notifications + recent VA status changes + pending HRM queue)
        try {
            if ($role === 'team_leader' || $role === 'admin') {
                $tlSql = $role === 'team_leader'
                    ? "SELECT id, title, message, event_type, is_read, created_at FROM team_lead_notifications WHERE team_leader_id = :tl ORDER BY is_read ASC, created_at DESC LIMIT 6"
                    : "SELECT id, title, message, event_type, is_read, created_at FROM team_lead_notifications ORDER BY is_read ASC, created_at DESC LIMIT 6";
                $tStmt = $db->prepare($tlSql);
                $tStmt->execute($role === 'team_leader' ? [':tl' => $uid] : []);
                foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $items[] = [
                        'id'         => 'pto-tl-' . $row['id'],
                        'category'   => 'pto',
                        'title'      => (string) $row['title'],
                        'subtitle'   => mb_substr((string) $row['message'], 0, 95, 'UTF-8'),
                        'href'       => '/pto',
                        'created_at' => (string) $row['created_at'],
                        'unread'     => !$row['is_read'],
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
                    $stLabel = ucfirst((string) $row['status']);
                    $items[] = [
                        'id'         => 'pto-va-' . $row['id'],
                        'category'   => 'pto',
                        'title'      => "PTO {$stLabel}: {$row['start_date']}",
                        'subtitle'   => "Dates: {$row['start_date']} to {$row['end_date']}",
                        'href'       => '/pto',
                        'created_at' => (string) $row['ev_at'],
                        'unread'     => $row['status'] !== 'pending',
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
                    $items[] = [
                        'id'         => 'pto-hrm-' . $row['id'],
                        'category'   => 'pto',
                        'title'      => 'Pending PTO: ' . $row['va_name'],
                        'subtitle'   => "{$row['start_date']} to {$row['end_date']}",
                        'href'       => '/pto',
                        'created_at' => (string) $row['requested_at'],
                        'unread'     => true,
                    ];
                }
            }
        } catch (Throwable) {
        }

        // 3. Incoming task comments (click opens the task Comments conversation directly)
        try {
            if ($role === 'va') {
                $cStmt = $db->prepare("
                    SELECT c.id, c.task_id, c.author_name, c.author_role, c.message, c.created_at, t.title AS task_title
                    FROM task_comments c
                    JOIN tasks t ON c.task_id = t.id
                    WHERE t.assigned_va_id = :va AND c.author_id <> :va2
                    ORDER BY c.created_at DESC
                    LIMIT 6
                ");
                $cStmt->execute([':va' => $uid, ':va2' => $uid]);
            } elseif ($role === 'team_leader') {
                $cStmt = $db->prepare("
                    SELECT c.id, c.task_id, c.author_name, c.author_role, c.message, c.created_at, t.title AS task_title
                    FROM task_comments c
                    JOIN tasks t ON c.task_id = t.id
                    JOIN users u ON t.assigned_va_id = u.id
                    WHERE (u.team_leader_id = :tl OR t.created_by_id = :tl2) AND c.author_id <> :tl3
                    ORDER BY c.created_at DESC
                    LIMIT 6
                ");
                $cStmt->execute([':tl' => $uid, ':tl2' => $uid, ':tl3' => $uid]);
            } else {
                $cStmt = $db->prepare("
                    SELECT c.id, c.task_id, c.author_name, c.author_role, c.message, c.created_at, t.title AS task_title
                    FROM task_comments c
                    JOIN tasks t ON c.task_id = t.id
                    WHERE c.author_id <> :uid
                    ORDER BY c.created_at DESC
                    LIMIT 6
                ");
                $cStmt->execute([':uid' => $uid]);
            }
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'id'         => 'cmt-' . $row['id'],
                    'category'   => 'comment',
                    'title'      => $row['author_name'] . ' commented on "' . mb_substr((string) $row['task_title'], 0, 32, 'UTF-8') . '"',
                    'subtitle'   => mb_substr((string) $row['message'], 0, 85, 'UTF-8'),
                    'href'       => '/tasks?open_task=' . rawurlencode((string) $row['task_id']) . '&tab=comments',
                    'created_at' => (string) $row['created_at'],
                    'unread'     => strtotime((string) $row['created_at']) >= (time() - 604800),
                ];
            }
        } catch (Throwable) {
        }

        // 4. Earned perks & badges
        try {
            $bStmt = $db->prepare("
                SELECT ub.id, b.title, b.tier, ub.created_at
                FROM user_badges ub
                JOIN badges b ON ub.badge_id = b.id
                WHERE ub.user_id = :uid
                ORDER BY ub.created_at DESC
                LIMIT 4
            ");
            $bStmt->execute([':uid' => $uid]);
            foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'id'         => 'bdg-' . $row['id'],
                    'category'   => 'perk',
                    'title'      => 'Badge Earned: ' . $row['title'],
                    'subtitle'   => ucfirst((string) $row['tier']) . ' tier achievement',
                    'href'       => '/profile',
                    'created_at' => (string) $row['created_at'],
                    'unread'     => strtotime((string) $row['created_at']) >= (time() - 604800),
                ];
            }

            $pkStmt = $db->prepare("
                SELECT up.id, p.title, p.category, up.granted_at
                FROM user_perks up
                JOIN perks p ON up.perk_id = p.id
                WHERE up.user_id = :uid
                ORDER BY up.granted_at DESC
                LIMIT 4
            ");
            $pkStmt->execute([':uid' => $uid]);
            foreach ($pkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'id'         => 'prk-' . $row['id'],
                    'category'   => 'perk',
                    'title'      => 'Perk Unlocked: ' . $row['title'],
                    'subtitle'   => (string) $row['category'],
                    'href'       => '/profile',
                    'created_at' => (string) $row['granted_at'],
                    'unread'     => strtotime((string) $row['granted_at']) >= (time() - 604800),
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
        CacheService::invalidatePrefix($this->db, 'alerts:');
        echo json_encode(['success' => true]);
    }
}
