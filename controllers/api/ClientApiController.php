<?php
/**
 * Workly Client CRM REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class ClientApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        $clientId = $parts[3] ?? ($_GET['id'] ?? null);
        $sub = $parts[4] ?? null;

        if ($method === 'GET' && !$clientId) {
            $this->listClients($user);
            return;
        }
        if ($method === 'POST' && $clientId === 'bulk') {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $user);
            $this->bulkImport($user);
            return;
        }
        if ($method === 'POST' && !$clientId) {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $user);
            $this->createClient($user);
            return;
        }
        if ($method === 'POST' && $clientId && $sub === 'activities') {
            $this->addActivity($clientId, $user);
            return;
        }
        if (($method === 'PUT' || $method === 'PATCH') && $clientId && !$sub) {
            AuthMiddleware::requireRoles(['admin', 'team_leader', 'hrm'], $user);
            $this->updateClient($clientId, $user);
            return;
        }
        if ($method === 'DELETE' && $clientId) {
            AuthMiddleware::requireRoles(['admin'], $user);
            $this->deleteClient($clientId, $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Client route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'clients:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
    }

    private function listClients(array $user): void
    {
        $search = trim($_GET['q'] ?? '');
        $role = $user['role'];
        $uid = $user['id'];
        $hit = false;

        $payload = CacheService::remember($this->db, "clients:{$role}:{$uid}:" . md5($search), 45, function () use ($role, $uid, $search): array {
            $sql = 'SELECT * FROM clients WHERE 1=1';
            $params = [];

            if ($role === 'va') {
                $sql .= ' AND focus_va_id = :va_id';
                $params[':va_id'] = $uid;
            } elseif ($role === 'team_leader') {
                $sql .= ' AND team_leader_id = :tl_id';
                $params[':tl_id'] = $uid;
            }

            if ($search !== '') {
                $sql .= ' AND (agency_name ILIKE :q OR name ILIKE :q OR email ILIKE :q)';
                $params[':q'] = "%{$search}%";
            }

            $sql .= ' ORDER BY agency_name ASC';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }, $hit);

        CacheService::sendJsonWithEtag($payload, $hit, 30);
    }

    private function createClient(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $id = $this->insertSingle($input, $user);
        $this->invalidate();
        AuditLogger::log('CLIENT_CREATED', 'success', "Onboarded client {$id}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function bulkImport(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $rows = $input['clients'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            http_response_code(400);
            echo json_encode(['error' => 'No rows supplied']);
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (empty($row['agency_name']) && empty($row['name'])) {
                continue;
            }
            $this->insertSingle($row, $user);
            $count++;
        }

        $this->invalidate();
        AuditLogger::log('CLIENT_BULK_IMPORT', 'success', "Imported {$count} client accounts", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'imported' => $count]);
    }

    private function insertSingle(array $input, array $actor): string
    {
        $id = 'client-' . bin2hex(random_bytes(5));
        $agency = SecurityHelper::sanitizePlainText($input['agency_name'] ?? '', 191);
        $contact = SecurityHelper::sanitizePlainText($input['name'] ?? '', 191);
        $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $focusVaId = SecurityHelper::sanitizePlainText($input['focus_va_id'] ?? '', 64);
        $tlId = SecurityHelper::sanitizePlainText($input['team_leader_id'] ?? $actor['id'], 64);

        if ($agency === '' || $contact === '' || $email === '' || $focusVaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Agency name, executive contact, email, and assigned VA are required']);
            exit;
        }

        $this->ensureAvailabilityColumn();
        $availabilitySlots = self::normalizeAvailabilitySlots($input['availability_slots'] ?? null);

        $stmt = $this->db->prepare("
            INSERT INTO clients (
                id, name, agency_name, email, phone, state, country, website, linkedin,
                additional_email, additional_phone, description, focus_va_id, team_leader_id,
                contract_status, contracted_hours_per_month, availability_slots, internal_notes
            ) VALUES (
                :id, :name, :agency, :email, :phone, :state, :country, :website, :linkedin,
                :add_email, :add_phone, :description, :va_id, :tl_id,
                :status, :hours, :avail, :notes
            )
        ");
        $stmt->execute([
            ':id'          => $id,
            ':name'        => $contact,
            ':agency'      => $agency,
            ':email'       => $email,
            ':phone'       => SecurityHelper::sanitizePlainText($input['phone'] ?? '', 64),
            ':state'       => SecurityHelper::sanitizePlainText($input['state'] ?? '', 64),
            ':country'     => SecurityHelper::sanitizePlainText($input['country'] ?? 'United States', 64),
            ':website'     => SecurityHelper::sanitizeUrl($input['website'] ?? ''),
            ':linkedin'    => SecurityHelper::sanitizeUrl($input['linkedin'] ?? ''),
            ':add_email'   => filter_var($input['additional_email'] ?? '', FILTER_SANITIZE_EMAIL),
            ':add_phone'   => SecurityHelper::sanitizePlainText($input['additional_phone'] ?? '', 64),
            ':description' => SecurityHelper::sanitizePlainText($input['description'] ?? '', 2000),
            ':va_id'       => $focusVaId,
            ':tl_id'       => $tlId,
            ':status'      => in_array($input['contract_status'] ?? '', ['active', 'pending', 'cancelled'], true) ? $input['contract_status'] : 'active',
            ':hours'       => max(1.0, (float) ($input['contracted_hours_per_month'] ?? 40)),
            ':avail'       => $availabilitySlots,
            ':notes'       => SecurityHelper::sanitizePlainText($input['internal_notes'] ?? '', 2000),
        ]);

        $act = $this->db->prepare("
            INSERT INTO client_activities (id, client_id, author_id, author_name, description, type)
            VALUES (:id, :cid, :aid, :aname, :desc, 'update')
        ");
        $act->execute([
            ':id'    => 'act-' . bin2hex(random_bytes(5)),
            ':cid'   => $id,
            ':aid'   => $actor['id'],
            ':aname' => $actor['name'],
            ':desc'  => "Client account onboarded by {$actor['name']}.",
        ]);

        return $id;
    }

    private function ensureAvailabilityColumn(): void
    {
        try {
            $this->db->exec('ALTER TABLE clients ADD COLUMN IF NOT EXISTS availability_slots TEXT DEFAULT NULL');
        } catch (Throwable) {
        }
    }

    public static function normalizeAvailabilitySlots(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($arr)) {
            return null;
        }
        $validDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $cleaned = [];
        foreach ($arr as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $day = ucfirst(strtolower(substr((string) ($slot['day'] ?? 'Mon'), 0, 3)));
            if (!in_array($day, $validDays, true)) {
                $day = 'Mon';
            }
            $start = (string) ($slot['start'] ?? '');
            $end = (string) ($slot['end'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $end <= $start) {
                continue;
            }
            $cleaned[] = ['day' => $day, 'start' => $start, 'end' => $end];
        }
        return empty($cleaned) ? null : json_encode($cleaned);
    }

    private function updateClient(string $clientId, array $user): void
    {
        $this->ensureAvailabilityColumn();
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $allowed = [
            'agency_name', 'name', 'email', 'phone', 'state', 'country', 'website', 'linkedin',
            'additional_email', 'additional_phone', 'description', 'focus_va_id', 'team_leader_id',
            'contract_status', 'contracted_hours_per_month', 'consumed_hours_month', 'availability_slots', 'internal_notes'
        ];
        $fields = [];
        $params = [':id' => $clientId];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $input)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            if ($col === 'availability_slots') {
                $params[':availability_slots'] = self::normalizeAvailabilitySlots($input[$col]);
            } elseif ($col === 'website' || $col === 'linkedin') {
                $params[":{$col}"] = SecurityHelper::sanitizeUrl((string) $input[$col]);
            } elseif ($col === 'contract_status') {
                $params[':contract_status'] = in_array($input[$col], ['active', 'pending', 'cancelled'], true) ? $input[$col] : 'active';
            } elseif (is_string($input[$col])) {
                $params[":{$col}"] = SecurityHelper::sanitizePlainText($input[$col]);
            } else {
                $params[":{$col}"] = $input[$col];
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid fields provided']);
            return;
        }

        $stmt = $this->db->prepare('UPDATE clients SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);

        $this->invalidate();
        AuditLogger::log('CLIENT_UPDATED', 'success', "Updated client {$clientId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $clientId]);
    }

    private function addActivity(string $clientId, array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $desc = SecurityHelper::sanitizePlainText($input['description'] ?? '', 2000);
        $type = in_array($input['type'] ?? '', ['contract', 'meeting', 'note', 'update'], true) ? $input['type'] : 'note';
        if ($desc === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Activity description is required']);
            return;
        }

        $id = 'act-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO client_activities (id, client_id, author_id, author_name, description, type)
            VALUES (:id, :cid, :aid, :aname, :desc, :type)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':cid'   => $clientId,
            ':aid'   => $user['id'],
            ':aname' => $user['name'],
            ':desc'  => $desc,
            ':type'  => $type,
        ]);

        $this->invalidate();
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function deleteClient(string $clientId, array $user): void
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE id = :id');
        $stmt->execute([':id' => $clientId]);
        $this->invalidate();
        AuditLogger::log('CLIENT_DELETED', 'warning', "Deleted client {$clientId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $clientId]);
    }
}
