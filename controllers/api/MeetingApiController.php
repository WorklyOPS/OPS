<?php
/**
 * Workly Intelligent Calendar Scheduler, Focus Time & Meetings API Controller
 * Supports VA focus blocks, CRM client availability windows, conflict detection,
 * and drag-and-drop rescheduling (PHP 8 + PostgreSQL).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';

final class MeetingApiController
{
    private const VALID_TYPES = [
        'client_meeting',
        'team_sync',
        'checkpoint',
        'review',
        'focus_block',
        'availability_window',
        'google_event',
    ];

    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);
        $this->ensureCalendarSchema();

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/meetings/{id}/{sub}
        $p3 = $parts[3] ?? '';
        $p4 = $parts[4] ?? '';

        if ($method === 'GET' && $p3 === 'availability') {
            $this->getAvailability($user);
            return;
        }

        if ($method === 'GET' && $p3 === 'live-google') {
            $this->liveGoogleSync($user);
            return;
        }

        if ($method === 'POST' && $p3 === 'sync-google') {
            $this->syncGoogleCalendar($user);
            return;
        }

        if ($method === 'POST' && $p3 === '') {
            $this->createMeeting($user);
            return;
        }

        if ($method === 'PATCH' && $p3 !== '' && $p4 === 'reschedule') {
            $this->rescheduleMeeting($p3, $user);
            return;
        }

        if ($method === 'DELETE' && $p3 !== '') {
            $this->deleteMeeting($p3, $user);
            return;
        }

        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }

    private function ensureCalendarSchema(): void
    {
        try {
            $this->db->exec("ALTER TABLE calendar_meetings ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL");
            $this->db->exec("ALTER TABLE calendar_meetings ADD COLUMN IF NOT EXISTS external_source VARCHAR(24) NOT NULL DEFAULT 'workly'");
            $this->db->exec("ALTER TABLE calendar_meetings ADD COLUMN IF NOT EXISTS is_all_day BOOLEAN NOT NULL DEFAULT FALSE");
            $this->db->exec("ALTER TABLE calendar_meetings DROP CONSTRAINT IF EXISTS calendar_meetings_meeting_type_check");
            $this->db->exec("ALTER TABLE calendar_meetings ADD CONSTRAINT calendar_meetings_meeting_type_check CHECK (meeting_type IN ('client_meeting', 'team_sync', 'checkpoint', 'review', 'focus_block', 'availability_window', 'google_event'))");
            GoogleCalendarService::ensureSchema($this->db);
        } catch (Throwable) {
            // Schema already aligned or read-only DDL
        }
    }

    private function parseClientWindowsForDate(?string $clientId, string $date): ?array
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }
        try {
            $cStmt = $this->db->prepare('SELECT availability_slots FROM clients WHERE id = :cid LIMIT 1');
            $cStmt->execute([':cid' => $clientId]);
            $raw = $cStmt->fetchColumn();
            if (!$raw) {
                return null;
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded) || empty($decoded)) {
                return null;
            }
            $dayName = (new DateTimeImmutable($date))->format('D'); // Mon..Sun
            $hours = [];
            foreach ($decoded as $slot) {
                if (!is_array($slot)) {
                    continue;
                }
                if (strcasecmp((string) ($slot['day'] ?? ''), $dayName) !== 0) {
                    continue;
                }
                $sHour = (int) substr((string) ($slot['start'] ?? '09:00'), 0, 2);
                $eHour = (int) substr((string) ($slot['end'] ?? '17:00'), 0, 2);
                $eMin = (int) substr((string) ($slot['end'] ?? '17:00'), 3, 2);
                if ($eMin > 0) {
                    $eHour++;
                }
                for ($h = max(0, $sHour); $h < min(23, $eHour); $h++) {
                    $hours[] = $h;
                }
            }
            $hours = array_values(array_unique($hours));
            sort($hours);
            return $hours;
        } catch (Throwable) {
            return null;
        }
    }

    private function getAvailability(array $user): void
    {
        $vaId = SecurityHelper::sanitizePlainText($_GET['va_id'] ?? $user['id'], 64);
        if ($user['role'] === 'va') {
            $vaId = (string) $user['id'];
        }
        $date = SecurityHelper::sanitizePlainText($_GET['date'] ?? date('Y-m-d'), 16);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $clientId = SecurityHelper::sanitizePlainText($_GET['client_id'] ?? '', 64);

        $stmt = $this->db->prepare("
            SELECT id, title, meeting_type, start_time, end_time, client_id
            FROM calendar_meetings
            WHERE va_id = :va AND start_time::DATE = :dt
            ORDER BY start_time ASC
        ");
        $stmt->execute([':va' => $vaId, ':dt' => $date]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ptoStmt = $this->db->prepare("
            SELECT id, start_date, end_date
            FROM pto_requests
            WHERE va_id = :va AND status = 'approved' AND :dt BETWEEN start_date AND end_date
            LIMIT 1
        ");
        $ptoStmt->execute([':va' => $vaId, ':dt' => $date]);
        $pto = $ptoStmt->fetch(PDO::FETCH_ASSOC);

        $clientHours = $this->parseClientWindowsForDate($clientId !== '' ? $clientId : null, $date);
        $candidateHours = $clientHours !== null ? $clientHours : range(9, 16);

        $openSlots = [];
        foreach ($candidateHours as $h) {
            $slotStart = sprintf('%sT%02d:00:00', $date, $h);
            $slotEnd = sprintf('%sT%02d:00:00', $date, $h + 1);
            if ($pto) {
                continue;
            }
            if (!$this->hasOverlap($vaId, $slotStart, $slotEnd, null)) {
                $openSlots[] = sprintf('%02d:00 - %02d:00', $h, $h + 1);
            }
        }

        echo json_encode([
            'va_id'         => $vaId,
            'client_id'     => $clientId !== '' ? $clientId : null,
            'client_synced' => $clientHours !== null,
            'date'          => $date,
            'on_pto'        => (bool) $pto,
            'events'        => $events,
            'open_slots'    => $openSlots,
        ]);
    }

    private function hasOverlap(string $vaId, string $startIso, string $endIso, ?string $excludeId): bool
    {
        $sql = 'SELECT COUNT(*)::INTEGER FROM calendar_meetings WHERE va_id = :va AND COALESCE(is_all_day, FALSE) = FALSE AND start_time < :e AND end_time > :s';
        $params = [':va' => $vaId, ':s' => $startIso, ':e' => $endIso];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params[':ex'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function isApprovedPto(string $vaId, string $startDate, string $endDate): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)::INTEGER
            FROM pto_requests
            WHERE va_id = :va AND status = 'approved' AND start_date <= :ed AND end_date >= :sd
        ");
        $stmt->execute([':va' => $vaId, ':sd' => $startDate, ':ed' => $endDate]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function findSmartSlotOnDate(string $vaId, string $date, int $durationMinutes = 60, ?string $clientId = null, ?DateTimeZone $tz = null): array
    {
        $useTz = $tz ?? GoogleCalendarService::resolveUserTimezone($this->db, $vaId);
        $durMin = max(30, min(480, $durationMinutes));
        $clientHours = $this->parseClientWindowsForDate($clientId, $date);
        $hours = (!empty($clientHours)) ? $clientHours : [9, 10, 11, 13, 14, 15, 16, 8, 12];
        foreach ($hours as $startHour) {
            try {
                $s = new DateTimeImmutable("{$date} " . sprintf('%02d:00:00', $startHour), $useTz);
                $e = $s->modify("+{$durMin} minutes");
                if ($e->format('Y-m-d') !== $date) {
                    continue;
                }
                if (!$this->hasOverlap($vaId, $s->format(DateTimeInterface::RFC3339), $e->format(DateTimeInterface::RFC3339), null)) {
                    return [$s->format('Y-m-d\TH:i:s'), $e->format('Y-m-d\TH:i:s')];
                }
            } catch (Throwable) {
                // Skip invalid
            }
        }
        $fallbackHour = !empty($hours) ? $hours[0] : 9;
        $s = new DateTimeImmutable("{$date} " . sprintf('%02d:00:00', $fallbackHour), $useTz);
        $e = $s->modify("+{$durMin} minutes");
        return [$s->format('Y-m-d\TH:i:s'), $e->format('Y-m-d\TH:i:s')];
    }

    private function createMeeting(array $user): void
    {
        AuthMiddleware::requireRoles(['va', 'team_leader', 'admin'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $type = in_array($input['meeting_type'] ?? '', self::VALID_TYPES, true)
            ? (string) $input['meeting_type']
            : (!empty($input['include_client']) ? 'client_meeting' : 'team_sync');

        if ($user['role'] === 'va') {
            $vaId = (string) $user['id'];
            if (!in_array($type, ['focus_block', 'availability_window', 'client_meeting'], true)) {
                $type = 'focus_block';
            }
        } else {
            $vaId = SecurityHelper::sanitizePlainText($input['va_id'] ?? $user['id'], 64);
        }

        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        $clientId = !empty($input['client_id'])
            ? SecurityHelper::sanitizePlainText($input['client_id'], 64)
            : null;
        $includeClient = filter_var($input['include_client'] ?? false, FILTER_VALIDATE_BOOLEAN) && $clientId !== null;
        $notes = SecurityHelper::sanitizePlainText($input['notes'] ?? '', 1000);
        $autoSlot = !empty($input['auto_slot']);

        $explicitTz = SecurityHelper::sanitizePlainText($input['timezone'] ?? '', 64);
        $userTz = GoogleCalendarService::resolveUserTimezone($this->db, (string) $user['id'], $explicitTz !== '' ? $explicitTz : null);

        $start = SecurityHelper::sanitizePlainText($input['start_time'] ?? '', 64);
        $end = SecurityHelper::sanitizePlainText($input['end_time'] ?? '', 64);

        if ($autoSlot && !empty($input['target_date'])) {
            $targetDate = SecurityHelper::sanitizePlainText($input['target_date'], 16);
            $dur = max(30, min(360, (int) ($input['duration_minutes'] ?? 60)));
            [$start, $end] = $this->findSmartSlotOnDate($vaId, $targetDate, $dur, $clientId, $userTz);
        }

        $useCustomLink = array_key_exists('use_custom_link', $input)
            ? filter_var($input['use_custom_link'], FILTER_VALIDATE_BOOLEAN)
            : (trim((string) ($input['meeting_link'] ?? '')) !== '');
        $customLink = $useCustomLink ? SecurityHelper::sanitizeUrl($input['meeting_link'] ?? '') : '';

        $link = in_array($type, ['focus_block', 'availability_window'], true)
            ? null
            : ($customLink !== '' ? $customLink : ('https://meet.google.com/wrk-' . bin2hex(random_bytes(4))));

        if ($title === '' || $vaId === '' || $start === '' || $end === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title, VA, start time, and end time are required']);
            return;
        }

        try {
            $dtStart = new DateTimeImmutable($start, $userTz);
            $dtEnd = new DateTimeImmutable($end, $userTz);
            if ($dtEnd <= $dtStart) {
                http_response_code(422);
                echo json_encode(['error' => 'End time must be after start time']);
                return;
            }
        } catch (Throwable) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid start or end timestamp']);
            return;
        }

        if ($this->isApprovedPto($vaId, $dtStart->format('Y-m-d'), $dtEnd->format('Y-m-d'))) {
            http_response_code(409);
            echo json_encode(['error' => 'Conflict: Staff member has Approved PTO on the selected date.']);
            return;
        }

        if (!$autoSlot && $this->hasOverlap($vaId, $dtStart->format(DateTimeInterface::RFC3339), $dtEnd->format(DateTimeInterface::RFC3339), null)) {
            http_response_code(409);
            echo json_encode(['error' => 'Schedule conflict detected with an existing calendar block or meeting. Adjust time or use smart auto-slot.']);
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

        $clientRow = null;
        if ($clientId !== null) {
            $clStmt = $this->db->prepare('SELECT id, agency_name, name, email FROM clients WHERE id = :cid LIMIT 1');
            $clStmt->execute([':cid' => $clientId]);
            $clientRow = $clStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$clientRow) {
                $clientId = null;
                $includeClient = false;
            }
        }

        $meetingId = (in_array($type, ['focus_block', 'availability_window'], true) ? 'blk-' : 'meet-') . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO calendar_meetings (id, title, va_id, client_id, include_client, start_time, end_time, meeting_type, meeting_link, notes, created_by_tl_id, google_synced)
            VALUES (:id, :title, :va, :cid, :inc, :start_t, :end_t, :type, :link, :notes, :creator, FALSE)
        ");
        $stmt->execute([
            ':id'      => $meetingId,
            ':title'   => $title,
            ':va'      => $vaId,
            ':cid'     => $clientId,
            ':inc'     => $includeClient ? 'true' : 'false',
            ':start_t' => $dtStart->format('Y-m-d\TH:i:sP'),
            ':end_t'   => $dtEnd->format('Y-m-d\TH:i:sP'),
            ':type'    => $type,
            ':link'    => $link,
            ':notes'   => $notes !== '' ? $notes : null,
            ':creator' => $user['id'],
        ]);

        // Notify internal staff in Email Hub when scheduling someone else
        if (!in_array($type, ['focus_block'], true) && $va['id'] !== $user['id']) {
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
                ':body'    => "You are invited to '{$title}'.\nStart: {$dtStart->format('Y-m-d g:i A T')}\nEnd: {$dtEnd->format('Y-m-d g:i A T')}" . ($link ? "\nMeeting Link: {$link}" : ''),
                ':cid'     => $clientId,
            ]);
        }

        $googleSync = GoogleCalendarService::syncMeeting($this->db, $meetingId);

        // Send calendar invitation & meeting link email to CRM client when optionally checked
        $clientInvited = false;
        $clientEmailSent = false;
        $clientEmailAddr = null;

        if ($includeClient && $clientRow) {
            $candidateEmail = trim((string) ($clientRow['email'] ?? ''));
            if (filter_var($candidateEmail, FILTER_VALIDATE_EMAIL)) {
                $clientInvited = true;
                $clientEmailAddr = $candidateEmail;

                $gcalHtmlLink = '';
                foreach (($googleSync['synced_users'] ?? []) as $su) {
                    if (!empty($su['html_link'])) {
                        $gcalHtmlLink = (string) $su['html_link'];
                        break;
                    }
                }

                $utcStart = $dtStart->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                $utcEnd = $dtEnd->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                $templateDetails = trim(($link ? "Join Meeting: {$link}\n\n" : '') . ($notes !== '' ? $notes : ''));
                $addToCalendarUrl = 'https://calendar.google.com/calendar/render?' . http_build_query([
                    'action'   => 'TEMPLATE',
                    'text'     => $title,
                    'dates'    => $utcStart . '/' . $utcEnd,
                    'details'  => $templateDetails,
                    'location' => $link ?: 'Online Meeting',
                ], '', '&', PHP_QUERY_RFC3986);

                $calendarInviteUrl = $gcalHtmlLink !== '' ? $gcalHtmlLink : $addToCalendarUrl;
                $clientDisplay = trim((string) ($clientRow['name'] ?: $clientRow['agency_name']));
                $agencyName = trim((string) ($clientRow['agency_name'] ?? ''));
                $whenLabel = $dtStart->format('l, M j, Y · g:i A') . ' – ' . $dtEnd->format('g:i A T');
                $mailSubject = "Meeting Invitation: {$title} ({$dtStart->format('M j, g:i A')})";

                $plainLines = [
                    "Hi {$clientDisplay},",
                    '',
                    "You're invited to a scheduled meeting: {$title}",
                    "When: {$whenLabel}",
                    "Participant: {$va['name']}",
                ];
                if ($link) {
                    $plainLines[] = "Meeting Video Link: {$link}";
                }
                $plainLines[] = "Calendar Invite Link: {$calendarInviteUrl}";
                if ($addToCalendarUrl !== $calendarInviteUrl) {
                    $plainLines[] = "Add to Google Calendar: {$addToCalendarUrl}";
                }
                if ($notes !== '') {
                    $plainLines[] = '';
                    $plainLines[] = 'Agenda / Notes:';
                    $plainLines[] = $notes;
                }
                $plainLines[] = '';
                $plainLines[] = "Best regards,\n{$user['name']}";
                $plainBody = implode("\n", $plainLines);

                $safeClient = SecurityHelper::e($clientDisplay);
                $safeTitle = SecurityHelper::e($title);
                $safeWhen = SecurityHelper::e($whenLabel);
                $safeVa = SecurityHelper::e((string) $va['name']);
                $safeAgency = SecurityHelper::e($agencyName);
                $safeLink = $link ? SecurityHelper::e($link) : '';
                $safeCalUrl = SecurityHelper::e($calendarInviteUrl);
                $safeAddCalUrl = SecurityHelper::e($addToCalendarUrl);
                $safeNotes = $notes !== '' ? nl2br(SecurityHelper::e($notes)) : '';

                $htmlBody = '<div style="max-width:560px;margin:0 auto;padding:20px;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;">'
                    . '<p style="margin:0 0 12px;">Hi ' . $safeClient . ',</p>'
                    . '<p style="margin:0 0 14px;">You have been invited to a meeting' . ($safeAgency !== '' ? ' for <strong>' . $safeAgency . '</strong>' : '') . ':</p>'
                    . '<div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:16px;">'
                    . '<div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:6px;">' . $safeTitle . '</div>'
                    . '<div style="font-size:13px;color:#334155;margin-bottom:4px;"><strong>When:</strong> ' . $safeWhen . '</div>'
                    . '<div style="font-size:13px;color:#334155;"><strong>Team Contact:</strong> ' . $safeVa . '</div>'
                    . ($safeLink !== '' ? '<div style="font-size:13px;color:#334155;margin-top:4px;"><strong>Meeting URL:</strong> <a href="' . $safeLink . '" style="color:#7c3aed;">' . $safeLink . '</a></div>' : '')
                    . '</div>'
                    . ($safeNotes !== '' ? '<div style="margin-bottom:16px;font-size:13px;color:#475569;"><strong>Agenda:</strong><br>' . $safeNotes . '</div>' : '')
                    . '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">'
                    . '<a href="' . $safeCalUrl . '" style="display:inline-block;padding:10px 16px;background:#8822ce;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;font-size:13px;margin-right:8px;">Open Calendar Invite</a>'
                    . ($safeLink !== '' ? '<a href="' . $safeLink . '" style="display:inline-block;padding:10px 16px;background:#0f172a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;font-size:13px;">Join Meeting</a>' : '')
                    . ($addToCalendarUrl !== $calendarInviteUrl ? ' <a href="' . $safeAddCalUrl . '" style="display:inline-block;padding:10px 14px;background:#f1f5f9;color:#0f172a;text-decoration:none;border-radius:8px;font-weight:600;font-size:13px;">Add to Google Calendar</a>' : '')
                    . '</div>'
                    . '<p style="margin:0;font-size:12.5px;color:#64748b;">Sent by ' . SecurityHelper::e((string) $user['name']) . '</p>'
                    . '</div>';

                $sendRes = GmailOAuthService::sendEmailViaMailbox(
                    $this->db,
                    (string) $user['id'],
                    (string) $user['name'],
                    $clientEmailAddr,
                    $mailSubject,
                    $plainBody,
                    $htmlBody
                );
                if (empty($sendRes['sent']) && $vaId !== (string) $user['id']) {
                    $sendRes = GmailOAuthService::sendEmailViaMailbox(
                        $this->db,
                        $vaId,
                        (string) $va['name'],
                        $clientEmailAddr,
                        $mailSubject,
                        $plainBody,
                        $htmlBody
                    );
                }

                $clientEmailSent = !empty($sendRes['sent']);
                $senderEmailUsed = (string) ($sendRes['sender_email'] ?: $user['email']);
                $gmailMsgId = $sendRes['gmail_message_id'] ?? null;
                $threadId = !empty($sendRes['gmail_thread_id']) ? (string) $sendRes['gmail_thread_id'] : ('th-' . bin2hex(random_bytes(5)));

                try {
                    $this->db->prepare("
                        INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, subject, body, body_html, body_format, snippet, gmail_message_id, folder, is_read, client_ref_id, project_label)
                        VALUES (:id, :tid, :sname, :semail, :remail, :subj, :body, :bhtml, 'html', :snip, :gid, 'sent', TRUE, :cid, 'Meeting Invite')
                    ")->execute([
                        ':id'     => 'msg-' . bin2hex(random_bytes(5)),
                        ':tid'    => $threadId,
                        ':sname'  => $user['name'],
                        ':semail' => $senderEmailUsed,
                        ':remail' => $clientEmailAddr,
                        ':subj'   => $mailSubject,
                        ':body'   => $plainBody,
                        ':bhtml'  => $htmlBody,
                        ':snip'   => mb_substr($plainBody, 0, 140, 'UTF-8'),
                        ':gid'    => $gmailMsgId,
                        ':cid'    => $clientId,
                    ]);
                } catch (Throwable) {
                    // Preserve meeting creation even if email archive insert has legacy schema columns
                }
            }
        }

        CacheService::invalidatePrefix($this->db, 'meetings:');
        CacheService::invalidatePrefix($this->db, 'emails:');
        AuditLogger::log('CALENDAR_EVENT_CREATED', 'success', "Created {$type} {$meetingId} ({$title}) for {$va['email']}", $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode([
            'success'           => true,
            'id'                => $meetingId,
            'meeting_type'      => $type,
            'start_time'        => $dtStart->format('Y-m-d H:i'),
            'end_time'          => $dtEnd->format('Y-m-d H:i'),
            'meeting_link'      => $link,
            'google_sync'       => $googleSync,
            'client_invited'    => $clientInvited,
            'client_email_sent' => $clientEmailSent,
            'client_email'      => $clientEmailAddr,
        ]);
    }

    private function rescheduleMeeting(string $id, array $user): void
    {
        $stmt = $this->db->prepare('SELECT * FROM calendar_meetings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $ev = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ev) {
            http_response_code(404);
            echo json_encode(['error' => 'Calendar event not found']);
            return;
        }

        $isOwner = ($ev['va_id'] === $user['id'] || $ev['created_by_tl_id'] === $user['id']);
        if ($user['role'] === 'va' && !$isOwner) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: You can only reschedule your own calendar blocks']);
            return;
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $targetDate = SecurityHelper::sanitizePlainText($input['target_date'] ?? '', 16);
        $hasTargetHour = array_key_exists('target_hour', $input) && $input['target_hour'] !== '' && $input['target_hour'] !== null;
        $targetHour = $hasTargetHour ? max(0, min(23, (int) $input['target_hour'])) : null;
        $explicitTz = SecurityHelper::sanitizePlainText($input['timezone'] ?? '', 64);
        $userTz = GoogleCalendarService::resolveUserTimezone($this->db, (string) $user['id'], $explicitTz !== '' ? $explicitTz : null);
        $clearAllDay = false;

        try {
            $oldStart = (new DateTimeImmutable((string) $ev['start_time']))->setTimezone($userTz);
            $oldEnd = (new DateTimeImmutable((string) $ev['end_time']))->setTimezone($userTz);
            $diff = $oldStart->diff($oldEnd);
            $isCurrentlyAllDay = filter_var($ev['is_all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($targetDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
                if ($targetHour !== null) {
                    $newStart = new DateTimeImmutable(sprintf('%s %02d:00:00', $targetDate, $targetHour), $userTz);
                    $newEnd = $isCurrentlyAllDay ? $newStart->modify('+1 hour') : $newStart->add($diff);
                    $clearAllDay = true;
                } else {
                    $newStart = new DateTimeImmutable($targetDate . ' ' . $oldStart->format('H:i:s'), $userTz);
                    $newEnd = $newStart->add($diff);
                }
            } elseif (!empty($input['start_time']) && !empty($input['end_time'])) {
                $newStart = new DateTimeImmutable((string) $input['start_time'], $userTz);
                $newEnd = new DateTimeImmutable((string) $input['end_time'], $userTz);
                $clearAllDay = true;
            } else {
                http_response_code(422);
                echo json_encode(['error' => 'target_date or start_time/end_time is required']);
                return;
            }
        } catch (Throwable) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid target date or timestamp']);
            return;
        }

        if ($this->isApprovedPto((string) $ev['va_id'], $newStart->format('Y-m-d'), $newEnd->format('Y-m-d'))) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot move block onto an Approved PTO day']);
            return;
        }

        $willBeAllDay = !$clearAllDay && filter_var($ev['is_all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$willBeAllDay && $this->hasOverlap((string) $ev['va_id'], $newStart->format(DateTimeInterface::RFC3339), $newEnd->format(DateTimeInterface::RFC3339), $id)) {
            http_response_code(409);
            echo json_encode(['error' => 'Schedule overlap detected at target slot']);
            return;
        }

        $upd = $this->db->prepare('UPDATE calendar_meetings SET start_time = :s, end_time = :e, is_all_day = :iad WHERE id = :id');
        $upd->execute([
            ':s'   => $newStart->format('Y-m-d\TH:i:sP'),
            ':e'   => $newEnd->format('Y-m-d\TH:i:sP'),
            ':iad' => $willBeAllDay ? 'true' : 'false',
            ':id'  => $id,
        ]);

        $googleSync = GoogleCalendarService::syncMeeting($this->db, $id);

        CacheService::invalidatePrefix($this->db, 'meetings:');
        AuditLogger::log('CALENDAR_EVENT_MOVED', 'success', "Rescheduled {$id} to {$newStart->format('Y-m-d H:i')}", $user['id'], $user['role'], $this->db);

        echo json_encode([
            'success'     => true,
            'id'          => $id,
            'start_time'  => $newStart->format('Y-m-d g:i A'),
            'google_sync' => $googleSync,
        ]);
    }

    private function deleteMeeting(string $id, array $user): void
    {
        $stmt = $this->db->prepare('SELECT id, va_id, created_by_tl_id FROM calendar_meetings WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $ev = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ev) {
            http_response_code(404);
            echo json_encode(['error' => 'Calendar block not found']);
            return;
        }

        $isOwner = ($ev['va_id'] === $user['id'] || $ev['created_by_tl_id'] === $user['id']);
        if (!in_array($user['role'], ['admin', 'team_leader'], true) && !$isOwner) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden: Cannot delete another user’s event']);
            return;
        }

        GoogleCalendarService::deleteEntityEvents($this->db, 'meeting', $id);
        $this->db->prepare('DELETE FROM calendar_meetings WHERE id = :id')->execute([':id' => $id]);
        CacheService::invalidatePrefix($this->db, 'meetings:');
        AuditLogger::log('CALENDAR_EVENT_DELETED', 'warning', "Deleted calendar block {$id}", $user['id'], $user['role'], $this->db);

        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function syncGoogleCalendar(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $month = SecurityHelper::sanitizePlainText($input['month'] ?? '', 7);
        try {
            $res = GoogleCalendarService::syncUpcomingForUser($this->db, (string) $user['id'], $month !== '' ? $month : null);
            CacheService::invalidatePrefix($this->db, 'meetings:');
            echo json_encode([
                'success'         => true,
                'meetings_synced' => $res['meetings_synced'],
                'tasks_synced'    => $res['tasks_synced'],
                'google_imported' => $res['google_imported'],
                'google_updated'  => $res['google_updated'],
                'google_deleted'  => $res['google_deleted'],
            ]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function liveGoogleSync(array $user): void
    {
        $res = GoogleCalendarService::pullFromGoogleForUser($this->db, (string) $user['id'], null, null, false);
        $changes = (int) ($res['imported'] ?? 0) + (int) ($res['updated'] ?? 0) + (int) ($res['deleted'] ?? 0);
        if ($changes > 0) {
            CacheService::invalidatePrefix($this->db, 'meetings:');
        }
        echo json_encode([
            'connected' => (bool) ($res['connected'] ?? false),
            'changed'   => $changes > 0,
            'imported'  => (int) ($res['imported'] ?? 0),
            'updated'   => (int) ($res['updated'] ?? 0),
            'deleted'   => (int) ($res['deleted'] ?? 0),
        ]);
    }
}
