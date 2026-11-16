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
        $participantIds = $input['participant_ids'] ?? [];
        if (!is_array($participantIds)) {
            $participantIds = [];
        }
        if ($participantIds === [] && !empty($input['va_id'])) {
            $participantIds = [$input['va_id']];
        }
        $participantIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => SecurityHelper::sanitizePlainText((string) $id, 64),
            $participantIds
        ))));

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

        if ($title === '' || $participantIds === [] || $start === '' || $end === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title, at least one VA participant, start time, and end time are required']);
            return;
        }
        if (count($participantIds) > 30) {
            http_response_code(422);
            echo json_encode(['error' => 'A meeting can include up to 30 VA participants']);
            return;
        }

        try {
            $startAt = new DateTimeImmutable($start);
            $endAt = new DateTimeImmutable($end);
            if ($endAt <= $startAt) {
                throw new InvalidArgumentException('End time must be after start time');
            }
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['error' => 'A valid start and end date/time are required']);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $sql = "SELECT id, name, email, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE AND id IN ({$placeholders})";
        $vaStmt = $this->db->prepare($sql);
        $vaStmt->execute($participantIds);
        $participants = $vaStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($participants) !== count($participantIds)) {
            http_response_code(422);
            echo json_encode(['error' => 'One or more selected VAs are invalid or inactive']);
            return;
        }
        if ($user['role'] === 'team_leader') {
            foreach ($participants as $participant) {
                if ((string) $participant['team_leader_id'] !== (string) $user['id']) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only schedule meetings with VAs assigned to your pod']);
                    return;
                }
            }
        }

        $clientEmail = null;
        if ($includeClient) {
            if (!$clientId) {
                http_response_code(422);
                echo json_encode(['error' => 'Select a client when including a client participant']);
                return;
            }
            $clientStmt = $this->db->prepare('SELECT email FROM clients WHERE id = :id LIMIT 1');
            $clientStmt->execute([':id' => $clientId]);
            $clientEmail = $clientStmt->fetchColumn();
            if (!$clientEmail) {
                http_response_code(422);
                echo json_encode(['error' => 'Selected client was not found']);
                return;
            }
        }

        $meetingId = 'meet-' . bin2hex(random_bytes(5));
        $primaryVaId = $participantIds[0];
        $threadId = 'th-' . bin2hex(random_bytes(5));

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("
                INSERT INTO calendar_meetings (id, title, va_id, client_id, include_client, start_time, end_time, meeting_type, meeting_link, created_by_tl_id, google_synced)
                VALUES (:id, :title, :va, :cid, :inc, :start_t, :end_t, :type, :link, :tl, FALSE)
            ");
            $stmt->execute([
                ':id' => $meetingId,
                ':title' => $title,
                ':va' => $primaryVaId,
                ':cid' => $clientId,
                ':inc' => $includeClient ? 'true' : 'false',
                ':start_t' => $startAt->format(DateTimeInterface::ATOM),
                ':end_t' => $endAt->format(DateTimeInterface::ATOM),
                ':type' => $type,
                ':link' => $link,
                ':tl' => $user['id'],
            ]);

            $participantInsert = $this->db->prepare('INSERT INTO calendar_meeting_participants (meeting_id, user_id) VALUES (:meeting, :user) ON CONFLICT DO NOTHING');
            $emailInsert = $this->db->prepare("
                INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, subject, body, folder, is_read, client_ref_id)
                VALUES (:id, :tid, :sname, :semail, :remail, :subject, :body, 'sent', FALSE, :cid)
            ");

            foreach ($participants as $participant) {
                $participantInsert->execute([':meeting' => $meetingId, ':user' => $participant['id']]);
                if (!empty($participant['email'])) {
                    $emailInsert->execute([
                        ':id' => 'msg-' . bin2hex(random_bytes(5)),
                        ':tid' => $threadId,
                        ':sname' => $user['name'],
                        ':semail' => $user['email'],
                        ':remail' => $participant['email'],
                        ':subject' => '[Calendar Invite] ' . $title,
                        ':body' => "You are invited to '{$title}'.\nStart: {$startAt->format('Y-m-d H:i')}\nEnd: {$endAt->format('Y-m-d H:i')}\nMeeting Link: {$link}",
                        ':cid' => $clientId,
                    ]);
                }
            }

            if ($includeClient && $clientEmail) {
                $emailInsert->execute([
                    ':id' => 'msg-' . bin2hex(random_bytes(5)),
                    ':tid' => $threadId,
                    ':sname' => $user['name'],
                    ':semail' => $user['email'],
                    ':remail' => $clientEmail,
                    ':subject' => '[Calendar Invite] ' . $title,
                    ':body' => "You are invited to '{$title}'.\nStart: {$startAt->format('Y-m-d H:i')}\nEnd: {$endAt->format('Y-m-d H:i')}\nMeeting Link: {$link}",
                    ':cid' => $clientId,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Meeting creation failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'The meeting could not be scheduled']);
            return;
        }

        CacheService::invalidatePrefix($this->db, 'meetings:');
        CacheService::invalidatePrefix($this->db, 'emails:');
        AuditLogger::log('MEETING_SCHEDULED', 'success', "Scheduled meeting {$meetingId} with " . count($participants) . ' VA participant(s)', $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'id' => $meetingId,
            'meeting_link' => $link,
            'participant_count' => count($participants),
        ]);
    }

}
