<?php
/**
 * Workly Client CRM REST API Controller
 * Includes queue-backed step-by-step bulk CSV analysis, validation, and batch worker processing.
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
        // /api/v1/clients/{clientId}/{sub}/{arg1}/{arg2}
        $clientId = $parts[3] ?? ($_GET['id'] ?? null);
        $sub = $parts[4] ?? null;
        $arg1 = $parts[5] ?? null;
        $arg2 = $parts[6] ?? null;

        if ($method === 'GET' && !$clientId) {
            $this->listClients($user);
            return;
        }
        if ($clientId === 'bulk') {
            AuthMiddleware::requireRoles(['admin', 'team_leader'], $user);
            if ($method === 'POST' && $sub === 'analyze') {
                $this->analyzeBulkCsv();
                return;
            }
            if ($method === 'POST' && $sub === 'jobs' && !$arg1) {
                $this->createBulkJob($user);
                return;
            }
            if ($method === 'POST' && $sub === 'jobs' && $arg1 && $arg2 === 'process') {
                $this->processBulkJobBatch($arg1, $user);
                return;
            }
            if ($method === 'GET' && $sub === 'jobs' && $arg1) {
                $this->getBulkJobStatus($arg1, $user);
                return;
            }
            if ($method === 'POST' && !$sub) {
                $this->bulkImport($user);
                return;
            }
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

    private function ensureBulkQueueTables(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS bulk_import_jobs (
                    id VARCHAR(64) PRIMARY KEY,
                    created_by_id VARCHAR(64) NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    filename VARCHAR(255) NOT NULL DEFAULT 'clients_import.csv',
                    column_mapping_json JSONB NOT NULL DEFAULT '{}'::jsonb,
                    default_va_id VARCHAR(64) NOT NULL,
                    team_leader_id VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'queued',
                    total_rows INTEGER NOT NULL DEFAULT 0,
                    processed_rows INTEGER NOT NULL DEFAULT 0,
                    succeeded_rows INTEGER NOT NULL DEFAULT 0,
                    failed_rows INTEGER NOT NULL DEFAULT 0,
                    error_log_json JSONB NOT NULL DEFAULT '[]'::jsonb,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS bulk_import_items (
                    id BIGSERIAL PRIMARY KEY,
                    job_id VARCHAR(64) NOT NULL REFERENCES bulk_import_jobs(id) ON DELETE CASCADE,
                    row_index INTEGER NOT NULL,
                    payload_json JSONB NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'queued',
                    error_message TEXT DEFAULT NULL,
                    created_client_id VARCHAR(64) DEFAULT NULL,
                    processed_at TIMESTAMPTZ DEFAULT NULL
                )
            ");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_bulk_items_job_status ON bulk_import_items (job_id, status, row_index)");
        } catch (Throwable) {
        }
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
        try {
            $id = $this->insertSingle($input, $user, true);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }
        $this->invalidate();
        AuditLogger::log('CLIENT_CREATED', 'success', "Onboarded client {$id}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function analyzeBulkCsv(): void
    {
        $rawText = '';
        $filename = 'pasted_clients.csv';

        if (!empty($_FILES['csv_file']['tmp_name']) && is_file((string) $_FILES['csv_file']['tmp_name'])) {
            $filename = SecurityHelper::sanitizePlainText(basename((string) ($_FILES['csv_file']['name'] ?? 'upload.csv')), 191) ?: 'upload.csv';
            $rawText = (string) @file_get_contents((string) $_FILES['csv_file']['tmp_name']);
        } else {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'multipart/form-data') !== false) {
                $rawText = (string) ($_POST['csv_data'] ?? '');
            } else {
                $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
                $rawText = (string) ($input['csv_data'] ?? '');
                if (!empty($input['filename'])) {
                    $filename = SecurityHelper::sanitizePlainText((string) $input['filename'], 191);
                }
            }
        }

        // Remove UTF-8 BOM if present
        $rawText = preg_replace('/^\xEF\xBB\xBF/', '', trim($rawText)) ?? trim($rawText);
        if ($rawText === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Upload a CSV file or paste CSV rows to analyze.']);
            return;
        }

        $lines = preg_split("/\r\n|\n|\r/", $rawText) ?: [];
        $lines = array_values(array_filter($lines, static fn(string $l): bool => trim($l) !== ''));
        if (count($lines) < 2) {
            http_response_code(422);
            echo json_encode(['error' => 'CSV must contain a header label row plus at least one data row.']);
            return;
        }

        // Auto-detect delimiter (, ; \t |)
        $firstLine = $lines[0];
        $delimiters = [',', ';', "\t", '|'];
        $bestDelim = ',';
        $bestCount = 0;
        foreach ($delimiters as $d) {
            $c = count(str_getcsv($firstLine, $d, '"', ''));
            if ($c > $bestCount) {
                $bestCount = $c;
                $bestDelim = $d;
            }
        }

        $rawHeaders = str_getcsv($firstLine, $bestDelim, '"', '');
        $headers = [];
        foreach ($rawHeaders as $idx => $h) {
            $clean = SecurityHelper::sanitizePlainText(trim((string) $h), 80);
            $headers[] = $clean !== '' ? $clean : ('Column ' . ($idx + 1));
        }

        // Suggest mapping from detected header labels
        $aliases = [
            'agency_name'                => ['agency', 'agency name', 'company', 'organization', 'account', 'business', 'client', 'client name'],
            'name'                       => ['name', 'contact', 'contact name', 'full name', 'executive', 'representative', 'person', 'owner'],
            'email'                      => ['email', 'e-mail', 'contact email', 'work email', 'mail'],
            'phone'                      => ['phone', 'telephone', 'mobile', 'tel', 'phone number'],
            'contracted_hours_per_month' => ['hours', 'contracted hours', 'monthly hours', 'retainer hours', 'hrs'],
            'country'                    => ['country', 'nation', 'region'],
            'state'                      => ['state', 'province', 'city', 'location'],
            'website'                    => ['website', 'url', 'domain', 'site'],
            'contract_status'            => ['status', 'contract status', 'state'],
            'internal_notes'             => ['notes', 'note', 'comments', 'description'],
        ];

        $suggestedMapping = [];
        foreach ($headers as $idx => $hdr) {
            $norm = strtolower(str_replace(['_', '-'], ' ', trim($hdr)));
            $matched = '';
            foreach ($aliases as $crmField => $candidates) {
                if (in_array($crmField, $suggestedMapping, true)) {
                    continue;
                }
                if (in_array($norm, $candidates, true)) {
                    $matched = $crmField;
                    break;
                }
            }
            if ($matched === '') {
                foreach ($aliases as $crmField => $candidates) {
                    if (in_array($crmField, $suggestedMapping, true)) {
                        continue;
                    }
                    foreach ($candidates as $cand) {
                        if (str_contains($norm, $cand)) {
                            $matched = $crmField;
                            break 2;
                        }
                    }
                }
            }
            $suggestedMapping[(string) $idx] = $matched;
        }

        $rows = [];
        $maxRows = min(count($lines), 501);
        for ($i = 1; $i < $maxRows; $i++) {
            $parsed = str_getcsv($lines[$i], $bestDelim, '"', '');
            $rowObj = [];
            $hasAny = false;
            foreach ($headers as $idx => $hdr) {
                $val = SecurityHelper::sanitizePlainText(trim((string) ($parsed[$idx] ?? '')), 500);
                if ($val !== '') {
                    $hasAny = true;
                }
                $rowObj[] = $val;
            }
            if ($hasAny) {
                $rows[] = $rowObj;
            }
        }

        if (empty($rows)) {
            http_response_code(422);
            echo json_encode(['error' => 'No non-empty client rows detected after the header row.']);
            return;
        }

        echo json_encode([
            'success'           => true,
            'filename'          => $filename,
            'delimiter'         => $bestDelim === "\t" ? 'TAB' : $bestDelim,
            'headers'           => $headers,
            'suggested_mapping' => $suggestedMapping,
            'rows'              => $rows,
            'total_rows'        => count($rows),
        ]);
    }

    private function createBulkJob(array $user): void
    {
        $this->ensureBulkQueueTables();
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $filename = SecurityHelper::sanitizePlainText($input['filename'] ?? 'clients_import.csv', 191);
        $mapping = is_array($input['mapping'] ?? null) ? $input['mapping'] : [];
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        $defaultVaId = SecurityHelper::sanitizePlainText($input['default_va_id'] ?? '', 64);

        if (empty($rows)) {
            http_response_code(422);
            echo json_encode(['error' => 'No verified CSV rows provided to enqueue.']);
            return;
        }
        if ($defaultVaId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Please select a default Assigned Virtual Assistant.']);
            return;
        }

        // Verify required field mappings exist (agency_name, name, email)
        $mappedFields = array_values(array_filter(array_map('strval', $mapping)));
        foreach (['agency_name', 'name', 'email'] as $reqField) {
            if (!in_array($reqField, $mappedFields, true)) {
                http_response_code(422);
                echo json_encode(['error' => "Required CRM column not mapped: {$reqField}"]);
                return;
            }
        }

        $jobId = 'bjob-' . bin2hex(random_bytes(5));
        $total = count($rows);

        $this->db->beginTransaction();
        try {
            $jStmt = $this->db->prepare("
                INSERT INTO bulk_import_jobs (id, created_by_id, filename, column_mapping_json, default_va_id, team_leader_id, status, total_rows)
                VALUES (:id, :uid, :fname, :map, :va, :tl, 'queued', :tot)
            ");
            $jStmt->execute([
                ':id'    => $jobId,
                ':uid'   => $user['id'],
                ':fname' => $filename,
                ':map'   => json_encode($mapping),
                ':va'    => $defaultVaId,
                ':tl'    => $user['id'],
                ':tot'   => $total,
            ]);

            $iStmt = $this->db->prepare("
                INSERT INTO bulk_import_items (job_id, row_index, payload_json, status)
                VALUES (:jid, :idx, :payload, 'queued')
            ");
            foreach ($rows as $idx => $row) {
                $iStmt->execute([
                    ':jid'     => $jobId,
                    ':idx'     => (int) $idx + 1,
                    ':payload' => json_encode($row),
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Failed to stage bulk import job queue.']);
            return;
        }

        http_response_code(201);
        echo json_encode([
            'success'    => true,
            'job_id'     => $jobId,
            'total_rows' => $total,
            'status'     => 'queued',
        ]);
    }

    private function processBulkJobBatch(string $jobId, array $user): void
    {
        $this->ensureBulkQueueTables();

        $jStmt = $this->db->prepare('SELECT * FROM bulk_import_jobs WHERE id = :id LIMIT 1');
        $jStmt->execute([':id' => $jobId]);
        $job = $jStmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            http_response_code(404);
            echo json_encode(['error' => 'Import job not found']);
            return;
        }

        if ($user['role'] !== 'admin' && (string) $job['created_by_id'] !== (string) $user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }

        $mapping = json_decode((string) $job['column_mapping_json'], true) ?: [];
        $errorLog = json_decode((string) $job['error_log_json'], true) ?: [];

        $this->db->beginTransaction();
        try {
            $batchStmt = $this->db->prepare("
                SELECT id, row_index, payload_json
                FROM bulk_import_items
                WHERE job_id = :jid AND status = 'queued'
                ORDER BY row_index ASC
                LIMIT 5
                FOR UPDATE SKIP LOCKED
            ");
            $batchStmt->execute([':jid' => $jobId]);
            $items = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                $this->db->prepare("UPDATE bulk_import_jobs SET status = 'completed', updated_at = NOW() WHERE id = :id")->execute([':id' => $jobId]);
                $this->db->commit();
                $this->invalidate();
                echo json_encode([
                    'success'        => true,
                    'job_id'         => $jobId,
                    'status'         => 'completed',
                    'total_rows'     => (int) $job['total_rows'],
                    'processed_rows' => (int) $job['processed_rows'],
                    'succeeded_rows' => (int) $job['succeeded_rows'],
                    'failed_rows'    => (int) $job['failed_rows'],
                    'errors'         => $errorLog,
                ]);
                return;
            }

            $doneCount = 0;
            $failCount = 0;
            $updOk = $this->db->prepare("UPDATE bulk_import_items SET status = 'completed', created_client_id = :cid, processed_at = NOW() WHERE id = :id");
            $updErr = $this->db->prepare("UPDATE bulk_import_items SET status = 'failed', error_message = :err, processed_at = NOW() WHERE id = :id");

            foreach ($items as $item) {
                $rawArr = json_decode((string) $item['payload_json'], true) ?: [];
                $mapped = [
                    'focus_va_id'    => (string) $job['default_va_id'],
                    'team_leader_id' => (string) $job['team_leader_id'],
                ];
                foreach ($mapping as $colIdx => $crmField) {
                    $field = trim((string) $crmField);
                    if ($field === '') {
                        continue;
                    }
                    $val = $rawArr[(int) $colIdx] ?? ($rawArr[(string) $colIdx] ?? '');
                    if ($val !== '') {
                        $mapped[$field] = $val;
                    }
                }

                try {
                    $createdId = $this->insertSingle($mapped, $user, true);
                    $updOk->execute([':cid' => $createdId, ':id' => $item['id']]);
                    $doneCount++;
                } catch (Throwable $ex) {
                    $msg = "Row #{$item['row_index']}: " . $ex->getMessage();
                    $updErr->execute([':err' => $ex->getMessage(), ':id' => $item['id']]);
                    $errorLog[] = $msg;
                    $failCount++;
                }
            }

            $remStmt = $this->db->prepare("SELECT COUNT(*)::INTEGER FROM bulk_import_items WHERE job_id = :jid AND status = 'queued'");
            $remStmt->execute([':jid' => $jobId]);
            $remaining = (int) $remStmt->fetchColumn();

            $newProcessed = (int) $job['processed_rows'] + count($items);
            $newSucceeded = (int) $job['succeeded_rows'] + $doneCount;
            $newFailed = (int) $job['failed_rows'] + $failCount;
            $newStatus = $remaining === 0 ? 'completed' : 'processing';

            $jUpd = $this->db->prepare("
                UPDATE bulk_import_jobs
                SET status = :st,
                    processed_rows = :proc,
                    succeeded_rows = :succ,
                    failed_rows = :fail,
                    error_log_json = :errs,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $jUpd->execute([
                ':st'   => $newStatus,
                ':proc' => $newProcessed,
                ':succ' => $newSucceeded,
                ':fail' => $newFailed,
                ':errs' => json_encode(array_slice($errorLog, -40)),
                ':id'   => $jobId,
            ]);

            $this->db->commit();

            if ($newStatus === 'completed') {
                $this->invalidate();
                AuditLogger::log('CLIENT_BULK_IMPORT', 'success', "Queue job {$jobId} imported {$newSucceeded} clients ({$newFailed} skipped)", $user['id'], $user['role'], $this->db);
            }

            echo json_encode([
                'success'        => true,
                'job_id'         => $jobId,
                'status'         => $newStatus,
                'total_rows'     => (int) $job['total_rows'],
                'processed_rows' => $newProcessed,
                'succeeded_rows' => $newSucceeded,
                'failed_rows'    => $newFailed,
                'remaining_rows' => $remaining,
                'errors'         => array_slice($errorLog, -20),
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Worker batch execution failed.']);
        }
    }

    private function getBulkJobStatus(string $jobId, array $user): void
    {
        $this->ensureBulkQueueTables();
        $stmt = $this->db->prepare('SELECT id, filename, status, total_rows, processed_rows, succeeded_rows, failed_rows, error_log_json FROM bulk_import_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            http_response_code(404);
            echo json_encode(['error' => 'Import job not found']);
            return;
        }
        $job['errors'] = json_decode((string) $job['error_log_json'], true) ?: [];
        unset($job['error_log_json']);
        echo json_encode(['success' => true, 'job' => $job]);
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
            try {
                $this->insertSingle($row, $user, true);
                $count++;
            } catch (InvalidArgumentException $e) {
                http_response_code(422);
                echo json_encode(['error' => $e->getMessage()]);
                return;
            }
        }

        $this->invalidate();
        AuditLogger::log('CLIENT_BULK_IMPORT', 'success', "Imported {$count} client accounts", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'imported' => $count]);
    }

    private function insertSingle(array $input, array $actor, bool $throwOnValidation = false): string
    {
        $id = 'client-' . bin2hex(random_bytes(5));
        $agency = SecurityHelper::sanitizePlainText($input['agency_name'] ?? '', 191);
        $contact = SecurityHelper::sanitizePlainText($input['name'] ?? '', 191);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
        $focusVaId = SecurityHelper::sanitizePlainText($input['focus_va_id'] ?? '', 64);
        $tlId = SecurityHelper::sanitizePlainText($input['team_leader_id'] ?? $actor['id'], 64);

        if ($agency === '' || $contact === '' || $email === '' || $focusVaId === '') {
            $errMsg = 'Agency name, executive contact, valid email, and assigned VA are required';
            if ($throwOnValidation) {
                throw new InvalidArgumentException($errMsg);
            }
            http_response_code(422);
            echo json_encode(['error' => $errMsg]);
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
