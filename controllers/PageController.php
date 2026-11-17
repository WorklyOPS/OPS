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
            $unreadEmailsStmt = $this->db->prepare("
                SELECT COUNT(*)::INTEGER
                FROM email_messages
                WHERE is_read = FALSE AND recipient_email = :email
            ");
            $unreadEmailsStmt->execute([':email' => $user['email']]);
            $unreadEmails = (int) $unreadEmailsStmt->fetchColumn();

            $pendingPto = 0;
            $reviewTasks = 0;
            $unreadTlNotifications = 0;

            if ($role === 'team_leader') {
                $rStmt = $this->db->prepare("
                    SELECT COUNT(*)::INTEGER
                    FROM tasks t
                    JOIN users u ON t.assigned_va_id = u.id
                    WHERE t.status = 'in_review' AND (u.team_leader_id = :tl OR t.created_by_id = :tl2)
                ");
                $rStmt->execute([':tl' => $uid, ':tl2' => $uid]);
                $reviewTasks = (int) $rStmt->fetchColumn();

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
                $reviewTasks = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM tasks WHERE status = 'in_review'")->fetchColumn();
            }

            return [
                'unread_emails'           => $unreadEmails,
                'pending_pto'             => $pendingPto,
                'review_tasks'            => $reviewTasks,
                'unread_tl_notifications' => $unreadTlNotifications,
            ];
        });
    }

    private function loadTasksData(array $user): array
    {
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

        $sql .= ' ORDER BY c.agency_name ASC, t.deadline ASC, t.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch notes, comments & extra hours indexed by task_id
        $taskIds = array_column($tasks, 'id');
        $notesByTask = [];
        $commentsByTask = [];
        $extraHoursByTask = [];
        if (!empty($taskIds)) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $nStmt = $this->db->prepare("SELECT * FROM task_notes WHERE task_id IN ({$placeholders}) ORDER BY created_at ASC");
            $nStmt->execute($taskIds);
            foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $notesByTask[$n['task_id']][] = $n;
            }

            $cStmt = $this->db->prepare("SELECT * FROM task_comments WHERE task_id IN ({$placeholders}) ORDER BY created_at ASC");
            $cStmt->execute($taskIds);
            foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $commentsByTask[$c['task_id']][] = $c;
            }

            $ehStmt = $this->db->prepare("SELECT * FROM extra_hours_logs WHERE task_id IN ({$placeholders}) ORDER BY logged_at DESC");
            $ehStmt->execute($taskIds);
            foreach ($ehStmt->fetchAll(PDO::FETCH_ASSOC) as $eh) {
                $extraHoursByTask[$eh['task_id']][] = $eh;
            }
        }

        $statusCounts = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'in_review' => 0, 'completed' => 0];
        foreach ($tasks as $t) {
            if (isset($statusCounts[$t['status']])) {
                $statusCounts[$t['status']]++;
            }
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

        return [
            'tasks'                => $tasks,
            'notes_by_task'        => $notesByTask,
            'comments_by_task'     => $commentsByTask,
            'extra_hours_by_task'  => $extraHoursByTask,
            'status_counts'        => $statusCounts,
            'due_today_count'      => $dueTodayCount,
            'overdue_count'        => $overdueCount,
            'clients'              => $clients,
            'vas'                  => $vas,
            'months'               => $months,
            'weeks'                => $weeks,
            'selected_month'       => $selectedMonth,
            'selected_week'        => $selectedWeek,
            'selected_va_filter'   => $selectedVaFilter,
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
                $res['specialty_categories'] = UserApiController::listSpecialtyCategories($this->db);
                $allUsers = $this->db->query("
                    SELECT u.id, u.name, u.email, u.role, u.avatar_url, u.phone, u.bio, u.hourly_rate, u.team_leader_id,
                           u.is_active, u.is_suspended, u.suspended_reason, u.pto_balance_days,
                           mb.email AS connected_gmail, mb.oauth_status AS gmail_status, mb.last_synced_at,
                           COALESCE(STRING_AGG(DISTINCT sp.specialty, '||'), '') AS specialties_joined
                    FROM users u
                    LEFT JOIN staff_connected_mailboxes mb ON u.id = mb.user_id
                    LEFT JOIN va_specialties sp ON u.id = sp.va_id
                    GROUP BY u.id, mb.email, mb.oauth_status, mb.last_synced_at
                    ORDER BY u.role ASC, u.name ASC
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
                           COUNT(*)::INTEGER AS total_count,
                           COUNT(*) FILTER (WHERE t.status = 'completed')::INTEGER AS done_count
                    FROM tasks t
                    JOIN users u ON t.assigned_va_id = u.id
                    WHERE (u.team_leader_id = :tl OR t.assigned_va_id = :tl2 OR t.created_by_id = :tl3)
                      AND t.deadline >= CURRENT_DATE - INTERVAL '30 days'
                    GROUP BY TO_CHAR(t.deadline, 'YYYY-MM-DD')
                    ORDER BY day_label ASC
                ");
                $trendStmt->execute([':tl' => $uid, ':tl2' => $uid, ':tl3' => $uid]);
                $res['completion_trend'] = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

                // Kanban distribution per status & per VA assignee
                $kDistStmt = $this->db->prepare("
                    SELECT u.id AS va_id,
                           u.name AS va_name,
                           u.avatar_url,
                           COALESCE(m.on_time_rate, 100.0) AS on_time_rate,
                           COUNT(t.id) FILTER (WHERE t.status = 'backlog')::INTEGER AS backlog,
                           COUNT(t.id) FILTER (WHERE t.status = 'todo')::INTEGER AS todo,
                           COUNT(t.id) FILTER (WHERE t.status = 'in_progress')::INTEGER AS in_progress,
                           COUNT(t.id) FILTER (WHERE t.status = 'in_review')::INTEGER AS in_review,
                           COUNT(t.id) FILTER (WHERE t.status = 'completed')::INTEGER AS completed,
                           COUNT(t.id)::INTEGER AS total_tasks,
                           COALESCE(SUM(t.estimated_hours + COALESCE(t.extra_hours_logged, 0)), 0)::NUMERIC AS total_hours
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

                $kanbanStatusCounts = [
                    'backlog'     => 0,
                    'todo'        => 0,
                    'in_progress' => 0,
                    'in_review'   => 0,
                    'completed'   => 0,
                ];
                foreach ($kanbanByVa as $vaRow) {
                    foreach (array_keys($kanbanStatusCounts) as $stKey) {
                        $kanbanStatusCounts[$stKey] += (int) ($vaRow[$stKey] ?? 0);
                    }
                }
                $res['kanban_status_counts'] = $kanbanStatusCounts;
                $res['kanban_by_va'] = $kanbanByVa;

                // Review queue (In Review deliverables awaiting TL approval)
                $revStmt = $this->db->prepare("
                    SELECT t.id, t.title, t.priority, t.deadline, t.estimated_hours,
                           u.name AS va_name, c.agency_name AS client_name
                    FROM tasks t
                    JOIN users u ON t.assigned_va_id = u.id
                    JOIN clients c ON t.client_id = c.id
                    WHERE t.status = 'in_review'
                      AND (u.team_leader_id = :tl OR t.created_by_id = :tl2)
                    ORDER BY t.deadline ASC
                    LIMIT 15
                ");
                $revStmt->execute([':tl' => $uid, ':tl2' => $uid]);
                $res['review_queue'] = $revStmt->fetchAll(PDO::FETCH_ASSOC);
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
                   COALESCE(STRING_AGG(DISTINCT s.specialty, '||'), '') AS specialties_joined,
                   COALESCE(STRING_AGG(DISTINCT s.specialty, ', '), '') AS specialties_csv
            FROM v_va_performance_summary v
            JOIN users u ON v.va_id = u.id
            LEFT JOIN va_specialties s ON u.id = s.va_id
            WHERE u.is_active = TRUE
        ";
        $params = [];
        if ($role === 'team_leader') {
            $sql .= ' AND u.team_leader_id = :tl';
            $params[':tl'] = $uid;
        }
        $sql .= ' GROUP BY v.va_id, v.va_name, v.va_email, v.hourly_rate, v.total_earnings, v.pto_balance_days, v.pto_taken_days, v.total_tasks, v.completed_tasks, v.in_progress_tasks, v.total_estimated_hours, v.total_extra_hours, v.on_time_rate, v.velocity_score, u.avatar_url, u.phone, u.bio, u.hire_date, u.team_leader_id ORDER BY v.va_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $vas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vas as &$v) {
            $v['specialties'] = $v['specialties_joined'] !== '' ? explode('||', (string) $v['specialties_joined']) : [];
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
        $targetId = $user['role'] === 'va'
            ? $user['id']
            : SecurityHelper::sanitizePlainText($_GET['va_id'] ?? $user['id'], 64);

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

        $allStaff = in_array($user['role'], ['admin', 'team_leader', 'hrm'], true)
            ? $this->db->query("SELECT id, name, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC)
            : [];
        $allVas = in_array($user['role'], ['admin', 'team_leader'], true)
            ? $this->db->query("SELECT id, name, email, team_leader_id FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
            : [];
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
            SELECT c.*, u.name AS focus_va_name, tl.name AS tl_name
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
            $sql .= ' AND (c.agency_name ILIKE :q OR c.name ILIKE :q OR c.email ILIKE :q)';
            $params[':q'] = "%{$search}%";
        }
        $sql .= ' ORDER BY c.agency_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $actByClient = [];
        $clientIds = array_column($clients, 'id');
        if (!empty($clientIds)) {
            $ph = implode(',', array_fill(0, count($clientIds), '?'));
            $aStmt = $this->db->prepare("SELECT * FROM client_activities WHERE client_id IN ({$ph}) ORDER BY created_at DESC");
            $aStmt->execute($clientIds);
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $actByClient[$a['client_id']][] = $a;
            }
        }

        $vas = $this->db->query("SELECT id, name FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tls = $this->db->query("SELECT id, name FROM users WHERE role = 'team_leader' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'clients'       => $clients,
            'activities'    => $actByClient,
            'vas'           => $vas,
            'team_leaders'  => $tls,
            'search'        => $search,
        ];
    }

    private function loadCalendarReportsData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        $calMonth = SecurityHelper::sanitizePlainText($_GET['month'] ?? date('Y-m'), 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $calMonth)) {
            $calMonth = date('Y-m');
        }

        $startDate = SecurityHelper::sanitizePlainText($_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week')), 16);
        $endDate = SecurityHelper::sanitizePlainText($_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week')), 16);
        $monitorUser = SecurityHelper::sanitizePlainText($_GET['monitor_user'] ?? '', 64);

        $mStart = $calMonth . '-01';
        $mEnd = (new DateTimeImmutable($mStart))->modify('last day of this month')->format('Y-m-d');

        // Strict calendar privacy: own meetings/shared meetings only; Admin can select a user to monitor
        $targetCalUid = ($role === 'admin' && $monitorUser !== '') ? $monitorUser : $uid;

        $mStmt = $this->db->prepare("
            SELECT m.*, u.name AS va_name, c.agency_name AS client_name
            FROM calendar_meetings m
            JOIN users u ON m.va_id = u.id
            LEFT JOIN clients c ON m.client_id = c.id
            WHERE (m.va_id = :u1 OR m.created_by_tl_id = :u2)
              AND m.start_time::DATE BETWEEN :ms AND :me
            ORDER BY m.start_time ASC
        ");
        $mStmt->execute([':u1' => $targetCalUid, ':u2' => $targetCalUid, ':ms' => $mStart, ':me' => $mEnd]);
        $meetings = $mStmt->fetchAll(PDO::FETCH_ASSOC);

        // Task milestones in that month (own tasks for VA; pod + own for TL; selected user for Admin)
        $tStmt = $this->db->prepare("
            SELECT id, title, deadline, status, priority
            FROM tasks
            WHERE assigned_va_id = :uid AND deadline BETWEEN :ms AND :me
            ORDER BY deadline ASC
        ");
        $tStmt->execute([':uid' => $targetCalUid, ':ms' => $mStart, ':me' => $mEnd]);
        $calendarTasks = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        // Approved PTO blocks inside selected month
        $ptoStmt = $this->db->prepare("
            SELECT id, va_name, start_date, end_date
            FROM pto_requests
            WHERE va_id = :uid AND status = 'approved'
              AND start_date <= :me AND end_date >= :ms
            ORDER BY start_date ASC
        ");
        $ptoStmt->execute([':uid' => $targetCalUid, ':ms' => $mStart, ':me' => $mEnd]);
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
        $repSql .= ' ORDER BY t.deadline ASC, t.priority DESC';
        $rStmt = $this->db->prepare($repSql);
        $rStmt->execute($repParams);
        $reportTasks = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute granular executive SLA analytics
        $todayStr = date('Y-m-d');
        $repStatusCounts = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'in_review' => 0, 'completed' => 0];
        $repPriorityCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        $repByClient = [];
        $repByVa = [];
        $repDone = 0;
        $repOnTime = 0;
        $repLate = 0;
        $repOverdue = 0;
        $repEstHours = 0.0;
        $repExtraHours = 0.0;

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

        // Team Leaders for VA SLA report submission recipient dropdown
        $teamLeaders = $this->db->query("SELECT id, name, email FROM users WHERE role IN ('team_leader', 'admin') AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'cal_month'        => $calMonth,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'report_va'        => $reportVa,
            'monitor_user'     => $monitorUser,
            'meetings'         => $meetings,
            'calendar_tasks'   => $calendarTasks,
            'calendar_pto'     => $calendarPto,
            'report_tasks'     => $reportTasks,
            'report_analytics' => [
                'total'           => count($reportTasks),
                'completed'       => $repDone,
                'on_time'         => $repOnTime,
                'late'            => $repLate,
                'overdue'         => $repOverdue,
                'est_hours'       => round($repEstHours, 2),
                'extra_hours'     => round($repExtraHours, 2),
                'total_hours'     => round($repEstHours + $repExtraHours, 2),
                'status_counts'   => $repStatusCounts,
                'priority_counts' => $repPriorityCounts,
                'by_client'       => array_values($repByClient),
                'by_va'           => array_values($repByVa),
            ],
            'vas'              => $vas,
            'clients'          => $clients,
            'staff_directory'  => $staffDirectory,
            'team_leaders'     => $teamLeaders,
        ];
    }

    private function loadPtoData(array $user): array
    {
        $role = $user['role'];
        $uid = $user['id'];

        if ($role === 'va') {
            $stmt = $this->db->prepare('SELECT * FROM pto_requests WHERE va_id = :va ORDER BY requested_at DESC');
            $stmt->execute([':va' => $uid]);
        } elseif ($role === 'team_leader') {
            $stmt = $this->db->prepare("
                SELECT p.*, u.pto_balance_days
                FROM pto_requests p
                JOIN users u ON p.va_id = u.id
                WHERE u.team_leader_id = :tl
                ORDER BY p.requested_at DESC
            ");
            $stmt->execute([':tl' => $uid]);
        } else {
            $stmt = $this->db->query("
                SELECT p.*, u.pto_balance_days, u.email AS va_email
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

        return [
            'requests'      => $requests,
            'notifications' => $notifications,
            'tracking'      => $trackingByReq,
        ];
    }

    private function loadEmailData(array $user): array
    {
        try {
            $this->db->exec('ALTER TABLE email_messages ADD COLUMN IF NOT EXISTS snippet TEXT DEFAULT NULL');
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS email_attachments (
                    id VARCHAR(64) PRIMARY KEY,
                    email_id VARCHAR(64) NOT NULL REFERENCES email_messages(id) ON DELETE CASCADE,
                    filename VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
                    size_bytes INTEGER NOT NULL DEFAULT 0,
                    data_base64 TEXT DEFAULT NULL,
                    gmail_attachment_id VARCHAR(255) DEFAULT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
        } catch (Throwable) {
        }

        $mbStmt = $this->db->prepare('SELECT * FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $mailbox = $mbStmt->fetch(PDO::FETCH_ASSOC);

        $email = (string) $user['email'];
        $linkedEmail = $mailbox ? (string) $mailbox['email'] : $email;
        $selectedThread = SecurityHelper::sanitizePlainText($_GET['thread'] ?? '', 64);
        $folder = SecurityHelper::sanitizePlainText($_GET['folder'] ?? 'all', 16);

        $folderClause = match ($folder) {
            'inbox' => " AND folder = 'inbox' ",
            'sent'  => " AND folder = 'sent' ",
            default => '',
        };

        if ($user['role'] === 'admin' && empty($mailbox)) {
            $stmt = $this->db->query("
                SELECT DISTINCT ON (thread_id)
                       thread_id, subject, sender_name, sender_email, recipient_email, cc_email, body, snippet, folder, is_read, created_at
                FROM email_messages
                WHERE 1=1 {$folderClause}
                ORDER BY thread_id, created_at DESC
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT DISTINCT ON (thread_id)
                       thread_id, subject, sender_name, sender_email, recipient_email, cc_email, body, snippet, folder, is_read, created_at
                FROM email_messages
                WHERE (
                    LOWER(sender_email) IN (LOWER(:e1), LOWER(:l1))
                    OR LOWER(recipient_email) IN (LOWER(:e2), LOWER(:l2))
                    OR LOWER(COALESCE(cc_email, '')) LIKE LOWER(:c1)
                ) {$folderClause}
                ORDER BY thread_id, created_at DESC
            ");
            $stmt->execute([
                ':e1' => $email,
                ':l1' => $linkedEmail,
                ':e2' => $email,
                ':l2' => $linkedEmail,
                ':c1' => '%' . $linkedEmail . '%',
            ]);
        }
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        usort($threads, static fn(array $a, array $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        if ($selectedThread === '' && !empty($threads)) {
            $selectedThread = (string) $threads[0]['thread_id'];
        }

        $threadMessages = [];
        $attachmentsByMsg = [];
        if ($selectedThread !== '') {
            $mStmt = $this->db->prepare('SELECT * FROM email_messages WHERE thread_id = :tid ORDER BY created_at ASC');
            $mStmt->execute([':tid' => $selectedThread]);
            $threadMessages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $markStmt = $this->db->prepare('UPDATE email_messages SET is_read = TRUE WHERE thread_id = :tid AND (LOWER(recipient_email) = LOWER(:email) OR LOWER(recipient_email) = LOWER(:lemail))');
            $markStmt->execute([':tid' => $selectedThread, ':email' => $email, ':lemail' => $linkedEmail]);

            $msgIds = array_column($threadMessages, 'id');
            if (!empty($msgIds)) {
                $ph = implode(',', array_fill(0, count($msgIds), '?'));
                $aStmt = $this->db->prepare("SELECT id, email_id, filename, mime_type, size_bytes FROM email_attachments WHERE email_id IN ({$ph}) ORDER BY created_at ASC");
                $aStmt->execute($msgIds);
                foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
                    $attachmentsByMsg[$att['email_id']][] = $att;
                }
            }
        }

        $contacts = $this->db->query("SELECT name, email, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $firebaseCfg = null;
        $cfgPath = __DIR__ . '/../firebase-applet-config.json';
        if (is_file($cfgPath)) {
            $decoded = json_decode((string) @file_get_contents($cfgPath), true);
            if (is_array($decoded)) {
                $firebaseCfg = [
                    'apiKey'            => $decoded['apiKey'] ?? '',
                    'authDomain'        => $decoded['authDomain'] ?? '',
                    'projectId'         => $decoded['projectId'] ?? '',
                    'appId'             => $decoded['appId'] ?? '',
                    'messagingSenderId' => $decoded['messagingSenderId'] ?? '',
                ];
            }
        }

        return [
            'threads'          => $threads,
            'selected_thread'  => $selectedThread,
            'selected_folder'  => $folder,
            'messages'         => $threadMessages,
            'attachments'      => $attachmentsByMsg,
            'mailbox'          => $mailbox,
            'contacts'         => $contacts,
            'firebase_config'  => $firebaseCfg,
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
        try {
            $this->db->exec("ALTER TABLE perks ADD COLUMN IF NOT EXISTS icon_name VARCHAR(64) NOT NULL DEFAULT 'Award'");
        } catch (Throwable) {
        }

        $perks = $this->db->query("SELECT id, title, category, COALESCE(icon_name, 'Award') AS icon_name, value, description, active, created_at FROM perks ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $grants = $this->db->query("
            SELECT up.perk_id, up.user_id, u.name AS va_name, up.granted_at
            FROM user_perks up
            JOIN users u ON up.user_id = u.id
            ORDER BY up.granted_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $badges = $this->db->query("SELECT id, title, description, COALESCE(icon_name, 'Medal') AS icon_name, tier, trigger_type, required_threshold, created_at FROM badges ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        $awards = $this->db->query("
            SELECT ub.badge_id, ub.user_id, u.name AS va_name, b.title AS badge_title, b.tier, COALESCE(b.icon_name, 'Medal') AS icon_name, ub.earned_at
            FROM user_badges ub
            JOIN users u ON ub.user_id = u.id
            JOIN badges b ON ub.badge_id = b.id
            ORDER BY ub.earned_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $vas = $this->db->query("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'perks'           => $perks,
            'grants'          => $grants,
            'badges'          => $badges,
            'awards'          => $awards,
            'vas'             => $vas,
            'available_icons' => PerksApiController::VALID_ICONS,
        ];
    }
}
