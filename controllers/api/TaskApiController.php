<?php
/**
 * Workly Tasks REST API Controller (PHP 8 + PostgreSQL)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../middleware/TaskRoleMiddleware.php';

final class TaskApiController
{
    public function __construct(private PDO $db) {}

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        TaskRoleMiddleware::requireOrchestrationRole($user, $this->db);
        CsrfMiddleware::validateApi($method);

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/tasks/{id}/{sub}
        $taskId = $parts[3] ?? ($_GET['id'] ?? null);
        $sub = $parts[4] ?? null;

        if ($method === 'GET' && !$taskId) {
            $this->listTasks($user);
            return;
        }
        if ($method === 'POST' && !$taskId) {
            $this->createTask($user);
            return;
        }
        if ($method === 'PATCH' && $taskId && $sub === 'status') {
            $this->updateStatus($taskId, $user);
            return;
        }
        if ($method === 'POST' && $taskId && $sub === 'notes') {
            $this->addNote($taskId, $user);
            return;
        }
        if ($method === 'POST' && $taskId && $sub === 'comments') {
            $this->addComment($taskId, $user);
            return;
        }
        if ($method === 'POST' && $taskId && $sub === 'extra-hours') {
            $this->logExtraHours($taskId, $user);
            return;
        }
        if ($method === 'PATCH' && !$sub) {
            $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $id = SecurityHelper::sanitizePlainText($taskId ?: ($input['id'] ?? ''));
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Task ID is required']);
                return;
            }
            if (isset($input['client_id']) || isset($input['priority']) || count($input) > 1) {
                $this->updateTask($id, $user, $input);
                return;
            }
            if (isset($input['status'])) {
                $this->updateStatus($id, $user, $input);
                return;
            }
        }
        if ($method === 'PUT' && $taskId) {
            $this->updateTask($taskId, $user);
            return;
        }
        if ($method === 'DELETE' && $taskId) {
            $this->deleteTask($taskId, $user);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Task API route not found']);
    }

    private function invalidate(): void
    {
        CacheService::invalidatePrefix($this->db, 'tasks:');
        CacheService::invalidatePrefix($this->db, 'analytics:');
        CacheService::invalidatePrefix($this->db, 'reports:');
    }

    private function assertTaskAccess(string $taskId, array $user, string $action = 'access'): array
    {
        return TaskRoleMiddleware::authorizeTaskAccess($this->db, $taskId, $user, $action);
    }

    private function listTasks(array $user): void
    {
        $role = $user['role'];
        $uid = $user['id'];
        $hit = false;

        $payload = CacheService::remember($this->db, "tasks:{$role}:{$uid}", 30, function () use ($role, $uid): array {
            if ($role === 'va') {
                $stmt = $this->db->prepare('SELECT * FROM tasks WHERE assigned_va_id = :va ORDER BY created_at DESC');
                $stmt->execute([':va' => $uid]);
            } elseif ($role === 'team_leader') {
                $stmt = $this->db->prepare("
                    SELECT t.* FROM tasks t
                    LEFT JOIN users u ON t.assigned_va_id = u.id
                    WHERE u.team_leader_id = :tl OR t.assigned_va_id = :tl OR t.created_by_id = :tl
                    ORDER BY t.created_at DESC
                ");
                $stmt->execute([':tl' => $uid]);
            } else {
                $stmt = $this->db->query('SELECT * FROM tasks ORDER BY created_at DESC');
            }
            return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }, $hit);

        CacheService::sendJsonWithEtag($payload, $hit, 20);
    }

    private function createTask(array $user): void
    {
        AuthMiddleware::requireRoles(['va', 'team_leader', 'admin'], $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        $description = SecurityHelper::sanitizePlainText($input['description'] ?? '', 4000);
        $priority = in_array($input['priority'] ?? '', ['low', 'medium', 'high'], true) ? $input['priority'] : 'medium';
        $status = in_array($input['status'] ?? '', ['backlog', 'todo', 'in_progress', 'in_review', 'completed'], true) ? $input['status'] : 'todo';
        $assignedVaId = $user['role'] === 'va'
            ? $user['id']
            : SecurityHelper::sanitizePlainText($input['assigned_va_id'] ?? $user['id'], 64);
        $clientId = SecurityHelper::sanitizePlainText($input['client_id'] ?? '', 64);
        $deadline = SecurityHelper::sanitizePlainText($input['deadline'] ?? date('Y-m-d'), 16);
        $estimatedHours = max(0.0, (float) ($input['estimated_hours'] ?? 4));

        if ($title === '' || $clientId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Title and client are required']);
            return;
        }

        $id = 'task-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO tasks (id, title, description, status, priority, assigned_va_id, client_id, created_by_id, creator_role, deadline, estimated_hours)
            VALUES (:id, :title, :desc, :status, :priority, :va_id, :client_id, :creator_id, :creator_role, :deadline, :hours)
        ");
        $stmt->execute([
            ':id'           => $id,
            ':title'        => $title,
            ':desc'         => $description,
            ':status'       => $status,
            ':priority'     => $priority,
            ':va_id'        => $assignedVaId,
            ':client_id'    => $clientId,
            ':creator_id'   => $user['id'],
            ':creator_role' => $user['role'],
            ':deadline'     => $deadline,
            ':hours'        => $estimatedHours,
        ]);

        if (!empty($input['initial_note'])) {
            $nStmt = $this->db->prepare("
                INSERT INTO task_notes (id, task_id, author_id, author_name, author_role, is_instruction, content)
                VALUES (:id, :tid, :aid, :aname, :arole, :instr, :content)
            ");
            $nStmt->execute([
                ':id'      => 'note-' . bin2hex(random_bytes(5)),
                ':tid'     => $id,
                ':aid'     => $user['id'],
                ':aname'   => $user['name'],
                ':arole'   => $user['role'],
                ':instr'   => $user['role'] !== 'va' ? 'true' : 'false',
                ':content' => SecurityHelper::sanitizePlainText($input['initial_note'], 2000),
            ]);
        }

        $this->invalidate();
        AuditLogger::log('TASK_CREATED', 'success', "Created task {$id} ({$title})", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function updateStatus(string $taskId, array $user, ?array $preloaded = null): void
    {
        TaskRoleMiddleware::authorizeStatusModification($this->db, $taskId, $user);
        $input = $preloaded ?? (json_decode((string) file_get_contents('php://input'), true) ?: []);
        $status = $input['status'] ?? '';
        if (!in_array($status, TaskRoleMiddleware::VALID_STATUSES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid task status']);
            return;
        }

        $stmt = $this->db->prepare("
            UPDATE tasks
            SET status = :status,
                completed_at = CASE WHEN :status_check = 'completed' THEN NOW() ELSE NULL END,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'       => $status,
            ':status_check' => $status,
            ':id'           => $taskId,
        ]);

        $this->invalidate();
        AuditLogger::log('TASK_STATUS_CHANGED', 'success', "Moved task {$taskId} to {$status}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $taskId, 'status' => $status]);
    }

    private function updateTask(string $taskId, array $user, ?array $preloaded = null): void
    {
        $this->assertTaskAccess($taskId, $user, 'update');
        $input = $preloaded ?? (json_decode((string) file_get_contents('php://input'), true) ?: []);

        if (array_key_exists('status', $input)) {
            TaskRoleMiddleware::authorizeStatusModification($this->db, $taskId, $user);
        }

        $fields = [];
        $params = [':id' => $taskId];
        $allowed = ['title', 'description', 'status', 'priority', 'client_id', 'deadline', 'estimated_hours'];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $input)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = is_string($input[$col])
                    ? SecurityHelper::sanitizePlainText($input[$col])
                    : $input[$col];
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid fields provided']);
            return;
        }

        if (array_key_exists('status', $input)) {
            $fields[] = "completed_at = CASE WHEN :status_done = 'completed' THEN NOW() ELSE NULL END";
            $params[':status_done'] = $params[':status'];
        }

        $stmt = $this->db->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);

        $this->invalidate();
        echo json_encode(['success' => true, 'id' => $taskId]);
    }

    private function deleteTask(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user);
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);

        $this->invalidate();
        AuditLogger::log('TASK_DELETED', 'warning', "Deleted task {$taskId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $taskId]);
    }

    private function addNote(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $content = SecurityHelper::sanitizePlainText($input['content'] ?? '', 2000);
        if ($content === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Note content cannot be empty']);
            return;
        }

        $id = 'note-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO task_notes (id, task_id, author_id, author_name, author_role, is_instruction, content)
            VALUES (:id, :tid, :aid, :aname, :arole, :instr, :content)
        ");
        $stmt->execute([
            ':id'      => $id,
            ':tid'     => $taskId,
            ':aid'     => $user['id'],
            ':aname'   => $user['name'],
            ':arole'   => $user['role'],
            ':instr'   => $user['role'] !== 'va' ? 'true' : 'false',
            ':content' => $content,
        ]);

        $this->invalidate();
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function addComment(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $message = SecurityHelper::sanitizePlainText($input['message'] ?? '', 2000);
        if ($message === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Comment cannot be empty']);
            return;
        }

        $id = 'cmt-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO task_comments (id, task_id, author_id, author_name, author_role, message)
            VALUES (:id, :tid, :aid, :aname, :arole, :msg)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':tid'   => $taskId,
            ':aid'   => $user['id'],
            ':aname' => $user['name'],
            ':arole' => $user['role'],
            ':msg'   => $message,
        ]);

        $this->invalidate();
        echo json_encode(['success' => true, 'id' => $id]);
    }

    private function logExtraHours(string $taskId, array $user): void
    {
        $task = $this->assertTaskAccess($taskId, $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $hours = max(0.25, (float) ($input['hours'] ?? 1.0));
        $justification = SecurityHelper::sanitizePlainText($input['justification'] ?? '', 1000);
        if ($justification === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Overtime justification is required']);
            return;
        }

        $cStmt = $this->db->prepare('SELECT agency_name FROM clients WHERE id = :cid LIMIT 1');
        $cStmt->execute([':cid' => $task['client_id']]);
        $clientName = (string) ($cStmt->fetchColumn() ?: 'Client Account');

        $id = 'eh-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO extra_hours_logs (id, va_id, va_name, client_id, client_name, task_id, task_title, extra_hours, date, justification, status)
            VALUES (:id, :va_id, :va_name, :cid, :cname, :tid, :ttitle, :hours, CURRENT_DATE, :just, 'pending')
        ");
        $stmt->execute([
            ':id'      => $id,
            ':va_id'   => $user['id'],
            ':va_name' => $user['name'],
            ':cid'     => $task['client_id'],
            ':cname'   => $clientName,
            ':tid'     => $taskId,
            ':ttitle'  => $task['title'],
            ':hours'   => $hours,
            ':just'    => $justification,
        ]);

        $upd = $this->db->prepare('UPDATE tasks SET extra_hours_logged = COALESCE(extra_hours_logged, 0) + :hours, updated_at = NOW() WHERE id = :id');
        $upd->execute([':hours' => $hours, ':id' => $taskId]);

        $this->invalidate();
        AuditLogger::log('EXTRA_HOURS_LOGGED', 'success', "Logged {$hours}h overtime on task {$taskId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $id]);
    }
}
