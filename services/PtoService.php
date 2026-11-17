<?php
/**
 * Workly PTO Service (PHP 8 + PostgreSQL)
 * Handles PTO submissions, Pending/Approved/Rejected status tracking,
 * overlap/business-day rules, and Team Lead notification triggers.
 */

declare(strict_types=1);

require_once __DIR__ . '/CacheService.php';
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/SecurityHelper.php';

final class PtoService
{
    public function __construct(private PDO $db) {}

    /**
     * Submits a new PTO request (Pending), lets PostgreSQL trg_pto_status_and_notify
     * write tracking/notification rows, and sends an internal alert thread to the VA's Team Lead.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function submitRequest(array $vaUser, string $startDate, string $endDate, string $reason): array
    {
        $cleanStart = SecurityHelper::sanitizePlainText($startDate, 16);
        $cleanEnd = SecurityHelper::sanitizePlainText($endDate, 16);
        $cleanReason = SecurityHelper::sanitizePlainText($reason, 255);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleanEnd) || $cleanReason === '') {
            throw new InvalidArgumentException('Valid start date (YYYY-MM-DD), end date, and reason are required.', 422);
        }

        $start = new DateTimeImmutable($cleanStart);
        $end = new DateTimeImmutable($cleanEnd);
        if ($end < $start) {
            throw new InvalidArgumentException('End date cannot precede start date.', 422);
        }

        $businessDays = $this->calculateBusinessDays($start, $end);
        if ($businessDays <= 0 || $businessDays > 5) {
            throw new InvalidArgumentException('PTO requests must span 1 to 5 Monday-Friday business days.', 422);
        }

        $balance = (int) ($vaUser['pto_balance_days'] ?? 0);
        if ($businessDays > $balance) {
            throw new InvalidArgumentException("Insufficient PTO balance ({$balance} day(s) remaining).", 422);
        }

        $overlap = $this->db->prepare("
            SELECT id FROM pto_requests
            WHERE va_id = :va
              AND status IN ('pending', 'approved')
              AND start_date <= :end_d
              AND end_date >= :start_d
            LIMIT 1
        ");
        $overlap->execute([
            ':va'      => $vaUser['id'],
            ':start_d' => $cleanStart,
            ':end_d'   => $cleanEnd,
        ]);
        if ($overlap->fetch()) {
            throw new RuntimeException('Date range overlaps an existing pending or approved PTO request.', 409);
        }

        $ptoId = 'pto-' . bin2hex(random_bytes(5));
        $tlNotified = false;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO pto_requests (id, va_id, va_name, start_date, end_date, business_days_count, reason, status)
                VALUES (:id, :va, :name, :start_d, :end_d, :days, :reason, 'pending')
            ");
            $stmt->execute([
                ':id'      => $ptoId,
                ':va'      => $vaUser['id'],
                ':name'    => $vaUser['name'],
                ':start_d' => $cleanStart,
                ':end_d'   => $cleanEnd,
                ':days'    => $businessDays,
                ':reason'  => $cleanReason,
            ]);

            // Ensure status tracking actor metadata is attached to the initial Pending row
            $trk = $this->db->prepare("
                UPDATE pto_status_tracking
                SET changed_by_id = :actor_id,
                    changed_by_role = :actor_role,
                    tracking_note = :note
                WHERE pto_request_id = :pid AND current_status = 'Pending' AND changed_by_id IS NULL
            ");
            $trk->execute([
                ':actor_id'   => $vaUser['id'],
                ':actor_role' => $vaUser['role'],
                ':note'       => "Submitted {$businessDays} business day(s): {$cleanReason}",
                ':pid'        => $ptoId,
            ]);

            $tlNotified = $this->dispatchTeamLeadEmailNotification(
                $vaUser,
                $ptoId,
                'Pending',
                $cleanStart,
                $cleanEnd,
                $businessDays,
                $cleanReason
            );

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->invalidateCaches();
        AuditLogger::log(
            'PTO_SUBMITTED',
            'success',
            "Submitted {$businessDays}d PTO ({$cleanStart} to {$cleanEnd})" . ($tlNotified ? ' [Team Lead notified]' : ''),
            (string) $vaUser['id'],
            (string) $vaUser['role'],
            $this->db
        );

        return [
            'id'                 => $ptoId,
            'status'             => 'Pending',
            'business_days'      => $businessDays,
            'team_lead_notified' => $tlNotified,
        ];
    }

    /**
     * Reviews a PTO request (Approved or Rejected), updates status tracking,
     * adjusts balance when approved, and notifies the assigned Team Lead.
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function reviewRequest(string $ptoId, array $reviewer, string $status, string $note = ''): array
    {
        $cleanStatus = strtolower(trim($status));
        if (!in_array($cleanStatus, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid review status. Allowed: approved, rejected.', 422);
        }

        $cleanNote = SecurityHelper::sanitizePlainText($note, 1000);

        $rowStmt = $this->db->prepare('SELECT * FROM pto_requests WHERE id = :id LIMIT 1');
        $rowStmt->execute([':id' => $ptoId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('PTO request not found.', 404);
        }
        if ($row['status'] !== 'pending') {
            throw new RuntimeException('Only pending PTO requests can be reviewed.', 409);
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE pto_requests
                SET status = :status,
                    reviewed_by = :reviewer,
                    reviewed_at = NOW(),
                    rejection_note = :note
                WHERE id = :id
            ");
            $stmt->execute([
                ':status'   => $cleanStatus,
                ':reviewer' => $reviewer['name'],
                ':note'     => $cleanNote !== '' ? $cleanNote : null,
                ':id'       => $ptoId,
            ]);

            $targetStatusLabel = ucfirst($cleanStatus);
            $trk = $this->db->prepare("
                UPDATE pto_status_tracking
                SET changed_by_id = :actor_id,
                    changed_by_role = :actor_role,
                    tracking_note = :note
                WHERE id = (
                    SELECT id FROM pto_status_tracking
                    WHERE pto_request_id = :pid AND current_status = :status_label
                    ORDER BY changed_at DESC
                    LIMIT 1
                )
            ");
            $trk->execute([
                ':actor_id'     => $reviewer['id'],
                ':actor_role'   => $reviewer['role'],
                ':note'         => $cleanNote !== '' ? $cleanNote : "Marked {$targetStatusLabel} by {$reviewer['name']}",
                ':pid'          => $ptoId,
                ':status_label' => $targetStatusLabel,
            ]);

            if ($cleanStatus === 'approved') {
                $upd = $this->db->prepare("
                    UPDATE users
                    SET pto_balance_days = GREATEST(0, pto_balance_days - :days),
                        pto_taken_days = pto_taken_days + :days2,
                        updated_at = NOW()
                    WHERE id = :va_id
                ");
                $upd->execute([
                    ':days'  => (int) $row['business_days_count'],
                    ':days2' => (int) $row['business_days_count'],
                    ':va_id' => $row['va_id'],
                ]);
            }

            $vaStmt = $this->db->prepare('SELECT id, name, email, team_leader_id FROM users WHERE id = :id LIMIT 1');
            $vaStmt->execute([':id' => $row['va_id']]);
            $vaRow = $vaStmt->fetch(PDO::FETCH_ASSOC);
            if ($vaRow) {
                $this->dispatchTeamLeadEmailNotification(
                    $vaRow,
                    $ptoId,
                    $targetStatusLabel,
                    (string) $row['start_date'],
                    (string) $row['end_date'],
                    (int) $row['business_days_count'],
                    $cleanNote !== '' ? $cleanNote : (string) $row['reason']
                );
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->invalidateCaches();
        AuditLogger::log('PTO_REVIEWED', 'success', "PTO {$ptoId} marked {$cleanStatus}", (string) $reviewer['id'], (string) $reviewer['role'], $this->db);

        return ['id' => $ptoId, 'status' => $cleanStatus];
    }

    /**
     * Cancels a pending PTO request owned by the requesting VA.
     */
    public function cancelRequest(string $ptoId, array $user): void
    {
        $stmt = $this->db->prepare("
            UPDATE pto_requests
            SET status = 'cancelled'
            WHERE id = :id AND va_id = :va_id AND status = 'pending'
        ");
        $stmt->execute([':id' => $ptoId, ':va_id' => $user['id']]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Pending PTO request not found or unauthorized.', 404);
        }

        $this->invalidateCaches();
        AuditLogger::log('PTO_CANCELLED', 'warning', "Cancelled PTO {$ptoId}", (string) $user['id'], (string) $user['role'], $this->db);
    }

    /**
     * Returns triggered PTO notifications for a specific Team Leader.
     */
    public function getTeamLeadNotifications(string $teamLeaderId, bool $unreadOnly = false): array
    {
        $sql = "
            SELECT n.*, u.name AS va_name, u.email AS va_email,
                   p.start_date, p.end_date, p.business_days_count, p.status AS pto_status
            FROM team_lead_notifications n
            JOIN users u ON n.va_id = u.id
            JOIN pto_requests p ON n.pto_request_id = p.id
            WHERE n.team_leader_id = :tl
        ";
        if ($unreadOnly) {
            $sql .= ' AND n.is_read = FALSE';
        }
        $sql .= ' ORDER BY n.created_at DESC LIMIT 50';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':tl' => $teamLeaderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Marks a Team Lead PTO notification as read.
     */
    public function markNotificationRead(string $notificationId, string $teamLeaderId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE team_lead_notifications
            SET is_read = TRUE, read_at = NOW()
            WHERE id = :id AND team_leader_id = :tl
        ");
        $stmt->execute([':id' => $notificationId, ':tl' => $teamLeaderId]);
        CacheService::invalidatePrefix($this->db, 'alerts:');
        return $stmt->rowCount() > 0;
    }

    /**
     * Returns chronological status transition history (Pending -> Approved / Rejected) for a PTO request.
     */
    public function getStatusHistory(string $ptoId, array $viewer): array
    {
        $pStmt = $this->db->prepare("
            SELECT p.*, u.team_leader_id
            FROM pto_requests p
            JOIN users u ON p.va_id = u.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $pStmt->execute([':id' => $ptoId]);
        $req = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            throw new RuntimeException('PTO request not found.', 404);
        }

        if ($viewer['role'] === 'va' && $req['va_id'] !== $viewer['id']) {
            throw new RuntimeException('Forbidden: Cannot view status history for another VA.', 403);
        }
        if ($viewer['role'] === 'team_leader' && $req['team_leader_id'] !== $viewer['id']) {
            throw new RuntimeException('Forbidden: VA is outside your supervised pod.', 403);
        }

        $hStmt = $this->db->prepare("
            SELECT h.*, u.name AS changed_by_name
            FROM pto_status_tracking h
            LEFT JOIN users u ON h.changed_by_id = u.id
            WHERE h.pto_request_id = :pid
            ORDER BY h.changed_at ASC
        ");
        $hStmt->execute([':pid' => $ptoId]);
        return $hStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function calculateBusinessDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $days = 0;
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($period as $dt) {
            if ((int) $dt->format('N') <= 5) {
                $days++;
            }
        }
        return $days;
    }

    private function dispatchTeamLeadEmailNotification(
        array $vaRow,
        string $ptoId,
        string $statusLabel,
        string $startDate,
        string $endDate,
        int $businessDays,
        string $notes
    ): bool {
        $tlId = (string) ($vaRow['team_leader_id'] ?? '');
        if ($tlId === '') {
            return false;
        }

        $tlStmt = $this->db->prepare('SELECT id, name, email FROM users WHERE id = :id AND is_active = TRUE LIMIT 1');
        $tlStmt->execute([':id' => $tlId]);
        $tl = $tlStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tl) {
            return false;
        }

        $subject = "[PTO {$statusLabel}] {$vaRow['name']} ({$startDate} to {$endDate})";
        $body = "PTO Status Update: {$statusLabel}\n"
            . "Virtual Assistant: {$vaRow['name']} ({$vaRow['email']})\n"
            . "Request ID: {$ptoId}\n"
            . "Dates: {$startDate} to {$endDate} ({$businessDays} business day(s))\n"
            . "Details: {$notes}";

        $eStmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, subject, body, folder, is_read)
            VALUES (:id, :tid, :sname, :semail, :remail, :subject, :body, 'sent', FALSE)
        ");
        $eStmt->execute([
            ':id'      => 'msg-' . bin2hex(random_bytes(5)),
            ':tid'     => 'th-pto-' . substr(md5($ptoId), 0, 8),
            ':sname'   => (string) $vaRow['name'],
            ':semail'  => (string) $vaRow['email'],
            ':remail'  => (string) $tl['email'],
            ':subject' => $subject,
            ':body'    => $body,
        ]);

        return true;
    }

    private function invalidateCaches(): void
    {
        CacheService::invalidatePrefix($this->db, 'pto:');
        CacheService::invalidatePrefix($this->db, 'users:');
        CacheService::invalidatePrefix($this->db, 'alerts:');
        CacheService::invalidatePrefix($this->db, 'emails:');
    }
}
