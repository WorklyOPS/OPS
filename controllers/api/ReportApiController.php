<?php
/**
 * Workly Custom Date-Range SLA Performance Report REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';

final class ReportApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        $sub = $parts[3] ?? 'generate';

        if ($method === 'GET' && ($sub === 'generate' || $sub === '')) {
            $this->generateReport($user);
            return;
        }
        if ($method === 'POST' && $sub === 'dispatch') {
            $this->dispatchReport($user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Report API route not found']);
    }

    private function generateReport(array $user): void
    {
        $startDate = SecurityHelper::sanitizePlainText($_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week')), 16);
        $endDate = SecurityHelper::sanitizePlainText($_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week')), 16);
        $requestedVa = SecurityHelper::sanitizePlainText($_GET['va_id'] ?? '', 64);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            http_response_code(422);
            echo json_encode(['error' => 'Valid YYYY-MM-DD start_date and end_date are required']);
            return;
        }

        if ($endDate < $startDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        // Strict RBAC scope for reporting
        $scopeVaId = null;
        if ($user['role'] === 'va') {
            $scopeVaId = $user['id'];
        } elseif ($requestedVa !== '' && $requestedVa !== 'all') {
            if ($user['role'] === 'team_leader' && $requestedVa !== $user['id']) {
                $chk = $this->db->prepare('SELECT id FROM users WHERE id = :id AND team_leader_id = :tl LIMIT 1');
                $chk->execute([':id' => $requestedVa, ':tl' => $user['id']]);
                if (!$chk->fetch()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Forbidden: VA is outside your assigned pod']);
                    return;
                }
            }
            $scopeVaId = $requestedVa;
        }

        $cacheKey = 'reports:' . md5($user['role'] . '|' . $user['id'] . '|' . ($scopeVaId ?? 'all') . "|{$startDate}|{$endDate}");
        $hit = false;

        $report = CacheService::remember($this->db, $cacheKey, 30, function () use ($user, $scopeVaId, $startDate, $endDate): array {
            $sql = "
                SELECT t.id, t.title, t.status, t.priority, t.deadline,
                       t.estimated_hours, t.extra_hours_logged, t.completed_at,
                       u.id AS va_id, u.name AS va_name, u.email AS va_email,
                       c.id AS client_id, c.agency_name AS client_name
                FROM tasks t
                JOIN users u ON t.assigned_va_id = u.id
                JOIN clients c ON t.client_id = c.id
                WHERE t.deadline BETWEEN :start_d AND :end_d
            ";
            $params = [
                ':start_d' => $startDate,
                ':end_d'   => $endDate,
            ];

            if ($scopeVaId !== null) {
                $sql .= ' AND t.assigned_va_id = :va_id';
                $params[':va_id'] = $scopeVaId;
            } elseif ($user['role'] === 'team_leader') {
                $sql .= ' AND (u.team_leader_id = :tl_id OR t.assigned_va_id = :tl_self)';
                $params[':tl_id'] = $user['id'];
                $params[':tl_self'] = $user['id'];
            }

            $sql .= ' ORDER BY t.deadline ASC, t.priority DESC';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = count($tasks);
            $completed = 0;
            $onTime = 0;
            $estHours = 0.0;
            $extraHours = 0.0;
            $byClient = [];

            foreach ($tasks as $t) {
                $estHours += (float) $t['estimated_hours'];
                $extraHours += (float) $t['extra_hours_logged'];
                $cname = (string) $t['client_name'];
                if (!isset($byClient[$cname])) {
                    $byClient[$cname] = ['client' => $cname, 'total' => 0, 'completed' => 0, 'hours' => 0.0];
                }
                $byClient[$cname]['total']++;
                $byClient[$cname]['hours'] += (float) $t['estimated_hours'] + (float) $t['extra_hours_logged'];

                if ($t['status'] === 'completed') {
                    $completed++;
                    $byClient[$cname]['completed']++;
                    $compDate = $t['completed_at'] ? substr((string) $t['completed_at'], 0, 10) : (string) $t['deadline'];
                    if ($compDate <= (string) $t['deadline']) {
                        $onTime++;
                    }
                }
            }

            $slaRate = $completed > 0 ? round(($onTime / $completed) * 100, 1) : ($total === 0 ? 100.0 : 0.0);
            $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            return [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'scope_va'   => $scopeVaId ?? 'all',
                'summary'    => [
                    'total_tasks'      => $total,
                    'completed_tasks'  => $completed,
                    'completion_rate'  => $completionRate,
                    'sla_compliance'   => $slaRate,
                    'estimated_hours'  => round($estHours, 2),
                    'extra_hours'      => round($extraHours, 2),
                ],
                'client_breakdown' => array_values($byClient),
                'tasks'            => $tasks,
            ];
        }, $hit);

        CacheService::sendJsonWithEtag($report, $hit, 20);
    }

    private function dispatchReport(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $recipient = filter_var($input['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        $startDate = SecurityHelper::sanitizePlainText($input['start_date'] ?? '', 16);
        $endDate = SecurityHelper::sanitizePlainText($input['end_date'] ?? '', 16);
        $subject = SecurityHelper::sanitizePlainText($input['subject'] ?? "SLA Performance Report ({$startDate} to {$endDate})", 255);
        $body = SecurityHelper::sanitizePlainText($input['summary_text'] ?? '', 10000);

        if ($recipient === '' || $body === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Valid team leader / recipient email and report summary are required']);
            return;
        }

        $threadId = 'th-' . bin2hex(random_bytes(5));
        $id = 'msg-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO email_messages (id, thread_id, sender_name, sender_email, recipient_email, subject, body, folder, is_read)
            VALUES (:id, :tid, :sname, :semail, :remail, :subject, :body, 'sent', FALSE)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $threadId,
            ':sname'   => $user['name'],
            ':semail'  => $user['email'],
            ':remail'  => $recipient,
            ':subject' => $subject,
            ':body'    => $body,
        ]);

        CacheService::invalidatePrefix($this->db, 'emails:');
        AuditLogger::log('SLA_REPORT_SUBMITTED', 'success', "Submitted SLA report ({$startDate} to {$endDate}) to {$recipient}", $user['id'], $user['role'], $this->db);

        http_response_code(201);
        echo json_encode(['success' => true, 'thread_id' => $threadId]);
    }
}
