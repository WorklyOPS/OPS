<?php
/**
 * Workly Meetings & Calendar API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class MeetingApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        if ($method === 'POST') {
            $this->createMeeting($user);
            return;
        }

        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

    private function createMeeting(array $user): void
    {
        AuthMiddleware::requireRoles(['team_leader', 'admin'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        $vaId = SecurityHelper::sanitizePlainText($input['va_id'] ?? '', 64);
        $includeClient = !empty($input['include_client']);
        $clientId = $includeClient && !empty($input['client_id'])
            ? SecurityHelper::sanitizePlainText($input['client_id'], 64)
            : null;
        $start = SecurityHelper::sanitizePlainText($input['start_time'] ?? '', 64);
        $end = SecurityHelper::sanitizePlainText($input['end_time'] ?? '', 64);
        $type = in_array($input['meeting_type'] ?? '', ['client_meeting', 'team_sync', 'checkpoint', 'review'], true)
            ? $input['meeting_type']
            : ($includeClient ? 'client_meeting' : 'team_sync');
        $link = SecurityHelper::sanitizeUrl($input['meeting_link'] ?? '') ?: ('https://meet.google.com/wrk-' . bin2hex(random_bytes(4)));

        if ($title === '' || $vaId === '' || $start === '' || $end === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title, assigned VA, start time, and end time are required']);
            return;
        }

        $vaStmt = $this->db->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $vaStmt->execute([':id' => $vaId]);
        $va = $vaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$va) {
            http_response_code(404);
            echo json_encode(['error' => 'Assigned VA not found']);
            return;
        }

        $meetingId = 'meet-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO calendar_meetings (id, title, va_id, client_id, include_client, start_time, end_time, meeting_type, meeting_link, created_by_tl_id, google_synced)
            VALUES (:id, :title, :va, :cid, :inc, :start_t, :end_t, :type, :link, :tl, TRUE)
        ");
        $stmt->execute([
            ':id'      => $meetingId,
            ':title'   => $title,
            ':va'      => $vaId,
            ':cid'     => $clientId,
            ':inc'     => $includeClient ? 'true' : 'false',
            ':start_t' => $start,
            ':end_t'   => $end,
            ':type'    => $type,
            ':link'    => $link,
            ':tl'      => $user['id'],
        ]);

        // Also record calendar invitation notification inside threaded email_messages in PostgreSQL
        $threadId = 'th-' . bin2hex(random_bytes(5));
        $eStmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, subject, body, folder, is_read, client_ref_id)
            VALUES (:id, :tid, :sname, :semail, :remail, :subject, :body, 'sent', FALSE, :cid)
        ");
        $eStmt->execute([
            ':id'      => 'msg-' . bin2hex(random_bytes(5)),
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $user['email'],
            ':remail'  => $va['email'],
            ':subject' => "[Calendar Invite] {$title}",
            ':body'    => "You are invited to '{$title}'.\nStart: {$start}\nEnd: {$end}\nMeeting Link: {$link}",
            ':cid'     => $clientId,
        ]);

        CacheService::invalidatePrefix($this->db, 'meetings:');
        CacheService::invalidatePrefix($this->db, 'emails:');
        AuditLogger::log('MEETING_SCHEDULED', 'success', "Scheduled meeting {$meetingId} for {$va['email']}", $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $meetingId, 'meeting_link' => $link]);
    }
}
