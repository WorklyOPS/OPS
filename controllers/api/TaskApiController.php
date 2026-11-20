<?php
/**
 * Workly Tasks REST API Controller
 */

declare(strict_types=1);

require_once __DIR__ . '/../../services/CacheService.php';
require_once __DIR__ . '/../../services/AuditLogger.php';
require_once __DIR__ . '/../../services/SecurityHelper.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../../middleware/TaskRoleMiddleware.php';
require_once __DIR__ . '/../../services/GoogleCalendarService.php';
require_once __DIR__ . '/NotificationApiController.php';

final class TaskApiController
{
    public const VALID_PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private static bool $schemaChecked = false;

    public function __construct(private PDO $db) {}

    public static function ensureTaskSchemaConstraints(PDO $db): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            $db->exec("UPDATE tasks SET status = 'in_progress', updated_at = NOW() WHERE status = 'in_review'");
            $db->exec("ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_status_check");
            $db->exec("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('backlog', 'todo', 'in_progress', 'completed'))");
            $db->exec("ALTER TABLE tasks DROP CONSTRAINT IF EXISTS tasks_priority_check");
            $db->exec("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('low', 'medium', 'high', 'urgent'))");
            $db->exec("
                CREATE TABLE IF NOT EXISTS task_subtasks (
                    id VARCHAR(64) PRIMARY KEY,
                    task_id VARCHAR(64) NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                    title VARCHAR(255) NOT NULL,
                    is_completed BOOLEAN NOT NULL DEFAULT FALSE,
                    position INTEGER NOT NULL DEFAULT 0,
                    created_by_id VARCHAR(64) DEFAULT NULL,
                    completed_at TIMESTAMPTZ DEFAULT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_subtask_task ON task_subtasks (task_id, position ASC, created_at ASC)");

            $db->exec("
                CREATE TABLE IF NOT EXISTS task_templates (
                    id VARCHAR(64) PRIMARY KEY,
                    title VARCHAR(160) NOT NULL,
                    specialty_category VARCHAR(80) NOT NULL,
                    description TEXT NOT NULL DEFAULT '',
                    default_priority VARCHAR(16) NOT NULL DEFAULT 'medium',
                    default_estimated_hours NUMERIC(6,2) NOT NULL DEFAULT 2.00,
                    subtasks_json TEXT NOT NULL DEFAULT '[]',
                    usage_count INTEGER NOT NULL DEFAULT 0,
                    is_system BOOLEAN NOT NULL DEFAULT FALSE,
                    created_by_id VARCHAR(64) DEFAULT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_task_tpl_category ON task_templates (specialty_category ASC, usage_count DESC)");

            $tplCount = (int) $db->query('SELECT COUNT(*)::INTEGER FROM task_templates')->fetchColumn();
            if ($tplCount === 0) {
                $seeds = [
                    [
                        'id'       => 'tpl-seed-exec-inbox',
                        'title'    => 'Executive Inbox & Calendar Triage',
                        'category' => 'Executive Admin',
                        'desc'     => 'Daily inbox prioritization, calendar conflict resolution, and executive briefing preparation.',
                        'priority' => 'high',
                        'hours'    => 2.0,
                        'steps'    => [
                            'Audit unread priority emails & flag VIP senders',
                            'Confirm upcoming 48h calendar invites & buffers',
                            'Prepare executive meeting briefing packet',
                            'Send EOD inbox zero status summary',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-exec-meeting',
                        'title'    => 'Leadership Meeting Prep & Minutes',
                        'category' => 'Executive Admin',
                        'desc'     => 'End-to-end agenda coordination, deck compilation, and post-meeting action item tracking.',
                        'priority' => 'medium',
                        'hours'    => 1.5,
                        'steps'    => [
                            'Collect department status decks & KPIs',
                            'Compile timeboxed agenda & talking points',
                            'Verify attendee list & video link access',
                            'Distribute action-item minutes within 2h',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-smm-sprint',
                        'title'    => 'Weekly Multi-Channel Content Sprint',
                        'category' => 'Social Media Manager',
                        'desc'     => 'Create, schedule, and QA a full weekly batch of social posts across primary client channels.',
                        'priority' => 'high',
                        'hours'    => 3.5,
                        'steps'    => [
                            'Draft post copy & hook variations',
                            'Size & export branded visual creatives',
                            'Schedule posts across LinkedIn, IG & X',
                            'Tag UTM links & verify first-comment hashtags',
                            'Log weekly engagement snapshot',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-smm-community',
                        'title'    => 'Community Engagement & DM Audit',
                        'category' => 'Social Media Manager',
                        'desc'     => 'Daily comment moderation, inbound DM routing, and competitor trend monitoring.',
                        'priority' => 'medium',
                        'hours'    => 1.5,
                        'steps'    => [
                            'Respond to pending comments & mentions',
                            'Escalate qualified sales/support DMs to CRM',
                            'Audit competitor & niche trend signals',
                            'Report top-performing posts & sentiment',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-data-hygiene',
                        'title'    => 'CRM & Spreadsheet Hygiene Audit',
                        'category' => 'Data Entry',
                        'desc'     => 'Standardize, deduplicate, and validate bulk CRM contact and account records.',
                        'priority' => 'medium',
                        'hours'    => 2.5,
                        'steps'    => [
                            'Deduplicate contact & company rows',
                            'Standardize phone, email & address formatting',
                            'Validate missing required CRM fields',
                            'Cross-check row counts & export clean CSV',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-data-invoice',
                        'title'    => 'Invoice & Vendor Record Entry',
                        'category' => 'Data Entry',
                        'desc'     => 'Structured extraction and ledger entry of vendor bills and client invoice line items.',
                        'priority' => 'high',
                        'hours'    => 2.0,
                        'steps'    => [
                            'Extract line items from source PDFs',
                            'Match vendor IDs & cost center codes',
                            'Verify tax & subtotal calculations',
                            'Mark batch ready for finance approval',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-dev-release',
                        'title'    => 'Feature QA & Release Verification',
                        'category' => 'Development',
                        'desc'     => 'Pre-release lint, cross-viewport UI verification, and regression test sign-off.',
                        'priority' => 'high',
                        'hours'    => 3.0,
                        'steps'    => [
                            'Verify staging branch & lint checks',
                            'Run regression QA pass on core flows',
                            'Test responsive layout on mobile & desktop',
                            'Update changelog & deployment notes',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-mkt-campaign',
                        'title'    => 'Email Campaign Launch & QA',
                        'category' => 'Marketing',
                        'desc'     => 'Audience segmentation, copy proofing, link validation, and scheduled campaign dispatch.',
                        'priority' => 'high',
                        'hours'    => 2.5,
                        'steps'    => [
                            'Segment target audience & suppress unsubscribes',
                            'Audit brand voice, subject line & preview text',
                            'Verify merge tags & UTM tracking links',
                            'Send test proof & schedule final dispatch',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-acc-recon',
                        'title'    => 'Month-End Bookkeeping Reconciliation',
                        'category' => 'Accounting',
                        'desc'     => 'Reconcile bank feeds, categorize expenses, and prepare aging & P&L summaries.',
                        'priority' => 'high',
                        'hours'    => 3.5,
                        'steps'    => [
                            'Reconcile bank & credit card statements',
                            'Categorize unreviewed ledger transactions',
                            'Audit AP/AR aging & overdue invoices',
                            'Export reconciled P&L variance summary',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-legal-contract',
                        'title'    => 'Contract Intake & Redline Review',
                        'category' => 'Legal',
                        'desc'     => 'Verify contract entities, clauses, and signature readiness before legal archive.',
                        'priority' => 'urgent',
                        'hours'    => 2.0,
                        'steps'    => [
                            'Verify legal entity names, dates & jurisdiction',
                            'Cross-check indemnity & renewal clause terms',
                            'Prepare redline summary for counsel sign-off',
                            'Archive executed PDF in compliance folder',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-hc-intake',
                        'title'    => 'Patient Intake & Eligibility Check',
                        'category' => 'Healthcare',
                        'desc'     => 'HIPAA-compliant patient verification, insurance check, and EHR record preparation.',
                        'priority' => 'high',
                        'hours'    => 1.5,
                        'steps'    => [
                            'Verify HIPAA consent & patient demographics',
                            'Confirm active insurance coverage & copay',
                            'Flag prior-authorization requirements',
                            'Log verified intake status in EHR',
                        ],
                    ],
                    [
                        'id'       => 'tpl-seed-ins-coi',
                        'title'    => 'Policy Renewal & COI Issuance',
                        'category' => 'Insurance',
                        'desc'     => 'Audit expiring carrier policies, gather loss runs, and issue client certificates.',
                        'priority' => 'medium',
                        'hours'    => 2.0,
                        'steps'    => [
                            'Audit expiring policy limits & coverage',
                            'Request updated loss runs from carrier portal',
                            'Prepare renewal quote comparison sheet',
                            'Send COI & renewal confirmation to client',
                        ],
                    ],
                ];

                $insTpl = $db->prepare("
                    INSERT INTO task_templates (id, title, specialty_category, description, default_priority, default_estimated_hours, subtasks_json, usage_count, is_system)
                    VALUES (:id, :title, :cat, :desc, :prio, :hrs, :steps, 0, TRUE)
                    ON CONFLICT (id) DO NOTHING
                ");
                foreach ($seeds as $sd) {
                    $insTpl->execute([
                        ':id'    => $sd['id'],
                        ':title' => $sd['title'],
                        ':cat'   => $sd['category'],
                        ':desc'  => $sd['desc'],
                        ':prio'  => $sd['priority'],
                        ':hrs'   => $sd['hours'],
                        ':steps' => json_encode($sd['steps'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }
        } catch (Throwable) {
            // Constraints already aligned or restricted
        }
    }

    public static function listTaskTemplates(PDO $db, ?string $category = null): array
    {
        self::ensureTaskSchemaConstraints($db);
        try {
            if ($category !== null && trim($category) !== '' && strtolower(trim($category)) !== 'all') {
                $stmt = $db->prepare("
                    SELECT t.id, t.title, t.specialty_category, t.description, t.default_priority,
                           t.default_estimated_hours, t.subtasks_json, t.usage_count, t.is_system,
                           t.created_by_id, COALESCE(u.name, 'Workly Standard') AS created_by_name,
                           t.created_at
                    FROM task_templates t
                    LEFT JOIN users u ON t.created_by_id = u.id
                    WHERE LOWER(t.specialty_category) = LOWER(:cat)
                    ORDER BY t.usage_count DESC, t.is_system DESC, t.title ASC
                ");
                $stmt->execute([':cat' => trim($category)]);
            } else {
                $stmt = $db->query("
                    SELECT t.id, t.title, t.specialty_category, t.description, t.default_priority,
                           t.default_estimated_hours, t.subtasks_json, t.usage_count, t.is_system,
                           t.created_by_id, COALESCE(u.name, 'Workly Standard') AS created_by_name,
                           t.created_at
                    FROM task_templates t
                    LEFT JOIN users u ON t.created_by_id = u.id
                    ORDER BY t.specialty_category ASC, t.usage_count DESC, t.title ASC
                ");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $decoded = json_decode((string) ($r['subtasks_json'] ?? '[]'), true);
                $r['subtasks'] = is_array($decoded)
                    ? array_values(array_filter(array_map(static fn($s): string => trim((string) $s), $decoded), static fn(string $s): bool => $s !== ''))
                    : [];
                $r['default_estimated_hours'] = round((float) ($r['default_estimated_hours'] ?? 2.0), 2);
                $r['usage_count'] = (int) ($r['usage_count'] ?? 0);
                $r['is_system'] = filter_var($r['is_system'] ?? false, FILTER_VALIDATE_BOOLEAN);
                unset($r['subtasks_json']);
            }
            unset($r);
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Backend-only suggestion engine combining internal VA specialties & task_templates.
     * Returns only clean UI-ready steps & optional task starters (never exposes internal categories/templates).
     */
    public static function buildInternalSuggestionsForVas(PDO $db, array $vaIds): array
    {
        self::ensureTaskSchemaConstraints($db);

        $presetSteps = [
            'Development' => [
                'Verify staging & lint checks',
                'Run regression QA test pass',
                'Update repository & PR notes',
                'Confirm production deploy check',
            ],
            'Marketing' => [
                'Audit brand voice & copywriting',
                'Verify UTM links & pixel tracking',
                'Prepare & size creative assets',
                'Schedule campaign & QA preview',
            ],
            'Social Media Manager' => [
                'Draft post copy & hook variations',
                'Size & export branded visual creatives',
                'Schedule posts across active channels',
                'Verify UTM links & hashtag formatting',
            ],
            'Legal' => [
                'Review clauses, dates & entities',
                'Cross-check compliance checklist',
                'Verify signatory & witness fields',
                'Archive executed PDF copy',
            ],
            'Insurance' => [
                'Verify policy limits & coverage',
                'Audit client underwriting docs',
                'Update carrier portal status',
                'Send COI / renewal confirmation',
            ],
            'Healthcare' => [
                'Verify HIPAA privacy compliance',
                'Confirm patient & provider intake',
                'Audit billing & coding accuracy',
                'Log EHR follow-up summary',
            ],
            'Administrative' => [
                'Audit inbox & calendar conflicts',
                'Organize client cloud folders',
                'Update CRM tracker records',
                'Send EOD status summary',
            ],
            'Executive Admin' => [
                'Audit unread priority emails & flag VIPs',
                'Confirm 48h calendar invites & buffers',
                'Prepare executive briefing packet',
                'Send EOD inbox zero status summary',
            ],
            'Data Entry' => [
                'Deduplicate contact & account rows',
                'Standardize phone, email & address fields',
                'Validate missing required records',
                'Cross-check totals & export clean CSV',
            ],
            'Management' => [
                'Confirm pod owners & capacity',
                'Review SLA milestones & blockers',
                'Verify QA sign-off criteria',
                'Send stakeholder progress brief',
            ],
            'Accounting' => [
                'Reconcile invoices & receipts',
                'Verify ledger account categories',
                'Cross-check tax & due dates',
                'Export reconciled ledger summary',
            ],
            'General' => [
                'Review acceptance criteria',
                'Complete primary deliverable draft',
                'Self-QA & proofread output',
                'Confirm client handoff ready',
            ],
        ];

        $categoryAliases = [
            'administrative' => ['Administrative', 'Executive Admin', 'Data Entry'],
            'marketing'      => ['Marketing', 'Social Media Manager'],
            'accounting'     => ['Accounting', 'Data Entry'],
            'management'     => ['Management', 'Executive Admin'],
        ];

        $allTemplates = self::listTaskTemplates($db);
        $templatesByCat = [];
        foreach ($allTemplates as $tpl) {
            $catKey = strtolower(trim((string) ($tpl['specialty_category'] ?? '')));
            if ($catKey !== '') {
                $templatesByCat[$catKey][] = $tpl;
            }
        }

        $cleanIds = array_values(array_unique(array_filter(array_map('strval', $vaIds), static fn(string $v): bool => $v !== '')));
        $specsByVa = [];
        if (!empty($cleanIds)) {
            try {
                $ph = implode(',', array_fill(0, count($cleanIds), '?'));
                $spStmt = $db->prepare("SELECT va_id, specialty FROM va_specialties WHERE va_id IN ({$ph}) ORDER BY specialty ASC");
                $spStmt->execute($cleanIds);
                foreach ($spStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $specsByVa[(string) $row['va_id']][] = (string) $row['specialty'];
                }
            } catch (Throwable) {
                $specsByVa = [];
            }
        }

        $buildForSpecs = static function (array $specs) use ($presetSteps, $categoryAliases, $templatesByCat, $allTemplates): array {
            $expandedCats = [];
            foreach ($specs as $sp) {
                $trimmed = trim((string) $sp);
                if ($trimmed === '') {
                    continue;
                }
                $low = strtolower($trimmed);
                $expandedCats[] = $trimmed;
                if (isset($categoryAliases[$low])) {
                    foreach ($categoryAliases[$low] as $alias) {
                        $expandedCats[] = $alias;
                    }
                }
            }
            $expandedCats = array_values(array_unique($expandedCats));

            $steps = [];
            $seenSteps = [];
            $pushStep = static function (string $s) use (&$steps, &$seenSteps): void {
                $clean = trim($s);
                $low = strtolower($clean);
                if ($clean !== '' && !isset($seenSteps[$low])) {
                    $seenSteps[$low] = true;
                    $steps[] = $clean;
                }
            };

            $tasks = [];
            $seenTasks = [];
            $pushTask = static function (array $tpl) use (&$tasks, &$seenTasks): void {
                $title = trim((string) ($tpl['title'] ?? ''));
                $low = strtolower($title);
                if ($title === '' || isset($seenTasks[$low])) {
                    return;
                }
                $seenTasks[$low] = true;
                $tasks[] = [
                    'title'           => $title,
                    'description'     => (string) ($tpl['description'] ?? ''),
                    'priority'        => (string) ($tpl['default_priority'] ?? 'medium'),
                    'estimated_hours' => (float) ($tpl['default_estimated_hours'] ?? 2.0),
                    'steps'           => array_values($tpl['subtasks'] ?? []),
                ];
            };

            foreach ($expandedCats as $cat) {
                $catLow = strtolower($cat);
                foreach (($templatesByCat[$catLow] ?? []) as $tpl) {
                    $pushTask($tpl);
                    foreach (($tpl['subtasks'] ?? []) as $st) {
                        $pushStep((string) $st);
                    }
                }
                foreach ($presetSteps as $pKey => $pList) {
                    if (strtolower($pKey) === $catLow) {
                        foreach ($pList as $st) {
                            $pushStep($st);
                        }
                    }
                }
            }

            if (empty($tasks)) {
                foreach (array_slice($allTemplates, 0, 4) as $tpl) {
                    $pushTask($tpl);
                }
            }

            foreach ($presetSteps['General'] as $st) {
                $pushStep($st);
            }

            return [
                'steps' => array_slice($steps, 0, 8),
                'tasks' => array_slice($tasks, 0, 4),
            ];
        };

        $result = [
            '__default__' => $buildForSpecs([]),
        ];
        foreach ($cleanIds as $vid) {
            $result[$vid] = $buildForSpecs($specsByVa[$vid] ?? []);
        }

        return $result;
    }

    public static function getMentionCandidatesForTask(PDO $db, array $task, array $user): array
    {
        $clientId = (string) ($task['client_id'] ?? '');
        $actorId = (string) ($user['id'] ?? '');
        $role = (string) ($user['role'] ?? '');

        if ($clientId === '' || $actorId === '') {
            return [];
        }

        try {
            $cStmt = $db->prepare('SELECT focus_va_id, team_leader_id FROM clients WHERE id = :cid LIMIT 1');
            $cStmt->execute([':cid' => $clientId]);
            $client = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $focusVaId = (string) ($client['focus_va_id'] ?? '');
            $clientTlId = (string) ($client['team_leader_id'] ?? '');

            $candidates = [];
            if ($role === 'va') {
                $myTlId = (string) ($user['team_leader_id'] ?? '');
                $tlIds = array_values(array_unique(array_filter([$myTlId, $clientTlId], static fn(string $v): bool => $v !== '' && $v !== $actorId)));

                if (!empty($tlIds)) {
                    $ph = implode(',', array_fill(0, count($tlIds), '?'));
                    $tlStmt = $db->prepare("SELECT id, name, role, COALESCE(avatar_url, '') AS avatar_url FROM users WHERE id IN ({$ph}) AND is_active = TRUE");
                    $tlStmt->execute($tlIds);
                    foreach ($tlStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $candidates[(string) $r['id']] = $r;
                    }
                }

                $mateStmt = $db->prepare("
                    SELECT DISTINCT u.id, u.name, u.role, COALESCE(u.avatar_url, '') AS avatar_url
                    FROM users u
                    WHERE u.is_active = TRUE
                      AND u.role = 'va'
                      AND u.id <> :me
                      AND (
                        u.id = :focus_va
                        OR EXISTS (
                            SELECT 1 FROM tasks t2
                            WHERE t2.client_id = :cid AND t2.assigned_va_id = u.id
                        )
                      )
                    ORDER BY u.name ASC
                ");
                $mateStmt->execute([
                    ':me'       => $actorId,
                    ':focus_va' => $focusVaId,
                    ':cid'      => $clientId,
                ]);
                foreach ($mateStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $candidates[(string) $r['id']] = $r;
                }
            } elseif ($role === 'team_leader') {
                $vaStmt = $db->prepare("
                    SELECT DISTINCT u.id, u.name, u.role, COALESCE(u.avatar_url, '') AS avatar_url
                    FROM users u
                    WHERE u.is_active = TRUE
                      AND u.role = 'va'
                      AND u.team_leader_id = :tl
                      AND (
                        u.id = :focus_va
                        OR EXISTS (
                            SELECT 1 FROM tasks t2
                            WHERE t2.client_id = :cid AND t2.assigned_va_id = u.id
                        )
                      )
                    ORDER BY u.name ASC
                ");
                $vaStmt->execute([
                    ':tl'       => $actorId,
                    ':focus_va' => $focusVaId,
                    ':cid'      => $clientId,
                ]);
                foreach ($vaStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $candidates[(string) $r['id']] = $r;
                }
            } elseif ($role === 'admin') {
                $admStmt = $db->prepare("
                    SELECT DISTINCT u.id, u.name, u.role, COALESCE(u.avatar_url, '') AS avatar_url
                    FROM users u
                    WHERE u.is_active = TRUE
                      AND u.id <> :me
                      AND (
                        u.id = :focus_va
                        OR u.id = :client_tl
                        OR EXISTS (
                            SELECT 1 FROM tasks t2
                            WHERE t2.client_id = :cid AND t2.assigned_va_id = u.id
                        )
                      )
                    ORDER BY u.name ASC
                ");
                $admStmt->execute([
                    ':me'        => $actorId,
                    ':focus_va'  => $focusVaId,
                    ':client_tl' => $clientTlId,
                    ':cid'       => $clientId,
                ]);
                foreach ($admStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $candidates[(string) $r['id']] = $r;
                }
            }

            $list = array_values($candidates);
            usort($list, static function (array $a, array $b): int {
                $ra = $a['role'] === 'team_leader' ? 0 : 1;
                $rb = $b['role'] === 'team_leader' ? 0 : 1;
                return $ra !== $rb ? $ra <=> $rb : strcasecmp((string) $a['name'], (string) $b['name']);
            });
            return $list;
        } catch (Throwable) {
            return [];
        }
    }

    public function handle(string $method, string $uri): void
    {
        $user = AuthMiddleware::authenticateApi($this->db);
        TaskRoleMiddleware::requireOrchestrationRole($user, $this->db);
        CsrfMiddleware::validateApi($method);
        self::ensureTaskSchemaConstraints($this->db);

        $parts = explode('/', trim($uri, '/'));
        // /api/v1/tasks/{id}/{sub}/{subId}
        $taskId = $parts[3] ?? ($_GET['id'] ?? null);
        $sub = $parts[4] ?? null;
        $subId = $parts[5] ?? null;

        // /api/v1/tasks/templates/{templateId}
        if ($taskId === 'templates') {
            if ($method === 'GET' && !$sub) {
                $this->listTemplates();
                return;
            }
            if ($method === 'POST' && !$sub) {
                $this->createTemplate($user);
                return;
            }
            if ($method === 'DELETE' && $sub) {
                $this->deleteTemplate($sub, $user);
                return;
            }
        }

        if ($method === 'GET' && !$taskId) {
            $this->listTasks($user);
            return;
        }
        if ($method === 'GET' && $taskId && $sub === 'activity') {
            $this->getActivity($taskId, $user);
            return;
        }
        if ($method === 'POST' && $taskId && $sub === 'inject-template') {
            $this->injectTemplateIntoTask($taskId, $user);
            return;
        }
        if ($taskId && $sub === 'subtasks') {
            if ($method === 'GET' && !$subId) {
                $this->listSubtasks($taskId, $user);
                return;
            }
            if ($method === 'POST' && !$subId) {
                $this->addSubtask($taskId, $user);
                return;
            }
            if ($method === 'PATCH' && $subId) {
                $this->updateSubtask($taskId, $subId, $user);
                return;
            }
            if ($method === 'DELETE' && $subId) {
                $this->deleteSubtask($taskId, $subId, $user);
                return;
            }
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
        CacheService::invalidatePrefix($this->db, 'alerts:');
    }

    private function assertTaskAccess(string $taskId, array $user, string $action = 'access'): array
    {
        return TaskRoleMiddleware::authorizeTaskAccess($this->db, $taskId, $user, $action);
    }

    private function fetchUserAvatar(string $userId): string
    {
        try {
            $st = $this->db->prepare('SELECT avatar_url FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            return (string) ($st->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
    }

    private function notifyTaskParticipants(array $task, array $actor, string $kind, string $text, string $recordId): void
    {
        $actorId = (string) $actor['id'];
        $taskId = (string) $task['id'];
        $taskTitle = mb_substr((string) ($task['title'] ?? 'Task'), 0, 36, 'UTF-8');
        $href = '/tasks?open_task=' . rawurlencode($taskId) . '&tab=combined';

        $targets = [];
        if (!empty($task['assigned_va_id'])) {
            $targets[(string) $task['assigned_va_id']] = true;
            try {
                $tlSt = $this->db->prepare('SELECT team_leader_id FROM users WHERE id = :id LIMIT 1');
                $tlSt->execute([':id' => $task['assigned_va_id']]);
                $tlId = (string) ($tlSt->fetchColumn() ?: '');
                if ($tlId !== '') {
                    $targets[$tlId] = true;
                }
            } catch (Throwable) {
            }
        }
        if (!empty($task['created_by_id'])) {
            $targets[(string) $task['created_by_id']] = true;
        }

        // Resolve @mentions in text against active staff names
        if (str_contains($text, '@')) {
            try {
                $uRows = $this->db->query('SELECT id, name FROM users WHERE is_active = TRUE')->fetchAll(PDO::FETCH_ASSOC);
                $lowerText = mb_strtolower($text, 'UTF-8');
                foreach ($uRows as $ur) {
                    $full = mb_strtolower(trim((string) $ur['name']), 'UTF-8');
                    $first = trim(explode(' ', $full)[0] ?? '');
                    if (($full !== '' && str_contains($lowerText, '@' . $full))
                        || (strlen($first) >= 2 && preg_match('/@' . preg_quote($first, '/') . '\b/u', $lowerText))
                    ) {
                        $targets[(string) $ur['id']] = true;
                    }
                }
            } catch (Throwable) {
            }
        }

        unset($targets[$actorId]);

        $title = $kind === 'note'
            ? "{$actor['name']} added a note on \"{$taskTitle}\""
            : "{$actor['name']} commented on \"{$taskTitle}\"";
        $sub = mb_substr($text, 0, 90, 'UTF-8');
        $cat = $kind === 'note' ? 'task' : 'comment';

        foreach (array_keys($targets) as $targetId) {
            NotificationApiController::notifyUser($this->db, (string) $targetId, $cat, $title, $sub, $href, $recordId);
        }
    }

    private function getActivity(string $taskId, array $user): void
    {
        $task = $this->assertTaskAccess($taskId, $user);

        $nStmt = $this->db->prepare("
            SELECT n.id, n.task_id, n.author_id, n.author_name, n.author_role, n.is_instruction, n.content, n.created_at,
                   COALESCE(u.avatar_url, '') AS avatar_url
            FROM task_notes n
            LEFT JOIN users u ON n.author_id = u.id
            WHERE n.task_id = :tid
            ORDER BY n.created_at ASC
        ");
        $nStmt->execute([':tid' => $taskId]);
        $notes = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        $cStmt = $this->db->prepare("
            SELECT c.id, c.task_id, c.author_id, c.author_name, c.author_role, c.message, c.created_at,
                   COALESCE(u.avatar_url, '') AS avatar_url
            FROM task_comments c
            LEFT JOIN users u ON c.author_id = u.id
            WHERE c.task_id = :tid
            ORDER BY c.created_at ASC
        ");
        $cStmt->execute([':tid' => $taskId]);
        $comments = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $timeline = [];
        foreach ($notes as $n) {
            $timeline[] = [
                'kind'           => 'note',
                'id'             => $n['id'],
                'task_id'        => $n['task_id'],
                'author_id'      => $n['author_id'],
                'author_name'    => $n['author_name'],
                'author_role'    => $n['author_role'],
                'avatar_url'     => $n['avatar_url'],
                'is_instruction' => (bool) $n['is_instruction'],
                'text'           => $n['content'],
                'created_at'     => $n['created_at'],
            ];
        }

        $authorSeen = [];
        $commentAuthors = [];
        foreach ($comments as $c) {
            $aid = (string) $c['author_id'];
            if (!isset($authorSeen[$aid])) {
                $authorSeen[$aid] = true;
                $commentAuthors[] = [
                    'id'         => $aid,
                    'name'       => (string) $c['author_name'],
                    'avatar_url' => (string) $c['avatar_url'],
                ];
            }
            $timeline[] = [
                'kind'           => 'comment',
                'id'             => $c['id'],
                'task_id'        => $c['task_id'],
                'author_id'      => $c['author_id'],
                'author_name'    => $c['author_name'],
                'author_role'    => $c['author_role'],
                'avatar_url'     => $c['avatar_url'],
                'is_instruction' => false,
                'text'           => $c['message'],
                'created_at'     => $c['created_at'],
            ];
        }

        usort($timeline, static fn(array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        $mentionCandidates = self::getMentionCandidatesForTask($this->db, $task, $user);
        $subtasks = $this->fetchSubtasksForTask($taskId);

        echo json_encode([
            'success'            => true,
            'task_id'            => $taskId,
            'notes'              => $notes,
            'comments'           => $comments,
            'timeline'           => $timeline,
            'comment_authors'    => $commentAuthors,
            'mention_candidates' => $mentionCandidates,
            'subtasks'           => $subtasks,
        ]);
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
        $priority = in_array($input['priority'] ?? '', self::VALID_PRIORITIES, true) ? $input['priority'] : 'medium';
        $status = in_array($input['status'] ?? '', TaskRoleMiddleware::VALID_STATUSES, true) ? $input['status'] : 'todo';
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

        $rawSubtasks = $input['subtasks'] ?? [];
        if (is_string($rawSubtasks)) {
            $decodedSubs = json_decode($rawSubtasks, true);
            $rawSubtasks = is_array($decodedSubs) ? $decodedSubs : array_filter(array_map('trim', explode("\n", $rawSubtasks)));
        }
        $createdSubtasks = [];
        if (!empty($rawSubtasks) && is_array($rawSubtasks)) {
            $stIns = $this->db->prepare("
                INSERT INTO task_subtasks (id, task_id, title, is_completed, position, created_by_id)
                VALUES (:id, :tid, :title, FALSE, :pos, :cid)
            ");
            $pos = 0;
            foreach ($rawSubtasks as $rawSubTitle) {
                $subTitle = SecurityHelper::sanitizePlainText((string) $rawSubTitle, 255);
                if ($subTitle === '') {
                    continue;
                }
                $subId = 'sub-' . bin2hex(random_bytes(5));
                $stIns->execute([
                    ':id'    => $subId,
                    ':tid'   => $id,
                    ':title' => $subTitle,
                    ':pos'   => $pos,
                    ':cid'   => $user['id'],
                ]);
                $createdSubtasks[] = [
                    'id'           => $subId,
                    'task_id'      => $id,
                    'title'        => $subTitle,
                    'is_completed' => false,
                    'position'     => $pos,
                ];
                $pos++;
            }
        }

        if ($assignedVaId !== '' && $assignedVaId !== $user['id']) {
            NotificationApiController::notifyUser(
                $this->db,
                $assignedVaId,
                'task',
                "New task assigned: {$title}",
                "Due {$deadline} · Assigned by {$user['name']}",
                '/tasks?open_task=' . rawurlencode($id) . '&tab=combined',
                $id
            );
        }

        $googleSync = GoogleCalendarService::syncTask($this->db, $id);

        $this->invalidate();
        AuditLogger::log('TASK_CREATED', 'success', "Created task {$id} ({$title})", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode([
            'success'     => true,
            'id'          => $id,
            'subtasks'    => $createdSubtasks,
            'google_sync' => $googleSync,
        ]);
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

        $googleSync = GoogleCalendarService::syncTask($this->db, $taskId);

        $this->invalidate();
        AuditLogger::log('TASK_STATUS_CHANGED', 'success', "Moved task {$taskId} to {$status}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $taskId, 'status' => $status, 'google_sync' => $googleSync]);
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
                $val = is_string($input[$col])
                    ? SecurityHelper::sanitizePlainText($input[$col])
                    : $input[$col];
                if ($col === 'status' && !in_array($val, TaskRoleMiddleware::VALID_STATUSES, true)) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Invalid task status']);
                    return;
                }
                if ($col === 'priority' && !in_array($val, self::VALID_PRIORITIES, true)) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Invalid task priority']);
                    return;
                }
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $val;
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

        $googleSync = GoogleCalendarService::syncTask($this->db, $taskId);

        $this->invalidate();
        echo json_encode(['success' => true, 'id' => $taskId, 'google_sync' => $googleSync]);
    }

    private function deleteTask(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user);
        GoogleCalendarService::deleteEntityEvents($this->db, 'task', $taskId);
        $stmt = $this->db->prepare('DELETE FROM tasks WHERE id = :id');
        $stmt->execute([':id' => $taskId]);

        $this->invalidate();
        AuditLogger::log('TASK_DELETED', 'warning', "Deleted task {$taskId}", $user['id'], $user['role'], $this->db);
        echo json_encode(['success' => true, 'id' => $taskId]);
    }

    private function addNote(string $taskId, array $user): void
    {
        $task = $this->assertTaskAccess($taskId, $user);
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $content = SecurityHelper::sanitizePlainText($input['content'] ?? '', 2000);
        if ($content === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Note content cannot be empty']);
            return;
        }

        $id = 'note-' . bin2hex(random_bytes(5));
        $isInstruction = $user['role'] !== 'va';
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
            ':instr'   => $isInstruction ? 'true' : 'false',
            ':content' => $content,
        ]);

        $this->notifyTaskParticipants($task, $user, 'note', $content, $id);
        $this->invalidate();
        $avatar = $this->fetchUserAvatar((string) $user['id']);

        echo json_encode([
            'success' => true,
            'id'      => $id,
            'item'    => [
                'id'             => $id,
                'task_id'        => $taskId,
                'author_id'      => $user['id'],
                'author_name'    => $user['name'],
                'author_role'    => $user['role'],
                'avatar_url'     => $avatar,
                'is_instruction' => $isInstruction,
                'content'        => $content,
                'created_at'     => gmdate('Y-m-d H:i:sP'),
            ],
        ]);
    }

    private function addComment(string $taskId, array $user): void
    {
        $task = $this->assertTaskAccess($taskId, $user);
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

        $this->notifyTaskParticipants($task, $user, 'comment', $message, $id);
        $this->invalidate();
        $avatar = $this->fetchUserAvatar((string) $user['id']);

        echo json_encode([
            'success' => true,
            'id'      => $id,
            'item'    => [
                'id'          => $id,
                'task_id'     => $taskId,
                'author_id'   => $user['id'],
                'author_name' => $user['name'],
                'author_role' => $user['role'],
                'avatar_url'  => $avatar,
                'message'     => $message,
                'created_at'  => gmdate('Y-m-d H:i:sP'),
            ],
        ]);
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

    private function fetchSubtasksForTask(string $taskId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, task_id, title, is_completed, position, created_by_id, completed_at, created_at, updated_at
                FROM task_subtasks
                WHERE task_id = :tid
                ORDER BY position ASC, created_at ASC
            ");
            $stmt->execute([':tid' => $taskId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['is_completed'] = filter_var($r['is_completed'], FILTER_VALIDATE_BOOLEAN);
                $r['position'] = (int) ($r['position'] ?? 0);
            }
            unset($r);
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private function listSubtasks(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user);
        $subtasks = $this->fetchSubtasksForTask($taskId);
        echo json_encode([
            'success'  => true,
            'task_id'  => $taskId,
            'subtasks' => $subtasks,
        ]);
    }

    private function addSubtask(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user, 'update');
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText($input['title'] ?? '', 255);
        if ($title === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Sub-task title cannot be empty']);
            return;
        }

        $posStmt = $this->db->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM task_subtasks WHERE task_id = :tid');
        $posStmt->execute([':tid' => $taskId]);
        $nextPos = (int) $posStmt->fetchColumn();

        $id = 'sub-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO task_subtasks (id, task_id, title, is_completed, position, created_by_id)
            VALUES (:id, :tid, :title, FALSE, :pos, :cid)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':tid'   => $taskId,
            ':title' => $title,
            ':pos'   => $nextPos,
            ':cid'   => $user['id'],
        ]);

        $this->invalidate();
        $subtasks = $this->fetchSubtasksForTask($taskId);
        AuditLogger::log('SUBTASK_ADDED', 'success', "Added sub-task {$id} to task {$taskId}", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode([
            'success'  => true,
            'id'       => $id,
            'subtasks' => $subtasks,
        ]);
    }

    private function updateSubtask(string $taskId, string $subtaskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user, 'update');
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];

        $chk = $this->db->prepare('SELECT id, title, is_completed FROM task_subtasks WHERE id = :sid AND task_id = :tid LIMIT 1');
        $chk->execute([':sid' => $subtaskId, ':tid' => $taskId]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Sub-task not found']);
            return;
        }

        $sets = [];
        $params = [':sid' => $subtaskId, ':tid' => $taskId];

        if (array_key_exists('is_completed', $input)) {
            $done = filter_var($input['is_completed'], FILTER_VALIDATE_BOOLEAN);
            $sets[] = 'is_completed = :done';
            $sets[] = $done ? 'completed_at = NOW()' : 'completed_at = NULL';
            $params[':done'] = $done ? 'true' : 'false';
        }

        if (array_key_exists('title', $input)) {
            $title = SecurityHelper::sanitizePlainText((string) $input['title'], 255);
            if ($title === '') {
                http_response_code(422);
                echo json_encode(['error' => 'Sub-task title cannot be empty']);
                return;
            }
            $sets[] = 'title = :title';
            $params[':title'] = $title;
        }

        if (empty($sets)) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid sub-task changes provided']);
            return;
        }

        $upd = $this->db->prepare('UPDATE task_subtasks SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :sid AND task_id = :tid');
        $upd->execute($params);

        $this->invalidate();
        $subtasks = $this->fetchSubtasksForTask($taskId);
        echo json_encode([
            'success'  => true,
            'id'       => $subtaskId,
            'subtasks' => $subtasks,
        ]);
    }

    private function deleteSubtask(string $taskId, string $subtaskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user, 'update');
        $stmt = $this->db->prepare('DELETE FROM task_subtasks WHERE id = :sid AND task_id = :tid');
        $stmt->execute([':sid' => $subtaskId, ':tid' => $taskId]);

        $this->invalidate();
        $subtasks = $this->fetchSubtasksForTask($taskId);
        AuditLogger::log('SUBTASK_DELETED', 'warning', "Deleted sub-task {$subtaskId} from task {$taskId}", $user['id'], $user['role'], $this->db);
        echo json_encode([
            'success'  => true,
            'id'       => $subtaskId,
            'subtasks' => $subtasks,
        ]);
    }

    private function listTemplates(): void
    {
        $cat = isset($_GET['category']) ? SecurityHelper::sanitizePlainText((string) $_GET['category'], 80) : null;
        echo json_encode([
            'success'   => true,
            'templates' => self::listTaskTemplates($this->db, $cat),
        ]);
    }

    private function createTemplate(array $user): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $title = SecurityHelper::sanitizePlainText((string) ($input['title'] ?? ''), 160);
        $category = SecurityHelper::sanitizePlainText((string) ($input['specialty_category'] ?? ''), 80);
        $description = SecurityHelper::sanitizePlainText((string) ($input['description'] ?? ''), 1000);
        $priority = strtolower(SecurityHelper::sanitizePlainText((string) ($input['default_priority'] ?? 'medium'), 16));
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            $priority = 'medium';
        }
        $estHours = max(0.5, min(100.0, (float) ($input['default_estimated_hours'] ?? 2.0)));

        $rawSteps = $input['subtasks'] ?? [];
        if (is_string($rawSteps)) {
            $rawSteps = preg_split('/\r?\n/', $rawSteps) ?: [];
        }
        $steps = [];
        $seen = [];
        if (is_array($rawSteps)) {
            foreach ($rawSteps as $st) {
                $clean = SecurityHelper::sanitizePlainText((string) $st, 255);
                $low = mb_strtolower($clean, 'UTF-8');
                if ($clean !== '' && !isset($seen[$low])) {
                    $seen[$low] = true;
                    $steps[] = $clean;
                }
            }
        }

        if ($title === '' || $category === '' || empty($steps)) {
            http_response_code(422);
            echo json_encode(['error' => 'Template title, VA specialization category, and at least one checklist step are required']);
            return;
        }

        $id = 'tpl-' . bin2hex(random_bytes(5));
        $stmt = $this->db->prepare("
            INSERT INTO task_templates (id, title, specialty_category, description, default_priority, default_estimated_hours, subtasks_json, usage_count, is_system, created_by_id)
            VALUES (:id, :title, :cat, :desc, :prio, :hrs, :steps, 0, FALSE, :cid)
        ");
        $stmt->execute([
            ':id'    => $id,
            ':title' => $title,
            ':cat'   => $category,
            ':desc'  => $description,
            ':prio'  => $priority,
            ':hrs'   => $estHours,
            ':steps' => json_encode($steps, JSON_UNESCAPED_UNICODE),
            ':cid'   => $user['id'],
        ]);

        $this->invalidate();
        AuditLogger::log('TASK_TEMPLATE_CREATED', 'success', "Created reusable task template '{$title}' ({$category})", $user['id'], $user['role'], $this->db);
        http_response_code(201);
        echo json_encode([
            'success'   => true,
            'id'        => $id,
            'templates' => self::listTaskTemplates($this->db),
        ]);
    }

    private function deleteTemplate(string $templateId, array $user): void
    {
        $tid = SecurityHelper::sanitizePlainText($templateId, 64);
        $chk = $this->db->prepare('SELECT id, title, created_by_id, is_system FROM task_templates WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $tid]);
        $tpl = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            http_response_code(404);
            echo json_encode(['error' => 'Task template not found']);
            return;
        }

        $isOwner = (string) ($tpl['created_by_id'] ?? '') === (string) $user['id'];
        if (!in_array($user['role'], ['admin', 'team_leader'], true) && !$isOwner) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions to delete this template']);
            return;
        }

        $del = $this->db->prepare('DELETE FROM task_templates WHERE id = :id');
        $del->execute([':id' => $tid]);

        $this->invalidate();
        AuditLogger::log('TASK_TEMPLATE_DELETED', 'warning', "Deleted task template {$tid}", $user['id'], $user['role'], $this->db);
        echo json_encode([
            'success'   => true,
            'id'        => $tid,
            'templates' => self::listTaskTemplates($this->db),
        ]);
    }

    private function injectTemplateIntoTask(string $taskId, array $user): void
    {
        $this->assertTaskAccess($taskId, $user, 'update');
        $input = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $templateId = SecurityHelper::sanitizePlainText((string) ($input['template_id'] ?? ''), 64);
        if ($templateId === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Template ID is required']);
            return;
        }

        $tStmt = $this->db->prepare('SELECT id, title, specialty_category, subtasks_json FROM task_templates WHERE id = :id LIMIT 1');
        $tStmt->execute([':id' => $templateId]);
        $tpl = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) {
            http_response_code(404);
            echo json_encode(['error' => 'Task template not found']);
            return;
        }

        $tplSteps = json_decode((string) ($tpl['subtasks_json'] ?? '[]'), true);
        if (!is_array($tplSteps) || empty($tplSteps)) {
            http_response_code(422);
            echo json_encode(['error' => 'Selected template contains no checklist steps']);
            return;
        }

        $existingSubtasks = $this->fetchSubtasksForTask($taskId);
        $existingMap = [];
        $maxPos = -1;
        foreach ($existingSubtasks as $es) {
            $existingMap[mb_strtolower(trim((string) $es['title']), 'UTF-8')] = true;
            $maxPos = max($maxPos, (int) ($es['position'] ?? 0));
        }

        $insStmt = $this->db->prepare("
            INSERT INTO task_subtasks (id, task_id, title, is_completed, position, created_by_id)
            VALUES (:id, :tid, :title, FALSE, :pos, :cid)
        ");
        $addedCount = 0;
        foreach ($tplSteps as $step) {
            $clean = SecurityHelper::sanitizePlainText((string) $step, 255);
            $low = mb_strtolower($clean, 'UTF-8');
            if ($clean === '' || isset($existingMap[$low])) {
                continue;
            }
            $existingMap[$low] = true;
            $maxPos++;
            $insStmt->execute([
                ':id'    => 'sub-' . bin2hex(random_bytes(5)),
                ':tid'   => $taskId,
                ':title' => $clean,
                ':pos'   => $maxPos,
                ':cid'   => $user['id'],
            ]);
            $addedCount++;
        }

        $this->db->prepare('UPDATE task_templates SET usage_count = usage_count + 1, updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $templateId]);

        $this->invalidate();
        $subtasks = $this->fetchSubtasksForTask($taskId);
        AuditLogger::log(
            'TASK_TEMPLATE_INJECTED',
            'success',
            "Injected template '{$tpl['title']}' ({$addedCount} new steps) into task {$taskId}",
            $user['id'],
            $user['role'],
            $this->db
        );

        echo json_encode([
            'success'       => true,
            'task_id'       => $taskId,
            'template_id'   => $templateId,
            'template_name' => (string) $tpl['title'],
            'added_count'   => $addedCount,
            'subtasks'      => $subtasks,
        ]);
    }
}
