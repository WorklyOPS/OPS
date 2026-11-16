<?php
/**
 * Workly PTO & Absence REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class PtoApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        if ($method === 'POST' && preg_match('#^/api/v1/pto/?$#', $uri)) {
            $this->createPto($user);
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/v1/pto/([^/]+)/review$#', $uri, $m)) {
            $this->reviewPto($m[1], $user);
            return;
        }
        if ($method === 'DELETE' && preg_match('#^/api/v1/pto/([^/]+)$#', $uri, $m)) {
            $this->cancelPto($m[1], $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'PTO API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'pto:');
        CacheService::invalidatePrefix($this->db, 'users:');
    }

    private function createPto(array $user): void
    {
        AuthMiddleware::requireRoles(['va'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $startDate = SecurityHelper::sanitizePlainText($input['start_date'] ?? '', 16);
        $endDate = SecurityHelper::sanitizePlainText($input['end_date'] ?? '', 16);
        $reason = SecurityHelper::sanitizePlainText($input['reason'] ?? '', 255);

        if ($startDate === '' || $endDate === '' || $reason === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Start date, end date, and reason are required']);
            return;
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        if ($end < $start) {
            http_response_code(422);
            echo json_encode(['error' => 'End date cannot precede start date']);
            return;
        }

        $businessDays = 0;
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        foreach ($period as $dt) {
            if ((int) $dt->format('N') <= 5) {
                $businessDays++;
            }
        }

        if ($businessDays <= 0 || $businessDays > 5) {
            http_response_code(422);
            echo json_encode(['error' => 'PTO requests must span 1 to 5 Monday-Friday business days']);
            return;
        }

        $overlap = $this->db->prepare("
            SELECT id FROM pto_requests
            WHERE va_id = :va AND status IN ('pending', 'approved')
              AND start_date <= :end_d AND end_date >= :start_d
            LIMIT 1
        ");
        $overlap->execute([':va' => $user['id'], ':start_d' => $startDate, ':end_d' => $endDate]);
        if ($overlap->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Date range overlaps an existing pending or approved PTO request']);
            return;
        }

        $id = 'pto-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO pto_requests (id, va_id, va_name, start_date, end_date, business_days_count, reason, status)
            VALUES (:id, :va, :name, :start_d, :end_d, :days, :reason, 'pending')
        ");
        $stmt->execute([
            ':id'      => $id,
            ':va'      => $user['id'],
            ':name'    => $user['name'],
            ':start_d' => $startDate,
            ':end_d'   => $endDate,
            ':days'    => $businessDays,
            ':reason'  => $reason,
        ]);

        $this->invalidate();
        AuditLogger::log('PTO_SUBMITTED', 'success', "Submitted {$businessDays}d PTO ({$startDate} to {$endDate})", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id, 'business_days' => $businessDays]);
    }

    private function reviewPto(string $id, array $user): void
    {
        AuthMiddleware::requireRoles(['hrm', 'admin'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $status = $input['status'] ?? '';
        $note = SecurityHelper::sanitizePlainText($input['rejection_note'] ?? '', 1000);

        if (!in_array($status, ['approved', 'rejected'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid review status']);
            return;
        }

        $rowStmt = $this->db->prepare('SELECT * FROM pto_requests WHERE id = :id LIMIT 1');
        $rowStmt->execute([':id' => $id]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'PTO request not found']);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE pto_requests
            SET status = :status, reviewed_by = :reviewer, reviewed_at = NOW(), rejection_note = :note
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'   => $status,
            ':reviewer' => $user['name'],
            ':note'     => $note !== '' ? $note : null,
            ':id'       => $id,
        ]);

        if ($status === 'approved' && $row['status'] !== 'approved') {
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

        $this->invalidate();
        AuditLogger::log('PTO_REVIEWED', 'success', "PTO {$id} marked {$status}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'status' => $status]);
    }

    private function cancelPto(string $id, array $user): void
    {
        $stmt = $this->db->prepare("UPDATE pto_requests SET status = 'cancelled' WHERE id = :id AND va_id = :va_id AND status = 'pending'");
        $stmt->execute([':id' => $id, ':va_id' => $user['id']]);
        $this->invalidate();
        AuditLogger::log('PTO_CANCELLED', 'warning', "Cancelled PTO {$id}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true]);
    }
}
