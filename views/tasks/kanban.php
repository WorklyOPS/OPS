<?php
$columns = [
    'backlog'     => 'Backlog',
    'todo'        => 'To Do',
    'in_progress' => 'In Progress',
    'in_review'   => 'In Review',
    'completed'   => 'Completed',
];

$tasksByCol = array_fill_keys(array_keys($columns), []);
$tasksMap = [];
foreach ($data['tasks'] as $t) {
    $st = isset($tasksByCol[$t['status']]) ? $t['status'] : 'todo';
    $tasksByCol[$st][] = $t;
    $tasksMap[$t['id']] = $t;
}
$totalCount = max(1, count($data['tasks']));
$notesMap = $data['notes_by_task'] ?? [];
$commentsMap = $data['comments_by_task'] ?? [];
$extraHoursMap = $data['extra_hours_by_task'] ?? [];
?>

<div class="page-header">
  <div>
    <h1>Weekly Task Organizer</h1>
    <p class="muted">Drag cards across stages, right-click any card for instant actions, or click a card to open the right-side detail drawer.</p>
  </div>
  <?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-task">+ New Deliverable</button>
  <?php endif; ?>
</div>

<?php if ($role === 'team_leader'): ?>
  <div class="card" style="margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
      <div>
        <h3 style="font-size:15px;">Team Workload Distribution</h3>
        <span class="muted" style="font-size:12px;">Status breakdown across your assigned Virtual Assistants for the selected period.</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <span class="badge badge-neutral">Backlog: <?= (int) $data['status_counts']['backlog'] ?></span>
        <span class="badge badge-neutral">To Do: <?= (int) $data['status_counts']['todo'] ?></span>
        <span class="badge badge-info">In Progress: <?= (int) $data['status_counts']['in_progress'] ?></span>
        <span class="badge badge-warn">In Review: <?= (int) $data['status_counts']['in_review'] ?></span>
        <span class="badge badge-success">Completed: <?= (int) $data['status_counts']['completed'] ?></span>
      </div>
    </div>
    <div class="progress-track" style="display:flex;height:10px;">
      <div style="width:<?= round(($data['status_counts']['completed'] / $totalCount) * 100, 1) ?>%;background:var(--success);"></div>
      <div style="width:<?= round(($data['status_counts']['in_review'] / $totalCount) * 100, 1) ?>%;background:var(--warn);"></div>
      <div style="width:<?= round(($data['status_counts']['in_progress'] / $totalCount) * 100, 1) ?>%;background:var(--brand);"></div>
      <div style="width:<?= round(($data['status_counts']['todo'] / $totalCount) * 100, 1) ?>%;background:#C4B5FD;"></div>
    </div>
  </div>
<?php endif; ?>

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
    </div>

    <div style="min-width:250px;flex:0 1 320px;">
      <input id="kanban-search" type="search" class="input" placeholder="Search tasks by title, client, or VA...">
    </div>
  </form>
</div>

<section id="workly-kanban" class="kanban-board">
  <?php foreach ($columns as $colKey => $colTitle): ?>
    <div class="kanban-col" data-status="<?= SecurityHelper::e($colKey) ?>">
      <div class="kanban-col-head">
        <span class="kanban-col-title"><?= SecurityHelper::e($colTitle) ?></span>
        <span class="badge badge-neutral col-count"><?= count($tasksByCol[$colKey]) ?></span>
      </div>

      <div class="kanban-col-cards">
        <?php foreach ($tasksByCol[$colKey] as $task): ?>
          <?php
            $pBadge = match ($task['priority']) {
                'high'  => 'badge-danger',
                'low'   => 'badge-neutral',
                default => 'badge-warn',
            };
            $noteCount = count($notesMap[$task['id']] ?? []);
            $commentCount = count($commentsMap[$task['id']] ?? []);
            $extraHrs = (float) ($task['extra_hours_logged'] ?? 0);
            $vaInitials = strtoupper(substr((string) ($task['va_name'] ?? 'VA'), 0, 2));
          ?>
          <article class="task-card"
                   draggable="true"
                   data-task-id="<?= SecurityHelper::e($task['id']) ?>"
                   data-search="<?= SecurityHelper::e($task['title'] . ' ' . $task['client_name'] . ' ' . $task['va_name']) ?>">
            <div class="task-card-top">
              <span class="badge <?= $pBadge ?>"><?= SecurityHelper::e(ucfirst($task['priority'])) ?></span>
              <button type="button" class="icon-chip-btn task-menu-btn" data-task-id="<?= SecurityHelper::e($task['id']) ?>" aria-label="Task context menu" title="Quick Actions">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
              </button>
            </div>

            <div class="task-title" data-open-task="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="details">
              <?= SecurityHelper::e($task['title']) ?>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:8px;">
              <span class="badge badge-brand"><?= SecurityHelper::e($task['client_name']) ?></span>
              <span class="muted mono" style="font-size:11px;"><?= SecurityHelper::e($task['deadline']) ?></span>
            </div>

            <div class="task-card-actions">
              <div style="display:flex;align-items:center;gap:4px;">
                <button type="button" class="icon-chip-btn" data-open-task="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="notes" title="VA Notes">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
                  <span><?= $noteCount ?></span>
                </button>
                <button type="button" class="icon-chip-btn" data-open-task="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="comments" title="Comments">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                  <span><?= $commentCount ?></span>
                </button>
                <button type="button" class="icon-chip-btn" data-open-task="<?= SecurityHelper::e($task['id']) ?>" data-task-tab="extrahours" title="Overtime Hours">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                  <span><?= $extraHrs > 0 ? '+' . number_format($extraHrs, 1) . 'h' : '0h' ?></span>
                </button>
              </div>

              <span style="display:inline-flex;align-items:center;gap:5px;" title="<?= SecurityHelper::e($task['va_name']) ?>">
                <?php if (!empty($task['va_avatar'])): ?>
                  <img src="<?= SecurityHelper::e($task['va_avatar']) ?>" alt="<?= SecurityHelper::e($task['va_name']) ?>" class="avatar avatar-sm">
                <?php else: ?>
                  <span class="avatar avatar-sm"><?= SecurityHelper::e($vaInitials) ?></span>
                <?php endif; ?>
              </span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<!-- Floating Right-Click Context Menu -->
