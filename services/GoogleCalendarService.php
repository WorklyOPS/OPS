<?php
/**
 * Native PHP Google Calendar Sync Service (OAuth 2.0 + Google Calendar API v3)
 * Uses PostgreSQL encrypted OAuth tokens from staff_connected_mailboxes (zero Firebase).
 * Automatically creates, updates, and deletes Google Calendar events for both
 * the assigned VA and associated Team Leader on Meetings and Task deadlines.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/GmailOAuthService.php';

final class GoogleCalendarService
{
    private const CALENDAR_EVENTS_BASE = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
    private static bool $schemaChecked = false;

    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS access_token_encrypted TEXT DEFAULT NULL');
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS refresh_token_encrypted TEXT DEFAULT NULL');
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_expires_at TIMESTAMPTZ DEFAULT NULL');
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS token_type VARCHAR(32) DEFAULT NULL');
            $db->exec("CREATE TABLE IF NOT EXISTS gmail_oauth_states (state_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE, return_to VARCHAR(64) NOT NULL DEFAULT '/email', expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
            $db->exec("ALTER TABLE gmail_oauth_states ADD COLUMN IF NOT EXISTS return_to VARCHAR(64) NOT NULL DEFAULT '/email'");
            $db->exec("
                CREATE TABLE IF NOT EXISTS google_calendar_event_links (
                    id VARCHAR(64) PRIMARY KEY,
                    entity_type VARCHAR(16) NOT NULL CHECK (entity_type IN ('meeting', 'task')),
                    entity_id VARCHAR(64) NOT NULL,
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    google_calendar_id VARCHAR(191) NOT NULL DEFAULT 'primary',
                    google_event_id VARCHAR(255) NOT NULL,
                    google_html_link VARCHAR(512) DEFAULT NULL,
                    last_synced_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    CONSTRAINT uk_gcal_entity_user UNIQUE (entity_type, entity_id, user_id)
                )
            ");
            $db->exec('CREATE INDEX IF NOT EXISTS idx_gcal_links_entity ON google_calendar_event_links (entity_type, entity_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_gcal_links_user ON google_calendar_event_links (user_id)');
        } catch (Throwable) {
            // Schema already aligned or read-only DDL
        }
    }

    public static function hasCalendarScope(?string $scopeString): bool
    {
        return $scopeString !== null && str_contains($scopeString, 'calendar');
    }

    /** Check whether a specific Workly user has an active Google connection with Calendar permissions. */
    public static function getUserCalendarStatus(PDO $db, string $userId): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare("
                SELECT email, oauth_status, oauth_scope, refresh_token_encrypted, last_synced_at
                FROM staff_connected_mailboxes
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || ($row['oauth_status'] ?? '') !== 'active' || empty($row['refresh_token_encrypted'])) {
                return [
                    'connected'       => false,
                    'needs_reconnect' => false,
                    'email'           => null,
                    'last_synced_at'  => null,
                ];
            }

            $hasScope = self::hasCalendarScope((string) ($row['oauth_scope'] ?? ''));
            return [
                'connected'       => $hasScope,
                'needs_reconnect' => !$hasScope,
                'email'           => (string) $row['email'],
                'last_synced_at'  => $row['last_synced_at'] ?? null,
            ];
        } catch (Throwable) {
            return [
                'connected'       => false,
                'needs_reconnect' => false,
                'email'           => null,
                'last_synced_at'  => null,
            ];
        }
    }

    /** Return list of meeting/task IDs already linked to the given user's Google Calendar. */
    public static function getLinkedEntityIdsForUser(PDO $db, string $userId, string $entityType): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare('SELECT entity_id FROM google_calendar_event_links WHERE user_id = :uid AND entity_type = :etype');
            $stmt->execute([':uid' => $userId, ':etype' => $entityType]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_filter(array_map('strval', $ids ?: [])));
        } catch (Throwable) {
            return [];
        }
    }

    /** Retrieve or refresh a valid Google OAuth 2.0 Bearer token for any user with Calendar scope. */
    public static function getValidAccessTokenForUser(PDO $db, string $userId): string
    {
        if ($userId === '') {
            return '';
        }
        self::ensureSchema($db);

        try {
            $stmt = $db->prepare("
                SELECT email, oauth_status, oauth_scope, access_token_encrypted, refresh_token_encrypted, token_expires_at
                FROM staff_connected_mailboxes
                WHERE user_id = :uid AND oauth_status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mailbox || !self::hasCalendarScope((string) ($mailbox['oauth_scope'] ?? ''))) {
                return '';
            }

            $accessToken = GmailOAuthService::decrypt((string) ($mailbox['access_token_encrypted'] ?? ''));
            $refreshToken = GmailOAuthService::decrypt((string) ($mailbox['refresh_token_encrypted'] ?? ''));
            $expiresAt = !empty($mailbox['token_expires_at']) ? strtotime((string) $mailbox['token_expires_at']) : 0;

            if ($accessToken !== '' && $expiresAt > time() + 60) {
                return $accessToken;
            }
            if ($refreshToken === '') {
                return '';
            }

            $token = GmailOAuthService::refreshAccessToken($refreshToken);
            $newAccessToken = (string) ($token['access_token'] ?? '');
            if ($newAccessToken === '') {
                return '';
            }

            $newRefreshToken = (string) ($token['refresh_token'] ?? $refreshToken);
            $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600));
            $upd = $db->prepare("
                UPDATE staff_connected_mailboxes
                SET access_token_encrypted = :access_token,
                    refresh_token_encrypted = :refresh_token,
                    token_expires_at = NOW() + (CAST(:expires_in AS INTEGER) * INTERVAL '1 second'),
                    token_type = :token_type,
                    updated_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([
                ':access_token'  => GmailOAuthService::encrypt($newAccessToken),
                ':refresh_token' => GmailOAuthService::encrypt($newRefreshToken),
                ':expires_in'    => $expiresIn,
                ':token_type'    => (string) ($token['token_type'] ?? 'Bearer'),
                ':uid'           => $userId,
            ]);

            return $newAccessToken;
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            error_log('Workly Google Calendar token error for ' . $userId . ': ' . $e->getMessage());
            if (str_contains($msg, 'invalid_grant') || str_contains($msg, 'revoked')) {
                try {
                    $db->prepare("UPDATE staff_connected_mailboxes SET oauth_status = 'needs_auth', updated_at = NOW() WHERE user_id = :uid")
                        ->execute([':uid' => $userId]);
                } catch (Throwable) {
                }
            }
            return '';
        }
    }

    /** Sync a Workly meeting / focus block into Google Calendar for both the VA and associated Team Leader. */
    public static function syncMeeting(PDO $db, string $meetingId): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare("
                SELECT m.*,
                       va.name AS va_name, va.email AS va_email, va.team_leader_id AS va_tl_id,
                       cr.name AS creator_name, cr.email AS creator_email, cr.role AS creator_role,
                       c.agency_name AS client_name, c.email AS client_email
                FROM calendar_meetings m
                JOIN users va ON m.va_id = va.id
                LEFT JOIN users cr ON m.created_by_tl_id = cr.id
                LEFT JOIN clients c ON m.client_id = c.id
                WHERE m.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $meetingId]);
            $m = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$m) {
                return ['synced_count' => 0, 'synced_users' => [], 'skipped_count' => 0];
            }

            $targetUserIds = array_values(array_unique(array_filter([
                (string) ($m['va_id'] ?? ''),
                (string) ($m['created_by_tl_id'] ?? ''),
                (string) ($m['va_tl_id'] ?? ''),
            ], static fn (string $id): bool => $id !== '')));

            $dtStart = new DateTimeImmutable((string) $m['start_time']);
            $dtEnd = new DateTimeImmutable((string) $m['end_time']);
            $clientName = trim((string) ($m['client_name'] ?? ''));
            $typeLabel = ucwords(str_replace('_', ' ', (string) ($m['meeting_type'] ?? 'team_sync')));

            $descLines = [
                'Workly Schedule — ' . $typeLabel,
                'Assigned Staff: ' . (string) ($m['va_name'] ?? ''),
            ];
            if ($clientName !== '') {
                $descLines[] = 'Client: ' . $clientName;
            }
            if (!empty($m['meeting_link'])) {
                $descLines[] = 'Meeting Link: ' . (string) $m['meeting_link'];
            }
            if (!empty($m['notes'])) {
                $descLines[] = '';
                $descLines[] = trim((string) $m['notes']);
            }
            $appUrl = rtrim(Secrets::get('APP_URL'), '/');
            if ($appUrl !== '') {
                $descLines[] = '';
                $descLines[] = 'Open in Workly: ' . $appUrl . '/calendar-reports?tab=calendar';
            }

            $basePayload = [
                'summary'     => ($clientName !== '' ? "[{$clientName}] " : '') . (string) $m['title'],
                'description' => implode("\n", $descLines),
                'start'       => ['dateTime' => $dtStart->format(DateTimeInterface::RFC3339)],
                'end'         => ['dateTime' => $dtEnd->format(DateTimeInterface::RFC3339)],
                'reminders'   => ['useDefault' => true],
            ];
            if (!empty($m['meeting_link'])) {
                $basePayload['location'] = (string) $m['meeting_link'];
            }

            $includeClient = filter_var($m['include_client'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $clientEmail = trim((string) ($m['client_email'] ?? ''));

            $syncedUsers = [];
            $skippedCount = 0;
            foreach ($targetUserIds as $idx => $targetUid) {
                $payload = $basePayload;
                if ($idx === 0 && $includeClient && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                    $payload['attendees'] = [[
                        'email'       => $clientEmail,
                        'displayName' => $clientName !== '' ? $clientName : $clientEmail,
                    ]];
                }
                $res = self::upsertUserEvent($db, 'meeting', $meetingId, $targetUid, $payload);
                if ($res !== null) {
                    $syncedUsers[] = $res;
                } else {
                    $skippedCount++;
                }
            }

            try {
                $db->prepare('UPDATE calendar_meetings SET google_synced = :gs WHERE id = :id')
                    ->execute([':gs' => count($syncedUsers) > 0 ? 'true' : 'false', ':id' => $meetingId]);
            } catch (Throwable) {
            }

            return [
                'synced_count'  => count($syncedUsers),
                'synced_users'  => $syncedUsers,
                'skipped_count' => $skippedCount,
            ];
        } catch (Throwable $e) {
            error_log('Workly syncMeeting error: ' . $e->getMessage());
            return ['synced_count' => 0, 'synced_users' => [], 'skipped_count' => 0];
        }
    }

    /** Sync a Workly task deadline into Google Calendar for the assigned VA and associated Team Leader. */
    public static function syncTask(PDO $db, string $taskId): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare("
                SELECT t.*,
                       va.name AS va_name, va.email AS va_email, va.team_leader_id AS va_tl_id,
                       c.agency_name AS client_name
                FROM tasks t
                JOIN users va ON t.assigned_va_id = va.id
                LEFT JOIN clients c ON t.client_id = c.id
                WHERE t.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $taskId]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t || empty($t['deadline'])) {
                return ['synced_count' => 0, 'synced_users' => [], 'skipped_count' => 0];
            }

            $deadline = substr((string) $t['deadline'], 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
                return ['synced_count' => 0, 'synced_users' => [], 'skipped_count' => 0];
            }

            $targetUserIds = array_values(array_unique(array_filter([
                (string) ($t['assigned_va_id'] ?? ''),
                (string) ($t['va_tl_id'] ?? ''),
                in_array((string) ($t['creator_role'] ?? ''), ['team_leader', 'admin'], true)
                    ? (string) ($t['created_by_id'] ?? '')
                    : '',
            ], static fn (string $id): bool => $id !== '')));

            self::pruneRemovedParticipants($db, 'task', $taskId, $targetUserIds);

            $endDate = (new DateTimeImmutable($deadline))->modify('+1 day')->format('Y-m-d');
            $clientName = trim((string) ($t['client_name'] ?? ''));
            $statusLabel = ucwords(str_replace('_', ' ', (string) ($t['status'] ?? 'todo')));
            $priorityLabel = ucfirst((string) ($t['priority'] ?? 'medium'));
            $isDone = ($t['status'] ?? '') === 'completed';

            $descLines = [
                'Workly Deliverable Due',
                'Status: ' . $statusLabel . ' | Priority: ' . $priorityLabel,
                'Assigned VA: ' . (string) ($t['va_name'] ?? ''),
            ];
            if ($clientName !== '') {
                $descLines[] = 'Client: ' . $clientName;
            }
            if (isset($t['estimated_hours'])) {
                $descLines[] = 'Estimated Hours: ' . number_format((float) $t['estimated_hours'], 1) . 'h';
            }
            if (!empty($t['description'])) {
                $descLines[] = '';
                $descLines[] = trim((string) $t['description']);
            }
            $appUrl = rtrim(Secrets::get('APP_URL'), '/');
            if ($appUrl !== '') {
                $descLines[] = '';
                $descLines[] = 'Open Task in Workly: ' . $appUrl . '/tasks?open_task=' . rawurlencode($taskId);
            }

            $prefix = $isDone ? '[Done] ' : '[Task Due] ';
            $payload = [
                'summary'      => $prefix . (string) $t['title'] . ($clientName !== '' ? " · {$clientName}" : ''),
                'description'  => implode("\n", $descLines),
                'start'        => ['date' => $deadline],
                'end'          => ['date' => $endDate],
                'transparency' => 'transparent',
                'reminders'    => ['useDefault' => true],
            ];

            $syncedUsers = [];
            $skippedCount = 0;
            foreach ($targetUserIds as $targetUid) {
                $res = self::upsertUserEvent($db, 'task', $taskId, $targetUid, $payload);
                if ($res !== null) {
                    $syncedUsers[] = $res;
                } else {
                    $skippedCount++;
                }
            }

            return [
                'synced_count'  => count($syncedUsers),
                'synced_users'  => $syncedUsers,
                'skipped_count' => $skippedCount,
            ];
        } catch (Throwable $e) {
            error_log('Workly syncTask error: ' . $e->getMessage());
            return ['synced_count' => 0, 'synced_users' => [], 'skipped_count' => 0];
        }
    }

    /** Delete Google Calendar events for all synced participants of a meeting or task. */
    public static function deleteEntityEvents(PDO $db, string $entityType, string $entityId): void
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare('SELECT id, user_id, google_event_id FROM google_calendar_event_links WHERE entity_type = :etype AND entity_id = :eid');
            $stmt->execute([':etype' => $entityType, ':eid' => $entityId]);
            $links = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($links as $lnk) {
                $token = self::getValidAccessTokenForUser($db, (string) $lnk['user_id']);
                if ($token !== '' && !empty($lnk['google_event_id'])) {
                    $url = self::CALENDAR_EVENTS_BASE . '/' . rawurlencode((string) $lnk['google_event_id']) . '?sendUpdates=none';
                    self::calendarHttp('DELETE', $url, $token);
                }
            }

            $db->prepare('DELETE FROM google_calendar_event_links WHERE entity_type = :etype AND entity_id = :eid')
                ->execute([':etype' => $entityType, ':eid' => $entityId]);
        } catch (Throwable $e) {
            error_log('Workly deleteEntityEvents error: ' . $e->getMessage());
        }
    }

    /** Manual sync helper for the connected user's visible month meetings & active tasks. */
    public static function syncUpcomingForUser(PDO $db, string $userId, ?string $month = null): array
    {
        self::ensureSchema($db);
        $status = self::getUserCalendarStatus($db, $userId);
        if (empty($status['connected'])) {
            throw new RuntimeException(
                !empty($status['needs_reconnect'])
                    ? 'Reconnect Google Calendar to grant calendar permissions.'
                    : 'Connect Google Calendar first to sync your events.'
            );
        }

        $rangeStart = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? $month . '-01'
            : date('Y-m-d', strtotime('-7 days'));

        $mStmt = $db->prepare("
            SELECT id
            FROM calendar_meetings
            WHERE (va_id = :u1 OR created_by_tl_id = :u2)
              AND start_time::DATE >= :rs
            ORDER BY start_time ASC
            LIMIT 30
        ");
        $mStmt->execute([':u1' => $userId, ':u2' => $userId, ':rs' => $rangeStart]);
        $meetingIds = $mStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $meetingsSynced = 0;
        foreach ($meetingIds as $mid) {
            $res = self::syncMeeting($db, (string) $mid);
            if (($res['synced_count'] ?? 0) > 0) {
                $meetingsSynced++;
            }
        }

        $tStmt = $db->prepare("
            SELECT t.id
            FROM tasks t
            JOIN users va ON t.assigned_va_id = va.id
            WHERE (t.assigned_va_id = :u1 OR va.team_leader_id = :u2 OR t.created_by_id = :u3)
              AND t.deadline >= :rs
            ORDER BY t.deadline ASC
            LIMIT 30
        ");
        $tStmt->execute([':u1' => $userId, ':u2' => $userId, ':u3' => $userId, ':rs' => $rangeStart]);
        $taskIds = $tStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $tasksSynced = 0;
        foreach ($taskIds as $tid) {
            $res = self::syncTask($db, (string) $tid);
            if (($res['synced_count'] ?? 0) > 0) {
                $tasksSynced++;
            }
        }

        return [
            'meetings_synced' => $meetingsSynced,
            'tasks_synced'    => $tasksSynced,
        ];
    }

    private static function pruneRemovedParticipants(PDO $db, string $entityType, string $entityId, array $keepUserIds): void
    {
        try {
            $stmt = $db->prepare('SELECT id, user_id, google_event_id FROM google_calendar_event_links WHERE entity_type = :etype AND entity_id = :eid');
            $stmt->execute([':etype' => $entityType, ':eid' => $entityId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                if (in_array((string) $r['user_id'], $keepUserIds, true)) {
                    continue;
                }
                $token = self::getValidAccessTokenForUser($db, (string) $r['user_id']);
                if ($token !== '' && !empty($r['google_event_id'])) {
                    $url = self::CALENDAR_EVENTS_BASE . '/' . rawurlencode((string) $r['google_event_id']) . '?sendUpdates=none';
                    self::calendarHttp('DELETE', $url, $token);
                }
                $db->prepare('DELETE FROM google_calendar_event_links WHERE id = :id')->execute([':id' => $r['id']]);
            }
        } catch (Throwable) {
        }
    }

    private static function upsertUserEvent(PDO $db, string $entityType, string $entityId, string $userId, array $payload): ?array
    {
        $token = self::getValidAccessTokenForUser($db, $userId);
        if ($token === '') {
            return null;
        }

        $linkStmt = $db->prepare('SELECT id, google_event_id FROM google_calendar_event_links WHERE entity_type = :etype AND entity_id = :eid AND user_id = :uid LIMIT 1');
        $linkStmt->execute([':etype' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        $existing = $linkStmt->fetch(PDO::FETCH_ASSOC);

        $eventData = null;
        if ($existing && !empty($existing['google_event_id'])) {
            $patchUrl = self::CALENDAR_EVENTS_BASE . '/' . rawurlencode((string) $existing['google_event_id']) . '?sendUpdates=none';
            $resp = self::calendarHttp('PATCH', $patchUrl, $token, $payload);
            if ($resp['status'] >= 200 && $resp['status'] < 300 && !empty($resp['data']['id'])) {
                $eventData = $resp['data'];
            } elseif (!in_array($resp['status'], [404, 410], true)) {
                error_log('Workly Google Calendar PATCH failed (' . $resp['status'] . '): ' . $resp['error']);
                return null;
            }
        }

        if ($eventData === null) {
            $postUrl = self::CALENDAR_EVENTS_BASE . '?sendUpdates=none';
            $resp = self::calendarHttp('POST', $postUrl, $token, $payload);
            if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['data']['id'])) {
                error_log('Workly Google Calendar POST failed (' . $resp['status'] . '): ' . $resp['error']);
                return null;
            }
            $eventData = $resp['data'];
        }

        $linkId = ($existing && !empty($existing['id'])) ? (string) $existing['id'] : ('gcal-' . bin2hex(random_bytes(5)));
        $upStmt = $db->prepare("
            INSERT INTO google_calendar_event_links (id, entity_type, entity_id, user_id, google_calendar_id, google_event_id, google_html_link, last_synced_at)
            VALUES (:id, :etype, :eid, :uid, 'primary', :geid, :hlink, NOW())
            ON CONFLICT (entity_type, entity_id, user_id) DO UPDATE SET
                google_event_id = EXCLUDED.google_event_id,
                google_html_link = EXCLUDED.google_html_link,
                last_synced_at = NOW()
        ");
        $upStmt->execute([
            ':id'    => $linkId,
            ':etype' => $entityType,
            ':eid'   => $entityId,
            ':uid'   => $userId,
            ':geid'  => (string) $eventData['id'],
            ':hlink' => !empty($eventData['htmlLink']) ? (string) $eventData['htmlLink'] : null,
        ]);

        return [
            'user_id'         => $userId,
            'google_event_id' => (string) $eventData['id'],
            'html_link'       => !empty($eventData['htmlLink']) ? (string) $eventData['htmlLink'] : null,
        ];
    }

    private static function calendarHttp(string $method, string $url, string $token, ?array $body = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'cURL extension missing'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'data' => null, 'error' => 'Unable to initialize cURL'];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw)) {
            return ['status' => $status, 'data' => null, 'error' => $curlErr !== '' ? $curlErr : 'Unknown cURL error'];
        }

        $decoded = json_decode($raw, true);
        $errMsg = '';
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $errMsg = (string) $decoded['error']['message'];
        } elseif ($status >= 400) {
            $errMsg = substr($raw, 0, 240);
        }

        return [
            'status' => $status,
            'data'   => is_array($decoded) ? $decoded : null,
            'error'  => $errMsg,
        ];
    }
}
