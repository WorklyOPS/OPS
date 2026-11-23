<?php
/**
 * Native PHP Bidirectional Google Calendar Sync Service (OAuth 2.0 + Calendar API v3)
 * Uses PostgreSQL encrypted OAuth tokens from staff_connected_mailboxes (zero Firebase).
 * Supports two-way sync:
 *   - Workly -> Google Calendar (Meetings & Task Deadlines for VA + Team Leader)
 *   - Google Calendar -> Workly (Imports new Google events, updates changes, removes deletions)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/GmailOAuthService.php';
require_once __DIR__ . '/SecurityHelper.php';

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
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS calendar_timezone VARCHAR(64) DEFAULT NULL');
            $db->exec('ALTER TABLE staff_connected_mailboxes ADD COLUMN IF NOT EXISTS calendar_last_synced_at TIMESTAMPTZ DEFAULT NULL');

            $db->exec("CREATE TABLE IF NOT EXISTS gmail_oauth_states (state_hash CHAR(64) PRIMARY KEY, user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE, return_to VARCHAR(64) NOT NULL DEFAULT '/email', expires_at TIMESTAMPTZ NOT NULL, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
            $db->exec("ALTER TABLE gmail_oauth_states ADD COLUMN IF NOT EXISTS return_to VARCHAR(64) NOT NULL DEFAULT '/email'");

            $db->exec("ALTER TABLE calendar_meetings ADD COLUMN IF NOT EXISTS external_source VARCHAR(24) NOT NULL DEFAULT 'workly'");
            $db->exec('ALTER TABLE calendar_meetings ADD COLUMN IF NOT EXISTS is_all_day BOOLEAN NOT NULL DEFAULT FALSE');
            $db->exec('ALTER TABLE calendar_meetings DROP CONSTRAINT IF EXISTS calendar_meetings_meeting_type_check');
            $db->exec("ALTER TABLE calendar_meetings ADD CONSTRAINT calendar_meetings_meeting_type_check CHECK (meeting_type IN ('client_meeting', 'team_sync', 'checkpoint', 'review', 'focus_block', 'availability_window', 'google_event'))");

            $db->exec("
                CREATE TABLE IF NOT EXISTS google_calendar_event_links (
                    id VARCHAR(64) PRIMARY KEY,
                    entity_type VARCHAR(16) NOT NULL CHECK (entity_type IN ('meeting', 'task')),
                    entity_id VARCHAR(64) NOT NULL,
                    user_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    google_calendar_id VARCHAR(191) NOT NULL DEFAULT 'primary',
                    google_event_id VARCHAR(255) NOT NULL,
                    google_html_link VARCHAR(512) DEFAULT NULL,
                    source_origin VARCHAR(16) NOT NULL DEFAULT 'workly',
                    last_synced_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    CONSTRAINT uk_gcal_entity_user UNIQUE (entity_type, entity_id, user_id)
                )
            ");
            $db->exec("ALTER TABLE google_calendar_event_links ADD COLUMN IF NOT EXISTS source_origin VARCHAR(16) NOT NULL DEFAULT 'workly'");
            $db->exec('CREATE INDEX IF NOT EXISTS idx_gcal_links_entity ON google_calendar_event_links (entity_type, entity_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_gcal_links_user ON google_calendar_event_links (user_id)');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_gcal_links_geid ON google_calendar_event_links (user_id, google_event_id)');
        } catch (Throwable) {
            // Schema already aligned or read-only DDL
        }
    }

    public static function hasCalendarScope(?string $scopeString): bool
    {
        return $scopeString !== null && str_contains($scopeString, 'calendar');
    }

    /** Resolve the effective IANA timezone for rendering & parsing a user's calendar events. */
    public static function resolveUserTimezone(PDO $db, string $userId = '', ?string $explicitTz = null): DateTimeZone
    {
        $candidates = [];
        if ($explicitTz !== null && trim($explicitTz) !== '') {
            $candidates[] = trim($explicitTz);
        }
        if (!empty($_COOKIE['workly_tz'])) {
            $candidates[] = trim((string) $_COOKIE['workly_tz']);
        }
        if ($userId !== '') {
            try {
                self::ensureSchema($db);
                $stmt = $db->prepare('SELECT calendar_timezone FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
                $stmt->execute([':uid' => $userId]);
                $dbTz = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($dbTz !== '') {
                    $candidates[] = $dbTz;
                }
            } catch (Throwable) {
            }
        }
        $candidates[] = date_default_timezone_get() ?: 'UTC';

        foreach ($candidates as $cand) {
            if ($cand === '' || strlen($cand) > 64) {
                continue;
            }
            try {
                return new DateTimeZone($cand);
            } catch (Throwable) {
                // Try next
            }
        }
        return new DateTimeZone('UTC');
    }

    /** Check whether a specific Workly user has an active Google connection with Calendar permissions. */
    public static function getUserCalendarStatus(PDO $db, string $userId): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare("
                SELECT email, oauth_status, oauth_scope, refresh_token_encrypted, calendar_last_synced_at, calendar_timezone
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
                    'timezone'        => null,
                ];
            }

            $hasScope = self::hasCalendarScope((string) ($row['oauth_scope'] ?? ''));
            return [
                'connected'       => $hasScope,
                'needs_reconnect' => !$hasScope,
                'email'           => (string) $row['email'],
                'last_synced_at'  => $row['calendar_last_synced_at'] ?? null,
                'timezone'        => $row['calendar_timezone'] ?? null,
            ];
        } catch (Throwable) {
            return [
                'connected'       => false,
                'needs_reconnect' => false,
                'email'           => null,
                'last_synced_at'  => null,
                'timezone'        => null,
            ];
        }
    }

    /** Return map of entity_id => google_html_link already linked to the given user's Google Calendar. */
    public static function getLinkedEntityMapForUser(PDO $db, string $userId, string $entityType): array
    {
        self::ensureSchema($db);
        try {
            $stmt = $db->prepare('SELECT entity_id, COALESCE(google_html_link, \'\') AS html_link FROM google_calendar_event_links WHERE user_id = :uid AND entity_type = :etype');
            $stmt->execute([':uid' => $userId, ':etype' => $entityType]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $eid = (string) ($r['entity_id'] ?? '');
                if ($eid !== '') {
                    $map[$eid] = (string) ($r['html_link'] ?? '');
                }
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    public static function getLinkedEntityIdsForUser(PDO $db, string $userId, string $entityType): array
    {
        return array_keys(self::getLinkedEntityMapForUser($db, $userId, $entityType));
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

            $isAllDay = filter_var($m['is_all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $dtStart = new DateTimeImmutable((string) $m['start_time']);
            $dtEnd = new DateTimeImmutable((string) $m['end_time']);
            $clientName = trim((string) ($m['client_name'] ?? ''));
            $typeLabel = ucwords(str_replace('_', ' ', (string) ($m['meeting_type'] ?? 'team_sync')));
            $isGoogleOrigin = (($m['external_source'] ?? 'workly') === 'google');

            $basePayload = [
                'summary'            => ($clientName !== '' ? "[{$clientName}] " : '') . (string) $m['title'],
                'reminders'          => ['useDefault' => true],
                'extendedProperties' => [
                    'private' => [
                        'worklyEntityType' => 'meeting',
                        'worklyEntityId'   => $meetingId,
                    ],
                ],
            ];

            if (!$isGoogleOrigin) {
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
                $basePayload['description'] = implode("\n", $descLines);
            } elseif (!empty($m['notes'])) {
                $basePayload['description'] = trim((string) $m['notes']);
            }

            if ($isAllDay) {
                $sDay = $dtStart->format('Y-m-d');
                $eDay = $dtEnd->modify('+1 day')->format('Y-m-d');
                $basePayload['start'] = ['date' => $sDay];
                $basePayload['end'] = ['date' => $eDay];
            } else {
                $basePayload['start'] = ['dateTime' => $dtStart->format(DateTimeInterface::RFC3339)];
                $basePayload['end'] = ['dateTime' => $dtEnd->format(DateTimeInterface::RFC3339)];
            }

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
                $res = self::upsertUserEvent($db, 'meeting', $meetingId, $targetUid, $payload, $isGoogleOrigin ? 'google' : 'workly');
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
                'summary'            => $prefix . (string) $t['title'] . ($clientName !== '' ? " · {$clientName}" : ''),
                'description'        => implode("\n", $descLines),
                'start'              => ['date' => $deadline],
                'end'                => ['date' => $endDate],
                'transparency'       => 'transparent',
                'reminders'          => ['useDefault' => true],
                'extendedProperties' => [
                    'private' => [
                        'worklyEntityType' => 'task',
                        'worklyEntityId'   => $taskId,
                    ],
                ],
            ];

            $syncedUsers = [];
            $skippedCount = 0;
            foreach ($targetUserIds as $targetUid) {
                $res = self::upsertUserEvent($db, 'task', $taskId, $targetUid, $payload, 'workly');
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

    /** Pull events created, updated, or deleted inside Google Calendar into Workly. */
    public static function pullFromGoogleForUser(
        PDO $db,
        string $userId,
        ?string $timeMinIso = null,
        ?string $timeMaxIso = null,
        bool $force = true
    ): array {
        self::ensureSchema($db);
        $summary = [
            'connected' => false,
            'skipped'   => false,
            'imported'  => 0,
            'updated'   => 0,
            'deleted'   => 0,
        ];

        $status = self::getUserCalendarStatus($db, $userId);
        if (empty($status['connected'])) {
            return $summary;
        }
        $summary['connected'] = true;

        if (!$force && !empty($status['last_synced_at'])) {
            $lastTs = strtotime((string) $status['last_synced_at']);
            if ($lastTs !== false && (time() - $lastTs) < 20) {
                $summary['skipped'] = true;
                return $summary;
            }
        }

        $token = self::getValidAccessTokenForUser($db, $userId);
        if ($token === '') {
            return $summary;
        }

        $userTz = self::resolveUserTimezone($db, $userId);
        $minIso = $timeMinIso ?: (new DateTimeImmutable('-14 days', $userTz))->format(DateTimeInterface::RFC3339);
        $maxIso = $timeMaxIso ?: (new DateTimeImmutable('+60 days', $userTz))->format(DateTimeInterface::RFC3339);

        $qs = http_build_query([
            'singleEvents' => 'true',
            'showDeleted'  => 'true',
            'timeMin'      => $minIso,
            'timeMax'      => $maxIso,
            'maxResults'   => 250,
            'orderBy'      => 'startTime',
        ], '', '&', PHP_QUERY_RFC3986);

        $resp = self::calendarHttp('GET', self::CALENDAR_EVENTS_BASE . '?' . $qs, $token);
        if ($resp['status'] < 200 || $resp['status'] >= 300 || !is_array($resp['data'])) {
            if ($resp['error'] !== '') {
                error_log('Workly pullFromGoogle error (' . $resp['status'] . '): ' . $resp['error']);
            }
            return $summary;
        }

        $data = $resp['data'];
        $gCalTzName = trim((string) ($data['timeZone'] ?? ''));
        $gCalTz = $userTz;
        if ($gCalTzName !== '') {
            try {
                $gCalTz = new DateTimeZone($gCalTzName);
            } catch (Throwable) {
                $gCalTzName = '';
            }
        }

        try {
            $db->prepare('UPDATE staff_connected_mailboxes SET calendar_timezone = COALESCE(NULLIF(:tz, \'\'), calendar_timezone), calendar_last_synced_at = NOW(), updated_at = NOW() WHERE user_id = :uid')
                ->execute([':tz' => $gCalTzName, ':uid' => $userId]);
        } catch (Throwable) {
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (empty($items)) {
            return $summary;
        }

        $findLinkStmt = $db->prepare('SELECT id, entity_type, entity_id, source_origin, last_synced_at FROM google_calendar_event_links WHERE user_id = :uid AND google_event_id = :geid LIMIT 1');

        foreach ($items as $gEv) {
            if (!is_array($gEv) || empty($gEv['id'])) {
                continue;
            }
            $gEventId = (string) $gEv['id'];
            $eventType = (string) ($gEv['eventType'] ?? 'default');
            if ($eventType === 'workingLocation') {
                continue;
            }

            $findLinkStmt->execute([':uid' => $userId, ':geid' => $gEventId]);
            $existingLink = $findLinkStmt->fetch(PDO::FETCH_ASSOC);

            // Handle cancellations/deletions performed directly inside Google Calendar
            if (($gEv['status'] ?? '') === 'cancelled') {
                if ($existingLink) {
                    $etype = (string) $existingLink['entity_type'];
                    $eid = (string) $existingLink['entity_id'];
                    $db->prepare('DELETE FROM google_calendar_event_links WHERE id = :id')->execute([':id' => $existingLink['id']]);
                    if ($etype === 'meeting') {
                        $mChk = $db->prepare('SELECT id, va_id, created_by_tl_id, external_source FROM calendar_meetings WHERE id = :id LIMIT 1');
                        $mChk->execute([':id' => $eid]);
                        $mRow = $mChk->fetch(PDO::FETCH_ASSOC);
                        if ($mRow) {
                            $cntStmt = $db->prepare("SELECT COUNT(*)::INTEGER FROM google_calendar_event_links WHERE entity_type = 'meeting' AND entity_id = :eid");
                            $cntStmt->execute([':eid' => $eid]);
                            $remainingLinks = (int) $cntStmt->fetchColumn();
                            $isGoogleCreated = (($mRow['external_source'] ?? 'workly') === 'google');
                            $isCreator = ((string) ($mRow['created_by_tl_id'] ?? '') === $userId || (string) ($mRow['va_id'] ?? '') === $userId);
                            if ($isGoogleCreated || ($remainingLinks === 0 && $isCreator)) {
                                $db->prepare('DELETE FROM calendar_meetings WHERE id = :id')->execute([':id' => $eid]);
                                $summary['deleted']++;
                            }
                        }
                    }
                }
                continue;
            }

            $startArr = is_array($gEv['start'] ?? null) ? $gEv['start'] : [];
            $endArr = is_array($gEv['end'] ?? null) ? $gEv['end'] : [];
            $isAllDay = false;

            try {
                if (!empty($startArr['dateTime'])) {
                    $dtStart = new DateTimeImmutable((string) $startArr['dateTime']);
                    $dtEnd = !empty($endArr['dateTime'])
                        ? new DateTimeImmutable((string) $endArr['dateTime'])
                        : $dtStart->modify('+1 hour');
                    if ($dtEnd <= $dtStart) {
                        $dtEnd = $dtStart->modify('+30 minutes');
                    }
                } elseif (!empty($startArr['date'])) {
                    $isAllDay = true;
                    $sDate = substr((string) $startArr['date'], 0, 10);
                    $eDateRaw = !empty($endArr['date']) ? substr((string) $endArr['date'], 0, 10) : $sDate;
                    $eIncDate = (new DateTimeImmutable($eDateRaw))->modify('-1 day')->format('Y-m-d');
                    if ($eIncDate < $sDate) {
                        $eIncDate = $sDate;
                    }
                    $dtStart = new DateTimeImmutable($sDate . ' 00:00:00', $gCalTz);
                    $dtEnd = new DateTimeImmutable($eIncDate . ' 23:59:59', $gCalTz);
                } else {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            $title = SecurityHelper::sanitizePlainText((string) ($gEv['summary'] ?? ''), 255);
            if ($title === '') {
                $title = 'Google Calendar Event';
            }
            $rawDesc = (string) ($gEv['description'] ?? '');
            $cleanDesc = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $rawDesc)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $notes = SecurityHelper::sanitizePlainText($cleanDesc, 1000);

            $rawLink = trim((string) ($gEv['hangoutLink'] ?? ''));
            if ($rawLink === '' && !empty($gEv['conferenceData']['entryPoints'][0]['uri'])) {
                $rawLink = (string) $gEv['conferenceData']['entryPoints'][0]['uri'];
            }
            if ($rawLink === '' && preg_match('#https?://\S+#i', (string) ($gEv['location'] ?? ''), $mUrl)) {
                $rawLink = $mUrl[0];
            }
            $meetLink = $rawLink !== '' ? SecurityHelper::sanitizeUrl($rawLink) : null;
            $htmlLink = !empty($gEv['htmlLink']) ? SecurityHelper::sanitizeUrl((string) $gEv['htmlLink']) : null;
            $gUpdatedTs = !empty($gEv['updated']) ? (strtotime((string) $gEv['updated']) ?: time()) : time();

            // Prevent duplicating Workly-created events if link row wasn't stored for this participant yet
            $extPriv = $gEv['extendedProperties']['private'] ?? [];
            if (!$existingLink && is_array($extPriv) && !empty($extPriv['worklyEntityId']) && !empty($extPriv['worklyEntityType'])) {
                $wType = (string) $extPriv['worklyEntityType'];
                $wId = (string) $extPriv['worklyEntityId'];
                if (in_array($wType, ['meeting', 'task'], true)) {
                    $tbl = $wType === 'meeting' ? 'calendar_meetings' : 'tasks';
                    $chk = $db->prepare("SELECT 1 FROM {$tbl} WHERE id = :id LIMIT 1");
                    $chk->execute([':id' => $wId]);
                    if ($chk->fetchColumn()) {
                        $db->prepare("
                            INSERT INTO google_calendar_event_links (id, entity_type, entity_id, user_id, google_calendar_id, google_event_id, google_html_link, source_origin, last_synced_at)
                            VALUES (:id, :etype, :eid, :uid, 'primary', :geid, :hlink, 'workly', NOW())
                            ON CONFLICT (entity_type, entity_id, user_id) DO UPDATE SET
                                google_event_id = EXCLUDED.google_event_id,
                                google_html_link = EXCLUDED.google_html_link,
                                last_synced_at = NOW()
                        ")->execute([
                            ':id'    => 'gcal-' . bin2hex(random_bytes(5)),
                            ':etype' => $wType,
                            ':eid'   => $wId,
                            ':uid'   => $userId,
                            ':geid'  => $gEventId,
                            ':hlink' => $htmlLink,
                        ]);
                        continue;
                    }
                }
            }

            if ($existingLink) {
                $lastSyncedTs = !empty($existingLink['last_synced_at']) ? (strtotime((string) $existingLink['last_synced_at']) ?: 0) : 0;
                $etype = (string) $existingLink['entity_type'];
                $eid = (string) $existingLink['entity_id'];

                if ($gUpdatedTs > ($lastSyncedTs + 5)) {
                    if ($etype === 'meeting') {
                        $isGoogleOrigin = (($existingLink['source_origin'] ?? 'workly') === 'google');
                        if ($isGoogleOrigin) {
                            $db->prepare("
                                UPDATE calendar_meetings
                                SET title = :title,
                                    start_time = :st,
                                    end_time = :et,
                                    is_all_day = :iad,
                                    meeting_link = :link,
                                    notes = :notes,
                                    google_synced = TRUE
                                WHERE id = :id
                            ")->execute([
                                ':title' => $title,
                                ':st'    => $dtStart->format('Y-m-d\TH:i:sP'),
                                ':et'    => $dtEnd->format('Y-m-d\TH:i:sP'),
                                ':iad'   => $isAllDay ? 'true' : 'false',
                                ':link'  => $meetLink,
                                ':notes' => $notes !== '' ? $notes : null,
                                ':id'    => $eid,
                            ]);
                        } else {
                            // Keep Workly-generated metadata formatting clean; sync time/title changes from Google
                            $db->prepare("
                                UPDATE calendar_meetings
                                SET start_time = :st,
                                    end_time = :et,
                                    is_all_day = :iad,
                                    google_synced = TRUE
                                WHERE id = :id
                            ")->execute([
                                ':st'  => $dtStart->format('Y-m-d\TH:i:sP'),
                                ':et'  => $dtEnd->format('Y-m-d\TH:i:sP'),
                                ':iad' => $isAllDay ? 'true' : 'false',
                                ':id'  => $eid,
                            ]);
                        }
                        $db->prepare('UPDATE google_calendar_event_links SET google_html_link = COALESCE(:hl, google_html_link), last_synced_at = NOW() WHERE id = :id')
                            ->execute([':hl' => $htmlLink, ':id' => $existingLink['id']]);
                        $summary['updated']++;
                    } elseif ($etype === 'task' && $isAllDay) {
                        $newDeadline = $dtStart->format('Y-m-d');
                        $db->prepare('UPDATE tasks SET deadline = :dl, updated_at = NOW() WHERE id = :id')
                            ->execute([':dl' => $newDeadline, ':id' => $eid]);
                        $db->prepare('UPDATE google_calendar_event_links SET last_synced_at = NOW() WHERE id = :id')
                            ->execute([':id' => $existingLink['id']]);
                        $summary['updated']++;
                    }
                }
                continue;
            }

            // Brand new event created directly in Google Calendar -> Import into Workly
            $newMeetingId = 'gcal-' . bin2hex(random_bytes(5));
            $insM = $db->prepare("
                INSERT INTO calendar_meetings (id, title, va_id, client_id, include_client, start_time, end_time, meeting_type, meeting_link, notes, created_by_tl_id, google_synced, external_source, is_all_day)
                VALUES (:id, :title, :va, NULL, FALSE, :st, :et, 'google_event', :link, :notes, :creator, TRUE, 'google', :iad)
            ");
            $insM->execute([
                ':id'      => $newMeetingId,
                ':title'   => $title,
                ':va'      => $userId,
                ':st'      => $dtStart->format('Y-m-d\TH:i:sP'),
                ':et'      => $dtEnd->format('Y-m-d\TH:i:sP'),
                ':link'    => $meetLink,
                ':notes'   => $notes !== '' ? $notes : null,
                ':creator' => $userId,
                ':iad'     => $isAllDay ? 'true' : 'false',
            ]);

            $insL = $db->prepare("
                INSERT INTO google_calendar_event_links (id, entity_type, entity_id, user_id, google_calendar_id, google_event_id, google_html_link, source_origin, last_synced_at)
                VALUES (:id, 'meeting', :eid, :uid, 'primary', :geid, :hlink, 'google', NOW())
                ON CONFLICT (entity_type, entity_id, user_id) DO UPDATE SET
                    google_event_id = EXCLUDED.google_event_id,
                    google_html_link = EXCLUDED.google_html_link,
                    last_synced_at = NOW()
            ");
            $insL->execute([
                ':id'    => 'gcal-' . bin2hex(random_bytes(5)),
                ':eid'   => $newMeetingId,
                ':uid'   => $userId,
                ':geid'  => $gEventId,
                ':hlink' => $htmlLink,
            ]);
            $summary['imported']++;
        }

        return $summary;
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

    /** Full bidirectional sync helper for the connected user's Google Calendar & Workly schedule. */
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

        // 1. Pull changes & new events from Google Calendar into Workly
        $userTz = self::resolveUserTimezone($db, $userId);
        $rangeStart = ($month && preg_match('/^\d{4}-\d{2}$/', $month))
            ? $month . '-01'
            : date('Y-m-d', strtotime('-7 days'));

        $pullMin = (new DateTimeImmutable($rangeStart . ' 00:00:00', $userTz))->modify('-7 days')->format(DateTimeInterface::RFC3339);
        $pullMax = (new DateTimeImmutable($rangeStart . ' 00:00:00', $userTz))->modify('+45 days')->format(DateTimeInterface::RFC3339);
        $pullRes = self::pullFromGoogleForUser($db, $userId, $pullMin, $pullMax, true);

        // 2. Push local Workly meetings & tasks to Google Calendar
        $mStmt = $db->prepare("
            SELECT id
            FROM calendar_meetings
            WHERE (va_id = :u1 OR created_by_tl_id = :u2)
              AND start_time::DATE >= :rs
              AND COALESCE(external_source, 'workly') = 'workly'
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
            'google_imported' => (int) ($pullRes['imported'] ?? 0),
            'google_updated'  => (int) ($pullRes['updated'] ?? 0),
            'google_deleted'  => (int) ($pullRes['deleted'] ?? 0),
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

    private static function upsertUserEvent(
        PDO $db,
        string $entityType,
        string $entityId,
        string $userId,
        array $payload,
        string $sourceOrigin = 'workly'
    ): ?array {
        $token = self::getValidAccessTokenForUser($db, $userId);
        if ($token === '') {
            return null;
        }

        $linkStmt = $db->prepare('SELECT id, google_event_id, source_origin FROM google_calendar_event_links WHERE entity_type = :etype AND entity_id = :eid AND user_id = :uid LIMIT 1');
        $linkStmt->execute([':etype' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        $existing = $linkStmt->fetch(PDO::FETCH_ASSOC);

        $sendUpdates = !empty($payload['attendees']) ? 'all' : 'none';
        $eventData = null;
        if ($existing && !empty($existing['google_event_id'])) {
            $patchUrl = self::CALENDAR_EVENTS_BASE . '/' . rawurlencode((string) $existing['google_event_id']) . '?sendUpdates=' . $sendUpdates;
            $resp = self::calendarHttp('PATCH', $patchUrl, $token, $payload);
            if ($resp['status'] >= 200 && $resp['status'] < 300 && !empty($resp['data']['id'])) {
                $eventData = $resp['data'];
            } elseif (!in_array($resp['status'], [404, 410], true)) {
                error_log('Workly Google Calendar PATCH failed (' . $resp['status'] . '): ' . $resp['error']);
                return null;
            }
        }

        if ($eventData === null) {
            $postUrl = self::CALENDAR_EVENTS_BASE . '?sendUpdates=' . $sendUpdates;
            $resp = self::calendarHttp('POST', $postUrl, $token, $payload);
            if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['data']['id'])) {
                error_log('Workly Google Calendar POST failed (' . $resp['status'] . '): ' . $resp['error']);
                return null;
            }
            $eventData = $resp['data'];
        }

        $linkId = ($existing && !empty($existing['id'])) ? (string) $existing['id'] : ('gcal-' . bin2hex(random_bytes(5)));
        $resolvedOrigin = ($existing && !empty($existing['source_origin'])) ? (string) $existing['source_origin'] : $sourceOrigin;
        $upStmt = $db->prepare("
            INSERT INTO google_calendar_event_links (id, entity_type, entity_id, user_id, google_calendar_id, google_event_id, google_html_link, source_origin, last_synced_at)
            VALUES (:id, :etype, :eid, :uid, 'primary', :geid, :hlink, :origin, NOW())
            ON CONFLICT (entity_type, entity_id, user_id) DO UPDATE SET
                google_event_id = EXCLUDED.google_event_id,
                google_html_link = EXCLUDED.google_html_link,
                last_synced_at = NOW()
        ");
        $upStmt->execute([
            ':id'     => $linkId,
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            ':uid'    => $userId,
            ':geid'   => (string) $eventData['id'],
            ':hlink'  => !empty($eventData['htmlLink']) ? (string) $eventData['htmlLink'] : null,
            ':origin' => $resolvedOrigin,
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
        unset($ch);

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
