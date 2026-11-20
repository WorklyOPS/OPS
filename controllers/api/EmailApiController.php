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
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

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
        if ($method === 'GET' && $sub === 'live') {
            $this->liveState($user);
            return;
        }
        if ($method === 'PATCH' && $sub === 'threads' && $arg !== '' && ($parts[5] ?? '') === 'read') {
            $this->setThreadReadState($arg, $user);
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
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS body_html TEXT DEFAULT NULL');
            $this->db->exec("ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS body_format VARCHAR(16) DEFAULT 'plain'");
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS access_token_encrypted TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS refresh_token_encrypted TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMPTZ DEFAULT NULL');
            $this->db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_type VARCHAR(32) DEFAULT NULL');
            $this->db->exec("CREATE TABLE IF NOT EXISTS gmail_oauth_states (state_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE, return_to VARCHAR(64) NOT NULL DEFAULT '/email', expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
            $this->db->exec("ALTER TABLE gmail_oauth_states ADD COLUMN IF NOT EXISTS return_to VARCHAR(64) NOT NULL DEFAULT '/email'");
            GoogleCalendarService::ensureSchema($this->db);
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_attachments (
                    id VARCHAR(64) PRIMARY KEY,
                    email_id VARCHAR(64) NOT NULL REFERENCES email_messages(id) ON DELETE CASCADE,
                    filename VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
                    size_bytes INTEGER NOT NULL DEFAULT 0,
                    data_base64 TEXT DEFAULT NULL,
                    gmail_attachment_id VARCHAR(255) DEFAULT NULL,
                    content_id VARCHAR(255) DEFAULT NULL,
                    is_inline BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $this->db->exec('ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS content_id VARCHAR(255) DEFAULT NULL');
            $this->db->exec('ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS is_inline BOOLEAN NOT NULL DEFAULT FALSE');
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
        $stmt = $this->db->prepare("SELECT email, oauth_status, oauth_scope, refresh_token_encrypted, last_synced_at FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $user['id']]);
        $mailbox = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $connected = $mailbox && $mailbox['oauth_status'] === 'active' && !empty($mailbox['refresh_token_encrypted']);
        $calendarConnected = $connected && GoogleCalendarService::hasCalendarScope((string) ($mailbox['oauth_scope'] ?? ''));
        echo json_encode([
            'connected'          => (bool) $connected,
            'calendar_connected' => (bool) $calendarConnected,
            'email'              => $connected ? $mailbox['email'] : null,
            'status'             => $mailbox['oauth_status'] ?? 'disconnected',
            'last_synced_at'     => $mailbox['last_synced_at'] ?? null,
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
        $nextRaw = (string) ($_GET['next'] ?? '/email');
        $returnTo = $nextRaw === '/calendar-reports' ? '/calendar-reports' : '/email';
        $errorParam = $returnTo === '/calendar-reports' ? 'calendar_error' : 'gmail_error';

        try {
            SecurityHelper::configureSecureSession();
            $state = bin2hex(random_bytes(32));
            $cleanup = $this->db->prepare('DELETE FROM gmail_oauth_states WHERE expires_at < NOW()');
            $cleanup->execute();
            $stateStmt = $this->db->prepare("INSERT INTO gmail_oauth_states (state_hash, user_id, return_to, expires_at) VALUES (:hash, :uid, :rto, NOW() + INTERVAL '10 minutes')");
            $stateStmt->execute([':hash' => hash('sha256', $state), ':uid' => $user['id'], ':rto' => $returnTo]);
            $authorizationUrl = GmailOAuthService::createAuthorizationUrl($state);
            header('Cache-Control: no-store');
            header('Location: ' . $authorizationUrl, true, 302);
            exit;
        } catch (Throwable $e) {
            error_log('Workly Google OAuth start failed: ' . $e->getMessage());
            header('Location: ' . $returnTo . '?' . $errorParam . '=config', true, 303);
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
        $stateStmt = $this->db->prepare("DELETE FROM gmail_oauth_states WHERE state_hash = :hash AND user_id = :uid AND expires_at > NOW() RETURNING user_id, return_to");
        $stateStmt->execute([':hash' => hash('sha256', $receivedState), ':uid' => $user['id']]);
        $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$stateRow) {
            header('Location: /email?gmail_error=state', true, 303);
            exit;
        }
        $returnTo = ($stateRow['return_to'] ?? '/email') === '/calendar-reports' ? '/calendar-reports' : '/email';
        $errorParam = $returnTo === '/calendar-reports' ? 'calendar_error' : 'gmail_error';
        $successParam = $returnTo === '/calendar-reports' ? 'calendar_connected' : 'gmail_connected';

        if (!empty($_GET['error']) || empty($_GET['code'])) {
            header('Location: ' . $returnTo . '?' . $errorParam . '=cancelled', true, 303);
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
                throw new RuntimeException('Unable to identify the authorized Google account.');
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
            $grantedScope = trim((string) ($token['scope'] ?? ''));
            $scopes = $grantedScope !== '' ? $grantedScope : implode(' ', GmailOAuthService::scopes());
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
            AuditLogger::log('GMAIL_OAUTH_CONNECTED', 'success', 'Google OAuth connected (Mail + Calendar): ' . $gmailEmail, $user['id'], $user['role'], $this->db);
            header('Location: ' . $returnTo . '?' . $successParam . '=1', true, 303);
            exit;
        } catch (Throwable $e) {
            error_log('Workly Google OAuth callback failed: ' . $e->getMessage());
            header('Location: ' . $returnTo . '?' . $errorParam . '=callback', true, 303);
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

    private function buildRawMime(string $fromEmail, string $fromName, string $to, string $cc, string $subject, string $bodyPlain, array $attachments, string $bodyHtml = ''): string
    {
        $safeSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $safeFromName = str_replace(["\r", "\n", '"'], '', $fromName);
        $headers = [
            "From: \"{$safeFromName}\" <{$fromEmail}>",
            "To: {$to}",
        ];
        if ($cc !== '') {
            $headers[] = "Cc: {$cc}";
        }
        $headers[] = "Subject: {$safeSubj}";
        $headers[] = "MIME-Version: 1.0";

        $hasHtml = trim($bodyHtml) !== '';
        $htmlWrapped = $hasHtml
            ? '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937;">' . $bodyHtml . '</body></html>'
            : '';

        if (empty($attachments) && !$hasHtml) {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: base64";
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($bodyPlain));
        } elseif (empty($attachments) && $hasHtml) {
            $altBoundary = 'wrk_alt_' . bin2hex(random_bytes(9));
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"";
            $altParts = [
                "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyPlain)),
                "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($htmlWrapped)),
                "--{$altBoundary}--",
            ];
            $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $altParts);
        } else {
            $mixBoundary = 'wrk_mix_' . bin2hex(random_bytes(9));
            $headers[] = "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"";
            $parts = [];
            if ($hasHtml) {
                $altBoundary = 'wrk_alt_' . bin2hex(random_bytes(9));
                $altBody = implode("\r\n", [
                    "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyPlain)),
                    "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($htmlWrapped)),
                    "--{$altBoundary}--",
                ]);
                $parts[] = "--{$mixBoundary}\r\nContent-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n{$altBody}";
            } else {
                $parts[] = "--{$mixBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bodyPlain));
            }
            foreach ($attachments as $att) {
                $b64 = chunk_split(base64_encode($att['bytes']));
                $fname = str_replace('"', '', $att['name']);
                $parts[] = "--{$mixBoundary}\r\nContent-Type: {$att['mime']}; name=\"{$fname}\"\r\nContent-Disposition: attachment; filename=\"{$fname}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$b64}";
            }
            $parts[] = "--{$mixBoundary}--";
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
        $bodyHtml = SecurityHelper::sanitizeEmailHtml((string) ($input['body_html'] ?? ''), 300000);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 16000);
        if ($body === '' && $bodyHtml !== '') {
            $body = SecurityHelper::htmlToPlainText($bodyHtml, 16000);
        }
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
            $rawMime = $this->buildRawMime($senderEmail, (string) $user['name'], $recipient, $cc, $subject, $body, $attachments, $bodyHtml);
            $gResp = $this->gmailRequest('POST', '/messages/send', $token, [
                'raw'      => $rawMime,
                'threadId' => str_starts_with($threadId, 'th-') ? null : $threadId,
            ]);
            if ($gResp && !empty($gResp['id'])) {
                $gmailSentId = (string) $gResp['id'];
            }
        }

        $id = 'msg-' . bin2hex(random_bytes(5));
        $bodyFormat = $bodyHtml !== '' ? 'html' : 'plain';
        $createdAtIso = gmdate('c');
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, body_html, body_format, snippet, gmail_message_id, folder, is_read, client_ref_id, created_at)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :bhtml, :bfmt, :snip, :gid, 'sent', TRUE, :cid, :cat)
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
            ':bhtml'   => $bodyHtml !== '' ? $bodyHtml : null,
            ':bfmt'    => $bodyFormat,
            ':snip'    => mb_substr($body, 0, 140, 'UTF-8'),
            ':gid'     => $gmailSentId,
            ':cid'     => $clientRef,
            ':cat'     => $createdAtIso,
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
        $bodyHtml = SecurityHelper::sanitizeEmailHtml((string) ($input['body_html'] ?? ''), 300000);
        $body = SecurityHelper::sanitizePlainText($input['body'] ?? '', 16000);
        if ($body === '' && $bodyHtml !== '') {
            $body = SecurityHelper::htmlToPlainText($bodyHtml, 16000);
        }
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
            $rawMime = $this->buildRawMime($senderEmail, (string) $user['name'], $recipient, $cc, $subject, $body, $attachments, $bodyHtml);
            $gResp = $this->gmailRequest('POST', '/messages/send', $token, ['raw' => $rawMime]);
            if ($gResp && !empty($gResp['id'])) {
                $gmailSentId = (string) $gResp['id'];
                if (!empty($gResp['threadId'])) {
                    $threadId = (string) $gResp['threadId'];
                }
            }
        }

        $id = 'msg-' . bin2hex(random_bytes(5));
        $bodyFormat = $bodyHtml !== '' ? 'html' : 'plain';
        $createdAtIso = gmdate('c');
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, body_html, body_format, snippet, gmail_message_id, folder, is_read, client_ref_id, created_at)
            VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :bhtml, :bfmt, :snip, :gid, 'sent', TRUE, :cid, :cat)
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
            ':bhtml'   => $bodyHtml !== '' ? $bodyHtml : null,
            ':bfmt'    => $bodyFormat,
            ':snip'    => mb_substr($body, 0, 140, 'UTF-8'),
            ':gid'     => $gmailSentId,
            ':cid'     => $clientRef,
            ':cat'     => $createdAtIso,
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

    private function extractPartHeaders(array $payload): array
    {
        $map = [];
        foreach (($payload['headers'] ?? []) as $h) {
            $name = strtolower(trim((string) ($h['name'] ?? '')));
            if ($name !== '') {
                $map[$name] = trim((string) ($h['value'] ?? ''));
            }
        }
        return $map;
    }

    private function decodeGmailBodyText(string $encoded, array $headers): string
    {
        $raw = $this->decodeBase64Url($encoded);
        if ($raw === '') {
            return '';
        }
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        if (str_contains($encoding, 'quoted-printable')) {
            $raw = quoted_printable_decode($raw);
        }
        $ctype = $headers['content-type'] ?? '';
        if (preg_match('/charset\s*=\s*"?([a-zA-Z0-9._-]+)"?/i', $ctype, $cm)) {
            $charset = strtoupper($cm[1]);
            if ($charset !== 'UTF-8' && $charset !== 'UTF8' && function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    $raw = $converted;
                }
            }
        }
        return $raw;
    }

    private function extractGmailParts(array $payload, string &$plainBody, string &$htmlBody, array &$attachments): void
    {
        $mime = strtolower((string) ($payload['mimeType'] ?? ''));
        $filename = trim((string) ($payload['filename'] ?? ''));
        $bodyData = (string) ($payload['body']['data'] ?? '');
        $attId = (string) ($payload['body']['attachmentId'] ?? '');
        $hdrs = $this->extractPartHeaders($payload);

        $rawCid = (string) ($hdrs['content-id'] ?? $hdrs['x-attachment-id'] ?? '');
        $contentId = trim($rawCid, " \t\n\r<>");
        $disposition = strtolower((string) ($hdrs['content-disposition'] ?? ''));
        $isExplicitAttachment = str_starts_with($disposition, 'attachment');
        $isInline = !$isExplicitAttachment && ($contentId !== '' || str_starts_with($disposition, 'inline')) && str_starts_with($mime, 'image/');
        $isTextBody = ($mime === 'text/plain' || $mime === 'text/html') && !$isExplicitAttachment && $filename === '';
        $isAttachment = ($filename !== '' || $attId !== '' || $contentId !== '' || $isExplicitAttachment)
            && !str_starts_with($mime, 'multipart/')
            && !$isTextBody;

        if ($isAttachment && ($attId !== '' || $bodyData !== '')) {
            $ext = explode('/', $mime)[1] ?? 'bin';
            $ext = preg_replace('/[^a-zA-Z0-9]/', '', explode(';', $ext)[0]) ?: 'bin';
            $safeName = $filename !== '' ? $filename : ('attachment-' . ($contentId !== '' ? preg_replace('/[^a-zA-Z0-9._-]/', '_', $contentId) : bin2hex(random_bytes(3))) . '.' . $ext);
            $decodedBytes = $bodyData !== '' ? $this->decodeBase64Url($bodyData) : '';
            $rawSize = (int) ($payload['body']['size'] ?? 0);
            if ($rawSize <= 0 && $decodedBytes !== '') {
                $rawSize = strlen($decodedBytes);
            }
            $attachments[] = [
                'filename'     => SecurityHelper::sanitizePlainText($safeName, 180) ?: 'attachment.bin',
                'mime_type'    => $mime !== '' ? $mime : 'application/octet-stream',
                'size_bytes'   => $rawSize,
                'gmail_att_id' => $attId !== '' ? $attId : null,
                'data_base64'  => $decodedBytes !== '' ? base64_encode($decodedBytes) : null,
                'content_id'   => $contentId !== '' ? $contentId : null,
                'is_inline'    => $isInline,
            ];
        } elseif ($mime === 'text/plain' && $bodyData !== '' && $plainBody === '' && !$isExplicitAttachment) {
            $plainBody = $this->decodeGmailBodyText($bodyData, $hdrs);
        } elseif ($mime === 'text/html' && $bodyData !== '' && $htmlBody === '' && !$isExplicitAttachment) {
            $htmlBody = $this->decodeGmailBodyText($bodyData, $hdrs);
        }

        if (!empty($payload['parts']) && is_array($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                if (is_array($part)) {
                    $this->extractGmailParts($part, $plainBody, $htmlBody, $attachments);
                }
            }
        }
    }

    private function performGmailSync(array $user, bool $force = false, int $maxResults = 25): array
    {
        $mbStmt = $this->db->prepare('SELECT email, oauth_status, last_synced_at FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $mailboxRow = $mbStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $gmailEmail = $mailboxRow ? strtolower((string) $mailboxRow['email']) : null;

        if (!$force && $mailboxRow && !empty($mailboxRow['last_synced_at'])) {
            $lastTs = strtotime((string) $mailboxRow['last_synced_at']);
            if ($lastTs !== false && (time() - $lastTs) < 8) {
                return [
                    'connected'    => $mailboxRow['oauth_status'] === 'active',
                    'gmail_email'  => $gmailEmail,
                    'synced_at'    => gmdate('c', $lastTs),
                    'imported'     => 0,
                    'new_messages' => [],
                    'throttled'    => true,
                ];
            }
        }

        $token = $this->getGoogleAccessToken($user);
        if ($token === '') {
            return [
                'connected'    => false,
                'gmail_email'  => null,
                'synced_at'    => null,
                'imported'     => 0,
                'new_messages' => [],
                'error'        => 'Connect your Gmail account before synchronizing.',
            ];
        }

        $syncedCount = 0;
        $newMessages = [];
        $profile = $this->gmailRequest('GET', '/profile', $token);
        if ($profile && !empty($profile['emailAddress'])) {
            $gmailEmail = strtolower((string) $profile['emailAddress']);
            $upMb = $this->db->prepare("
                INSERT INTO staff_connected_mailboxes (user_id, provider, email, oauth_status, sync_interval_minutes, oauth_scope, last_synced_at, updated_at)
                VALUES (:uid, 'gmail', :em, 'active', 1, 'gmail.readonly gmail.send', NOW(), NOW())
                ON CONFLICT (user_id) DO UPDATE
                SET email = EXCLUDED.email, oauth_status = 'active', last_synced_at = NOW(), updated_at = NOW()
            ");
            $upMb->execute([':uid' => $user['id'], ':em' => $gmailEmail]);
        }

        $limit = max(5, min(40, $maxResults));
        $list = $this->gmailRequest('GET', '/messages?maxResults=' . $limit, $token);
        if ($list && !empty($list['messages']) && is_array($list['messages'])) {
            foreach ($list['messages'] as $msgStub) {
                $gid = (string) ($msgStub['id'] ?? '');
                if ($gid === '') {
                    continue;
                }
                $chk = $this->db->prepare('
                    SELECT m.id, m.body_html,
                           (SELECT COUNT(*)::INT FROM email_attachments ea WHERE ea.email_id = m.id) AS att_cnt
                    FROM email_messages m
                    WHERE m.gmail_message_id = :gid
                    LIMIT 1
                ');
                $chk->execute([':gid' => $gid]);
                $existingRow = $chk->fetch(PDO::FETCH_ASSOC);
                if ($existingRow && !empty($existingRow['body_html']) && (!$force || (int) ($existingRow['att_cnt'] ?? 0) > 0)) {
                    continue;
                }

                $full = $this->gmailRequest('GET', '/messages/' . rawurlencode($gid) . '?format=full', $token);
                if (!$full || empty($full['payload'])) {
                    continue;
                }

                $plainBody = '';
                $htmlBody = '';
                $atts = [];
                $this->extractGmailParts($full['payload'], $plainBody, $htmlBody, $atts);

                // Upgrade legacy pre-HTML / pre-attachment synced messages seamlessly if encountered
                if ($existingRow) {
                    $exId = (string) $existingRow['id'];
                    $attStmt = $this->db->prepare('SELECT id, content_id FROM email_attachments WHERE email_id = :eid');
                    $attStmt->execute([':eid' => $exId]);
                    $existingAtts = $attStmt->fetchAll(PDO::FETCH_ASSOC);
                    $cidMap = [];
                    foreach ($existingAtts as $ea) {
                        if (!empty($ea['content_id'])) {
                            $cidMap[strtolower((string) $ea['content_id'])] = (string) $ea['id'];
                        }
                    }

                    if (empty($existingAtts) && !empty($atts)) {
                        foreach ($atts as $a) {
                            $attDbId = 'eatt-' . bin2hex(random_bytes(5));
                            $aIns = $this->db->prepare("
                                INSERT INTO email_attachments (id, email_id, filename, mime_type, size_bytes, data_base64, gmail_attachment_id, content_id, is_inline)
                                VALUES (:id, :eid, :fname, :mime, :sz, :b64, :gaid, :cid, :inl)
                            ");
                            $aIns->execute([
                                ':id'    => $attDbId,
                                ':eid'   => $exId,
                                ':fname' => $a['filename'],
                                ':mime'  => $a['mime_type'],
                                ':sz'    => $a['size_bytes'],
                                ':b64'   => $a['data_base64'],
                                ':gaid'  => $a['gmail_att_id'],
                                ':cid'   => $a['content_id'],
                                ':inl'   => !empty($a['is_inline']) ? 'true' : 'false',
                            ]);
                            if (!empty($a['content_id'])) {
                                $cidMap[strtolower((string) $a['content_id'])] = $attDbId;
                            }
                        }
                        $syncedCount++;
                    }

                    if (empty($existingRow['body_html']) && $htmlBody !== '') {
                        $resolvedHtml = $this->resolveCidImages($htmlBody, $cidMap);
                        $cleanHtml = SecurityHelper::sanitizeEmailHtml($resolvedHtml, 300000);
                        if ($cleanHtml !== '') {
                            $upHtml = $this->db->prepare("UPDATE email_messages SET body_html = :bh, body_format = 'html' WHERE id = :id");
                            $upHtml->execute([':bh' => $cleanHtml, ':id' => $exId]);
                            $syncedCount++;
                        }
                    }
                    continue;
                }

                $hdrMap = $this->extractPartHeaders($full['payload']);
                $fromRaw = $hdrMap['from'] ?? '';
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

                // Never import admin-involved emails into non-admin accounts
                if (($user['role'] ?? '') !== 'admin' && ($this->isAdminAddress($fromEmail) || $this->isAdminAddress($toEmail) || $this->containsAdminCc($ccEmail))) {
                    continue;
                }

                $subject = SecurityHelper::sanitizePlainText($hdrMap['subject'] ?? '(No Subject)', 255);
                $snippet = SecurityHelper::sanitizePlainText(html_entity_decode((string) ($full['snippet'] ?? ''), ENT_QUOTES, 'UTF-8'), 500);

                if (trim($plainBody) === '' && $htmlBody !== '') {
                    $plainBody = SecurityHelper::htmlToPlainText($htmlBody, 16000);
                }
                if (trim($plainBody) === '') {
                    $plainBody = $snippet !== '' ? $snippet : '(Empty message body)';
                }

                $labels = (array) ($full['labelIds'] ?? []);
                $folder = in_array('SENT', $labels, true) ? 'sent' : 'inbox';
                $isRead = !in_array('UNREAD', $labels, true);
                $threadId = SecurityHelper::sanitizePlainText((string) ($full['threadId'] ?? ('th-' . bin2hex(random_bytes(5)))), 64);

                $createdAtIso = gmdate('c');
                if (!empty($full['internalDate']) && is_numeric($full['internalDate'])) {
                    $sec = (int) floor(((float) $full['internalDate']) / 1000.0);
                    if ($sec > 946684800) {
                        $createdAtIso = gmdate('c', $sec);
                    }
                }

                $id = 'msg-' . bin2hex(random_bytes(5));
                $ins = $this->db->prepare("
                    INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, body_html, body_format, snippet, gmail_message_id, folder, is_read, created_at)
                    VALUES (:id, :tid, :sname, :semail, :remail, :cc, :subject, :body, :bhtml, :bfmt, :snip, :gid, :folder, :is_read, :cat)
                ");
                $ins->execute([
                    ':id'      => $id,
                    ':tid'     => $threadId,
                    ':sname'   => SecurityHelper::sanitizePlainText($fromName, 191),
                    ':semail'  => SecurityHelper::sanitizePlainText($fromEmail, 191),
                    ':remail'  => SecurityHelper::sanitizePlainText($toEmail, 191),
                    ':cc'      => $ccEmail !== '' ? SecurityHelper::sanitizePlainText($ccEmail, 191) : null,
                    ':subject' => $subject,
                    ':body'    => SecurityHelper::sanitizePlainText($plainBody, 16000),
                    ':bhtml'   => null,
                    ':bfmt'    => $htmlBody !== '' ? 'html' : 'plain',
                    ':snip'    => $snippet,
                    ':gid'     => $gid,
                    ':folder'  => $folder,
                    ':is_read' => $isRead ? 'true' : 'false',
                    ':cat'     => $createdAtIso,
                ]);

                $cidMap = [];
                foreach ($atts as $a) {
                    $attDbId = 'eatt-' . bin2hex(random_bytes(5));
                    $aIns = $this->db->prepare("
                        INSERT INTO email_attachments (id, email_id, filename, mime_type, size_bytes, data_base64, gmail_attachment_id, content_id, is_inline)
                        VALUES (:id, :eid, :fname, :mime, :sz, :b64, :gaid, :cid, :inl)
                    ");
                    $aIns->execute([
                        ':id'    => $attDbId,
                        ':eid'   => $id,
                        ':fname' => $a['filename'],
                        ':mime'  => $a['mime_type'],
                        ':sz'    => $a['size_bytes'],
                        ':b64'   => $a['data_base64'],
                        ':gaid'  => $a['gmail_att_id'],
                        ':cid'   => $a['content_id'],
                        ':inl'   => !empty($a['is_inline']) ? 'true' : 'false',
                    ]);
                    if (!empty($a['content_id'])) {
                        $cidMap[strtolower((string) $a['content_id'])] = $attDbId;
                    }
                }

                if ($htmlBody !== '') {
                    $resolvedHtml = $this->resolveCidImages($htmlBody, $cidMap);
                    $cleanHtml = SecurityHelper::sanitizeEmailHtml($resolvedHtml, 300000);
                    if ($cleanHtml !== '') {
                        $upHtml = $this->db->prepare('UPDATE email_messages SET body_html = :bh WHERE id = :id');
                        $upHtml->execute([':bh' => $cleanHtml, ':id' => $id]);
                    }
                }

                if ($folder === 'inbox' && !$isRead && $fromEmail !== strtolower((string) ($gmailEmail ?? '')) && $fromEmail !== strtolower((string) $user['email'])) {
                    $newMessages[] = [
                        'id'           => $id,
                        'thread_id'    => $threadId,
                        'sender_name'  => SecurityHelper::sanitizePlainText($fromName, 120),
                        'sender_email' => SecurityHelper::sanitizePlainText($fromEmail, 120),
                        'subject'      => $subject,
                        'snippet'      => mb_substr($snippet ?: $plainBody, 0, 120, 'UTF-8'),
                        'created_at'   => $createdAtIso,
                    ];
                }

                $syncedCount++;
            }
        } else {
            $stmt = $this->db->prepare('UPDATE staff_connected_mailboxes SET last_synced_at = NOW(), updated_at = NOW() WHERE user_id = :uid');
            $stmt->execute([':uid' => $user['id']]);
        }

        if ($syncedCount > 0) {
            $this->invalidate();
        }

        return [
            'connected'    => true,
            'gmail_email'  => $gmailEmail,
            'synced_at'    => gmdate('c'),
            'imported'     => $syncedCount,
            'new_messages' => $newMessages,
            'throttled'    => false,
        ];
    }

    private function resolveCidImages(string $html, array $cidMap): string
    {
        if (empty($cidMap) || !str_contains(strtolower($html), 'cid:')) {
            return $html;
        }
        return preg_replace_callback('/(["\'])\s*cid:([^"\'\s>]+)\s*\1/i', static function (array $m) use ($cidMap): string {
            $rawCid = urldecode(trim($m[2], " <>"));
            $key = strtolower($rawCid);
            if (isset($cidMap[$key])) {
                return $m[1] . '/api/v1/email/attachments/' . rawurlencode($cidMap[$key]) . '?inline=1' . $m[1];
            }
            return $m[0];
        }, $html) ?? $html;
    }

    private function syncMailbox(array $user): void
    {
        $res = $this->performGmailSync($user, true, 25);
        if (empty($res['connected']) && !empty($res['error'])) {
            http_response_code(409);
            echo json_encode(['error' => $res['error']]);
            return;
        }
        echo json_encode([
            'success'      => true,
            'synced_at'    => $res['synced_at'],
            'gmail_email'  => $res['gmail_email'],
            'imported'     => $res['imported'],
            'new_messages' => $res['new_messages'],
        ]);
    }

    private function getHiddenAdminEmails(array $user): array
    {
        if (($user['role'] ?? '') === 'admin') {
            return [];
        }
        $lookup = [];
        try {
            $stmt = $this->db->query("
                SELECT LOWER(email) FROM users WHERE role = 'admin'
                UNION
                SELECT LOWER(m.email) FROM staff_connected_mailboxes m JOIN users u ON m.user_id = u.id WHERE u.role = 'admin'
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $ae) {
                $clean = strtolower(trim((string) $ae));
                if ($clean !== '') {
                    $lookup[$clean] = true;
                }
            }
        } catch (Throwable) {
        }
        return $lookup;
    }

    private function messageInvolvesAdmin(array $row, array $adminEmails): bool
    {
        if (empty($adminEmails)) {
            return false;
        }
        $sender = strtolower(trim((string) ($row['sender_email'] ?? '')));
        $recip = strtolower(trim((string) ($row['recipient_email'] ?? '')));
        if (isset($adminEmails[$sender]) || isset($adminEmails[$recip])) {
            return true;
        }
        $cc = strtolower(trim((string) ($row['cc_email'] ?? '')));
        if ($cc !== '') {
            foreach (array_keys($adminEmails) as $ae) {
                if (str_contains($cc, $ae)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function setThreadReadState(string $threadId, array $user): void
    {
        $cleanTid = SecurityHelper::sanitizePlainText($threadId, 64);
        if ($cleanTid === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid thread id']);
            return;
        }
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $isRead = array_key_exists('is_read', $input) ? (bool) $input['is_read'] : true;

        $mbStmt = $this->db->prepare('SELECT email FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $linkedEmail = (string) ($mbStmt->fetchColumn() ?: $user['email']);

        $stmt = $this->db->prepare("
            UPDATE email_messages
            SET is_read = :read_flag
            WHERE thread_id = :tid
              AND (LOWER(recipient_email) IN (LOWER(:e1), LOWER(:l1)) OR LOWER(COALESCE(cc_email, '')) LIKE LOWER(:c1))
        ");
        $stmt->execute([
            ':read_flag' => $isRead ? 'true' : 'false',
            ':tid'       => $cleanTid,
            ':e1'        => (string) $user['email'],
            ':l1'        => $linkedEmail,
            ':c1'        => '%' . $linkedEmail . '%',
        ]);
        $this->invalidate();
        echo json_encode(['success' => true, 'thread_id' => $cleanTid, 'is_read' => $isRead]);
    }

    private function liveState(array $user): void
    {
        $shouldSync = ($_GET['sync'] ?? '1') === '1';
        $summaryOnly = ($_GET['summary_only'] ?? '0') === '1';
        $folder = SecurityHelper::sanitizePlainText($_GET['folder'] ?? 'all', 16);
        $query = trim(SecurityHelper::sanitizePlainText($_GET['q'] ?? '', 120));
        $selectedThread = SecurityHelper::sanitizePlainText($_GET['thread'] ?? '', 64);
        $markRead = ($_GET['mark_read'] ?? '1') === '1';
        $keepThreadsRaw = (string) ($_GET['keep_threads'] ?? '');
        $keepThreadSet = [];
        if ($folder === 'unread' && $keepThreadsRaw !== '') {
            foreach (explode(',', $keepThreadsRaw) as $kt) {
                $cleanKt = SecurityHelper::sanitizePlainText($kt, 64);
                if ($cleanKt !== '') {
                    $keepThreadSet[$cleanKt] = true;
                }
            }
        }

        $syncRes = ['imported' => 0, 'new_messages' => [], 'synced_at' => null];
        if ($shouldSync) {
            $syncRes = $this->performGmailSync($user, false, 20);
        }

        $mbStmt = $this->db->prepare("SELECT email, oauth_status, last_synced_at FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1");
        $mbStmt->execute([':uid' => $user['id']]);
        $mailbox = $mbStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $connected = $mailbox && $mailbox['oauth_status'] === 'active';

        $email = (string) $user['email'];
        $linkedEmail = ($mailbox && !empty($mailbox['email'])) ? (string) $mailbox['email'] : $email;
        $adminEmails = $this->getHiddenAdminEmails($user);

        // Fetch all visible messages summary scoped to user
        if (($user['role'] ?? '') === 'admin' && empty($mailbox)) {
            $allStmt = $this->db->query("
                SELECT DISTINCT ON (m.thread_id)
                       m.id, m.thread_id, m.subject, m.sender_name, m.sender_email, m.recipient_email, m.cc_email,
                       m.body, m.snippet, m.folder, m.is_read, m.created_at,
                       (SELECT COUNT(*)::INT FROM email_messages m2 WHERE m2.thread_id = m.thread_id) AS msg_count,
                       (SELECT COUNT(*)::INT FROM email_attachments ea JOIN email_messages m3 ON ea.email_id = m3.id WHERE m3.thread_id = m.thread_id AND (ea.is_inline = FALSE OR ea.size_bytes >= 1024)) AS att_count
                FROM email_messages m
                ORDER BY m.thread_id, m.created_at DESC, m.id DESC
            ");
        } else {
            $allStmt = $this->db->prepare("
                SELECT DISTINCT ON (m.thread_id)
                       m.id, m.thread_id, m.subject, m.sender_name, m.sender_email, m.recipient_email, m.cc_email,
                       m.body, m.snippet, m.folder, m.is_read, m.created_at,
                       (SELECT COUNT(*)::INT FROM email_messages m2 WHERE m2.thread_id = m.thread_id) AS msg_count,
                       (SELECT COUNT(*)::INT FROM email_attachments ea JOIN email_messages m3 ON ea.email_id = m3.id WHERE m3.thread_id = m.thread_id AND (ea.is_inline = FALSE OR ea.size_bytes >= 1024)) AS att_count
                FROM email_messages m
                WHERE (
                    LOWER(m.sender_email) IN (LOWER(:e1), LOWER(:l1))
                    OR LOWER(m.recipient_email) IN (LOWER(:e2), LOWER(:l2))
                    OR LOWER(COALESCE(m.cc_email, '')) LIKE LOWER(:c1)
                )
                ORDER BY m.thread_id, m.created_at DESC, m.id DESC
            ");
            $allStmt->execute([
                ':e1' => $email,
                ':l1' => $linkedEmail,
                ':e2' => $email,
                ':l2' => $linkedEmail,
                ':c1' => '%' . $linkedEmail . '%',
            ]);
        }

        $allThreads = $allStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($adminEmails)) {
            $allThreads = array_values(array_filter($allThreads, fn(array $row): bool => !$this->messageInvolvesAdmin($row, $adminEmails)));
        }

        // Compute unread email count and check incoming unread
        $unreadCount = 0;
        $selfLow = strtolower($email);
        $linkLow = strtolower($linkedEmail);
        foreach ($allThreads as &$thRow) {
            $sLow = strtolower((string) ($thRow['sender_email'] ?? ''));
            $isForMe = $sLow !== $selfLow && $sLow !== $linkLow;
            $thRow['is_unread'] = !$thRow['is_read'] && $isForMe;
            if ($thRow['is_unread']) {
                $unreadCount++;
            }
            if (empty($thRow['snippet'])) {
                $thRow['snippet'] = mb_substr(trim(strip_tags((string) ($thRow['body'] ?? ''))), 0, 140, 'UTF-8');
            }
            unset($thRow['body']);
        }
        unset($thRow);

        usort($allThreads, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return ($tb <=> $ta) ?: strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });

        if ($summaryOnly) {
            echo json_encode([
                'connected'    => (bool) $connected,
                'gmail_email'  => $connected ? $mailbox['email'] : null,
                'last_synced'  => $mailbox['last_synced_at'] ?? $syncRes['synced_at'],
                'imported'     => (int) ($syncRes['imported'] ?? 0),
                'unread_count' => $unreadCount,
                'new_messages' => $syncRes['new_messages'] ?? [],
                'latest_unread' => array_values(array_slice(array_filter($allThreads, static fn(array $t): bool => !empty($t['is_unread'])), 0, 3)),
            ]);
            return;
        }

        $threads = array_values(array_filter($allThreads, static function (array $th) use ($folder, $query, $keepThreadSet): bool {
            $tid = (string) ($th['thread_id'] ?? '');
            if ($folder === 'inbox' && ($th['folder'] ?? '') !== 'inbox') {
                return false;
            }
            if ($folder === 'sent' && ($th['folder'] ?? '') !== 'sent') {
                return false;
            }
            if ($folder === 'unread' && empty($th['is_unread']) && !isset($keepThreadSet[$tid])) {
                return false;
            }
            if ($query !== '') {
                $hay = strtolower(($th['sender_name'] ?? '') . ' ' . ($th['sender_email'] ?? '') . ' ' . ($th['subject'] ?? '') . ' ' . ($th['snippet'] ?? ''));
                if (!str_contains($hay, strtolower($query))) {
                    return false;
                }
            }
            return true;
        }));

        if ($selectedThread === '' && !empty($threads)) {
            $selectedThread = (string) $threads[0]['thread_id'];
        }

        $threadMessages = [];
        $attachmentsByMsg = [];
        if ($selectedThread !== '') {
            $mStmt = $this->db->prepare('SELECT id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, body_html, body_format, snippet, folder, is_read, created_at FROM email_messages WHERE thread_id = :tid ORDER BY created_at ASC, id ASC');
            $mStmt->execute([':tid' => $selectedThread]);
            $threadMessages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($adminEmails)) {
                $threadMessages = array_values(array_filter($threadMessages, fn(array $m): bool => !$this->messageInvolvesAdmin($m, $adminEmails)));
            }

            if (!empty($threadMessages)) {
                if ($markRead) {
                    $mk = $this->db->prepare('UPDATE email_messages SET is_read = TRUE WHERE thread_id = :tid AND is_read = FALSE AND (LOWER(recipient_email) IN (LOWER(:e1), LOWER(:l1)) OR LOWER(COALESCE(cc_email, \'\')) LIKE LOWER(:c1))');
                    $mk->execute([':tid' => $selectedThread, ':e1' => $email, ':l1' => $linkedEmail, ':c1' => '%' . $linkedEmail . '%']);
                    if ($mk->rowCount() > 0) {
                        $this->invalidate();
                        foreach ($threads as &$tItem) {
                            if ($tItem['thread_id'] === $selectedThread && !empty($tItem['is_unread'])) {
                                $tItem['is_unread'] = false;
                                $tItem['is_read'] = true;
                                $unreadCount = max(0, $unreadCount - 1);
                            }
                        }
                        unset($tItem);
                    }
                }

                $msgIds = array_column($threadMessages, 'id');
                if (!empty($msgIds)) {
                    $ph = implode(',', array_fill(0, count($msgIds), '?'));
                    $aStmt = $this->db->prepare("SELECT id, email_id, filename, mime_type, size_bytes, content_id, is_inline FROM email_attachments WHERE email_id IN ({$ph}) ORDER BY created_at ASC");
                    $aStmt->execute($msgIds);
                    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
                        $attachmentsByMsg[$att['email_id']][] = $att;
                    }
                }
            }
        }

        echo json_encode([
            'connected'       => (bool) $connected,
            'gmail_email'     => $connected ? $mailbox['email'] : null,
            'last_synced'     => $mailbox['last_synced_at'] ?? $syncRes['synced_at'],
            'imported'        => (int) ($syncRes['imported'] ?? 0),
            'unread_count'    => $unreadCount,
            'total_threads'   => count($allThreads),
            'threads'         => $threads,
            'selected_thread' => $selectedThread,
            'messages'        => $threadMessages,
            'attachments'     => $attachmentsByMsg,
            'new_messages'    => $syncRes['new_messages'] ?? [],
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

        $adminEmails = $this->getHiddenAdminEmails($user);
        if (!empty($adminEmails) && $this->messageInvolvesAdmin($att, $adminEmails)) {
            http_response_code(403);
            echo json_encode(['error' => 'Attachment unavailable']);
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
                    if ($bytes !== '' && strlen($bytes) <= 4194304) {
                        try {
                            $cacheStmt = $this->db->prepare('UPDATE email_attachments SET data_base64 = :b64 WHERE id = :id');
                            $cacheStmt->execute([':b64' => base64_encode($bytes), ':id' => $attId]);
                        } catch (Throwable) {
                        }
                    }
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
        $mime = (string) ($att['mime_type'] ?: 'application/octet-stream');
        $isInlineReq = ($_GET['inline'] ?? '') === '1' && str_starts_with(strtolower($mime), 'image/');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($isInlineReq ? 'inline' : 'attachment') . '; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, max-age=86400');
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
