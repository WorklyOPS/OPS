<?php
require_once __DIR__ . '/../partials/icons.php';

$columns = [
    'backlog'     => 'Backlog',
    'todo'        => 'To Do',
    'in_progress' => 'In Progress',
    'in_review'   => 'In Review',
    'completed'   => 'Completed',
];

$tasksByCol = array_fill_keys(array_keys($columns), []);
$tasksMap = [];
$swimlanes = [];

foreach (($data['clients'] ?? []) as $c) {
    $cid = (string) $c['id'];
    $swimlanes[$cid] = [
        'id'               => $cid,
        'agency_name'      => (string) $c['agency_name'],
        'contact_name'     => (string) ($c['name'] ?? ''),
        'contract_status'  => (string) ($c['contract_status'] ?? 'active'),
        'contracted_hours' => (float) ($c['contracted_hours_per_month'] ?? 0),
        'consumed_hours'   => (float) ($c['consumed_hours_month'] ?? 0),
        'total_tasks'      => 0,
        'cols'             => array_fill_keys(array_keys($columns), []),
    ];
}

foreach ($data['tasks'] as $t) {
    $st = isset($tasksByCol[$t['status']]) ? $t['status'] : 'todo';
    $tasksByCol[$st][] = $t;
    $tasksMap[$t['id']] = $t;

    $cid = (string) ($t['client_id'] ?? 'unassigned');
    if (!isset($swimlanes[$cid])) {
        $swimlanes[$cid] = [
            'id'               => $cid,
            'agency_name'      => (string) ($t['client_name'] ?? 'Client Scope'),
            'contact_name'     => (string) ($t['client_contact'] ?? ''),
            'contract_status'  => (string) ($t['client_status'] ?? 'active'),
            'contracted_hours' => (float) ($t['contracted_hours_per_month'] ?? 0),
            'consumed_hours'   => (float) ($t['consumed_hours_month'] ?? 0),
            'total_tasks'      => 0,
            'cols'             => array_fill_keys(array_keys($columns), []),
        ];
    }
    $swimlanes[$cid]['cols'][$st][] = $t;
    $swimlanes[$cid]['total_tasks']++;
}

$totalCount = max(1, count($data['tasks']));
$notesMap = $data['notes_by_task'] ?? [];
$commentsMap = $data['comments_by_task'] ?? [];
$extraHoursMap = $data['extra_hours_by_task'] ?? [];
$dueToday = (int) ($data['due_today_count'] ?? 0);
$overdue = (int) ($data['overdue_count'] ?? 0);
$todayYmd = date('Y-m-d');

$renderTaskCard = static function (array $task) use ($notesMap, $commentsMap, $todayYmd): string {
    $pBadge = match ($task['priority']) {
        'high'  => 'badge-danger',
        'low'   => 'badge-neutral',
        default => 'badge-warn',
    };
    $noteCount = count($notesMap[$task['id']] ?? []);
    $commentCount = count($commentsMap[$task['id']] ?? []);
    $extraHrs = (float) ($task['extra_hours_logged'] ?? 0);
    $vaInitials = strtoupper(substr((string) ($task['va_name'] ?? 'VA'), 0, 2));
    $isOverdueCard = ($task['status'] !== 'completed' && (string) $task['deadline'] < $todayYmd);

    ob_start();
    ?>
    <article class="task-card"
             draggable="true"
             data-task-id="<?= SecurityHelper::e($task['id']) ?>"
             data-client-id="<?= SecurityHelper::e($task['client_id']) ?>"
             data-search="<?= SecurityHelper::e($task['title'] . ' ' . $task['client_name'] . ' ' . $task['va_name']) ?>">
      <div class="task-card-top">
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <span class="badge <?= $pBadge ?>"><?= SecurityHelper::e(ucfirst($task['priority'])) ?></span>
          <?php if ($isOverdueCard): ?>
            <span class="badge badge-danger">Overdue</span>
          <?php endif; ?>
        </div>
        <button type="button" class="icon-chip-btn task-menu-btn" data-task-id="<?= SecurityHelper::e($task['id']) ?>" aria-label="Task context menu" title="Quick Actions">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
        </button>
      </div>

      <div class="task-title" data-open-task-edit="<?= SecurityHelper::e($task['id']) ?>">
        <?= SecurityHelper::e($task['title']) ?>
      </div>

      <div class="meta-inline" style="justify-content:space-between;margin-top:8px;">
        <strong style="color:var(--brand-secondary);font-size:12px;"><?= SecurityHelper::e($task['client_name']) ?></strong>
        <span class="mono" style="font-size:11px;color:<?= $isOverdueCard ? 'var(--danger)' : 'var(--muted)' ?>;"><?= SecurityHelper::e($task['deadline']) ?></span>
      </div>

      <div class="task-card-actions">
        <div style="display:flex;align-items:center;gap:4px;">
          <button type="button" class="icon-chip-btn" data-open-task-collab="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="notes" title="VA Notes">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
            <span><?= $noteCount ?></span>
          </button>
          <button type="button" class="icon-chip-btn" data-open-task-collab="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="comments" title="Comments">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span><?= $commentCount ?></span>
          </button>
          <button type="button" class="icon-chip-btn" data-open-task-overtime="<?= SecurityHelper::e($task['id']) ?>" title="Overtime Hours">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
            <span><?= $extraHrs > 0 ? '+' . number_format($extraHrs, 1) . 'h' : '0h' ?></span>
          </button>
        </div>

        <span style="display:inline-flex;align-items:center;" title="<?= SecurityHelper::e($task['va_name']) ?>">
          <?php if (!empty($task['va_avatar'])): ?>
            <img src="<?= SecurityHelper::e($task['va_avatar']) ?>" alt="<?= SecurityHelper::e($task['va_name']) ?>" class="avatar avatar-sm">
          <?php else: ?>
            <span class="avatar avatar-sm"><?= SecurityHelper::e($vaInitials) ?></span>
          <?php endif; ?>
        </span>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
};
?>

