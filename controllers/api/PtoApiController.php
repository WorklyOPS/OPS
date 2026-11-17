<?php
/**
 * Workly PTO & Absence REST API Controller
 * Delegates submissions, status tracking, Team Lead notifications, and HRM balance management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/PtoService.php';
require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/NotificationApiController.php';

final class PtoApiController
{
    private PtoService $ptoService;

    public function __construct(private PDO $db)
    {
        $this->ptoService = new PtoService($db);
    }

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        if ($method === 'GET' && preg_match('#^/api/v1/pto/calendar/?$#', $uri)) {
            AuthMiddleware::requireRoles(['team_leader', 'hrm', 'admin'], $user);
            $this->getCalendarData($user);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pto/notifications/?$#', $uri)) {
            AuthMiddleware::requireRoles(['team_leader', 'admin'], $user);
            $unreadOnly = !empty($_GET['unread']);
            $items = $this->ptoService->getTeamLeadNotifications((string) $user['id'], $unreadOnly);
            echo json_encode(['data' => $items]);
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/pto/notifications/([^/]+)/read/?$#', $uri, $m)) {
            AuthMiddleware::requireRoles(['team_leader', 'admin'], $user);
            $updated = $this->ptoService->markNotificationRead($m[1], (string) $user['id']);
            echo json_encode(['success' => $updated]);
            return;
        }

        if (($method === 'PATCH' || $method === 'PUT') && preg_match('#^/api/v1/pto/balances/([^/]+)/?$#', $uri, $m)) {
            AuthMiddleware::requireRoles(['admin', 'hrm'], $user);
            $this->updateBalance($m[1], $user);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/pto/([^/]+)/history/?$#', $uri, $m)) {
            $this->getHistory($m[1], $user);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/pto/?$#', $uri)) {
            $this->createPto($user);
            return;
        }

        if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/v1/pto/([^/]+)/review/?$#', $uri, $m)) {
            $this->reviewPto($m[1], $user);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/pto/([^/]+)/?$#', $uri, $m)) {
            $this->cancelPto($m[1], $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'PTO API route not found']);
    }

    private function getCalendarData(array $user): void
    {
        $monthParam = SecurityHelper::sanitizePlainText($_GET['month'] ?? date('Y-m'), 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = date('Y-m');
        }

        $monthStartDt = new DateTimeImmutable($monthParam . '-01');
        $monthEndDt = $monthStartDt->modify('last day of this month');
        $mStart = $monthStartDt->format('Y-m-d');
        $mEnd = $monthEndDt->format('Y-m-d');

        $role = (string) ($user['role'] ?? 'team_leader');
        $uid = (string) ($user['id'] ?? '');
        $scopeSql = $role === 'team_leader' ? ' AND u.team_leader_id = :tl ' : '';

        $calSql = "
            SELECT p.id, p.va_id, p.va_name, p.start_date, p.end_date,
                   p.business_days_count, p.reason, p.status, p.reviewed_by, p.rejection_note,
                   p.requested_at, u.avatar_url, u.email AS va_email, u.pto_balance_days,
                   tl.name AS tl_name
            FROM pto_requests p
            JOIN users u ON p.va_id = u.id
            LEFT JOIN users tl ON u.team_leader_id = tl.id
            WHERE p.status IN ('pending', 'approved', 'rejected')
              AND p.start_date <= :m_end
              AND p.end_date >= :m_start
              {$scopeSql}
            ORDER BY p.start_date ASC, CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END
        ";
        $calStmt = $this->db->prepare($calSql);
        $calParams = [':m_start' => $mStart, ':m_end' => $mEnd];
        if ($role === 'team_leader') {
            $calParams[':tl'] = $uid;
        }
        $calStmt->execute($calParams);
        $requests = $calStmt->fetchAll(PDO::FETCH_ASSOC);

        $upSql = "
            SELECT p.id, p.va_id, p.va_name, p.start_date, p.end_date,
                   p.business_days_count, p.reason, p.status, p.reviewed_by,
                   u.avatar_url, u.pto_balance_days, tl.name AS tl_name
            FROM pto_requests p
            JOIN users u ON p.va_id = u.id
            LEFT JOIN users tl ON u.team_leader_id = tl.id
            WHERE p.status IN ('pending', 'approved')
              AND p.end_date >= CURRENT_DATE
              {$scopeSql}
            ORDER BY CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END, p.start_date ASC
            LIMIT 14
        ";
        $upStmt = $this->db->prepare($upSql);
        $upStmt->execute($role === 'team_leader' ? [':tl' => $uid] : []);
        $upcoming = $upStmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute coverage overlap names for active/pending requests
        $activePool = array_merge($requests, $upcoming);
        $overlapsById = [];
        foreach ($activePool as $a) {
            if (!in_array($a['status'], ['pending', 'approved'], true)) {
                continue;
            }
            foreach ($activePool as $b) {
                if ($a['id'] === $b['id'] || $a['va_id'] === $b['va_id'] || !in_array($b['status'], ['pending', 'approved'], true)) {
                    continue;
                }
                if ($a['start_date'] <= $b['end_date'] && $a['end_date'] >= $b['start_date']) {
                    $overlapsById[$a['id']][] = (string) $b['va_name'];
                }
            }
            if (!empty($overlapsById[$a['id']])) {
                $overlapsById[$a['id']] = array_values(array_unique($overlapsById[$a['id']]));
            }
        }

        foreach ($requests as &$r) {
            $r['overlaps'] = $overlapsById[$r['id']] ?? [];
        }
        unset($r);
        foreach ($upcoming as &$uRow) {
            $uRow['overlaps'] = $overlapsById[$uRow['id']] ?? [];
        }
        unset($uRow);

        echo json_encode([
            'success'      => true,
            'month'        => $monthParam,
            'month_label'  => $monthStartDt->format('F Y'),
            'prev_month'   => $monthStartDt->modify('-1 month')->format('Y-m'),
            'next_month'   => $monthStartDt->modify('+1 month')->format('Y-m'),
            'can_approve'  => in_array($role, ['hrm', 'admin'], true),
            'requests'     => $requests,
            'upcoming'     => $upcoming,
        ]);
    }

    private function updateBalance(string $vaId, array $actor): void
    {
        $cleanId = SecurityHelper::sanitizePlainText($vaId, 64);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $balanceDays = isset($input['pto_balance_days']) ? (int) $input['pto_balance_days'] : null;
        $takenDays = isset($input['pto_taken_days']) ? (int) $input['pto_taken_days'] : null;

        if ($balanceDays === null || $balanceDays < 0 || $balanceDays > 365) {
            http_response_code(422);
            echo json_encode(['error' => 'Available PTO days must be between 0 and 365.']);
            return;
        }
        if ($takenDays !== null && ($takenDays < 0 || $takenDays > 365)) {
            http_response_code(422);
            echo json_encode(['error' => 'Recorded PTO days taken must be between 0 and 365.']);
            return;
        }

        $chk = $this->db->prepare('SELECT id, name FROM users WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $cleanId]);
        $target = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            http_response_code(404);
            echo json_encode(['error' => 'Virtual Assistant not found']);
            return;
        }

        if ($takenDays !== null) {
            $stmt = $this->db->prepare('UPDATE users SET pto_balance_days = :bal, pto_taken_days = :taken, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':bal' => $balanceDays, ':taken' => $takenDays, ':id' => $cleanId]);
        } else {
            $stmt = $this->db->prepare('UPDATE users SET pto_balance_days = :bal, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':bal' => $balanceDays, ':id' => $cleanId]);
        }

        NotificationApiController::notifyUser(
            $this->db,
            $cleanId,
            'pto',
            "PTO Allowance Updated: {$balanceDays} day(s) available",
            "Adjusted by {$actor['name']} (" . strtoupper((string) $actor['role']) . ")",
            '/pto',
            'bal-' . $cleanId
        );

        CacheService::invalidatePrefix($this->db, 'pto:');
        CacheService::invalidatePrefix($this->db, 'users:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
        CacheService::invalidatePrefix($this->db, 'alerts:');

        AuditLogger::log('PTO_BALANCE_UPDATED', 'success', "Updated PTO balance for {$target['name']} (#{$cleanId}) to {$balanceDays}d", (string) $actor['id'], (string) $actor['role'], $this->db);

        echo json_encode([
            'success'          => true,
            'va_id'            => $cleanId,
            'pto_balance_days' => $balanceDays,
            'pto_taken_days'   => $takenDays,
        ]);
    }

    private function createPto(array $user): void
    {
        AuthMiddleware::requireRoles(['va'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        try {
            $result = $this->ptoService->submitRequest(
                $user,
                (string) ($input['start_date'] ?? ''),
                (string) ($input['end_date'] ?? ''),
                (string) ($input['reason'] ?? '')
            );
            http_response_code(201);
            echo json_encode(array_merge(['success' => true], $result));
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function reviewPto(string $id, array $user): void
    {
        AuthMiddleware::requireRoles(['hrm', 'admin'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        try {
            $result = $this->ptoService->reviewRequest(
                $id,
                $user,
                (string) ($input['status'] ?? ''),
                (string) ($input['rejection_note'] ?? '')
            );
            echo json_encode(array_merge(['success' => true], $result));
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function cancelPto(string $id, array $user): void
    {
        try {
            $this->ptoService->cancelRequest($id, $user);
            echo json_encode(['success' => true]);
        } catch (RuntimeException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 404);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function getHistory(string $id, array $user): void
    {
        try {
            $history = $this->ptoService->getStatusHistory($id, $user);
            echo json_encode(['data' => $history]);
        } catch (RuntimeException $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 403);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
