<?php
/**
 * Workly Task Orchestration Role & Ownership Middleware (PHP 8 + PostgreSQL)
 * Enforces strict role verification on task orchestration endpoints, ensuring
 * only authorized Virtual Assistants and Team Leaders can modify task statuses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../services/AuditLogger.php';

final class TaskRoleMiddleware
{
    /** Roles permitted to transition Kanban / task deliverable statuses. */
    private const STATUS_MODIFIER_ROLES = ['va', 'team_leader'];

    /** Roles permitted to participate in task orchestration endpoints. */
    private const ORCHESTRATION_ROLES = ['va', 'team_leader', 'admin'];

    /** Allowed task workflow states. */
    public const VALID_STATUSES = ['backlog', 'todo', 'in_progress', 'in_review', 'completed'];

    /**
     * Verifies the authenticated user belongs to roles allowed to invoke task orchestration endpoints.
     */
    public static function requireOrchestrationRole(array $user, PDO $db): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, self::ORCHESTRATION_ROLES, true)) {
            AuditLogger::log(
                'TASK_ORCHESTRATION_DENIED',
                'blocked',
                "Role '{$role}' denied access to task orchestration endpoint",
                (string) ($user['id'] ?? 'unknown'),
                $role,
                $db
            );
            self::deny('Forbidden: Insufficient role permissions for task orchestration');
        }
    }

    /**
     * Strictly verifies that ONLY authorized VAs and Team Leaders may modify a task's status,
     * and enforces task ownership (VA own task) or supervised pod boundaries (Team Leader pod).
     */
    public static function authorizeStatusModification(PDO $db, string $taskId, array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $uid = (string) ($user['id'] ?? '');

        if (!in_array($role, self::STATUS_MODIFIER_ROLES, true)) {
            AuditLogger::log(
                'TASK_STATUS_ROLE_DENIED',
                'blocked',
                "Role '{$role}' blocked from modifying status of task {$taskId} (VA or Team Leader required)",
                $uid,
                $role,
                $db
            );
            self::deny('Forbidden: Only authorized Virtual Assistants and Team Leaders can modify task statuses');
        }

        $task = self::fetchTaskWithAssigneeMeta($db, $taskId);

        if ($role === 'va' && (string) $task['assigned_va_id'] !== $uid) {
            AuditLogger::log(
                'TASK_STATUS_SCOPE_DENIED',
                'blocked',
                "VA {$uid} attempted to modify status of unassigned task {$taskId}",
                $uid,
                $role,
                $db
            );
            self::deny('Forbidden: You can only modify the status of deliverables assigned to you');
        }

        if ($role === 'team_leader') {
            $inSupervisedPod = ((string) ($task['assignee_team_leader_id'] ?? '') === $uid)
                || ((string) $task['assigned_va_id'] === $uid)
                || ((string) $task['created_by_id'] === $uid);

            if (!$inSupervisedPod) {
                AuditLogger::log(
                    'TASK_STATUS_POD_DENIED',
                    'blocked',
                    "Team Leader {$uid} attempted to modify status of out-of-pod task {$taskId}",
                    $uid,
                    $role,
                    $db
                );
                self::deny('Forbidden: Task belongs to a Virtual Assistant outside your supervised pod');
            }
        }

        return $task;
    }

    /**
     * Verifies read/edit/notes/comments/delete access for a specific task record.
     */
    public static function authorizeTaskAccess(PDO $db, string $taskId, array $user, string $action = 'access'): array
    {
        self::requireOrchestrationRole($user, $db);
        $task = self::fetchTaskWithAssigneeMeta($db, $taskId);
        $role = (string) ($user['role'] ?? '');
        $uid = (string) ($user['id'] ?? '');

        if ($role === 'va' && (string) $task['assigned_va_id'] !== $uid) {
            AuditLogger::log(
                'TASK_ACCESS_DENIED',
                'blocked',
                "VA {$uid} denied {$action} on task {$taskId}",
                $uid,
                $role,
                $db
            );
            self::deny('Forbidden: Task belongs to another Virtual Assistant');
        }

        if ($role === 'team_leader') {
            $inSupervisedPod = ((string) ($task['assignee_team_leader_id'] ?? '') === $uid)
                || ((string) ($task['assigned_va_id'] === $uid))
                || ((string) ($task['created_by_id'] === $uid));

            if (!$inSupervisedPod) {
                AuditLogger::log(
                    'TASK_POD_ACCESS_DENIED',
                    'blocked',
                    "Team Leader {$uid} denied {$action} on out-of-pod task {$taskId}",
                    $uid,
                    $role,
                    $db
                );
                self::deny('Forbidden: Task is outside your assigned team pod');
            }
        }

        return $task;
    }

    private static function fetchTaskWithAssigneeMeta(PDO $db, string $taskId): array
    {
        $stmt = $db->prepare("
            SELECT t.*, u.team_leader_id AS assignee_team_leader_id
            FROM tasks t
            LEFT JOIN users u ON t.assigned_va_id = u.id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            http_response_code(404);
            echo json_encode(['error' => 'Task not found']);
            exit;
        }

        return $task;
    }

    private static function deny(string $message): never
    {
        http_response_code(403);
        echo json_encode([
            'error' => $message,
            'code'  => 'WORKLY_TASK_ROLE_FORBIDDEN',
        ]);
        exit;
    }
}