<div id="kanban-context-menu" class="context-menu is-hidden" role="menu">
  <div class="context-menu-label">Inspect &amp; Collaborate</div>
  <button type="button" data-cm-action="open" data-cm-tab="details">Edit Deliverable</button>
  <button type="button" data-cm-action="open" data-cm-tab="notes">VA Notes &amp; Instructions</button>
  <button type="button" data-cm-action="open" data-cm-tab="comments">Discussion Comments</button>
  <button type="button" data-cm-action="open" data-cm-tab="extrahours">Log Overtime Hours</button>

  <div class="context-menu-divider"></div>
  <div class="context-menu-label">Move Stage</div>
  <button type="button" data-cm-action="status" data-status="backlog">Move to Backlog</button>
  <button type="button" data-cm-action="status" data-status="todo">Move to To Do</button>
  <button type="button" data-cm-action="status" data-status="in_progress">Move to In Progress</button>
  <button type="button" data-cm-action="status" data-status="in_review">Move to In Review</button>
  <button type="button" data-cm-action="status" data-status="completed">Mark Completed</button>

  <div class="context-menu-divider"></div>
  <div class="context-menu-label">Set Priority</div>
  <button type="button" data-cm-action="priority" data-priority="high">High Priority</button>
  <button type="button" data-cm-action="priority" data-priority="medium">Medium Priority</button>
  <button type="button" data-cm-action="priority" data-priority="low">Low Priority</button>

  <div class="context-menu-divider"></div>
  <button type="button" class="danger" data-cm-action="delete">Delete Deliverable</button>
</div>

<!-- Embedded Non-Executable JSON Payload for Drawer Inspection -->
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

<!-- Right-Side Slide-Over Drawer: Task Details, Inline Edit, VA Notes, Comments & Overtime -->
<div id="drawer-task-detail" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Deliverable Dossier">
    <div class="drawer-head">
      <div>
        <h3 id="detail-task-title"></h3>
        <div id="detail-task-meta" class="muted" style="font-size:12px;margin-top:3px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <div class="tab-strip">
        <button type="button" class="tab-btn active" data-tab-group="task-drawer" data-tab-btn="details">Details &amp; Edit</button>
        <button type="button" class="tab-btn" data-tab-group="task-drawer" data-tab-btn="notes">VA Notes</button>
        <button type="button" class="tab-btn" data-tab-group="task-drawer" data-tab-btn="comments">Comments</button>
        <button type="button" class="tab-btn" data-tab-group="task-drawer" data-tab-btn="extrahours">Overtime</button>
      </div>

      <!-- Tab: Inline Edit -->
      <div data-tab-group="task-drawer" data-tab-panel="details">
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
            <button type="submit" class="btn btn-primary">Save Task Changes</button>
          </div>
        </form>
      </div>

      <!-- Tab: Execution Notes -->
      <div class="is-hidden" data-tab-group="task-drawer" data-tab-panel="notes">
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
      <div class="is-hidden" data-tab-group="task-drawer" data-tab-panel="comments">
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

      <!-- Tab: Overtime / Extra Hours -->
      <div class="is-hidden" data-tab-group="task-drawer" data-tab-panel="extrahours">
        <div id="detail-extrahours-list" style="margin-bottom:14px;"></div>
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
          <button type="submit" class="btn btn-primary">Record Extra Hours</button>
        </form>
      </div>
    </div>
  </aside>
</div>
