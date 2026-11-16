<?php
/**
 * Workly Threaded Email & Gmail Linkage REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class EmailApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        $sub = $parts[3] ?? '';

        if ($method === 'POST' && $sub === 'reply') {
            $this->replyInThread($user);
            return;
        }
        if ($method === 'POST' && $sub === 'compose') {
            $this->compose($user);
            return;
        }
        if ($method === 'POST' && $sub === 'admin-link-gmail') {
            AuthMiddleware::requireRoles(['admin'], $user);
            $this->linkGmail($user);
            return;
        }
        if ($method === 'POST' && $sub === 'sync-gmail') {
            $this->syncMailbox($user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Email API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'emails:');
        CacheService::invalidatePrefix($this->db, 'users:');
    }

    private function replyInThread(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $threadId = SecurityHelper::sanitizePlainText($input['thread_id'] ?? '', 64);
        $recipient = filter_var($input['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $cc = filter_var($input['cc_email'] ?? '', FILTER_SANITIZE_EMAIL);
        $subject = SecurityHelper::sanitizePlainText($input['subject'] ?? 'Conversation Thread', 255);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 8000);
        $clientRef = SecurityHelper::sanitizePlainText($input['client_ref_id'] ?? '', 64) ?: null;

        if ($threadId === '' || $recipient === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'thread_id, recipient_email, and body are required']);
            return;
        }

        if (stripos($subject, 'Re:') !== 0) {
            $subject = 'Re: ' . $subject;
        }

        $id = 'msg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, folder, is_read, client_ref_id)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, 'sent', TRUE, :cid)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $user['email'],
            ':remail'  => $recipient,
            ':cc'      => $cc !== '' ? $cc : null,
            ':subject' => $subject,
            ':body'    => $body,
            ':cid'     => $clientRef,
        ]);

        $this->invalidate();
        AuditLogger::log('EMAIL_THREAD_REPLY', 'success', "Replied in thread {$threadId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $id, 'thread_id' => $threadId]);
    }

    private function compose(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $recipient = filter_var($input['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $cc = filter_var($input['cc_email'] ?? '', FILTER_SANITIZE_EMAIL);
        $subject = SecurityHelper::sanitizePlainText($input['subject'] ?? '', 255);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 8000);
        $clientRef = SecurityHelper::sanitizePlainText($input['client_ref_id'] ?? '', 64) ?: null;

        if ($recipient === '' || $subject === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Recipient email, subject, and body are required']);
            return;
        }

        $threadId = 'th-' . bin2hex(random_bytes(5));
        $id = 'msg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, folder, is_read, client_ref_id)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, 'sent', TRUE, :cid)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $user['email'],
            ':remail'  => $recipient,
            ':cc'      => $cc !== '' ? $cc : null,
            ':subject' => $subject,
            ':body'    => $body,
            ':cid'     => $clientRef,
        ]);

        $this->invalidate();
        AuditLogger::log('EMAIL_CREATED', 'success', "Started thread {$threadId} to {$recipient}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id, 'thread_id' => $threadId]);
    }

    private function linkGmail(array $admin): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $userId = SecurityHelper::sanitizePlainText($input['user_id'] ?? '', 64);
        $gmailEmail = strtolower(filter_var($input['gmail_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '');
        $provider = in_array($input['provider'] ?? '', ['gmail', 'google_workspace', 'outlook'], true) ? $input['provider'] : 'gmail';
        $status = in_array($input['status'] ?? '', ['active', 'needs_auth', 'syncing'], true) ? $input['status'] : 'active';

        if ($userId === '' || $gmailEmail === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Staff user_id and valid email are required']);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO staff_connected_mailboxes (user_id, provider, email, oauth_status, sync_interval_minutes, oauth_scope, last_synced_at, updated_at)
            VALUES (:uid, :prov, :email, :status, 5, 'https://mail.google.com/ https://www.googleapis.com/auth/calendar', NOW(), NOW())
            ON CONFLICT (user_id) DO UPDATE
            SET provider = EXCLUDED.provider,
                email = EXCLUDED.email,
                oauth_status = EXCLUDED.oauth_status,
                last_synced_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':prov'   => $provider,
            ':email'  => $gmailEmail,
            ':status' => $status,
        ]);

        $this->invalidate();
        AuditLogger::log('GMAIL_MAILBOX_LINKED', 'success', "Admin linked {$gmailEmail} to {$userId}", $admin['id'], $admin['role'], $this->db);
        echo json_encode(['success' => true]);
    }

    private function syncMailbox(array $user): void
    {
        $stmt = $this->db->prepare('UPDATE staff_connected_mailboxes SET last_synced_at = NOW(), updated_at = NOW() WHERE user_id = :uid');
        $stmt->execute([':uid' => $user['id']]);
        $this->invalidate();
        echo json_encode(['success' => true, 'synced_at' => gmdate('c')]);
    }
}
