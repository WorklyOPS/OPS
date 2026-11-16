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

        $alerts = $this->loadSidebarAlerts($user);
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
            'va_directory'     => 'VA & Client Directory',
            'profile'          => 'VA Profile & Performance',
            'clients'          => 'Client CRM',
            'calendar_reports' => 'Calendar & SLA Reports',
            'pto'              => 'PTO & Leave Requests',
            'email'            => 'Email Communications',
            'knowledge'        => 'SOP Knowledge Base',
            'perks'            => 'Perks & Achievements',
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
            } elseif ($role === 'hrm' || $role === 'admin') {
                $pendingPto = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM pto_requests WHERE status = 'pending'")->fetchColumn();
                $reviewTasks = (int) $this->db->query("SELECT COUNT(*)::INTEGER FROM tasks WHERE status = 'in_review'")->fetchColumn();
            }

            return [
                'unread_emails' => $unreadEmails,
                'pending_pto'   => $pendingPto,
                'review_tasks'  => $reviewTasks,
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
            SELECT t.*, u.name AS va_name, u.avatar_url AS va_avatar, c.agency_name AS client_name
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

        $sql .= ' ORDER BY t.deadline ASC, t.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch notes & comments indexed by task_id
        $taskIds = array_column($tasks, 'id');
        $notesByTask = [];
        $commentsByTask = [];
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
        }

        $statusCounts = ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'in_review' => 0, 'completed' => 0];
        foreach ($tasks as $t) {
            if (isset($statusCounts[$t['status']])) {
                $statusCounts[$t['status']]++;
            }
        }

        // Clients for task creation dropdown
        if ($role === 'va') {
            $clStmt = $this->db->prepare('SELECT id, agency_name FROM clients WHERE focus_va_id = :va ORDER BY agency_name ASC');
            $clStmt->execute([':va' => $uid]);
        } elseif ($role === 'team_leader') {
            $clStmt = $this->db->prepare('SELECT id, agency_name FROM clients WHERE team_leader_id = :tl ORDER BY agency_name ASC');
            $clStmt->execute([':tl' => $uid]);
        } else {
            $clStmt = $this->db->query('SELECT id, agency_name FROM clients ORDER BY agency_name ASC');
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

        // Past 6 months list for historical selection
        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $m = (new DateTimeImmutable('first day of this month'))->modify("-{$i} months");
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return [
            'tasks'              => $tasks,
            'notes_by_task'      => $notesByTask,
            'comments_by_task'   => $commentsByTask,
            'status_counts'      => $statusCounts,
            'clients'            => $clients,
            'vas'                => $vas,
            'months'             => $months,
            'weeks'              => $weeks,
            'selected_month'     => $selectedMonth,
            'selected_week'      => $selectedWeek,
            'selected_va_filter' => $selectedVaFilter,
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
                $res['all_users'] = $this->db->query("
                    SELECT u.id, u.name, u.email, u.role, u.avatar_url, u.hourly_rate, u.team_leader_id,
                           u.is_active, u.is_suspended, u.suspended_reason, u.pto_balance_days,
                           mb.email AS connected_gmail, mb.oauth_status AS gmail_status, mb.last_synced_at
                    FROM users u
                    LEFT JOIN staff_connected_mailboxes mb ON u.id = mb.user_id
                    ORDER BY u.role ASC, u.name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);

                $res['team_leaders'] = array_values(array_filter(
                    $res['all_users'],
                    static fn(array $r) => $r['role'] === 'team_leader' && $r['is_active']
                ));

                $res['clients_overview'] = $this->db->query('SELECT * FROM v_client_capacity_overview ORDER BY agency_name ASC')->fetchAll(PDO::FETCH_ASSOC);
                $res['audit_months'] = AuditLogger::listMonths($this->db);
                $res['audit_entries'] = AuditLogger::queryLogEntries($this->db, null, null, 80);
                $res['secrets_status'] = Secrets::publicStatus();
                $res['cache_entries'] = (int) $this->db->query('SELECT COUNT(*)::INTEGER FROM cache_store WHERE expires_at > NOW()')->fetchColumn();
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
                    WHERE (u.team_leader_id = :tl OR t.created_by_id = :tl2)
                      AND t.deadline >= CURRENT_DATE - INTERVAL '30 days'
                    GROUP BY TO_CHAR(t.deadline, 'YYYY-MM-DD')
                    ORDER BY day_label ASC
                ");
                $trendStmt->execute([':tl' => $uid, ':tl2' => $uid]);
                $res['completion_trend'] = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($role === 'hrm') {
                $res['pto_requests'] = $this->db->query("
                    SELECT p.*, u.email AS va_email, u.pto_balance_days
                    FROM pto_requests p
                    JOIN users u ON p.va_id = u.id
                    ORDER BY CASE WHEN p.status = 'pending' THEN 0 ELSE 1 END, p.requested_at DESC
                ")->fetchAll(PDO::FETCH_ASSOC);

                $res['extra_hours'] = $this->db->query('SELECT * FROM extra_hours_logs ORDER BY logged_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
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

        $cSql = 'SELECT * FROM v_client_capacity_overview WHERE 1=1';
        $cParams = [];
        if ($role === 'team_leader') {
            $cSql = 'SELECT c.id AS client_id, c.agency_name, c.name AS executive_contact, c.contract_status, c.contracted_hours_per_month, c.consumed_hours_month, ROUND((c.consumed_hours_month / NULLIF(c.contracted_hours_per_month, 0)) * 100, 1) AS utilization_pct, c.focus_va_id, u.name AS focus_va_name, 0::INTEGER AS linked_tasks_count FROM clients c LEFT JOIN users u ON c.focus_va_id = u.id WHERE c.team_leader_id = :tl ORDER BY c.agency_name ASC';
            $cParams[':tl'] = $uid;
        } else {
            $cSql .= ' ORDER BY agency_name ASC';
        }
        $cStmt = $this->db->prepare($cSql);
        $cStmt->execute($cParams);

        return [
            'vas'     => $vas,
            'clients' => $cStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function loadVaProfileData(array $user): array
    {
        $targetId = $user['role'] === 'va'
            ? $user['id']
            : SecurityHelper::sanitizePlainText($_GET['va_id'] ?? $user['id'], 64);

        $uStmt = $this->db->prepare('SELECT id, name, email, role, avatar_url, phone, bio, hire_date, hourly_rate, total_earnings, pto_balance_days, pto_taken_days FROM users WHERE id = :id LIMIT 1');
        $uStmt->execute([':id' => $targetId]);
        $profile = $uStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

        $spStmt = $this->db->prepare('SELECT specialty FROM va_specialties WHERE va_id = :va ORDER BY specialty ASC');
        $spStmt->execute([':va' => $profile['id']]);
        $specialties = $spStmt->fetchAll(PDO::FETCH_COLUMN);

        $rStmt = $this->db->prepare('SELECT responsibility FROM va_responsibilities WHERE va_id = :va');
        $rStmt->execute([':va' => $profile['id']]);
        $responsibilities = $rStmt->fetchAll(PDO::FETCH_COLUMN);

        $eStmt = $this->db->prepare('SELECT * FROM va_monthly_earnings WHERE va_id = :va ORDER BY record_year DESC, month_number DESC LIMIT 12');
        $eStmt->execute([':va' => $profile['id']]);
        $earnings = $eStmt->fetchAll(PDO::FETCH_ASSOC);

        $bStmt = $this->db->prepare("
            SELECT b.title, b.description, b.tier, ub.earned_at
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            WHERE ub.user_id = :va
            ORDER BY ub.earned_at DESC
        ");
        $bStmt->execute([':va' => $profile['id']]);
        $badges = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        $pStmt = $this->db->prepare("
            SELECT p.title, p.category, p.value, p.description, up.granted_at
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

        return [
            'profile'          => $profile,
            'specialties'      => $specialties,
            'responsibilities' => $responsibilities,
            'earnings'         => $earnings,
            'badges'           => $badges,
            'perks'            => $perks,
            'task_stats'       => $taskStats,
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

        // SLA Report summary for initial range
        $repSql = "
            SELECT t.*, u.name AS va_name, c.agency_name AS client_name
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
        }
        $repSql .= ' ORDER BY t.deadline ASC';
        $rStmt = $this->db->prepare($repSql);
        $rStmt->execute($repParams);
        $reportTasks = $rStmt->fetchAll(PDO::FETCH_ASSOC);

        $vas = [];
        $clients = [];
        $staffDirectory = [];
        if ($role === 'team_leader') {
            $vs = $this->db->prepare("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE AND team_leader_id = :tl ORDER BY name ASC");
            $vs->execute([':tl' => $uid]);
            $vas = $vs->fetchAll(PDO::FETCH_ASSOC);
            $cs = $this->db->prepare('SELECT id, agency_name FROM clients WHERE team_leader_id = :tl ORDER BY agency_name ASC');
            $cs->execute([':tl' => $uid]);
            $clients = $cs->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($role !== 'va') {
            $vas = $this->db->query("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $clients = $this->db->query('SELECT id, agency_name FROM clients ORDER BY agency_name ASC')->fetchAll(PDO::FETCH_ASSOC);
            if ($role === 'admin') {
                $staffDirectory = $this->db->query("SELECT id, name, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Team Leaders for VA SLA report submission recipient dropdown
        $teamLeaders = $this->db->query("SELECT id, name, email FROM users WHERE role IN ('team_leader', 'admin') AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'cal_month'       => $calMonth,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'monitor_user'    => $monitorUser,
            'meetings'        => $meetings,
            'calendar_tasks'  => $calendarTasks,
            'report_tasks'    => $reportTasks,
            'vas'             => $vas,
            'clients'         => $clients,
            'staff_directory' => $staffDirectory,
            'team_leaders'    => $teamLeaders,
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

        return [
            'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function loadEmailData(array $user): array
    {
        $email = $user['email'];
        $selectedThread = SecurityHelper::sanitizePlainText($_GET['thread'] ?? '', 64);

        if ($user['role'] === 'admin') {
            $stmt = $this->db->query("
                SELECT DISTINCT ON (thread_id)
                       thread_id, subject, sender_name, sender_email, recipient_email, body, is_read, created_at
                FROM email_messages
                ORDER BY thread_id, created_at DESC
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT DISTINCT ON (thread_id)
                       thread_id, subject, sender_name, sender_email, recipient_email, body, is_read, created_at
                FROM email_messages
                WHERE sender_email = :e1 OR recipient_email = :e2 OR cc_email = :e3
                ORDER BY thread_id, created_at DESC
            ");
            $stmt->execute([':e1' => $email, ':e2' => $email, ':e3' => $email]);
        }
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sort threads newest first
        usort($threads, static fn(array $a, array $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        if ($selectedThread === '' && !empty($threads)) {
            $selectedThread = (string) $threads[0]['thread_id'];
        }

        $threadMessages = [];
        if ($selectedThread !== '') {
            $mStmt = $this->db->prepare('SELECT * FROM email_messages WHERE thread_id = :tid ORDER BY created_at ASC');
            $mStmt->execute([':tid' => $selectedThread]);
            $threadMessages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

            $markStmt = $this->db->prepare('UPDATE email_messages SET is_read = TRUE WHERE thread_id = :tid AND recipient_email = :email');
            $markStmt->execute([':tid' => $selectedThread, ':email' => $email]);
        }

        $mbStmt = $this->db->prepare('SELECT * FROM staff_connected_mailboxes WHERE user_id = :uid LIMIT 1');
        $mbStmt->execute([':uid' => $user['id']]);
        $mailbox = $mbStmt->fetch(PDO::FETCH_ASSOC);

        $contacts = $this->db->query("SELECT name, email, role FROM users WHERE is_active = TRUE ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'threads'         => $threads,
            'selected_thread' => $selectedThread,
            'messages'        => $threadMessages,
            'mailbox'         => $mailbox,
            'contacts'        => $contacts,
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
        $perks = $this->db->query('SELECT * FROM perks ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $grants = $this->db->query("
            SELECT up.perk_id, up.user_id, u.name AS va_name, up.granted_at
            FROM user_perks up
            JOIN users u ON up.user_id = u.id
            ORDER BY up.granted_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $badges = $this->db->query('SELECT * FROM badges ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        $awards = $this->db->query("
            SELECT ub.badge_id, ub.user_id, u.name AS va_name, b.title AS badge_title, b.tier, ub.earned_at
            FROM user_badges ub
            JOIN users u ON ub.user_id = u.id
            JOIN badges b ON ub.badge_id = b.id
            ORDER BY ub.earned_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $vas = $this->db->query("SELECT id, name, email FROM users WHERE role = 'va' AND is_active = TRUE ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'perks'  => $perks,
            'grants' => $grants,
            'badges' => $badges,
            'awards' => $awards,
            'vas'    => $vas,
        ];
    }
}
