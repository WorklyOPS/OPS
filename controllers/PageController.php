<?php
/**
 * Workly Web Page Controller (PHP 8 + PostgreSQL + Two-Tier Cache)
 * Fetches real PostgreSQL records scoped by user role and renders clean PHP views.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../services/SecurityHelper.php';
require_once __DIR__ . '/../services/CacheService.php';
require_once __DIR__ . '/../services/AuditLogger.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/api/NotificationApiController.php';
require_once __DIR__ . '/api/UserApiController.php';
require_once __DIR__ . '/api/PerksApiController.php';
require_once __DIR__ . '/api/TaskApiController.php';
require_once __DIR__ . '/../services/GoogleCalendarService.php';

final class PageController
{
    public function __construct(private PDO $db) {}

    public function render(string $section): void
    {
        SecurityHelper::sendHtmlHeaders();
        $user = AuthMiddleware::requirePageAuth($this->db);
        $csrfToken = SecurityHelper::csrfToken();
        $role = (string) $user['role'];

        // Route RBAC enforcement
        if ($section === 'va_directory' && $role === 'va') {
            header('Location: /tasks');
            exit;
        }
        if ($section === 'dashboard' && $role === 'va') {
            header('Location: /profile');
            exit;
        }
        if ($section === 'perks' && !in_array($role, ['admin', 'team_leader'], true)) {
            header('Location: ' . ($role === 'va' ? '/tasks' : '/dashboard'));
            exit;
        }

        $alerts = $this->loadSidebarAlerts($user);
        $headerNotifications = NotificationApiController::fetchForUser($this->db, $user);
        $unreadNotifCount = count(array_filter($headerNotifications, static fn(array $n): bool => !empty($n['unread'])));

        $data = match ($section) {
            'tasks'            => $this->loadTasksData($user),
            'dashboard'        => $this->loadDashboardData($user),
            'va_directory'     => $this->loadVaDirectoryData($user),
            'profile'          => $this->loadVaProfileData($user),
            'clients'          => $this->loadClientsData($user),
            'calendar_reports' => $this->loadCalendarReportsData($user),
            'pto'              => $this->loadPtoData($user),
            'email'            => $this->loadEmailData($user),
            'knowledge'        => $this->loadKnowledgeData($user),
            'perks'            => $this->loadPerksData($user),
            default            => $this->loadTasksData($user),
        };

        $pageTitle = match ($section) {
            'tasks'            => 'Weekly Task Organizer',
            'dashboard'        => 'Operations Overview',
            'va_directory'     => 'VA Directory',
            'profile'          => 'Staff Profile & Performance',
            'clients'          => 'Client CRM',
            'calendar_reports' => 'Calendar & SLA Reports',
            'pto'              => 'PTO & Leave Requests',
            'email'            => 'Email Communications',
            'knowledge'        => 'SOP Knowledge Base',
            'perks'            => 'Perks & Badges',
            default            => 'Workly Platform',
        };

        $activeSection = $section;
        require __DIR__ . '/../views/layouts/app.php';
    }

    private function loadSidebarAlerts(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        return CacheService::remember($this->db, "alerts:{$role}:{$uid}", 20, function () use ($role, $uid, $user): array {
            $linkedEmail = (string) $user['email'];
            try {
                $mbQ = $this->db->prepare('SELECT email FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
                $mbQ->execute([':uid' => $uid]);
                $foundMb = $mbQ->fetchColumn();
                if ($foundMb) {
                    $linkedEmail = (string) $foundMb;
                }
            } catch (Throwable) {
            }

            $adminExclusionSql = '';
            if ($role !== 'admin') {
                $adminExclusionSql = "
                    AND LOWER(sender_email) NOT IN (
                        SELECT LOWER(email) FROM users WHERE role = 'admin'
                        UNION
                        SELECT LOWER(m2.email) FROM staff_connected_mailboxes m2 JOIN users u2 ON m2.user_id = u2.id WHERE u2.role = 'admin'
                    )
                ";
            }

            $unreadEmailsStmt = $this->db->prepare("
                SELECT COUNT(DISTINCT thread_id)::INTEGER
                FROM email_messages
                WHERE is_read = FALSE
                  AND LOWER(sender_email) NOT IN (LOWER(:se1), LOWER(:se2))
                  AND (
                    LOWER(recipient_email) IN (LOWER(:e1), LOWER(:l1))
                    OR LOWER(COALESCE(cc_email, '')) LIKE LOWER(:c1)
                  )
                  {$adminExclusionSql}
            ");
            $unreadEmailsStmt->execute([
                ':se1' => $user['email'],
                ':se2' => $linkedEmail,
                ':e1'  => $user['email'],
                ':l1'  => $linkedEmail,
                ':c1'  => '%' . $linkedEmail . '%',
            ]);
            $unreadEmails = (int) $unreadEmailsStmt->fetchColumn();

            $pendingPto = 0;
            $reviewTasks = 0;
            $unreadTlNotifications = 0;

            if ($role === 'team_leader') {
                $pStmt = $this->db->prepare("
                    SELECT COUNT(*)::INTEGER
                    FROM pto_requests p
                    JOIN users u ON p.va_id = u.id
                    WHERE p.status = 'pending' AND u.team_leader_id = :tl
                ");
                $pStmt->execute([':tl' => $uid]);
                $pendingPto = (int) $pStmt->fetchColumn();

                try {
                    $nStmt = $this->db->prepare("
                        SELECT COUNT(*)::INTEGER
                        FROM team_lead_notifications
                        WHERE team_leader_id = :tl AND is_read = FALSE
                    ");
                    $nStmt->execute([':tl' => $uid]);
                    $unreadTlNotifications = (int) $nStmt->fetchColumn();
                } catch (Throwable) {
                    $unreadTlNotifications = 0;
                }
            } elseif ($role === 'hrm' || $role === 'admin') {
                $pendingPto = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM pto_requests WHERE status = 'pending'")->fetchColumn();
            }

            return [
                'unread_emails'           => $unreadEmails,
                'pending_pto'             => $pendingPto,
                'review_tasks'            => 0,
                'unread_tl_notifications' => $unreadTlNotifications,
            ];
        });
    }

    private function loadTasksData(array $user): array
    {
        TaskApiController::ensureTaskSchemaConstraints($this->db);
        $selectedMonth = SecurityHelper::sanitizePlainText($_GET['month'] ?? date('Y-m'), 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            $selectedMonth = date('Y-m');
        }
        $selectedWeek = SecurityHelper::sanitizePlainText($_GET['week'] ?? 'all', 12);
        $selectedVaFilter = SecurityHelper::sanitizePlainText($_GET['va'] ?? 'all', 64);

        // Build weeks within selected month
        $monthStart = new DateTimeImmutable($selectedMonth . '-01');
        $monthEnd = $monthStart->modify('last day of this month');
        $weeks = [];
        $cursor = $monthStart->modify('monday this week');
        if ($cursor > $monthStart) {
            $cursor = $cursor->modify('-7 days');
        }
        $weekIdx = 1;
        while ($cursor <= $monthEnd) {
            $wEnd = $cursor->modify('+6 days');
            $weeks[] = [
                'key'   => $cursor->format('Y-m-d'),
                'label' => 'Week ' . $weekIdx . ' (' . $cursor->format('M j') . ' – ' . $wEnd->format('M j') . ')',
                'start' => $cursor->format('Y-m-d'),
                'end'   => $wEnd->format('Y-m-d'),
            ];
            $cursor = $cursor->modify('+7 days');
            $weekIdx++;
        }

        $rangeStart = $monthStart->format('Y-m-d');
        $rangeEnd = $monthEnd->format('Y-m-d');
        foreach ($weeks as $w) {
            if ($w['key'] === $selectedWeek) {
                $rangeStart = $w['start'];
                $rangeEnd = $w['end'];
                break;
            }
        }

        $role = $user['role'];
        $uid = $user['id'];

        $sql = "
            SELECT t.*, u.name AS va_name, u.avatar_url AS va_avatar,
                   c.agency_name AS client_name, c.name AS client_contact,
                   c.contract_status AS client_status, c.contracted_hours_per_month, c.consumed_hours_month
            FROM tasks t
            JOIN users u ON t.assigned_va_id = u.id
            JOIN clients c ON t.client_id = c.id
            WHERE t.deadline BETWEEN :r_start AND :r_end
        ";
        $params = [':r_start' => $rangeStart, ':r_end' => $rangeEnd];

        if ($role === 'va') {
            $sql .= ' AND t.assigned_va_id = :va_id';
            $params[':va_id'] = $uid;
        } elseif ($role === 'team_leader') {
            $sql .= ' AND (u.team_leader_id = :tl_id OR t.assigned_va_id = :tl_self OR t.created_by_id = :tl_cre)';
            $params[':tl_id'] = $uid;
            $params[':tl_self'] = $uid;
            $params[':tl_cre'] = $uid;
            if ($selectedVaFilter !== 'all' && $selectedVaFilter !== '') {
                $sql .= ' AND t.assigned_va_id = :va_filter';
                $params[':va_filter'] = $selectedVaFilter;
            }
        } elseif ($selectedVaFilter !== 'all' && $selectedVaFilter !== '') {
            $sql .= ' AND t.assigned_va_id = :va_filter';
            $params[':va_filter'] = $selectedVaFilter;
        }

        $sql .= " ORDER BY c.agency_name ASC, CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC, t.deadline ASC, t.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch notes, comments, extra hours & sub-tasks indexed by task_id
        TaskApiController::ensureTaskSchemaConstraints($this->db);
        $taskIds = array_column($tasks, 'id');
        $notesByTask = [];
        $commentsByTask = [];
        $commentAuthorsByTask = [];
        $extraHoursByTask = [];
        $subtasksByTask = [];
        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $nStmt = $this->db->prepare("
                SELECT n.*, COALESCE(u.avatar_url, '') AS avatar_url
                FROM task_notes n
                LEFT JOIN users u ON n.author_id = u.id
                WHERE n.task_id IN ({$placeholders})
                ORDER BY n.created_at ASC
            ");
            $nStmt->execute($taskIds);
            foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $notesByTask[$n['task_id']][] = $n;
            }

            $cStmt = $this->db->prepare("
                SELECT c.*, COALESCE(u.avatar_url, '') AS avatar_url
                FROM task_comments c
                LEFT JOIN users u ON c.author_id = u.id
                WHERE c.task_id IN ({$placeholders})
                ORDER BY c.created_at ASC
            ");
            $cStmt->execute($taskIds);
            $seenAuthors = [];
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $commentsByTask[$c['task_id']][] = $c;
                $tid = (string) $c['task_id'];
                $aid = (string) $c['author_id'];
                if (!isset($seenAuthors[$tid][$aid])) {
                    $seenAuthors[$tid][$aid] = true;
                    $commentAuthorsByTask[$tid][] = [
                        'id'         => $aid,
                        'name'       => (string) $c['author_name'],
                        'avatar_url' => (string) ($c['avatar_url'] ?? ''),
                    ];
                }
            }

            $ehStmt = $this->db->prepare("SELECT * FROM extra_hours_logs WHERE task_id IN ({$placeholders}) ORDER BY logged_at DESC");
            $ehStmt->execute($taskIds);
            foreach ($ehStmt->fetchAll(PDO::FETCH_ASSOC) as $eh) {
                $extraHoursByTask[$eh['task_id']][] = $eh;
            }

            try {
                $stStmt = $this->db->prepare("
                    SELECT id, task_id, title, is_completed, position, created_by_id, completed_at, created_at, updated_at
                    FROM task_subtasks
                    WHERE task_id IN ({$placeholders})
                    ORDER BY position ASC, created_at ASC
                ");
                $stStmt->execute($taskIds);
                foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $st) {
                    $st['is_completed'] = filter_var($st['is_completed'], FILTER_VALIDATE_BOOLEAN);
                    $st['position'] = (int) ($st['position'] ?? 0);
                    $subtasksByTask[$st['task_id']][] = $st;
                }
            } catch (Throwable) {
                $subtasksByTask = [];
            }
        }

        $statusCounts = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'completed' => 0];
        foreach ($tasks as $t) {
            if (isset($statusCounts[$t['status']])) {
                $statusCounts[$t['status']]++;
            }
        }

        // Client-scoped @mention candidates indexed by task ID (cached per client_id)
        $mentionCandidatesByTask = [];
        $candidatesByClient = [];
        foreach ($tasks as $t) {
            $cid = (string) ($t['client_id'] ?? '');
            if ($cid === '') {
                $mentionCandidatesByTask[$t['id']] = [];
                continue;
            }
            if (!array_key_exists($cid, $candidatesByClient)) {
                $candidatesByClient[$cid] = TaskApiController::getMentionCandidatesForTask($this->db, $t, $user);
            }
            $mentionCandidatesByTask[$t['id']] = $candidatesByClient[$cid];
        }

        // Clients for task creation dropdown & horizontal swimlanes
        if ($role === 'va') {
            $clStmt = $this->db->prepare('SELECT id, agency_name, name, contract_status, contracted_hours_per_month, consumed_hours_month FROM clients WHERE focus_va_id = :va ORDER BY agency_name ASC');
            $clStmt->execute([':va' => $uid]);
        } elseif ($role === 'team_leader') {
            $clStmt = $this->db->prepare('SELECT id, agency_name, name, contract_status, contracted_hours_per_month, consumed_hours_month FROM clients WHERE team_leader_id = :tl ORDER BY agency_name ASC');
            $clStmt->execute([':tl' => $uid]);
        } else {
            $clStmt = $this->db->query('SELECT id, agency_name, name, contract_status, contracted_hours_per_month, consumed_hours_month FROM clients ORDER BY agency_name ASC');
        }
        $clients = $clStmt->fetchAll(PDO::FETCH_ASSOC);

        // Assignable VAs for Team Leader / Admin / HRM
        $vas = [];
        if ($role === 'team_leader') {
            $vStmt = $this->db->prepare("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE AND team_leader_id = :tl ORDER BY name ASC");
            $vStmt->execute([':tl' => $uid]);
            $vas = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($role !== 'va') {
            $vas = $this->db->query("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        // Past 12 months list for historical selection
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = (new DateTimeImmutable('first day of this month'))->modify("-{$i} months");
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        $today = date('Y-m-d');
        $dueTodayCount = 0;
        $overdueCount = 0;
        foreach ($tasks as $t) {
            if ($t['status'] !== 'completed') {
                if ((string) $t['deadline'] === $today) {
                    $dueTodayCount++;
                } elseif ((string) $t['deadline'] < $today) {
                    $overdueCount++;
                }
            }
        }

        $vaIdsForSpec = [];
        foreach ($tasks as $t) {
            if (!empty($t['assigned_va_id'])) {
                $vaIdsForSpec[(string) $t['assigned_va_id']] = true;
            }
        }
        foreach ($vas as $v) {
            if (!empty($v['id'])) {
                $vaIdsForSpec[(string) $v['id']] = true;
            }
        }
        if ($role === 'va' && !empty($uid)) {
            $vaIdsForSpec[(string) $uid] = true;
        }
        $suggestedByVa = TaskApiController::buildInternalSuggestionsForVas(
            $this->db,
            array_keys($vaIdsForSpec)
        );

        return [
            'tasks'                      => $tasks,
            'notes_by_task'              => $notesByTask,
            'comments_by_task'           => $commentsByTask,
            'comment_authors_by_task'    => $commentAuthorsByTask,
            'extra_hours_by_task'        => $extraHoursByTask,
            'subtasks_by_task'           => $subtasksByTask,
            'suggested_by_va'            => $suggestedByVa,
            'mention_candidates_by_task' => $mentionCandidatesByTask,
            'status_counts'              => $statusCounts,
            'due_today_count'            => $dueTodayCount,
            'overdue_count'              => $overdueCount,
            'clients'                    => $clients,
            'vas'                        => $vas,
            'months'                     => $months,
            'weeks'                      => $weeks,
            'selected_month'             => $selectedMonth,
            'selected_week'              => $selectedWeek,
            'selected_va_filter'         => $selectedVaFilter,
        ];
    }

    private function loadDashboardData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        $data = CacheService::remember($this->db, "analytics:dash:{$role}:{$uid}", 30, function () use ($role, $uid): array {
            $vas = $this->db->query("
                SELECT u.id, u.name, u.email, u.avatar_url, u.hourly_rate, u.hire_date, u.team_leader_id,
                       COALESCE(m.tasks_completed, 0) AS tasks_completed,
                       COALESCE(m.tasks_in_progress, 0) AS tasks_in_progress,
                       COALESCE(m.on_time_rate, 100.0) AS on_time_rate,
                       COALESCE(m.velocity_score, 100.0) AS velocity_score
                FROM users u
                LEFT JOIN va_task_metrics m ON u.id = m.va_id
                WHERE u.role = 'va'
                ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $clientCount = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM clients WHERE contract_status = 'active'")->fetchColumn();
            $totalTasks = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM tasks")->fetchColumn();
            $completedTasks = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM tasks WHERE status = 'completed'")->fetchColumn();
            $pendingPto = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM pto_requests WHERE status = 'pending'")->fetchColumn();

            $res = [
                'vas'             => $vas,
                'client_count'    => $clientCount,
                'total_tasks'     => $totalTasks,
                'completed_tasks' => $completedTasks,
                'pending_pto'     => $pendingPto,
            ];

            if ($role === 'admin') {
                UserApiController::ensureRolesSchema($this->db);
                $res['roles'] = UserApiController::listRoleCategories($this->db);
                $res['specialty_categories'] = UserApiController::listSpecialtyCategories($this->db);
                $allUsers = $this->db->query("
                    SELECT u.id, u.name, u.email, COALESCE(MAX(ur.role_slug), u.role) AS role,
                           u.avatar_url, u.phone, u.bio, u.hourly_rate, u.team_leader_id,
                           u.is_active, u.is_suspended, u.suspended_reason, u.pto_balance_days,
                           mb.email AS connected_gmail, mb.oauth_status AS gmail_status, mb.last_synced_at,
                           COALESCE(STRING_AGG(DISTINCT sp.specialty, '||'), '') AS specialties_joined
                    FROM users u
                    LEFT JOIN user_roles ur ON u.id = ur.user_id
                    LEFT JOIN staff_connected_mailboxes mb ON u.id = mb.user_id
                    LEFT JOIN va_specialties sp ON u.id = sp.va_id
                    GROUP BY u.id, mb.email, mb.oauth_status, mb.last_synced_at
                    ORDER BY COALESCE(MAX(ur.role_slug), u.role) ASC, u.name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allUsers as &$au) {
                    $au['specialties'] = ($au['role'] === 'va' && $au['specialties_joined'] !== '')
                        ? explode('||', (string) $au['specialties_joined'])
                        : [];
                    unset($au['specialties_joined']);
                }
                unset($au);
                $res['all_users'] = $allUsers;

                $res['team_leaders'] = array_values(array_filter(
                    $res['all_users'],
                    static fn(array $r) => $r['role'] === 'team_leader' && $r['is_active']
                ));

                $res['clients_overview'] = $this->db->query('SELECT * FROM v_client_capacity_overview ORDER BY agency_name ASC')->fetchAll(PDO::FETCH_ASSOC);
                $res['audit_months'] = AuditLogger::listMonths($this->db);
                $res['audit_entries'] = AuditLogger::queryLogEntries($this->db, null, null, 80);
                $res['secrets_status'] = Secrets::publicStatus();
                $res['cache_entries'] = (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM cache_store WHERE expires_at > NOW()')->fetchColumn();
                $res['db_stats'] = [
                    'users'          => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM users')->fetchColumn(),
                    'clients'        => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM clients')->fetchColumn(),
                    'tasks'          => $totalTasks,
                    'pto_requests'   => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM pto_requests')->fetchColumn(),
                    'email_messages' => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM email_messages')->fetchColumn(),
                    'knowledge_docs' => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM knowledge_docs')->fetchColumn(),
                    'audit_logs'     => (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM audit_logs')->fetchColumn(),
                ];
            } elseif ($role === 'team_leader') {
                $podStmt = $this->db->prepare("
                    SELECT v.*, u.avatar_url, u.hourly_rate
                    FROM v_va_performance_summary v
                    JOIN users u ON v.va_id = u.id
                    WHERE u.team_leader_id = :tl
                    ORDER BY v.va_name ASC
                ");
                $podStmt->execute([':tl' => $uid]);
                $res['pod_vas'] = $podStmt->fetchAll(PDO::FETCH_ASSOC);
                $res['all_vas'] = $this->db->query("SELECT id, name, email, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

                $clStmt = $this->db->prepare("
                    SELECT c.*, u.name AS focus_va_name
                    FROM clients c
                    LEFT JOIN users u ON c.focus_va_id = u.id
                    WHERE c.team_leader_id = :tl
                    ORDER BY c.agency_name ASC
                ");
                $clStmt->execute([':tl' => $uid]);
                $res['pod_clients'] = $clStmt->fetchAll(PDO::FETCH_ASSOC);

                $trendStmt = $this->db->prepare("
                    SELECT TO_CHAR(t.deadline, 'YYYY-MM-DD') AS day_label,
                           TO_CHAR(t.deadline, 'Mon DD') AS short_date,
                           COUNT(*)::INTEGER AS total_count,
                           COUNT(*) FILTER (WHERE t.status = 'completed')::INTEGER AS done_count,
                           COUNT(*) FILTER (WHERE t.status <> 'completed' AND t.deadline < CURRENT_DATE)::INTEGER AS overdue_count,
                           COALESCE(SUM(t.estimated_hours + COALESCE(t.extra_hours_logged, 0)), 0)::NUMERIC AS logged_hours,
                           ROUND(
                             COALESCE(
                               AVG(GREATEST(0.0, EXTRACT(EPOCH FROM (COALESCE(t.completed_at, t.updated_at) - t.created_at)) / 3600.0))
                               FILTER (WHERE t.status = 'completed'),
                               0
                             )::NUMERIC,
                             1
                           ) AS avg_turnaround_hours
                    FROM tasks t
                    JOIN users u ON t.assigned_va_id = u.id
                    WHERE (u.team_leader_id = :tl OR t.assigned_va_id = :tl2 OR t.created_by_id = :tl3)
                      AND t.deadline >= CURRENT_DATE - INTERVAL '30 days'
                    GROUP BY TO_CHAR(t.deadline, 'YYYY-MM-DD'), TO_CHAR(t.deadline, 'Mon DD')
                    ORDER BY day_label ASC
                ");
                $trendStmt->execute([':tl' => $uid, ':tl2' => $uid, ':tl3' => $uid]);
                $trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($trendRows as &$tr) {
                    $tot = (int) ($tr['total_count'] ?? 0);
                    $done = (int) ($tr['done_count'] ?? 0);
                    $ovd = (int) ($tr['overdue_count'] ?? 0);
                    $compPct = $tot > 0 ? round(($done / $tot) * 100, 1) : 0.0;
                    $tr['total_count'] = $tot;
                    $tr['done_count'] = $done;
                    $tr['overdue_count'] = $ovd;
                    $tr['completion_rate'] = $compPct;
                    $tr['avg_turnaround_hours'] = round((float) ($tr['avg_turnaround_hours'] ?? 0), 1);
                    $tr['logged_hours'] = round((float) ($tr['logged_hours'] ?? 0), 1);
                    $tr['productivity_score'] = $tot > 0
                        ? round(max(0.0, min(100.0, $compPct - ($ovd * 5.0))), 1)
                        : 0.0;
                }
                unset($tr);
                $res['completion_trend'] = $trendRows;

                // Kanban distribution per status & per VA assignee (including completion_rate, overdue_count & avg turnaround hours)
                $kDistStmt = $this->db->prepare("
                    SELECT u.id AS va_id,
                           u.name AS va_name,
                           u.avatar_url,
                           COALESCE(m.on_time_rate, 100.0) AS on_time_rate,
                           COUNT(t.id) FILTER (WHERE t.status = 'backlog')::INTEGER AS backlog,
                           COUNT(t.id) FILTER (WHERE t.status = 'todo')::INTEGER AS todo,
                           COUNT(t.id) FILTER (WHERE t.status = 'in_progress')::INTEGER AS in_progress,
                           COUNT(t.id) FILTER (WHERE t.status = 'completed')::INTEGER AS completed,
                           COUNT(t.id) FILTER (WHERE t.status <> 'completed' AND t.deadline < CURRENT_DATE)::INTEGER AS overdue_count,
                           COUNT(t.id)::INTEGER AS total_tasks,
                           COALESCE(SUM(t.estimated_hours + COALESCE(t.extra_hours_logged, 0)), 0)::NUMERIC AS total_hours,
                           ROUND(
                             COALESCE(
                               AVG(GREATEST(0.0, EXTRACT(EPOCH FROM (COALESCE(t.completed_at, t.updated_at) - t.created_at)) / 3600.0))
                               FILTER (WHERE t.status = 'completed'),
                               0
                             )::NUMERIC,
                             1
                           ) AS avg_turnaround_hours
                    FROM users u
                    LEFT JOIN va_task_metrics m ON u.id = m.va_id
                    LEFT JOIN tasks t ON t.assigned_va_id = u.id
                    WHERE u.is_active = TRUE
                      AND (u.team_leader_id = :tl OR t.created_by_id = :tl2)
                    GROUP BY u.id, u.name, u.avatar_url, m.on_time_rate
                    ORDER BY total_tasks DESC, u.name ASC
                ");
                $kDistStmt->execute([':tl' => $uid, ':tl2' => $uid]);
                $kanbanByVa = $kDistStmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch VA specialties / functional roles for all pod VAs
                $vaSpecMap = [];
                if (!empty($kanbanByVa)) {
                    $podVaIds = array_values(array_unique(array_map(static fn(array $r): string => (string) $r['va_id'], $kanbanByVa)));
                    if (!empty($podVaIds)) {
                        try {
                            $spPh = implode(',', array_fill(0, count($podVaIds), '?'));
                            $spStmt = $this->db->prepare("SELECT va_id, specialty FROM va_specialties WHERE va_id IN ({$spPh}) ORDER BY specialty ASC");
                            $spStmt->execute($podVaIds);
                            foreach ($spStmt->fetchAll(PDO::FETCH_ASSOC) as $spRow) {
                                $vaSpecMap[(string) $spRow['va_id']][] = (string) $spRow['specialty'];
                            }
                        } catch (Throwable) {
                            $vaSpecMap = [];
                        }
                    }
                }

                $kanbanStatusCounts = [
                    'backlog'     => 0,
                    'todo'        => 0,
                    'in_progress' => 0,
                    'completed'   => 0,
                ];
                $overdueTotal = 0;
                $vaPerfRows = [];
                $tatWeightedSum = 0.0;
                $tatCompletedSum = 0;
                $roleBuckets = [];
                foreach ($kanbanByVa as &$vaRow) {
                    foreach (array_keys($kanbanStatusCounts) as $stKey) {
                        $kanbanStatusCounts[$stKey] += (int) ($vaRow[$stKey] ?? 0);
                    }
                    $vid = (string) $vaRow['va_id'];
                    $specs = $vaSpecMap[$vid] ?? [];
                    if (empty($specs)) {
                        $specs = ['General VA'];
                    }
                    $primaryRole = (string) $specs[0];
                    $totVa = (int) ($vaRow['total_tasks'] ?? 0);
                    $doneVa = (int) ($vaRow['completed'] ?? 0);
                    $inProgVa = (int) ($vaRow['in_progress'] ?? 0);
                    $ovdVa = (int) ($vaRow['overdue_count'] ?? 0);
                    $hrsVa = round((float) ($vaRow['total_hours'] ?? 0), 1);
                    $tatHours = round((float) ($vaRow['avg_turnaround_hours'] ?? 0), 1);
                    $tatDays = round($tatHours / 24.0, 2);
                    $slaRate = round((float) ($vaRow['on_time_rate'] ?? 100), 1);
                    $overdueTotal += $ovdVa;
                    if ($doneVa > 0) {
                        $tatWeightedSum += ($tatHours * $doneVa);
                        $tatCompletedSum += $doneVa;
                    }
                    $compRate = $totVa > 0 ? round(($doneVa / $totVa) * 100, 1) : 0.0;
                    $vaRow['completion_rate'] = $compRate;
                    $vaRow['avg_turnaround_hours'] = $tatHours;
                    $vaRow['avg_turnaround_days'] = $tatDays;
                    $vaRow['specialties'] = $specs;
                    $vaRow['primary_role'] = $primaryRole;

                    $firstName = explode(' ', trim((string) $vaRow['va_name']))[0] ?: 'VA';
                    $vaPerfRows[] = [
                        'va_id'                => $vid,
                        'name'                 => $firstName,
                        'full_name'            => (string) $vaRow['va_name'],
                        'avatar_url'           => (string) ($vaRow['avatar_url'] ?? ''),
                        'specialties'          => $specs,
                        'primary_role'         => $primaryRole,
                        'completion_rate'      => $compRate,
                        'on_time_rate'         => $slaRate,
                        'avg_turnaround_hours' => $tatHours,
                        'avg_turnaround_days'  => $tatDays,
                        'completed'            => $doneVa,
                        'in_progress'          => $inProgVa,
                        'overdue_count'        => $ovdVa,
                        'total_tasks'          => $totVa,
                        'logged_hours'         => $hrsVa,
                    ];

                    foreach ($specs as $roleName) {
                        if (!isset($roleBuckets[$roleName])) {
                            $roleBuckets[$roleName] = [
                                'role_name'     => $roleName,
                                'va_ids'        => [],
                                'total_tasks'   => 0,
                                'completed'     => 0,
                                'in_progress'   => 0,
                                'overdue_count' => 0,
                                'logged_hours'  => 0.0,
                                'tat_weighted'  => 0.0,
                                'tat_done'      => 0,
                                'sla_sum'       => 0.0,
                                'sla_count'     => 0,
                            ];
                        }
                        $roleBuckets[$roleName]['va_ids'][$vid] = true;
                        $roleBuckets[$roleName]['total_tasks'] += $totVa;
                        $roleBuckets[$roleName]['completed'] += $doneVa;
                        $roleBuckets[$roleName]['in_progress'] += $inProgVa;
                        $roleBuckets[$roleName]['overdue_count'] += $ovdVa;
                        $roleBuckets[$roleName]['logged_hours'] += $hrsVa;
                        if ($doneVa > 0) {
                            $roleBuckets[$roleName]['tat_weighted'] += ($tatHours * $doneVa);
                            $roleBuckets[$roleName]['tat_done'] += $doneVa;
                        }
                        $roleBuckets[$roleName]['sla_sum'] += $slaRate;
                        $roleBuckets[$roleName]['sla_count']++;
                    }
                }
                unset($vaRow);

                $roleBenchmarks = [];
                foreach ($roleBuckets as $rb) {
                    $rTot = (int) $rb['total_tasks'];
                    $rDone = (int) $rb['completed'];
                    $rCompRate = $rTot > 0 ? round(($rDone / $rTot) * 100, 1) : 0.0;
                    $rAvgTat = $rb['tat_done'] > 0 ? round($rb['tat_weighted'] / $rb['tat_done'], 1) : 0.0;
                    $rSla = $rb['sla_count'] > 0 ? round($rb['sla_sum'] / $rb['sla_count'], 1) : 100.0;
                    $rProd = $rTot > 0 ? round(($rCompRate * 0.65) + ($rSla * 0.35), 1) : 0.0;
                    $roleBenchmarks[] = [
                        'role_name'            => $rb['role_name'],
                        'va_count'             => count($rb['va_ids']),
                        'total_tasks'          => $rTot,
                        'completed'            => $rDone,
                        'in_progress'          => (int) $rb['in_progress'],
                        'overdue_count'        => (int) $rb['overdue_count'],
                        'completion_rate'      => $rCompRate,
                        'on_time_rate'         => $rSla,
                        'avg_turnaround_hours' => $rAvgTat,
                        'avg_turnaround_days'  => round($rAvgTat / 24.0, 2),
                        'logged_hours'         => round((float) $rb['logged_hours'], 1),
                        'productivity_score'   => $rProd,
                    ];
                }
                usort($roleBenchmarks, static fn(array $a, array $b): int => ($b['total_tasks'] <=> $a['total_tasks']) ?: strcasecmp($a['role_name'], $b['role_name']));

                $podTotalTasks = array_sum($kanbanStatusCounts);
                $podCompletedTasks = (int) ($kanbanStatusCounts['completed'] ?? 0);
                $podAvgTatHours = $tatCompletedSum > 0 ? round($tatWeightedSum / $tatCompletedSum, 1) : 0.0;

                $res['kanban_status_counts'] = $kanbanStatusCounts;
                $res['kanban_by_va'] = $kanbanByVa;
                $res['va_completion_rates'] = $vaPerfRows;
                $res['role_benchmarks'] = $roleBenchmarks;
                $res['available_roles'] = array_map(static fn(array $r): string => (string) $r['role_name'], $roleBenchmarks);
                $res['overdue_total'] = $overdueTotal;
                $res['va_summary_totals'] = [
                    'pod_completion_rate'      => $podTotalTasks > 0 ? round(($podCompletedTasks / $podTotalTasks) * 100, 1) : 0.0,
                    'pod_avg_turnaround_hours' => $podAvgTatHours,
                    'pod_avg_turnaround_days'  => round($podAvgTatHours / 24.0, 2),
                    'pod_completed_tasks'      => $podCompletedTasks,
                    'pod_total_tasks'          => $podTotalTasks,
                    'assigned_va_count'        => count($vaPerfRows),
                ];

                 // Priority & active pod deliverables queue (Urgent/High first)
                 $actStmt = $this->db->prepare("
                     SELECT t.id, t.title, t.status, t.priority, t.deadline, t.estimated_hours,
                            u.name AS va_name, c.agency_name AS client_name
                     FROM tasks t
                     JOIN users u ON t.assigned_va_id = u.id
                     JOIN clients c ON t.client_id = c.id
                     WHERE t.status <> 'completed'
                       AND (u.team_leader_id = :tl OR t.created_by_id = :tl2)
                     ORDER BY CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC, t.deadline ASC
                     LIMIT 15
                 ");
                 $actStmt->execute([':tl' => $uid, ':tl2' => $uid]);
                 $res['active_deliverables'] = $actStmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($role === 'hrm') {
                $ptoReqs = $this->db->query("
                    SELECT p.*, u.email AS va_email, u.pto_balance_days
                    FROM pto_requests p
                    JOIN users u ON p.va_id = u.id
                    ORDER BY CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END, p.requested_at DESC
                ")->fetchAll(PDO::FETCH_ASSOC);
                $res['pto_requests'] = $ptoReqs;

                // Detect coverage overlaps among active/pending PTO requests
                $overlaps = [];
                foreach ($ptoReqs as $idxA => $a) {
                    if (!in_array($a['status'], ['pending', 'approved'], true)) {
                        continue;
                    }
                    foreach ($ptoReqs as $idxB => $b) {
                        if ($idxA === $idxB || !in_array($b['status'], ['pending', 'approved'], true) || $a['va_id'] === $b['va_id']) {
                            continue;
                        }
                        if ($a['start_date'] <= $b['end_date'] && $a['end_date'] >= $b['start_date']) {
                            $overlaps[$a['id']][] = $b['va_name'];
                        }
                    }
                    if (!empty($overlaps[$a['id']])) {
                        $overlaps[$a['id']] = array_values(array_unique($overlaps[$a['id']]));
                    }
                }
                $res['pto_overlaps'] = $overlaps;

                $res['extra_hours'] = $this->db->query('SELECT * FROM extra_hours_logs ORDER BY logged_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
                $res['payroll_projection'] = (float) $this->db->query("SELECT COALESCE(SUM(hourly_rate * 160), 0) FROM users WHERE role = 'va' AND is_active = TRUE")->fetchColumn();
                $res['clients_compliance'] = $this->db->query("
                    SELECT c.id, c.agency_name, c.name, c.contract_status, c.contract_signed_date, c.contract_pdf_url, u.name AS focus_va_name
                    FROM clients c
                    LEFT JOIN users u ON c.focus_va_id = u.id
                    ORDER BY c.agency_name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
                $res['va_roster_health'] = $this->db->query("
                    SELECT v.*, u.is_suspended, u.hire_date
                    FROM v_va_performance_summary v
                    JOIN users u ON v.va_id = u.id
                    WHERE u.is_active = TRUE
                    ORDER BY v.va_name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }

            return $res;
        });

        return $data;
    }

    private function loadVaDirectoryData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        $sql = "
            SELECT v.*, u.avatar_url, u.phone, u.bio, u.hire_date, u.team_leader_id,
                   u.is_active, u.is_suspended, u.suspended_reason,
                   COALESCE(tl.name, '') AS tl_name,
                   COALESCE(STRING_AGG(DISTINCT s.specialty, '||'), '') AS specialties_joined,
                   COALESCE(STRING_AGG(DISTINCT s.specialty, ', '), '') AS specialties_csv
            FROM v_va_performance_summary v
            JOIN users u ON v.va_id = u.id
            LEFT JOIN users tl ON u.team_leader_id = tl.id
            LEFT JOIN va_specialties s ON u.id = s.va_id
        ";
        $where = [];
        $params = [];
        if ($role !== 'admin') {
            $where[] = 'u.is_active = TRUE';
        }
        if ($role === 'team_leader') {
            $where[] = 'u.team_leader_id = :tl';
            $params[':tl'] = $uid;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY v.va_id, v.va_name, v.va_email, v.hourly_rate, v.total_earnings, v.pto_balance_days, v.pto_taken_days, v.total_tasks, v.completed_tasks, v.in_progress_tasks, v.total_estimated_hours, v.total_extra_hours, v.on_time_rate, v.velocity_score, u.avatar_url, u.phone, u.bio, u.hire_date, u.team_leader_id, u.is_active, u.is_suspended, u.suspended_reason, tl.name ORDER BY v.va_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $vas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vas as &$v) {
            $v['specialties'] = $v['specialties_joined'] !== '' ? explode('||', (string) $v['specialties_joined']) : [];
            $v['is_active'] = (bool) ($v['is_active'] ?? true);
            $v['is_suspended'] = (bool) ($v['is_suspended'] ?? false);
            unset($v['specialties_joined']);
        }
        unset($v);

        $tls = $this->db->query("SELECT id, name FROM users WHERE role = 'team_leader' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'vas'                  => $vas,
            'specialty_categories' => UserApiController::listSpecialtyCategories($this->db),
            'team_leaders'         => $tls,
        ];
    }

    private function loadVaProfileData(array $user): array
    {
        $role = (string) ($user['role'] ?? 'va');
        $requestedId = SecurityHelper::sanitizePlainText($_GET['va_id'] ?? $user['id'], 64);
        $targetId = (string) $user['id'];

        if ($role === 'admin' && $requestedId !== '') {
            $targetId = $requestedId;
        }

        $uStmt = $this->db->prepare('SELECT id, name, email, role, avatar_url, phone, bio, hire_date, hourly_rate, total_earnings, pto_balance_days, pto_taken_days, team_leader_id FROM users WHERE id = :id LIMIT 1');
        $uStmt->execute([':id' => $targetId]);
        $profile = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

        $specialties = [];
        if ($profile['role'] === 'va') {
            $spStmt = $this->db->prepare('SELECT specialty FROM va_specialties WHERE va_id = :va ORDER BY specialty ASC');
            $spStmt->execute([':va' => $profile['id']]);
            $specialties = $spStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $rStmt = $this->db->prepare('SELECT responsibility FROM va_responsibilities WHERE va_id = :va');
        $rStmt->execute([':va' => $profile['id']]);
        $responsibilities = $rStmt->fetchAll(PDO::FETCH_COLUMN);

        $eStmt = $this->db->prepare('SELECT * FROM va_monthly_earnings WHERE va_id = :va ORDER BY record_year DESC, month_number DESC LIMIT 12');
        $eStmt->execute([':va' => $profile['id']]);
        $earnings = $eStmt->fetchAll(PDO::FETCH_ASSOC);

        $bStmt = $this->db->prepare("
            SELECT b.title, b.description, b.tier, COALESCE(b.icon_name, 'Medal') AS icon_name, ub.earned_at
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            WHERE ub.user_id = :va
            ORDER BY ub.earned_at DESC
        ");
        $bStmt->execute([':va' => $profile['id']]);
        $badges = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        $pStmt = $this->db->prepare("
            SELECT p.title, p.category, COALESCE(p.icon_name, 'Award') AS icon_name, p.value, p.description, up.granted_at
            FROM user_perks up
            JOIN perks p ON up.perk_id = p.id
            WHERE up.user_id = :va
            ORDER BY up.granted_at DESC
        ");
        $pStmt->execute([':va' => $profile['id']]);
        $perks = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $tStmt = $this->db->prepare("
            SELECT COUNT(*)::INTEGER AS total_tasks,
                   COUNT(*) FILTER (WHERE status = 'completed')::INTEGER AS completed_tasks,
                   COALESCE(SUM(extra_hours_logged), 0) AS extra_hours
            FROM tasks
            WHERE assigned_va_id = :va
        ");
        $tStmt->execute([':va' => $profile['id']]);
        $taskStats = $tStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_tasks' => 0, 'completed_tasks' => 0, 'extra_hours' => 0];

        // Daily tasks Created vs Updated over the last 14 days (for VA & Team Leader profiles)
        $dailyMap = [];
        for ($d = 13; $d >= 0; $d--) {
            $dt = (new DateTimeImmutable())->modify("-{$d} days")->format('Y-m-d');
            $dailyMap[$dt] = ['date' => $dt, 'label' => date('M j', strtotime($dt)), 'created' => 0, 'updated' => 0];
        }
        try {
            if ($profile['role'] === 'va') {
                $dcStmt = $this->db->prepare("
                    SELECT created_at::DATE AS d, COUNT(*)::INTEGER AS cnt
                    FROM tasks
                    WHERE assigned_va_id = :va AND created_at::DATE >= CURRENT_DATE - INTERVAL '13 days'
                    GROUP BY created_at::DATE
                ");
                $dcStmt->execute([':va' => $profile['id']]);
                $duStmt = $this->db->prepare("
                    SELECT updated_at::DATE AS d, COUNT(*)::INTEGER AS cnt
                    FROM tasks
                    WHERE assigned_va_id = :va AND updated_at::DATE >= CURRENT_DATE - INTERVAL '13 days'
                    GROUP BY updated_at::DATE
                ");
                $duStmt->execute([':va' => $profile['id']]);
            } elseif ($profile['role'] === 'team_leader') {
                $dcStmt = $this->db->prepare("
                    SELECT t.created_at::DATE AS d, COUNT(*)::INTEGER AS cnt
                    FROM tasks t
                    LEFT JOIN users u ON t.assigned_va_id = u.id
                    WHERE (u.team_leader_id = :tl1 OR t.created_by_id = :tl2)
                      AND t.created_at::DATE >= CURRENT_DATE - INTERVAL '13 days'
                    GROUP BY t.created_at::DATE
                ");
                $dcStmt->execute([':tl1' => $profile['id'], ':tl2' => $profile['id']]);
                $duStmt = $this->db->prepare("
                    SELECT t.updated_at::DATE AS d, COUNT(*)::INTEGER AS cnt
                    FROM tasks t
                    LEFT JOIN users u ON t.assigned_va_id = u.id
                    WHERE (u.team_leader_id = :tl1 OR t.created_by_id = :tl2)
                      AND t.updated_at::DATE >= CURRENT_DATE - INTERVAL '13 days'
                    GROUP BY t.updated_at::DATE
                ");
                $duStmt->execute([':tl1' => $profile['id'], ':tl2' => $profile['id']]);
            } else {
                $dcStmt = $this->db->query("SELECT created_at::DATE AS d, COUNT(*)::INTEGER AS cnt FROM tasks WHERE created_at::DATE >= CURRENT_DATE - INTERVAL '13 days' GROUP BY created_at::DATE");
                $duStmt = $this->db->query("SELECT updated_at::DATE AS d, COUNT(*)::INTEGER AS cnt FROM tasks WHERE updated_at::DATE >= CURRENT_DATE - INTERVAL '13 days' GROUP BY updated_at::DATE");
            }
            foreach ($dcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($dailyMap[$r['d']])) {
                    $dailyMap[$r['d']]['created'] = (int) $r['cnt'];
                }
            }
            foreach ($duStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($dailyMap[$r['d']])) {
                    $dailyMap[$r['d']]['updated'] = (int) $r['cnt'];
                }
            }
        } catch (Throwable) {
        }

        if ($role === 'admin') {
            $allStaff = $this->db->query("SELECT id, name, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $allVas = $this->db->query("SELECT id, name, email, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($role === 'team_leader') {
            $allStaff = [];
            $vasStmt = $this->db->query("SELECT id, name, email, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC");
            $allVas = $vasStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $allStaff = [];
            $allVas = [];
        }
        $tls = $this->db->query("SELECT id, name FROM users WHERE role = 'team_leader' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'profile'              => $profile,
            'specialties'          => $specialties,
            'specialty_categories' => UserApiController::listSpecialtyCategories($this->db),
            'responsibilities'     => $responsibilities,
            'earnings'             => $earnings,
            'badges'               => $badges,
            'perks'                => $perks,
            'task_stats'           => $taskStats,
            'daily_task_activity'  => array_values($dailyMap),
            'all_staff'            => $allStaff,
            'all_vas'              => $allVas,
            'team_leaders'         => $tls,
        ];
    }

    private function loadClientsData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];
        $search = trim((string) ($_GET['q'] ?? ''));

        $sql = "
            SELECT c.*,
                   u.name AS focus_va_name,
                   u.email AS focus_va_email,
                   COALESCE(u.avatar_url, '') AS focus_va_avatar,
                   tl.name AS tl_name,
                   COALESCE((SELECT COUNT(*) FROM tasks t WHERE t.client_id = c.id), 0)::INTEGER AS task_count,
                   COALESCE((SELECT COUNT(*) FROM tasks t WHERE t.client_id = c.id AND t.status != 'completed'), 0)::INTEGER AS open_task_count
            FROM clients c
            LEFT JOIN users u ON c.focus_va_id = u.id
            LEFT JOIN users tl ON c.team_leader_id = tl.id
            WHERE 1=1
        ";
        $params = [];

        if ($role === 'va') {
            $sql .= ' AND c.focus_va_id = :va';
            $params[':va'] = $uid;
        } elseif ($role === 'team_leader') {
            $sql .= ' AND c.team_leader_id = :tl';
            $params[':tl'] = $uid;
        }
        if ($search !== '') {
            $sql .= ' AND (c.agency_name ILIKE :q OR c.name ILIKE :q OR c.email ILIKE :q OR COALESCE(u.name, \'\') ILIKE :q)';
            $params[':q'] = "%{$search}%";
        }
        $sql .= ' ORDER BY c.agency_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $actByClient = [];
        $emailsByClient = [];
        $tasksByClient = [];
        $commLogs = [];
        $lastCommByClient = [];
        $clientIds = array_column($clients, 'id');
        $clientNameMap = [];
        $clientEmailMap = [];
        foreach ($clients as $cl) {
            $clientNameMap[$cl['id']] = $cl['agency_name'];
            if (!empty($cl['email'])) {
                $clientEmailMap[strtolower((string) $cl['email'])] = $cl;
            }
            if (!empty($cl['additional_email'])) {
                $clientEmailMap[strtolower((string) $cl['additional_email'])] = $cl;
            }
        }

        if (!empty($clientIds)) {
            $ph = implode(',', array_fill(0, count($clientIds), '?'));
            $aStmt = $this->db->prepare("SELECT * FROM client_activities WHERE client_id IN ({$ph}) ORDER BY created_at DESC");
            $aStmt->execute($clientIds);
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $cid = (string) $a['client_id'];
                $actByClient[$cid][] = $a;
                $entry = [
                    'source'      => 'activity',
                    'type'        => (string) ($a['type'] ?? 'note'),
                    'client_id'   => $cid,
                    'client_name' => (string) ($clientNameMap[$cid] ?? 'Account'),
                    'actor'       => (string) $a['author_name'],
                    'subject'     => ucfirst((string) ($a['type'] ?? 'note')) . ' Log',
                    'summary'     => (string) $a['description'],
                    'created_at'  => (string) $a['created_at'],
                ];
                $commLogs[] = $entry;
                if (!isset($lastCommByClient[$cid]) || strcmp($entry['created_at'], $lastCommByClient[$cid]['created_at']) > 0) {
                    $lastCommByClient[$cid] = $entry;
                }
            }

            try {
                $tStmt = $this->db->prepare("
                    SELECT t.id, t.client_id, t.title, t.status, t.priority, t.deadline, t.estimated_hours,
                           u.name AS va_name
                    FROM tasks t
                    LEFT JOIN users u ON t.assigned_va_id = u.id
                    WHERE t.client_id IN ({$ph})
                    ORDER BY CASE t.status WHEN 'in_progress' THEN 0 WHEN 'todo' THEN 1 WHEN 'backlog' THEN 2 ELSE 3 END,
                             t.deadline ASC
                ");
                $tStmt->execute($clientIds);
                foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                    $cid = (string) $tr['client_id'];
                    if (count($tasksByClient[$cid] ?? []) < 12) {
                        $tasksByClient[$cid][] = $tr;
                    }
                }
            } catch (Throwable) {
            }

            try {
                $emStmt = $this->db->query("
                    SELECT id, thread_id, sender_name, sender_email, recipient_email, subject, COALESCE(snippet, LEFT(body, 140)) AS excerpt, client_ref_id, created_at
                    FROM email_messages
                    ORDER BY created_at DESC
                    LIMIT 150
                ");
                foreach ($emStmt->fetchAll(PDO::FETCH_ASSOC) as $em) {
                    $matchedCl = null;
                    if (!empty($em['client_ref_id']) && isset($clientNameMap[$em['client_ref_id']])) {
                        $matchedCl = ['id' => $em['client_ref_id'], 'agency_name' => $clientNameMap[$em['client_ref_id']]];
                    } else {
                        $se = strtolower((string) $em['sender_email']);
                        $re = strtolower((string) $em['recipient_email']);
                        if (isset($clientEmailMap[$se])) {
                            $matchedCl = $clientEmailMap[$se];
                        } elseif (isset($clientEmailMap[$re])) {
                            $matchedCl = $clientEmailMap[$re];
                        }
                    }
                    if ($matchedCl) {
                        $cid = (string) $matchedCl['id'];
                        $entry = [
                            'source'      => 'email',
                            'type'        => 'email',
                            'client_id'   => $cid,
                            'client_name' => (string) $matchedCl['agency_name'],
                            'actor'       => (string) $em['sender_name'] . ' → ' . (string) $em['recipient_email'],
                            'subject'     => (string) $em['subject'],
                            'summary'     => (string) $em['excerpt'],
                            'thread_id'   => (string) $em['thread_id'],
                            'created_at'  => (string) $em['created_at'],
                        ];
                        $commLogs[] = $entry;
                        if (count($emailsByClient[$cid] ?? []) < 25) {
                            $emailsByClient[$cid][] = $entry;
                        }
                        if (!isset($lastCommByClient[$cid]) || strcmp($entry['created_at'], $lastCommByClient[$cid]['created_at']) > 0) {
                            $lastCommByClient[$cid] = $entry;
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        usort($commLogs, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        $commLogs = array_slice($commLogs, 0, 120);

        foreach ($clients as &$clRef) {
            $cid = (string) $clRef['id'];
            $last = $lastCommByClient[$cid] ?? null;
            $clRef['last_comm_at'] = $last ? (string) $last['created_at'] : '';
            $clRef['last_comm_summary'] = $last ? (string) $last['summary'] : '';
            $clRef['last_comm_source'] = $last ? (string) $last['source'] : '';
            $clRef['comm_count'] = count($actByClient[$cid] ?? []) + count($emailsByClient[$cid] ?? []);
        }
        unset($clRef);

        try {
            $vas = $this->db->query("
                SELECT u.id, u.name, u.email, COALESCE(u.avatar_url, '') AS avatar_url, u.team_leader_id,
                       COALESCE(STRING_AGG(DISTINCT sp.specialty, '||'), '') AS specialties_joined
                FROM users u
                LEFT JOIN va_specialties sp ON u.id = sp.va_id
                WHERE u.role = 'va' AND u.is_active = TRUE
                GROUP BY u.id, u.name, u.email, u.avatar_url, u.team_leader_id
                ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($vas as &$vRef) {
                $vRef['specialties'] = $vRef['specialties_joined'] !== '' ? explode('||', (string) $vRef['specialties_joined']) : [];
                unset($vRef['specialties_joined']);
            }
            unset($vRef);
        } catch (Throwable) {
            $vas = $this->db->query("SELECT id, name, email, COALESCE(avatar_url, '') AS avatar_url, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($vas as &$vRef) {
                $vRef['specialties'] = [];
            }
            unset($vRef);
        }
        $tls = $this->db->query("SELECT id, name FROM users WHERE role = 'team_leader' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'clients'        => $clients,
            'activities'     => $actByClient,
            'emails'         => $emailsByClient,
            'tasks'          => $tasksByClient,
            'comm_logs'      => $commLogs,
            'vas'            => $vas,
            'team_leaders'   => $tls,
            'search'         => $search,
        ];
    }

    private function loadCalendarReportsData(array $user): array
    {
        GoogleCalendarService::ensureSchema($this->db);

        $role = $user['role'];
        $uid = $user['id'];

        $monitorUser = SecurityHelper::sanitizePlainText($_GET['monitor_user'] ?? '', 64);
        $targetCalUid = ($role === 'admin' && $monitorUser !== '') ? $monitorUser : $uid;

        $userTz = GoogleCalendarService::resolveUserTimezone($this->db, (string) $targetCalUid);
        $todayInTz = (new DateTimeImmutable('now', $userTz))->format('Y-m-d');

        $calView = (string) ($_GET['cal_view'] ?? '');
        if (!in_array($calView, ['week', 'day', 'month'], true)) {
            $calView = (isset($_GET['month']) && !isset($_GET['cal_date'])) ? 'month' : 'week';
        }

        $calDate = SecurityHelper::sanitizePlainText($_GET['cal_date'] ?? '', 16);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calDate)) {
            if (!empty($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['month'])) {
                $calDate = (string) $_GET['month'] . '-01';
            } else {
                $calDate = $todayInTz;
            }
        }

        $calMonth = SecurityHelper::sanitizePlainText($_GET['month'] ?? substr($calDate, 0, 7), 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $calMonth)) {
            $calMonth = substr($calDate, 0, 7);
        }

        $anchorDt = new DateTimeImmutable($calDate . ' 00:00:00', $userTz);
        if ($calView === 'day') {
            $rangeStart = $anchorDt->format('Y-m-d');
            $rangeEnd = $rangeStart;
        } elseif ($calView === 'week') {
            $dow = (int) $anchorDt->format('N'); // 1=Mon .. 7=Sun
            $weekStartDt = $anchorDt->modify('-' . ($dow - 1) . ' days');
            $rangeStart = $weekStartDt->format('Y-m-d');
            $rangeEnd = $weekStartDt->modify('+6 days')->format('Y-m-d');
        } else {
            $monthFirst = new DateTimeImmutable($calMonth . '-01 00:00:00', $userTz);
            $rangeStart = $monthFirst->format('Y-m-d');
            $rangeEnd = $monthFirst->modify('last day of this month')->format('Y-m-d');
        }

        $startDate = SecurityHelper::sanitizePlainText($_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week')), 16);
        $endDate = SecurityHelper::sanitizePlainText($_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week')), 16);

        // Throttled automatic pull from Google Calendar so newly added Google events appear on load
        $gcalStatus = GoogleCalendarService::getUserCalendarStatus($this->db, (string) $uid);
        if (!empty($gcalStatus['connected'])) {
            $pullMin = (new DateTimeImmutable($rangeStart . ' 00:00:00', $userTz))->modify('-7 days')->format(DateTimeInterface::RFC3339);
            $pullMax = (new DateTimeImmutable($rangeEnd . ' 23:59:59', $userTz))->modify('+14 days')->format(DateTimeInterface::RFC3339);
            GoogleCalendarService::pullFromGoogleForUser($this->db, (string) $uid, $pullMin, $pullMax, false);
        }

        $qStart = (new DateTimeImmutable($rangeStart . ' 00:00:00', $userTz))->modify('-1 day')->format(DateTimeInterface::RFC3339);
        $qEnd = (new DateTimeImmutable($rangeEnd . ' 23:59:59', $userTz))->modify('+1 day')->format(DateTimeInterface::RFC3339);

        $mStmt = $this->db->prepare("
            SELECT m.*, u.name AS va_name, c.agency_name AS client_name
            FROM calendar_meetings m
            JOIN users u ON m.va_id = u.id
            LEFT JOIN clients c ON m.client_id = c.id
            WHERE (m.va_id = :u1 OR m.created_by_tl_id = :u2)
              AND m.start_time <= :qe
              AND m.end_time >= :qs
            ORDER BY m.start_time ASC
        ");
        $mStmt->execute([':u1' => $targetCalUid, ':u2' => $targetCalUid, ':qs' => $qStart, ':qe' => $qEnd]);
        $meetings = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        // Task milestones in visible range
        $tStmt = $this->db->prepare("
            SELECT id, title, deadline, status, priority
            FROM tasks
            WHERE assigned_va_id = :uid AND deadline BETWEEN :ms AND :me
            ORDER BY deadline ASC
        ");
        $tStmt->execute([':uid' => $targetCalUid, ':ms' => $rangeStart, ':me' => $rangeEnd]);
        $calendarTasks = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        // Approved PTO blocks inside visible range
        $ptoStmt = $this->db->prepare("
            SELECT id, va_name, start_date, end_date
            FROM pto_requests
            WHERE va_id = :uid AND status = 'approved'
              AND start_date <= :me AND end_date >= :ms
            ORDER BY start_date ASC
        ");
        $ptoStmt->execute([':uid' => $targetCalUid, ':ms' => $rangeStart, ':me' => $rangeEnd]);
        $calendarPto = $ptoStmt->fetchAll(PDO::FETCH_ASSOC);

        $reportVa = SecurityHelper::sanitizePlainText($_GET['rep_va'] ?? 'all', 64);

        // SLA Report summary for initial range
        $repSql = "
            SELECT t.*, u.name AS va_name, c.agency_name AS client_name,
                   (SELECT n.content FROM task_notes n WHERE n.task_id = t.id ORDER BY n.created_at DESC LIMIT 1) AS latest_note
            FROM tasks t
            JOIN users u ON t.assigned_va_id = u.id
            JOIN clients c ON t.client_id = c.id
            WHERE t.deadline BETWEEN :sd AND :ed
        ";
        $repParams = [':sd' => $startDate, ':ed' => $endDate];
        if ($role !== 'admin') {
            $repSql .= " AND u.role <> 'admin'";
        }
        if ($role === 'va') {
            $repSql .= ' AND t.assigned_va_id = :va';
            $repParams[':va'] = $uid;
        } elseif ($role === 'team_leader') {
            $repSql .= ' AND (u.team_leader_id = :tl OR t.assigned_va_id = :tl2)';
            $repParams[':tl'] = $uid;
            $repParams[':tl2'] = $uid;
            if ($reportVa !== '' && $reportVa !== 'all') {
                $repSql .= ' AND t.assigned_va_id = :rep_va';
                $repParams[':rep_va'] = $reportVa;
            }
        } elseif ($reportVa !== '' && $reportVa !== 'all') {
            $repSql .= ' AND t.assigned_va_id = :rep_va';
            $repParams[':rep_va'] = $reportVa;
        }
        $repSql .= " ORDER BY t.deadline ASC, CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC";
        $rStmt = $this->db->prepare($repSql);
        $rStmt->execute($repParams);
        $reportTasks = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch checklist / sub-tasks breakdown for every task in the SLA report
        TaskApiController::ensureTaskSchemaConstraints($this->db);
        $repSubtasksByTask = [];
        $repTaskIds = array_values(array_filter(array_column($reportTasks, 'id')));
        if (!empty($repTaskIds)) {
            try {
                $stPh = implode(',', array_fill(0, count($repTaskIds), '?'));
                $stStmt = $this->db->prepare("
                    SELECT id, task_id, title, is_completed, position
                    FROM task_subtasks
                    WHERE task_id IN ({$stPh})
                    ORDER BY position ASC, created_at ASC
                ");
                $stStmt->execute($repTaskIds);
                foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $stRow) {
                    $stRow['is_completed'] = filter_var($stRow['is_completed'], FILTER_VALIDATE_BOOLEAN);
                    $repSubtasksByTask[(string) $stRow['task_id']][] = $stRow;
                }
            } catch (Throwable) {
                $repSubtasksByTask = [];
            }
        }

        // Compute granular executive SLA analytics
        $todayStr = date('Y-m-d');
        $repStatusCounts = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'completed' => 0];
        $repPriorityCounts = ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $repByClient = [];
        $repByVa = [];
        $repDone = 0;
        $repOnTime = 0;
        $repLate = 0;
        $repOverdue = 0;
        $repEstHours = 0.0;
        $repExtraHours = 0.0;
        $repSubTotal = 0;
        $repSubDone = 0;
        $repTasksWithSubtasks = 0;

        foreach ($reportTasks as &$rt) {
            $st = isset($repStatusCounts[$rt['status']]) ? $rt['status'] : 'todo';
            $repStatusCounts[$st]++;
            $pr = isset($repPriorityCounts[$rt['priority']]) ? $rt['priority'] : 'medium';
            $repPriorityCounts[$pr]++;

            $est = (float) $rt['estimated_hours'];
            $ext = (float) $rt['extra_hours_logged'];
            $totH = $est + $ext;
            $repEstHours += $est;
            $repExtraHours += $ext;

            $subs = $repSubtasksByTask[(string) $rt['id']] ?? [];
            $sTotal = count($subs);
            $sDone = 0;
            foreach ($subs as $sItem) {
                if (!empty($sItem['is_completed'])) {
                    $sDone++;
                }
            }
            $sPct = $sTotal > 0 ? (int) round(($sDone / $sTotal) * 100) : 0;
            $sStatus = $sTotal === 0
                ? 'No Checklist'
                : ($sDone === $sTotal ? 'Complete' : ($sDone > 0 ? 'In Progress' : 'Not Started'));

            $rt['subtasks'] = $subs;
            $rt['subtask_total'] = $sTotal;
            $rt['subtask_done'] = $sDone;
            $rt['subtask_pct'] = $sPct;
            $rt['subtask_status'] = $sStatus;

            $repSubTotal += $sTotal;
            $repSubDone += $sDone;
            if ($sTotal > 0) {
                $repTasksWithSubtasks++;
            }

            $cname = (string) $rt['client_name'];
            if (!isset($repByClient[$cname])) {
                $repByClient[$cname] = ['name' => $cname, 'total' => 0, 'completed' => 0, 'on_time' => 0, 'est_hours' => 0.0, 'extra_hours' => 0.0];
            }
            $repByClient[$cname]['total']++;
            $repByClient[$cname]['est_hours'] += $est;
            $repByClient[$cname]['extra_hours'] += $ext;

            $vname = (string) $rt['va_name'];
            if (!isset($repByVa[$vname])) {
                $repByVa[$vname] = ['name' => $vname, 'total' => 0, 'completed' => 0, 'on_time' => 0, 'est_hours' => 0.0, 'extra_hours' => 0.0];
            }
            $repByVa[$vname]['total']++;
            $repByVa[$vname]['est_hours'] += $est;
            $repByVa[$vname]['extra_hours'] += $ext;

            if ($rt['status'] === 'completed') {
                $repDone++;
                $repByClient[$cname]['completed']++;
                $repByVa[$vname]['completed']++;
                $compDate = $rt['completed_at'] ? substr((string) $rt['completed_at'], 0, 10) : (string) $rt['deadline'];
                if ($compDate <= (string) $rt['deadline']) {
                    $repOnTime++;
                    $repByClient[$cname]['on_time']++;
                    $repByVa[$vname]['on_time']++;
                    $rt['sla_state'] = 'On Time';
                } else {
                    $repLate++;
                    $rt['sla_state'] = 'Late';
                }
            } elseif ((string) $rt['deadline'] < $todayStr) {
                $repOverdue++;
                $rt['sla_state'] = 'Overdue';
            } else {
                $rt['sla_state'] = 'Active';
            }
        }
        unset($rt);

        $vas = [];
        $clients = [];
        $staffDirectory = [];
        try {
            $this->db->exec('ALTER TABLE clients ADD COLUMN IF NOT EXISTS availability_slots TEXT DEFAULT NULL');
        } catch (Throwable) {
        }
        if ($role === 'va') {
            $cs = $this->db->prepare('SELECT id, agency_name, name, contracted_hours_per_month, consumed_hours_month, contract_status, availability_slots FROM clients WHERE focus_va_id = :va ORDER BY agency_name ASC');
            $cs->execute([':va' => $uid]);
            $clients = $cs->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($role === 'team_leader') {
            $vs = $this->db->prepare("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE AND team_leader_id = :tl ORDER BY name ASC");
            $vs->execute([':tl' => $uid]);
            $vas = $vs->fetchAll(PDO::FETCH_ASSOC);
            $cs = $this->db->prepare('SELECT id, agency_name, name, contracted_hours_per_month, consumed_hours_month, contract_status, availability_slots FROM clients WHERE team_leader_id = :tl ORDER BY agency_name ASC');
            $cs->execute([':tl' => $uid]);
            $clients = $cs->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $vas = $this->db->query("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $clients = $this->db->query('SELECT id, agency_name, name, contracted_hours_per_month, consumed_hours_month, contract_status, availability_slots FROM clients ORDER BY agency_name ASC')->fetchAll(PDO::FETCH_ASSOC);
            if ($role === 'admin') {
                $staffDirectory = $this->db->query("SELECT id, name, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Team Leaders for VA SLA report submission recipient dropdown (hide Admin from non-admins)
        $tlRoleFilter = $role === 'admin' ? "role IN ('team_leader', 'admin')" : "role = 'team_leader'";
        $teamLeaders = $this->db->query("SELECT id, name, email FROM users WHERE {$tlRoleFilter} AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $gcalStatus = GoogleCalendarService::getUserCalendarStatus($this->db, (string) $uid);
        $syncedMeetingMap = GoogleCalendarService::getLinkedEntityMapForUser($this->db, (string) $targetCalUid, 'meeting');
        $syncedMeetingIds = array_keys($syncedMeetingMap);
        $syncedTaskIds = GoogleCalendarService::getLinkedEntityIdsForUser($this->db, (string) $targetCalUid, 'task');

        return [
            'cal_view'               => $calView,
            'cal_date'               => $calDate,
            'cal_month'              => $calMonth,
            'cal_range_start'        => $rangeStart,
            'cal_range_end'          => $rangeEnd,
            'user_timezone'          => $userTz->getName(),
            'today_in_tz'            => $todayInTz,
            'start_date'             => $startDate,
            'end_date'               => $endDate,
            'report_va'              => $reportVa,
            'monitor_user'           => $monitorUser,
            'meetings'               => $meetings,
            'calendar_tasks'         => $calendarTasks,
            'calendar_pto'           => $calendarPto,
            'report_tasks'           => $reportTasks,
            'report_analytics'       => [
                'total'               => count($reportTasks),
                'completed'           => $repDone,
                'on_time'             => $repOnTime,
                'late'                => $repLate,
                'overdue'             => $repOverdue,
                'est_hours'           => round($repEstHours, 2),
                'extra_hours'         => round($repExtraHours, 2),
                'total_hours'         => round($repEstHours + $repExtraHours, 2),
                'subtask_total'       => $repSubTotal,
                'subtask_done'        => $repSubDone,
                'subtask_rate'        => $repSubTotal > 0 ? round(($repSubDone / $repSubTotal) * 100, 1) : 0.0,
                'tasks_with_subtasks' => $repTasksWithSubtasks,
                'status_counts'       => $repStatusCounts,
                'priority_counts'     => $repPriorityCounts,
                'by_client'           => array_values($repByClient),
                'by_va'               => array_values($repByVa),
            ],
            'vas'                    => $vas,
            'clients'                => $clients,
            'staff_directory'        => $staffDirectory,
            'team_leaders'           => $teamLeaders,
            'google_calendar_status' => $gcalStatus,
            'synced_meeting_ids'     => $syncedMeetingIds,
            'synced_meeting_map'     => $syncedMeetingMap,
            'synced_task_ids'        => $syncedTaskIds,
        ];
    }

    private function loadPtoData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        $selectedMonth = SecurityHelper::sanitizePlainText($_GET['month'] ?? date('Y-m'), 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
            $selectedMonth = date('Y-m');
        }
        $mStartDt = new DateTimeImmutable($selectedMonth . '-01');
        $mEndDt = $mStartDt->modify('last day of this month');
        $mStartStr = $mStartDt->format('Y-m-d');
        $mEndStr = $mEndDt->format('Y-m-d');

        if ($role === 'va') {
            $stmt = $this->db->prepare("
                SELECT p.*, u.avatar_url
                FROM pto_requests p
                JOIN users u ON p.va_id = u.id
                WHERE p.va_id = :va
                ORDER BY p.requested_at DESC
            ");
            $stmt->execute([':va' => $uid]);
        } elseif ($role === 'team_leader') {
            $stmt = $this->db->prepare("
                SELECT p.*, u.pto_balance_days, u.pto_taken_days, u.avatar_url
                FROM pto_requests p
                JOIN users u ON p.va_id = u.id
                WHERE u.team_leader_id = :tl
                ORDER BY p.requested_at DESC
            ");
            $stmt->execute([':tl' => $uid]);
        } else {
            $stmt = $this->db->query("
                SELECT p.*, u.pto_balance_days, u.pto_taken_days, u.email AS va_email, u.avatar_url
                FROM pto_requests p
                JOIN users u ON p.va_id = u.id
                ORDER BY CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END, p.requested_at DESC
            ");
        }

        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $notifications = [];
        $trackingByReq = [];

        if ($role === 'team_leader' || $role === 'admin') {
            try {
                if ($role === 'team_leader') {
                    $nStmt = $this->db->prepare("
                        SELECT n.*, u.name AS va_name
                        FROM team_lead_notifications n
                        JOIN users u ON n.va_id = u.id
                        WHERE n.team_leader_id = :tl
                        ORDER BY n.is_read ASC, n.created_at DESC
                        LIMIT 20
                    ");
                    $nStmt->execute([':tl' => $uid]);
                } else {
                    $nStmt = $this->db->query("
                        SELECT n.*, u.name AS va_name
                        FROM team_lead_notifications n
                        JOIN users u ON n.va_id = u.id
                        ORDER BY n.is_read ASC, n.created_at DESC
                        LIMIT 20
                    ");
                }
                $notifications = $nStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $notifications = [];
            }
        }

        $reqIds = array_column($requests, 'id');
        if (!empty($reqIds)) {
            try {
                $ph = implode(',', array_fill(0, count($reqIds), '?'));
                $tStmt = $this->db->prepare("
                    SELECT * FROM pto_status_tracking
                    WHERE pto_request_id IN ({$ph})
                    ORDER BY changed_at ASC
                ");
                $tStmt->execute($reqIds);
                foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                    $trackingByReq[$tr['pto_request_id']][] = $tr;
                }
            } catch (Throwable) {
                $trackingByReq = [];
            }
        }

        // VA Monthly PTO Usage Category + Governance Register (Admin & HRM)
        $vaRegister = [];
        if (in_array($role, ['admin', 'hrm'], true)) {
            $vaRegister = $this->db->query("
                SELECT u.id, u.name, u.email, u.avatar_url, u.pto_balance_days, u.pto_taken_days,
                       tl.name AS tl_name
                FROM users u
                LEFT JOIN users tl ON u.team_leader_id = tl.id
                WHERE u.role = 'va' AND u.is_active = TRUE
                ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $mStmt = $this->db->prepare("
                SELECT va_id, start_date, end_date
                FROM pto_requests
                WHERE status = 'approved'
                  AND start_date <= :m_end
                  AND end_date >= :m_start
            ");
            $mStmt->execute([':m_start' => $mStartStr, ':m_end' => $mEndStr]);

            $monthlyByVa = [];
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $s = new DateTimeImmutable(max((string) $mr['start_date'], $mStartStr));
                $e = new DateTimeImmutable(min((string) $mr['end_date'], $mEndStr));
                $days = 0;
                $period = new DatePeriod($s, new DateInterval('P1D'), $e->modify('+1 day'));
                foreach ($period as $dt) {
                    if ((int) $dt->format('N') <= 5) {
                        $days++;
                    }
                }
                $monthlyByVa[$mr['va_id']] = ($monthlyByVa[$mr['va_id']] ?? 0) + $days;
            }

            foreach ($vaRegister as &$vr) {
                $vr['monthly_days'] = (int) ($monthlyByVa[$vr['id']] ?? 0);
            }
            unset($vr);
        }

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = (new DateTimeImmutable('first day of this month'))->modify("-{$i} months");
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return [
            'requests'       => $requests,
            'notifications'  => $notifications,
            'tracking'       => $trackingByReq,
            'va_register'    => $vaRegister,
            'selected_month' => $selectedMonth,
            'month_label'    => $mStartDt->format('F Y'),
            'months'         => $months,
        ];
    }

    private function loadEmailData(array $user): array
    {
        try {
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS snippet TEXT DEFAULT NULL');
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS body_html TEXT DEFAULT NULL');
            $this->db->exec("ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS body_format VARCHAR(16) DEFAULT 'plain'");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_attachments (
                    id VARCHAR(64) PRIMARY KEY,
                    email_id VARCHAR(64) NOT NULL REFERENCES email_messages(id) ON DELETE CASCADE,
                    filename VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
                    size_bytes INTEGER NOT NULL DEFAULT 0,
                    data_base64 TEXT DEFAULT NULL,
                    gmail_attachment_id VARCHAR(255) DEFAULT NULL,
                    content_id VARCHAR(255) DEFAULT NULL,
                    is_inline BOOLEAN NOT NULL DEFAULT FALSE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $this->db->exec('ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS content_id VARCHAR(255) DEFAULT NULL');
            $this->db->exec('ALTER TABLE email_attachments ADD COLUMN IF NOT EXISTS is_inline BOOLEAN NOT NULL DEFAULT FALSE');
        } catch (Throwable) {
        }

        $mbStmt = $this->db->prepare('SELECT * FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $mailbox = $mbStmt->fetch(PDO::FETCH_ASSOC);

        $email = (string) $user['email'];
        $linkedEmail = ($mailbox && !empty($mailbox['email'])) ? (string) $mailbox['email'] : $email;
        $selectedThread = SecurityHelper::sanitizePlainText($_GET['thread'] ?? '', 64);
        $folder = SecurityHelper::sanitizePlainText($_GET['folder'] ?? 'all', 16);
        if (!in_array($folder, ['all', 'inbox', 'unread', 'sent'], true)) {
            $folder = 'all';
        }

        if ($user['role'] === 'admin' && empty($mailbox)) {
            $stmt = $this->db->query("
                SELECT DISTINCT ON (m.thread_id)
                       m.id, m.thread_id, m.subject, m.sender_name, m.sender_email, m.recipient_email, m.cc_email, m.body, m.snippet, m.folder, m.is_read, m.created_at,
                       (SELECT COUNT(*)::INT FROM email_messages m2 WHERE m2.thread_id = m.thread_id) AS msg_count,
                       (SELECT COUNT(*)::INT FROM email_attachments ea JOIN email_messages m3 ON ea.email_id = m3.id WHERE m3.thread_id = m.thread_id AND (ea.is_inline = FALSE OR ea.size_bytes >= 1024)) AS att_count
                FROM email_messages m
                ORDER BY m.thread_id, m.created_at DESC, m.id DESC
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT DISTINCT ON (m.thread_id)
                       m.id, m.thread_id, m.subject, m.sender_name, m.sender_email, m.recipient_email, m.cc_email, m.body, m.snippet, m.folder, m.is_read, m.created_at,
                       (SELECT COUNT(*)::INT FROM email_messages m2 WHERE m2.thread_id = m.thread_id) AS msg_count,
                       (SELECT COUNT(*)::INT FROM email_attachments ea JOIN email_messages m3 ON ea.email_id = m3.id WHERE m3.thread_id = m.thread_id AND (ea.is_inline = FALSE OR ea.size_bytes >= 1024)) AS att_count
                FROM email_messages m
                WHERE (
                    LOWER(m.sender_email) IN (LOWER(:e1), LOWER(:l1))
                    OR LOWER(m.recipient_email) IN (LOWER(:e2), LOWER(:l2))
                    OR LOWER(COALESCE(m.cc_email, '')) LIKE LOWER(:c1)
                )
                ORDER BY m.thread_id, m.created_at DESC, m.id DESC
            ");
            $stmt->execute([
                ':e1' => $email,
                ':l1' => $linkedEmail,
                ':e2' => $email,
                ':l2' => $linkedEmail,
                ':c1' => '%' . $linkedEmail . '%',
            ]);
        }
        $allThreads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hide Admin users and admin-involved threads/messages from all non-admin users
        $adminEmailLookup = [];
        if (($user['role'] ?? '') !== 'admin') {
            try {
                $aeStmt = $this->db->query("
                    SELECT LOWER(email) FROM users WHERE role = 'admin'
                    UNION
                    SELECT LOWER(m.email) FROM staff_connected_mailboxes m JOIN users u ON m.user_id = u.id WHERE u.role = 'admin'
                ");
                foreach ($aeStmt->fetchAll(PDO::FETCH_COLUMN) as $ae) {
                    $ae = strtolower(trim((string) $ae));
                    if ($ae !== '') {
                        $adminEmailLookup[$ae] = true;
                    }
                }
            } catch (Throwable) {
                $adminEmailLookup = [];
            }

            $containsAdminEmail = static function (array $row) use ($adminEmailLookup): bool {
                if (empty($adminEmailLookup)) {
                    return false;
                }
                $sender = strtolower(trim((string) ($row['sender_email'] ?? '')));
                $recip = strtolower(trim((string) ($row['recipient_email'] ?? '')));
                if (isset($adminEmailLookup[$sender]) || isset($adminEmailLookup[$recip])) {
                    return true;
                }
                $cc = strtolower(trim((string) ($row['cc_email'] ?? '')));
                if ($cc !== '') {
                    foreach (array_keys($adminEmailLookup) as $ae) {
                        if (str_contains($cc, $ae)) {
                            return true;
                        }
                    }
                }
                return false;
            };

            $allThreads = array_values(array_filter($allThreads, static fn(array $th): bool => !$containsAdminEmail($th)));
        }

        $selfLow = strtolower($email);
        $linkLow = strtolower($linkedEmail);
        $unreadCount = 0;
        foreach ($allThreads as &$thRow) {
            $sLow = strtolower(trim((string) ($thRow['sender_email'] ?? '')));
            $thRow['is_unread'] = !$thRow['is_read'] && $sLow !== $selfLow && $sLow !== $linkLow;
            if ($thRow['is_unread']) {
                $unreadCount++;
            }
        }
        unset($thRow);

        usort($allThreads, static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return ($tb <=> $ta) ?: strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
        });

        $threads = array_values(array_filter($allThreads, static function (array $th) use ($folder, $selectedThread): bool {
            if ($folder === 'inbox') {
                return ($th['folder'] ?? '') === 'inbox';
            }
            if ($folder === 'sent') {
                return ($th['folder'] ?? '') === 'sent';
            }
            if ($folder === 'unread') {
                return !empty($th['is_unread']) || ($selectedThread !== '' && ($th['thread_id'] ?? '') === $selectedThread);
            }
            return true;
        }));

        if ($selectedThread === '' && !empty($threads)) {
            $selectedThread = (string) $threads[0]['thread_id'];
        } elseif ($selectedThread !== '' && ($user['role'] ?? '') !== 'admin') {
            $visibleThreadIds = array_flip(array_map(static fn(array $th): string => (string) $th['thread_id'], $allThreads));
            if (!isset($visibleThreadIds[$selectedThread])) {
                $selectedThread = !empty($threads) ? (string) $threads[0]['thread_id'] : '';
            }
        }

        $threadMessages = [];
        $attachmentsByMsg = [];
        if ($selectedThread !== '') {
            $mStmt = $this->db->prepare('SELECT id, thread_id, sender_name, sender_email, recipient_email, cc_email, subject, body, body_html, body_format, snippet, folder, is_read, created_at FROM email_messages WHERE thread_id = :tid ORDER BY created_at ASC, id ASC');
            $mStmt->execute([':tid' => $selectedThread]);
            $threadMessages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            if (($user['role'] ?? '') !== 'admin' && !empty($adminEmailLookup)) {
                $threadMessages = array_values(array_filter($threadMessages, static function (array $m) use ($adminEmailLookup): bool {
                    $sender = strtolower(trim((string) ($m['sender_email'] ?? '')));
                    $recip = strtolower(trim((string) ($m['recipient_email'] ?? '')));
                    return !isset($adminEmailLookup[$sender]) && !isset($adminEmailLookup[$recip]);
                }));
            }

            if (!empty($threadMessages)) {
                $markStmt = $this->db->prepare("UPDATE email_messages SET is_read = TRUE WHERE thread_id = :tid AND is_read = FALSE AND (LOWER(recipient_email) IN (LOWER(:email), LOWER(:lemail)) OR LOWER(COALESCE(cc_email, '')) LIKE LOWER(:cemail))");
                $markStmt->execute([':tid' => $selectedThread, ':email' => $email, ':lemail' => $linkedEmail, ':cemail' => '%' . $linkedEmail . '%']);
                if ($markStmt->rowCount() > 0) {
                    CacheService::invalidatePrefix($this->db, 'alerts:');
                    foreach ($threads as &$tMark) {
                        if ($tMark['thread_id'] === $selectedThread && !empty($tMark['is_unread'])) {
                            $tMark['is_unread'] = false;
                            $tMark['is_read'] = true;
                            $unreadCount = max(0, $unreadCount - 1);
                        }
                    }
                    unset($tMark);
                }

                $msgIds = array_column($threadMessages, 'id');
                if (!empty($msgIds)) {
                    $ph = implode(',', array_fill(0, count($msgIds), '?'));
                    $aStmt = $this->db->prepare("SELECT id, email_id, filename, mime_type, size_bytes, content_id, is_inline FROM email_attachments WHERE email_id IN ({$ph}) ORDER BY created_at ASC");
                    $aStmt->execute($msgIds);
                    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
                        $attachmentsByMsg[$att['email_id']][] = $att;
                    }
                }
            }
        }

        if (($user['role'] ?? '') === 'admin') {
            $contacts = $this->db->query("SELECT name, email, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $contacts = $this->db->query("SELECT name, email, role FROM users WHERE is_active = TRUE AND role <> 'admin' ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'threads'          => $threads,
            'total_threads'    => count($allThreads),
            'unread_count'     => $unreadCount,
            'selected_thread'  => $selectedThread,
            'selected_folder'  => $folder,
            'messages'         => $threadMessages,
            'attachments'      => $attachmentsByMsg,
            'mailbox'          => $mailbox,
            'contacts'         => $contacts,
        ];
    }

    private function loadKnowledgeData(array $user): array
    {
        $cats = $this->db->query('SELECT id, name FROM knowledge_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

        if ($user['role'] === 'va') {
            $stmt = $this->db->prepare("
                SELECT k.*, c.agency_name AS client_name
                FROM knowledge_docs k
                LEFT JOIN clients c ON k.client_id = c.id
                WHERE k.client_id IS NULL
                   OR k.client_id IN (SELECT id FROM clients WHERE focus_va_id = :va)
                ORDER BY k.last_updated DESC, k.created_at DESC
            ");
            $stmt->execute([':va' => $user['id']]);
        } else {
            $stmt = $this->db->query("
                SELECT k.*, c.agency_name AS client_name
                FROM knowledge_docs k
                LEFT JOIN clients c ON k.client_id = c.id
                ORDER BY k.last_updated DESC, k.created_at DESC
            ");
        }

        $clients = $this->db->query('SELECT id, agency_name FROM clients ORDER BY agency_name ASC')->fetchAll(PDO::FETCH_ASSOC);

        return [
            'categories' => $cats,
            'docs'       => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'clients'    => $clients,
        ];
    }

    private function loadPerksData(array $user): array
    {
        $board = PerksApiController::fetchBoardPayload($this->db, $user);

        try {
            $vas = $this->db->query("
                SELECT u.id, u.name, u.email, COALESCE(u.avatar_url, '') AS avatar_url
                FROM users u
                WHERE u.is_active = TRUE
                  AND (
                    u.role = 'va'
                    OR EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_slug = 'va')
                  )
                ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $vas = $this->db->query("
                SELECT id, name, email, COALESCE(avatar_url, '') AS avatar_url
                FROM users
                WHERE role = 'va' AND is_active = TRUE
                ORDER BY name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'perks'           => $board['perks'],
            'grants'          => $board['grants'],
            'badges'          => $board['badges'],
            'awards'          => $board['awards'],
            'history'         => $board['history'],
            'vas'             => $vas,
            'available_icons' => PerksApiController::VALID_ICONS,
        ];
    }
}
