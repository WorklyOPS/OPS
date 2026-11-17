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