<div class="page-header">
  <div>
    <h1>Weekly Task Organizer</h1>
    <p class="muted">Drag cards across stages, right-click for instant quick actions, click a card title to edit details, or open Notes / Comments / Overtime separately.</p>
  </div>
  <?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-task">+ New Deliverable</button>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:18px;padding:14px 18px;">
  <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;<?= $role === 'team_leader' ? 'margin-bottom:12px;' : '' ?>">
    <div>
      <h3 style="font-size:15px;">Daily Priority Focus</h3>
      <div class="meta-inline" style="margin-top:3px;">
        <span>Total Period Deliverables: <strong class="tabular-nums" style="color:var(--ink);"><?= count($data['tasks']) ?></strong></span>
        <span>·</span>
        <span style="color:<?= $dueToday > 0 ? 'var(--brand-secondary)' : 'var(--muted)' ?>;">Due Today: <strong class="tabular-nums"><?= $dueToday ?></strong></span>
        <span>·</span>
        <span style="color:<?= $overdue > 0 ? 'var(--danger)' : 'var(--success)' ?>;">Overdue Alerts: <strong class="tabular-nums"><?= $overdue ?></strong></span>
        <span>·</span>
        <span>Client Swimlanes: <strong class="tabular-nums" style="color:var(--ink);"><?= count($swimlanes) ?></strong></span>
      </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <span class="badge badge-neutral">Backlog: <?= (int) $data['status_counts']['backlog'] ?></span>
      <span class="badge badge-neutral">To Do: <?= (int) $data['status_counts']['todo'] ?></span>
      <span class="badge badge-info">In Progress: <?= (int) $data['status_counts']['in_progress'] ?></span>
      <span class="badge badge-warn">In Review: <?= (int) $data['status_counts']['in_review'] ?></span>
      <span class="badge badge-success">Completed: <?= (int) $data['status_counts']['completed'] ?></span>
    </div>
  </div>
  <?php if ($role === 'team_leader'): ?>
    <div class="progress-track" style="display:flex;height:10px;">
      <div style="width:<?= round(($data['status_counts']['completed'] / $totalCount) * 100, 1) ?>%;background:var(--success);"></div>
      <div style="width:<?= round(($data['status_counts']['in_review'] / $totalCount) * 100, 1) ?>%;background:var(--warn);"></div>
      <div style="width:<?= round(($data['status_counts']['in_progress'] / $totalCount) * 100, 1) ?>%;background:var(--brand);"></div>
      <div style="width:<?= round(($data['status_counts']['todo'] / $totalCount) * 100, 1) ?>%;background:#C4B5FD;"></div>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:18px;padding:14px;">
  <form method="GET" action="/tasks" class="kanban-toolbar" style="margin-bottom:0;">
    <div class="kanban-filters">
      <div>
        <select name="month" class="select" data-auto-submit>
          <?php foreach ($data['months'] as $m): ?>
            <option value="<?= SecurityHelper::e($m['value']) ?>" <?= $data['selected_month'] === $m['value'] ? 'selected' : '' ?>>
              <?= SecurityHelper::e($m['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <select name="week" class="select" data-auto-submit>
          <option value="all" <?= $data['selected_week'] === 'all' ? 'selected' : '' ?>>All Weeks in Month</option>
          <?php foreach ($data['weeks'] as $w): ?>
            <option value="<?= SecurityHelper::e($w['key']) ?>" <?= $data['selected_week'] === $w['key'] ? 'selected' : '' ?>>
              <?= SecurityHelper::e($w['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($role !== 'va' && !empty($data['vas'])): ?>
        <div>
          <select name="va" class="select" data-auto-submit>
            <option value="all" <?= $data['selected_va_filter'] === 'all' ? 'selected' : '' ?>>All Assigned Staff</option>
            <?php foreach ($data['vas'] as $v): ?>
              <option value="<?= SecurityHelper::e($v['id']) ?>" <?= $data['selected_va_filter'] === $v['id'] ? 'selected' : '' ?>>
                <?= SecurityHelper::e($v['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="tab-strip" style="margin-bottom:0;">
        <button type="button" class="tab-btn active" data-tab-group="kanban-layout" data-tab-btn="swimlanes">Client Swimlanes</button>
        <button type="button" class="tab-btn" data-tab-group="kanban-layout" data-tab-btn="board">5-Column Board</button>
      </div>
    </div>

    <div style="min-width:240px;flex:0 1 300px;">
      <input id="kanban-search" type="search" class="input" placeholder="Search tasks by title, client, or VA...">
    </div>
  </form>
</div>

<!-- View 1: Horizontal Client / Project Swimlanes -->
<div data-tab-group="kanban-layout" data-tab-panel="swimlanes">
  <?php if (empty($swimlanes)): ?>
    <div class="card">
      <p class="muted">No client projects or tasks found in this date range.</p>
    </div>
  <?php else: ?>
    <section id="workly-kanban-swimlanes" class="swimlane-stack kanban-surface">
      <?php foreach ($swimlanes as $lane): ?>
        <?php
          $statusBadge = $lane['contract_status'] === 'active'
              ? 'badge-success'
              : ($lane['contract_status'] === 'pending' ? 'badge-warn' : 'badge-neutral');
        ?>
        <section class="swimlane-section" data-client-id="<?= SecurityHelper::e($lane['id']) ?>">
          <header class="swimlane-header">
            <button type="button" class="swimlane-toggle" data-toggle-swimlane aria-expanded="true">
              <svg class="swimlane-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4">
                <path d="m6 9 6 6 6-6"/>
              </svg>
              <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <strong style="font-size:14.5px;"><?= SecurityHelper::e($lane['agency_name']) ?></strong>
                  <span class="badge <?= $statusBadge ?>"><?= SecurityHelper::e(ucfirst($lane['contract_status'])) ?></span>
                  <span class="badge badge-brand swimlane-count tabular-nums"><?= (int) $lane['total_tasks'] ?> tasks</span>
                </div>
                <div class="meta-inline" style="margin-top:2px;">
                  <?php if ($lane['contact_name'] !== ''): ?>
                    <span>Contact: <?= SecurityHelper::e($lane['contact_name']) ?></span>
                  <?php endif; ?>
                  <?php if ($lane['contracted_hours'] > 0): ?>
                    <span>·</span>
                    <span>Capacity: <strong class="tabular-nums"><?= number_format($lane['consumed_hours'], 1) ?>h</strong> / <?= number_format($lane['contracted_hours'], 0) ?>h</span>
                  <?php endif; ?>
                </div>
              </div>
            </button>

            <?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
              <button type="button" class="btn btn-secondary btn-sm" data-create-for-client="<?= SecurityHelper::e($lane['id']) ?>">+ Deliverable</button>
            <?php endif; ?>
          </header>

          <div class="swimlane-body">
            <div class="swimlane-grid">
              <?php foreach ($columns as $colKey => $colTitle): ?>
                <div class="swimlane-col" data-status="<?= SecurityHelper::e($colKey) ?>" data-client-id="<?= SecurityHelper::e($lane['id']) ?>">
                  <div class="swimlane-col-head">
                    <span><?= SecurityHelper::e($colTitle) ?></span>
                    <span class="badge badge-neutral col-count tabular-nums"><?= count($lane['cols'][$colKey]) ?></span>
                  </div>
                  <div class="swimlane-col-cards">
                    <?php foreach ($lane['cols'][$colKey] as $task): ?>
                      <?= $renderTaskCard($task) ?>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</div>

<!-- View 2: Unified 5-Column Board -->
<div class="is-hidden" data-tab-group="kanban-layout" data-tab-panel="board">
  <section id="workly-kanban" class="kanban-board kanban-surface">
    <?php foreach ($columns as $colKey => $colTitle): ?>
      <div class="kanban-col" data-status="<?= SecurityHelper::e($colKey) ?>">
        <div class="kanban-col-head">
          <span class="kanban-col-title"><?= SecurityHelper::e($colTitle) ?></span>
          <span class="badge badge-neutral col-count tabular-nums"><?= count($tasksByCol[$colKey]) ?></span>
        </div>

        <div class="kanban-col-cards">
          <?php foreach ($tasksByCol[$colKey] as $task): ?>
            <?= $renderTaskCard($task) ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<!-- Streamlined Floating Right-Click Context Menu with Minimalist Icons -->
<div id="kanban-context-menu" class="context-menu is-hidden" role="menu">
  <button type="button" data-cm-action="open-edit">
    <?= workly_icon_svg('Edit', 14) ?> <span>Edit Details</span>
  </button>

  <div class="context-menu-divider"></div>
  <div class="context-menu-label">Move Stage</div>
  <button type="button" data-cm-action="status" data-status="backlog"><?= workly_icon_svg('ArrowRight', 13) ?> <span>Backlog</span></button>
  <button type="button" data-cm-action="status" data-status="todo"><?= workly_icon_svg('ArrowRight', 13) ?> <span>To Do</span></button>
  <button type="button" data-cm-action="status" data-status="in_progress"><?= workly_icon_svg('ArrowRight', 13) ?> <span>In Progress</span></button>
  <button type="button" data-cm-action="status" data-status="in_review"><?= workly_icon_svg('ArrowRight', 13) ?> <span>In Review</span></button>
  <button type="button" data-cm-action="status" data-status="completed"><?= workly_icon_svg('Check', 13) ?> <span>Completed</span></button>

  <div class="context-menu-divider"></div>
  <div class="context-menu-label">Priority</div>
  <button type="button" data-cm-action="priority" data-priority="high"><?= workly_icon_svg('Flag', 13) ?> <span>High Priority</span></button>
  <button type="button" data-cm-action="priority" data-priority="medium"><?= workly_icon_svg('Flag', 13) ?> <span>Medium Priority</span></button>
  <button type="button" data-cm-action="priority" data-priority="low"><?= workly_icon_svg('Flag', 13) ?> <span>Low Priority</span></button>

  <div class="context-menu-divider"></div>
  <button type="button" class="danger" data-cm-action="delete">
    <?= workly_icon_svg('Trash', 14) ?> <span>Delete Deliverable</span>
  </button>
</div>

<!-- Embedded Non-Executable JSON Payload for Drawer/Modal Inspection -->
<script type="application/json" id="task-details-json"><?= json_encode([
    'tasks'       => $tasksMap,
    'notes'       => $notesMap,
    'comments'    => $commentsMap,
    'extra_hours' => $extraHoursMap,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Right-Side Drawer: Create Deliverable -->
<div id="drawer-create-task" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="New Deliverable">
    <div class="drawer-head">
      <div>
        <h3>New Deliverable</h3>
        <div class="muted" style="font-size:12px;">Assign task stage, client scope, deadline &amp; initial execution note.</div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <?php if (empty($data['clients'])): ?>
        <div class="notice notice-warn">
          No active clients assigned yet. Ask a Team Leader or Administrator to assign a Client account before creating deliverables.
        </div>
      <?php else: ?>
        <form data-api-form="/api/v1/tasks" data-api-method="POST">
          <div class="form-group">
            <label class="form-label">Deliverable Title</label>
            <input class="input" name="title" required placeholder="e.g. Weekly CRM Pipeline Reconciliation">
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Client Account</label>
              <select class="select" name="client_id" required>
                <?php foreach ($data['clients'] as $c): ?>
                  <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if ($role !== 'va' && !empty($data['vas'])): ?>
              <div class="form-group">
                <label class="form-label">Assign To VA</label>
                <select class="select" name="assigned_va_id" required>
                  <?php foreach ($data['vas'] as $v): ?>
                    <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="assigned_va_id" value="<?= SecurityHelper::e($user['id']) ?>">
            <?php endif; ?>
          </div>

          <div class="grid-3">
            <div class="form-group">
              <label class="form-label">Priority</label>
              <select class="select" name="priority">
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Deadline</label>
              <input class="input" type="date" name="deadline" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Estimated Hours</label>
              <input class="input" type="number" step="0.5" min="0.5" name="estimated_hours" value="4">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Description &amp; Acceptance Criteria</label>
            <textarea class="textarea" name="description" placeholder="Specify deliverable output and guidelines..."></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Initial Instruction / Note (optional)</label>
            <input class="input" name="initial_note" placeholder="Pinned guidance note...">
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Create Deliverable</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </aside>
</div>

<!-- Window 1: Centered Popup Modal for Editing Task Details -->
<div id="modal-task-edit" class="modal-backdrop is-hidden">
  <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Edit Deliverable Details">
    <div class="modal-head">
      <div>
        <h3 id="modal-edit-task-title">Edit Deliverable Details</h3>
        <div id="modal-edit-task-meta" class="muted" style="font-size:12px;margin-top:2px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>
    <div class="modal-body">
      <form id="form-edit-task" data-api-form="/api/v1/tasks" data-api-method="PUT">
        <div class="form-group">
          <label class="form-label">Deliverable Title</label>
          <input class="input" name="title" required>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Stage</label>
            <select class="select" name="status">
              <option value="backlog">Backlog</option>
              <option value="todo">To Do</option>
              <option value="in_progress">In Progress</option>
              <option value="in_review">In Review</option>
              <option value="completed">Completed</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Priority</label>
            <select class="select" name="priority">
              <option value="low">Low</option>
              <option value="medium">Medium</option>
              <option value="high">High</option>
            </select>
          </div>
        </div>

        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">Client</label>
            <select class="select" name="client_id">
              <?php foreach ($data['clients'] as $c): ?>
                <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Deadline</label>
            <input class="input" type="date" name="deadline" required>
          </div>
          <div class="form-group">
            <label class="form-label">Estimated Hrs</label>
            <input class="input" type="number" step="0.5" min="0.5" name="estimated_hours" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description &amp; Acceptance Criteria</label>
          <textarea class="textarea" name="description" rows="4"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Save Task Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Window 2: Right-Side Drawer for VA Notes & Comments -->
<div id="drawer-task-collab" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Task Notes and Comments">
    <div class="drawer-head">
      <div>
        <h3 id="collab-task-title">Deliverable Conversation</h3>
        <div id="collab-task-meta" class="muted" style="font-size:12px;margin-top:3px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <div class="tab-strip">
        <button type="button" class="tab-btn active" data-tab-group="task-collab" data-tab-btn="notes">VA Notes</button>
        <button type="button" class="tab-btn" data-tab-group="task-collab" data-tab-btn="comments">Comments</button>
      </div>

      <!-- Tab: Execution Notes -->
      <div data-tab-group="task-collab" data-tab-panel="notes">
        <div id="detail-notes-list" style="margin-bottom:14px;"></div>
        <form data-api-form="/api/v1/tasks/{task_id}/notes" data-api-method="POST">
          <input type="hidden" name="task_id" value="">
          <div class="form-group">
            <label class="form-label">Add SLA Execution Note / Instruction</label>
            <textarea class="textarea" name="content" rows="3" required placeholder="Add SOP guidance, execution check, or handover note..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Add Note</button>
        </form>
      </div>

      <!-- Tab: Comments -->
      <div class="is-hidden" data-tab-group="task-collab" data-tab-panel="comments">
        <div id="detail-comments-list" style="margin-bottom:14px;"></div>
        <form data-api-form="/api/v1/tasks/{task_id}/comments" data-api-method="POST">
          <input type="hidden" name="task_id" value="">
          <div class="form-group">
            <label class="form-label">Post Threaded Comment</label>
            <textarea class="textarea" name="message" rows="3" required placeholder="Collaborate with Team Leader or VA..."></textarea>
          </div>
          <button type="submit" class="btn btn-primary">Post Comment</button>
        </form>
      </div>
    </div>
  </aside>
</div>

<!-- Window 3: Separate Centered Modal for Overtime Logging -->
<div id="modal-task-overtime" class="modal-backdrop is-hidden">
  <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Log Deliverable Overtime">
    <div class="modal-head">
      <div>
        <h3 id="overtime-task-title">Overtime &amp; Extra Hours</h3>
        <div id="overtime-task-meta" class="muted" style="font-size:12px;margin-top:2px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>
    <div class="modal-body">
      <div id="detail-extrahours-list" style="margin-bottom:14px;max-height:190px;overflow-y:auto;"></div>
      <form data-api-form="/api/v1/tasks/{task_id}/extra-hours" data-api-method="POST">
        <input type="hidden" name="task_id" value="">
        <div class="form-group">
          <label class="form-label">Overtime Hours</label>
          <input class="input" type="number" step="0.25" min="0.25" name="hours" value="1.0" required>
        </div>
        <div class="form-group">
          <label class="form-label">Justification</label>
          <textarea class="textarea" name="justification" rows="3" required placeholder="Explain scope expansion or urgent client request..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Record Extra Hours</button>
        </div>
      </form>
    </div>
  </div>
</div>
