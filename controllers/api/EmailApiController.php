<?php
/**
 * Workly Gmail OAuth Sync, CC & Attachments Email Hub REST API Controller
 * Native PHP 8 + PostgreSQL + Google Gmail REST API v1 via cURL
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../services/GmailOAuthService.php';

final class EmailApiController
{
    private const GMAIL_API_BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);
        $parts = explode('/', trim($uri, '/'));
        $this->ensureEmailSchema();
        $sub = $parts[3] ?? '';
        $arg = $parts[4] ?? '';

        if ($sub === 'google' && $arg === 'connect' && $method === 'GET') {
            $this->startGoogleOAuth($user);
            return;
        }
        if ($sub === 'google' && $arg === 'callback' && $method === 'GET') {
            $this->completeGoogleOAuth($user);
            return;
        }

        if ($method === 'GET' && $sub === 'status') {
            $this->mailboxStatus($user);
            return;
        }
        if ($method === 'POST' && $sub === 'disconnect') {
            $this->disconnectMailbox($user);
            return;
        }
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
        if ($method === 'GET' && $sub === 'attachments' && $arg !== '') {
            $this->downloadAttachment($arg, $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Email API route not found']);
    }

    private function ensureEmailSchema(): void
    {
        try {
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS gmail_message_id VARCHAR(128) DEFAULT NULL');
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS snippet TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS access_token_encrypted TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS refresh_token_encrypted TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMPTZ DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_type VARCHAR(32) DEFAULT NULL');
            $this->db->exec("CREATE TABLE IF NOT EXISTS gmail_oauth_states (state_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_attachments (
                    id VARCHAR(64) PRIMARY KEY,
                    email_id VARCHAR(64) NOT NULL REFERENCES email_messages(id) ON DELETE CASCADE,
                    filename VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
                    size_bytes INTEGER NOT NULL DEFAULT 0,
                    data_base64 TEXT DEFAULT NULL,
                    gmail_attachment_id VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
        } catch (Throwable) {
        }
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'emails:');
        CacheService::invalidatePrefix($this->db, 'users:');
        CacheService::invalidatePrefix($this->db, 'alerts:');
    }

    private function getGoogleAccessToken(array $user): string
    {
        $stmt = $this->db->prepare("
            SELECT access_token_encrypted, refresh_token_encrypted, token_expires_at
            FROM staff_connected_mailboxes WHERE user_id = :uid AND oauth_status = 'active' LIMIT 1
        " );
        $stmt->execute([':uid' => $user['id']]);
        $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mailbox) {
            return '';
        }

        try {
            $accessToken = GmailOAuthService::decrypt((string) ($mailbox['access_token_encrypted'] ?? ''));
            $refreshToken = GmailOAuthService::decrypt((string) ($mailbox['refresh_token_encrypted'] ?? ''));
        } catch (Throwable $e) {
            error_log('Workly Gmail token decryption failed: ' . $e->getMessage());
            $this->markMailboxNeedsAuth((string) $user['id']);
            return '';
        }
        $expiresAt = !empty($mailbox['token_expires_at']) ? strtotime((string) $mailbox['token_expires_at']) : 0;

        if ($accessToken !== '' && $expiresAt > time() + 60) {
            return $accessToken;
        }
        if ($refreshToken === '') {
            $this->markMailboxNeedsAuth((string) $user['id']);
            return '';
        }

        try {
            $token = GmailOAuthService::refreshAccessToken($refreshToken);
            $newAccessToken = (string) ($token['access_token'] ?? '');
            if ($newAccessToken === '') {
                $this->markMailboxNeedsAuth((string) $user['id']);
                return '';
            }

            $newRefreshToken = (string) ($token['refresh_token'] ?? $refreshToken);
            $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
            $update = $this->db->prepare("
                UPDATE staff_connected_mailboxes
                SET access_token_encrypted = :access_token, refresh_token_encrypted = :refresh_token,
                    token_expires_at = NOW() + (CAST(:expires_in AS INTEGER) * INTERVAL '1 second'),
                    token_type = :token_type, oauth_status = 'active', updated_at = NOW()
                WHERE user_id = :uid
            " );
            $update->execute([
                ':access_token' => GmailOAuthService::encrypt($newAccessToken),
                ':refresh_token' => GmailOAuthService::encrypt($newRefreshToken),
                ':expires_in' => $expiresIn,
                ':token_type' => (string) ($token['token_type'] ?? 'Bearer'),
                ':uid' => $user['id'],
            ]);
            return $newAccessToken;
        } catch (Throwable $e) {
            error_log('Workly Gmail token refresh failed: ' . $e->getMessage());
            $this->markMailboxNeedsAuth((string) $user['id']);
            return '';
        }
    }

    private function markMailboxNeedsAuth(string $userId): void
    {
        $stmt = $this->db->prepare("UPDATE staff_connected_mailboxes SET oauth_status = 'needs_auth', updated_at = NOW() WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
    }

    private function mailboxStatus(array $user): void
    {
        $stmt = $this->db->prepare("SELECT email, oauth_status, last_synced_at FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $user['id']]);
        $mailbox = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $connected = $mailbox && $mailbox['oauth_status'] === 'active' && !empty($mailbox['refresh_token_encrypted']);
        echo json_encode([
            'connected' => (bool) $connected,
            'email' => $connected ? $mailbox['email'] : null,
            'status' => $mailbox['oauth_status'] ?? 'disconnected',
            'last_synced_at' => $mailbox['last_synced_at'] ?? null,
        ]);
    }

    private function disconnectMailbox(array $user): void
    {
        $stmt = $this->db->prepare("
            UPDATE staff_connected_mailboxes
            SET oauth_status = 'needs_auth', access_token_encrypted = NULL, refresh_token_encrypted = NULL,
                token_expires_at = NULL, token_type = NULL, updated_at = NOW()
            WHERE user_id = :uid
        " );
        $stmt->execute([':uid' => $user['id']]);
        AuditLogger::log('GMAIL_DISCONNECTED', 'success', 'User disconnected Gmail OAuth mailbox', $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true]);
    }

    private function startGoogleOAuth(array $user): void
    {
        try {
            SecurityHelper::configureSecureSession();
            $state = bin2hex(random_bytes(32));
            $cleanup = $this->db->prepare('DELETE FROM gmail_oauth_states WHERE expires_at < NOW()');
            $cleanup->execute();
            $stateStmt = $this->db->prepare("INSERT INTO gmail_oauth_states (state_hash, user_id, expires_at) VALUES (:hash, :uid, NOW() + INTERVAL '10 minutes')");
            $stateStmt->execute([':hash' => hash('sha256', $state), ':uid' => $user['id']]);
            $authorizationUrl = GmailOAuthService::createAuthorizationUrl($state);
            header('Cache-Control: no-store');
            header('Location: ' . $authorizationUrl, true, 302);
            exit;
        } catch (Throwable $e) {
            error_log('Workly Gmail OAuth start failed: ' . $e->getMessage());
            header('Location: /email?gmail_error=config', true, 303);
            exit;
        }
    }

    private function completeGoogleOAuth(array $user): void
    {
        $receivedState = (string) ($_GET['state'] ?? '');
        if ($receivedState === '' || !preg_match('/^[a-f0-9]{64}$/', $receivedState)) {
            header('Location: /email?gmail_error=state', true, 303);
            exit;
        }
        $stateStmt = $this->db->prepare("DELETE FROM gmail_oauth_states WHERE state_hash = :hash AND user_id = :uid AND expires_at > NOW() RETURNING user_id");
        $stateStmt->execute([':hash' => hash('sha256', $receivedState), ':uid' => $user['id']]);
        if (!$stateStmt->fetchColumn()) {
            header('Location: /email?gmail_error=state', true, 303);
            exit;
        }
        if (!empty($_GET['error']) || empty($_GET['code'])) {
            header('Location: /email?gmail_error=cancelled', true, 303);
            exit;
        }

        try {
            $token = GmailOAuthService::exchangeAuthorizationCode((string) $_GET['code']);
            if (!is_array($token) || !empty($token['error']) || empty($token['access_token'])) {
                throw new RuntimeException('Google did not return a valid access token.');
            }

            $accessToken = (string) $token['access_token'];
            $profile = GmailOAuthService::fetchProfile($accessToken);
            $gmailEmail = strtolower(trim((string) ($profile['emailAddress'] ?? '')));
            if (!filter_var($gmailEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Unable to identify the authorized Gmail account.');
            }

            $existing = $this->db->prepare('SELECT refresh_token_encrypted FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
            $existing->execute([':uid' => $user['id']]);
            $oldRefresh = (string) ($existing->fetchColumn() ?: '');
            $refreshToken = (string) ($token['refresh_token'] ?? '');
            if ($refreshToken === '' && $oldRefresh !== '') {
                $refreshToken = GmailOAuthService::decrypt($oldRefresh);
            }
            if ($refreshToken === '') {
                throw new RuntimeException('Google did not issue a refresh token. Revoke Workly access in your Google Account and connect again.');
            }

            $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
            $scopes = implode(' ', GmailOAuthService::scopes());
            $stmt = $this->db->prepare("
                INSERT INTO staff_connected_mailboxes
                    (user_id, provider, email, oauth_status, sync_interval_minutes, oauth_scope, access_token_encrypted, refresh_token_encrypted, token_expires_at, token_type, updated_at)
                VALUES (:uid, 'gmail', :email, 'active', 5, :scope, :access_token, :refresh_token, NOW() + (CAST(:expires_in AS INTEGER) * INTERVAL '1 second'), :token_type, NOW())
                ON CONFLICT (user_id) DO UPDATE SET
                    provider = 'gmail', email = EXCLUDED.email, oauth_status = 'active', oauth_scope = EXCLUDED.oauth_scope,
                    access_token_encrypted = EXCLUDED.access_token_encrypted, refresh_token_encrypted = EXCLUDED.refresh_token_encrypted,
                    token_expires_at = EXCLUDED.token_expires_at, token_type = EXCLUDED.token_type, updated_at = NOW()
            " );
            $stmt->execute([
                ':uid' => $user['id'], ':email' => $gmailEmail, ':scope' => $scopes,
                ':access_token' => GmailOAuthService::encrypt($accessToken),
                ':refresh_token' => GmailOAuthService::encrypt($refreshToken),
                ':expires_in' => $expiresIn, ':token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            ]);
            AuditLogger::log('GMAIL_OAUTH_CONNECTED', 'success', 'Google Gmail OAuth connected: ' . $gmailEmail, $user['id'], $user['role'], $this->db);
            header('Location: /email?gmail_connected=1', true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Workly Gmail OAuth callback failed: ' . $e->getMessage());
            header('Location: /email?gmail_error=callback', true, 303);
            exit;
        }
    }

    private function gmailRequest(string $method, string $path, string $token, ?array $jsonBody = null): ?array
    {
        $ch = curl_init(self::GMAIL_API_BASE . $path);
        if ($ch === false) {
            return null;
        }
        $hdrs = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
        ];
        if ($jsonBody !== null) {
            $hdrs[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
        }
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function collectUploadedFiles(): array
    {
        $files = [];
        if (empty($_FILES['attachments'])) {
            return $files;
        }
        $raw = $_FILES['attachments'];
        if (!is_array($raw['name'])) {
            if (($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $raw['tmp_name'])) {
                $bytes = @file_get_contents((string) $raw['tmp_name']);
                if (is_string($bytes) && strlen($bytes) <= 8388608) {
                    $files[] = [
                        'name'  => SecurityHelper::sanitizePlainText(basename((string) $raw['name']), 180) ?: 'attachment.bin',
                        'mime'  => SecurityHelper::sanitizePlainText((string) ($raw['type'] ?: 'application/octet-stream'), 120),
                        'bytes' => $bytes,
                    ];
                }
            }
            return $files;
        }

        $count = min(5, count($raw['name']));
        for ($i = 0; $i < $count; $i++) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string) ($raw['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_file($tmp)) {
                continue;
            }
            $bytes = @file_get_contents($tmp);
            if (!is_string($bytes) || strlen($bytes) > 8388608) {
                continue;
            }
            $files[] = [
                'name'  => SecurityHelper::sanitizePlainText(basename((string) $raw['name'][$i]), 180) ?: 'attachment.bin',
                'mime'  => SecurityHelper::sanitizePlainText((string) ($raw['type'][$i] ?: 'application/octet-stream'), 120),
                'bytes' => $bytes,
            ];
        }
        return $files;
    }

    private function buildRawMime(string $fromEmail, string $fromName, string $to, string $cc, string $subject, string $body, array $attachments): string
    {
        $safeSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            "From: {$fromName} <{$fromEmail}>",
            "To: {$to}",
        ];
        if ($cc !== '') {
            $headers[] = "Cc: {$cc}";
        }
        $headers[] = "Subject: {$safeSubj}";
        $headers[] = "MIME-Version: 1.0";

        if (empty($attachments)) {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: 8bit";
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        } else {
            $boundary = 'wrk_' . bin2hex(random_bytes(10));
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
            $parts = [];
            $parts[] = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}";
            foreach ($attachments as $att) {
                $b64 = chunk_split(base64_encode($att['bytes']));
                $fname = str_replace('"', '', $att['name']);
                $parts[] = "--{$boundary}\r\nContent-Type: {$att['mime']}; name=\"{$fname}\"\r\nContent-Disposition: attachment; filename=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$b64}";
            }
            $parts[] = "--{$boundary}--";
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function parseComposePayload(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'multipart/form-data') !== false) {
            return $_POST;
        }
        return json_decode((string) file_get_contents('php://input'), true) ?: [];
    }

    private function storeAttachments(string $emailId, array $attachments): void
    {
        if (empty($attachments)) {
            return;
        }
        $stmt = $this->db->prepare("
            INSERT INTO email_attachments (id, email_id, filename, mime_type, size_bytes, data_base64)
            VALUES (:id, :eid, :fname, :mime, :sz, :b64)
        ");
        foreach ($attachments as $att) {
            $stmt->execute([
                ':id'    => 'eatt-' . bin2hex(random_bytes(5)),
                ':eid'   => $emailId,
                ':fname' => $att['name'],
                ':mime'  => $att['mime'],
                ':sz'    => strlen($att['bytes']),
                ':b64'   => base64_encode($att['bytes']),
            ]);
        }
    }

    private function replyInThread(array $user): void
    {
        $input = $this->parseComposePayload();
        $attachments = $this->collectUploadedFiles();

        $threadId = SecurityHelper::sanitizePlainText($input['thread_id'] ?? '', 64);
        $recipient = filter_var($input['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $cc = SecurityHelper::sanitizePlainText($input['cc_email'] ?? '', 191);
        $subject = SecurityHelper::sanitizePlainText($input['subject'] ?? 'Conversation Thread', 255);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 8000);
        $clientRef = SecurityHelper::sanitizePlainText($input['client_ref_id'] ?? '', 64) ?: null;

        if ($threadId === '' || $recipient === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'thread_id, recipient_email, and body are required']);
            return;
        }

        if (($user['role'] ?? '') !== 'admin' && ($this->isAdminAddress($recipient) || $this->containsAdminCc($cc))) {
            http_response_code(403);
            echo json_encode(['error' => 'Recipient is not available for email delivery']);
            return;
        }

        if (stripos($subject, 'Re:') !== 0) {
            $subject = 'Re: ' . $subject;
        }

        // Send via Gmail API when active OAuth token is provided
        $gmailSentId = null;
        $token = $this->getGoogleAccessToken($user);
        $senderEmail = (string) $user['email'];
        $mbStmt = $this->db->prepare('SELECT email FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $linkedGmail = $mbStmt->fetchColumn();
        if ($linkedGmail) {
            $senderEmail = (string) $linkedGmail;
        }

        if ($token !== '') {
            $rawMime = $this->buildRawMime($senderEmail, (string) $user['name'], $recipient, $cc, $subject, $body, $attachments);
            $gResp = $this->gmailRequest('POST', '/messages/send', $token, [
                'raw'      => $rawMime,
                'threadId' => str_starts_with($threadId, 'th-') ? null : $threadId,
            ]);
            if ($gResp && !empty($gResp['id'])) {
                $gmailSentId = (string) $gResp['id'];
            }
        }

        $id = 'msg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, snippet, gmail_message_id, folder, is_read, client_ref_id)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :snip, :gid, 'sent', TRUE, :cid)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $senderEmail,
            ':remail'  => $recipient,
            ':cc'      => $cc !== '' ? $cc : null,
            ':subject' => $subject,
            ':body'    => $body,
            ':snip'    => mb_substr($body, 0, 140, 'UTF-8'),
            ':gid'     => $gmailSentId,
            ':cid'     => $clientRef,
        ]);

        $this->storeAttachments($id, $attachments);
        $this->invalidate();
        AuditLogger::log('EMAIL_THREAD_REPLY', 'success', "Replied in thread {$threadId}" . ($gmailSentId ? ' via Gmail API' : ''), $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $id, 'thread_id' => $threadId, 'gmail_synced' => $gmailSentId !== null]);
    }

    private function compose(array $user): void
    {
        $input = $this->parseComposePayload();
        $attachments = $this->collectUploadedFiles();

        $recipient = filter_var($input['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $cc = SecurityHelper::sanitizePlainText($input['cc_email'] ?? '', 191);
        $subject = SecurityHelper::sanitizePlainText($input['subject'] ?? '', 255);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 8000);
        $clientRef = SecurityHelper::sanitizePlainText($input['client_ref_id'] ?? '', 64) ?: null;

        if ($recipient === '' || $subject === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Recipient email, subject, and body are required']);
            return;
        }

        if (($user['role'] ?? '') !== 'admin' && ($this->isAdminAddress($recipient) || $this->containsAdminCc($cc))) {
            http_response_code(403);
            echo json_encode(['error' => 'Recipient is not available for email delivery']);
            return;
        }

        $threadId = 'th-' . bin2hex(random_bytes(5));
        $gmailSentId = null;
        $token = $this->getGoogleAccessToken($user);

        $senderEmail = (string) $user['email'];
        $mbStmt = $this->db->prepare('SELECT email FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $linkedGmail = $mbStmt->fetchColumn();
        if ($linkedGmail) {
            $senderEmail = (string) $linkedGmail;
        }

        if ($token !== '') {
            $rawMime = $this->buildRawMime($senderEmail, (string) $user['name'], $recipient, $cc, $subject, $body, $attachments);
            $gResp = $this->gmailRequest('POST', '/messages/send', $token, ['raw' => $rawMime]);
            if ($gResp && !empty($gResp['id'])) {
                $gmailSentId = (string) $gResp['id'];
                if (!empty($gResp['threadId'])) {
                    $threadId = (string) $gResp['threadId'];
                }
            }
        }

        $id = 'msg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, snippet, gmail_message_id, folder, is_read, client_ref_id)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :snip, :gid, 'sent', TRUE, :cid)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $senderEmail,
            ':remail'  => $recipient,
            ':cc'      => $cc !== '' ? $cc : null,
            ':subject' => $subject,
            ':body'    => $body,
            ':snip'    => mb_substr($body, 0, 140, 'UTF-8'),
            ':gid'     => $gmailSentId,
            ':cid'     => $clientRef,
        ]);

        $this->storeAttachments($id, $attachments);
        $this->invalidate();
        AuditLogger::log('EMAIL_CREATED', 'success', "Sent message {$threadId} to {$recipient}" . ($gmailSentId ? ' via Gmail API' : ''), $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id, 'thread_id' => $threadId, 'gmail_synced' => $gmailSentId !== null]);
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
            VALUES (:uid, :prov, :email, :status, 5, 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send', NOW(), NOW())
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

    private function decodeBase64Url(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        return is_string($raw) ? $raw : '';
    }

    private function extractGmailParts(array $payload, string &$plainBody, array &$attachments): void
    {
        $mime = (string) ($payload['mimeType'] ?? '');
        $filename = (string) ($payload['filename'] ?? '');
        $bodyData = (string) ($payload['body']['data'] ?? '');
        $attId = (string) ($payload['body']['attachmentId'] ?? '');

        if ($filename !== '' && ($attId !== '' || $bodyData !== '')) {
            $attachments[] = [
                'filename'     => SecurityHelper::sanitizePlainText($filename, 180),
                'mime_type'    => $mime !== '' ? $mime : 'application/octet-stream',
                'size_bytes'   => (int) ($payload['body']['size'] ?? 0),
                'gmail_att_id' => $attId !== '' ? $attId : null,
                'data_base64'  => $bodyData !== '' ? base64_encode($this->decodeBase64Url($bodyData)) : null,
            ];
        } elseif ($mime === 'text/plain' && $bodyData !== '' && $plainBody === '') {
            $plainBody = $this->decodeBase64Url($bodyData);
        } elseif ($mime === 'text/html' && $bodyData !== '' && $plainBody === '') {
            $plainBody = trim(strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $this->decodeBase64Url($bodyData))));
        }

        if (!empty($payload['parts']) && is_array($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                if (is_array($part)) {
                    $this->extractGmailParts($part, $plainBody, $attachments);
                }
            }
        }
    }

    private function syncMailbox(array $user): void
    {
        $token = $this->getGoogleAccessToken($user);
        $syncedCount = 0;
        $gmailEmail = null;

        if ($token === '') {
            http_response_code(409);
            echo json_encode(['error' => 'Connect your Gmail account before synchronizing.']);
            return;
        }

        if ($token !== '') {
            $profile = $this->gmailRequest('GET', '/profile', $token);
            if ($profile && !empty($profile['emailAddress'])) {
                $gmailEmail = strtolower((string) $profile['emailAddress']);
                $upMb = $this->db->prepare("
                    INSERT INTO staff_connected_mailboxes (user_id, provider, email, oauth_status, sync_interval_minutes, oauth_scope, last_synced_at, updated_at)
                    VALUES (:uid, 'gmail', :em, 'active', 5, 'gmail.readonly gmail.send', NOW(), NOW())
                    ON CONFLICT (user_id) DO UPDATE
                    SET email = EXCLUDED.email, oauth_status = 'active', last_synced_at = NOW(), updated_at = NOW()
                ");
                $upMb->execute([':uid' => $user['id'], ':em' => $gmailEmail]);
            }

            $list = $this->gmailRequest('GET', '/messages?maxResults=15', $token);
            if ($list && !empty($list['messages']) && is_array($list['messages'])) {
                foreach ($list['messages'] as $msgStub) {
                    $gid = (string) ($msgStub['id'] ?? '');
                    if ($gid === '') {
                        continue;
                    }
                    $chk = $this->db->prepare('SELECT id FROM email_messages WHERE gmail_message_id = :gid LIMIT 1');
                    $chk->execute([':gid' => $gid]);
                    if ($chk->fetch()) {
                        continue;
                    }

                    $full = $this->gmailRequest('GET', '/messages/' . rawurlencode($gid) . '?format=full', $token);
                    if (!$full || empty($full['payload'])) {
                        continue;
                    }

                    $hdrMap = [];
                    foreach (($full['payload']['headers'] ?? []) as $h) {
                        $hdrMap[strtolower((string) ($h['name'] ?? ''))] = (string) ($h['value'] ?? '');
                    }

                    $fromRaw = $hdrMap['from'] ?? '';
                    $fromEmail = $fromRaw;
                    $fromName = $fromRaw;
                    if (preg_match('/^(.*?)\s*<([^>]+)>/', $fromRaw, $m)) {
                        $fromName = trim($m[1], "\"' ") ?: $m[2];
                        $fromEmail = strtolower(trim($m[2]));
                    } else {
                        $fromEmail = strtolower(trim($fromRaw));
                        $fromName = $fromEmail;
                    }

                    $toEmail = strtolower(trim($hdrMap['to'] ?? ($gmailEmail ?? $user['email'])));
                    if (preg_match('/<([^>]+)>/', $toEmail, $tm)) {
                        $toEmail = strtolower(trim($tm[1]));
                    }
                    $ccEmail = trim($hdrMap['cc'] ?? '');
                    $subject = SecurityHelper::sanitizePlainText($hdrMap['subject'] ?? '(No Subject)', 255);
                    $snippet = SecurityHelper::sanitizePlainText(html_entity_decode((string) ($full['snippet'] ?? ''), ENT_QUOTES, 'UTF-8'), 500);
                    $plainBody = '';
                    $atts = [];
                    $this->extractGmailParts($full['payload'], $plainBody, $atts);
                    if (trim($plainBody) === '') {
                        $plainBody = $snippet !== '' ? $snippet : '(Empty message body)';
                    }

                    $labels = (array) ($full['labelIds'] ?? []);
                    $folder = in_array('SENT', $labels, true) ? 'sent' : 'inbox';
                    $isRead = !in_array('UNREAD', $labels, true);
                    $threadId = SecurityHelper::sanitizePlainText((string) ($full['threadId'] ?? ('th-' . bin2hex(random_bytes(5)))), 64);

                    $id = 'msg-' . bin2hex(random_bytes(5));
                    $ins = $this->db->prepare("
                        INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, snippet, gmail_message_id, folder, is_read)
                        VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :snip, :gid, :folder, :is_read)
                    ");
                    $ins->execute([
                        ':id'      => $id,
                        ':tid'     => $threadId,
                        ':sname'   => SecurityHelper::sanitizePlainText($fromName, 191),
                        ':semail'  => SecurityHelper::sanitizePlainText($fromEmail, 191),
                        ':remail'  => SecurityHelper::sanitizePlainText($toEmail, 191),
                        ':cc'      => $ccEmail !== '' ? SecurityHelper::sanitizePlainText($ccEmail, 191) : null,
                        ':subject' => $subject,
                        ':body'    => SecurityHelper::sanitizePlainText($plainBody, 8000),
                        ':snip'    => $snippet,
                        ':gid'     => $gid,
                        ':folder'  => $folder,
                        ':is_read' => $isRead ? 'true' : 'false',
                    ]);

                    foreach ($atts as $a) {
                        $aIns = $this->db->prepare("
                            INSERT INTO email_attachments (id, email_id, filename, mime_type, size_bytes, data_base64, gmail_attachment_id)
                            VALUES (:id, :eid, :fname, :mime, :sz, :b64, :gaid)
                        ");
                        $aIns->execute([
                            ':id'    => 'eatt-' . bin2hex(random_bytes(5)),
                            ':eid'   => $id,
                            ':fname' => $a['filename'],
                            ':mime'  => $a['mime_type'],
                            ':sz'    => $a['size_bytes'],
                            ':b64'   => $a['data_base64'],
                            ':gaid'  => $a['gmail_att_id'],
                        ]);
                    }

                    $syncedCount++;
                }
            }
        } else {
            $stmt = $this->db->prepare('UPDATE staff_connected_mailboxes SET last_synced_at = NOW(), updated_at = NOW() WHERE user_id = :uid');
            $stmt->execute([':uid' => $user['id']]);
        }

        $this->invalidate();
        echo json_encode([
            'success'       => true,
            'synced_at'     => gmdate('c'),
            'gmail_email'   => $gmailEmail,
            'imported'      => $syncedCount,
        ]);
    }

    private function downloadAttachment(string $attId, array $user): void
    {
        $stmt = $this->db->prepare("
            SELECT a.*, m.sender_email, m.recipient_email, m.cc_email, m.gmail_message_id
            FROM email_attachments a
            JOIN email_messages m ON a.email_id = m.id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $attId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) {
            http_response_code(404);
            echo json_encode(['error' => 'Attachment not found']);
            return;
        }

        $bytes = '';
        if (!empty($att['data_base64'])) {
            $decoded = base64_decode((string) $att['data_base64'], true);
            if (is_string($decoded)) {
                $bytes = $decoded;
            }
        } elseif (!empty($att['gmail_attachment_id']) && !empty($att['gmail_message_id'])) {
            $token = $this->getGoogleAccessToken($user);
            if ($token !== '') {
                $resp = $this->gmailRequest(
                    'GET',
                    '/messages/' . rawurlencode((string) $att['gmail_message_id']) . '/attachments/' . rawurlencode((string) $att['gmail_attachment_id']),
                    $token
                );
                if ($resp && !empty($resp['data'])) {
                    $bytes = $this->decodeBase64Url((string) $resp['data']);
                }
            }
        }

        if ($bytes === '') {
            http_response_code(404);
            echo json_encode(['error' => 'Attachment content unavailable. Re-sync Gmail to download.']);
            return;
        }

        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $fname = str_replace(['"', "\r", "\n"], '', (string) $att['filename']);
        header('Content-Type: ' . ($att['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    private function isAdminAddress(string $email): bool
    {
        $clean = strtolower(trim($email));
        if ($clean === '') {
            return false;
        }
        $stmt = $this->db->prepare("
            SELECT 1 FROM users WHERE LOWER(email) = :e1 AND role = 'admin'
            UNION ALL
            SELECT 1 FROM staff_connected_mailboxes m JOIN users u ON m.user_id = u.id WHERE LOWER(m.email) = :e2 AND u.role = 'admin'
            LIMIT 1
        ");
        $stmt->execute([':e1' => $clean, ':e2' => $clean]);
        return (bool) $stmt->fetchColumn();
    }

    private function containsAdminCc(string $cc): bool
    {
        if (trim($cc) === '') {
            return false;
        }
        foreach (explode(',', $cc) as $part) {
            $addr = trim($part);
            if ($addr !== '' && $this->isAdminAddress($addr)) {
                return true;
            }
        }
        return false;
    }
}
