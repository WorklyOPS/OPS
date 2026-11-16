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
?>

<div class="page-header">
  <div>
    <h1>Weekly Task Organizer</h1>
    <p class="muted">Select any month and week to review deliverables, track deadlines, and drag cards across stages.</p>
  </div>
  <?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
    <button type="button" class="btn btn-primary" data-open-modal="modal-create-task">+ New Deliverable</button>
  <?php endif; ?>
</div>

<?php if ($role === 'team_leader'): ?>
  <div class="card" style="margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;">
      <div>
        <h3 style="font-size:15px;">Team Workload Distribution</h3>
        <span class="muted" style="font-size:12px;">Status breakdown across your assigned Virtual Assistants for the selected period.</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:10px;">
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

    <div style="min-width:240px;flex:0 1 300px;">
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
                'high'   => 'badge-danger',
                'low'    => 'badge-neutral',
                default  => 'badge-warn',
            };
          ?>
          <article class="task-card"
                   tabindex="0"
                   draggable="true"
                   data-task-id="<?= SecurityHelper::e($task['id']) ?>"
                   data-search="<?= SecurityHelper::e($task['title'] . ' ' . $task['client_name'] . ' ' . $task['va_name']) ?>">
            <div class="task-card-top">
              <div class="task-badges">
                <span class="badge <?= $pBadge ?>"><?= SecurityHelper::e(ucfirst($task['priority'])) ?></span>
                <?php if (!empty($task['is_urgent'])): ?>
                  <span class="badge badge-danger">Urgent</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="task-dropdown is-hidden" role="menu" aria-label="Task actions">
              <button type="button" data-task-action="open" data-task-id="<?= SecurityHelper::e($task['id']) ?>">Open task details</button>
              <div class="task-menu-divider"></div>
              <span class="task-menu-label">Move to</span>
              <?php foreach ($columns as $statusKey => $statusLabel): ?>
                <?php if ($statusKey !== $task['status']): ?>
                  <button type="button" data-task-action="status" data-status="<?= SecurityHelper::e($statusKey) ?>" data-task-id="<?= SecurityHelper::e($task['id']) ?>"><?= SecurityHelper::e($statusLabel) ?></button>
                <?php endif; ?>
              <?php endforeach; ?>
              <div class="task-menu-divider"></div>
              <span class="task-menu-label">Priority</span>
              <button type="button" data-task-action="priority" data-priority="high" data-task-id="<?= SecurityHelper::e($task['id']) ?>">High</button>
              <button type="button" data-task-action="priority" data-priority="medium" data-task-id="<?= SecurityHelper::e($task['id']) ?>">Medium</button>
              <button type="button" data-task-action="priority" data-priority="low" data-task-id="<?= SecurityHelper::e($task['id']) ?>">Low</button>
              <button type="button" data-task-action="urgent" data-urgent="<?= !empty($task['is_urgent']) ? '0' : '1' ?>" data-task-id="<?= SecurityHelper::e($task['id']) ?>"><?= !empty($task['is_urgent']) ? 'Remove urgent flag' : 'Mark as urgent' ?></button>
              <?php if (in_array($role, ['team_leader', 'admin'], true)): ?>
                <div class="task-menu-divider"></div>
                <button type="button" data-task-action="duplicate" data-task-id="<?= SecurityHelper::e($task['id']) ?>">Duplicate task</button>
                <button type="button" data-task-action="archive" data-task-id="<?= SecurityHelper::e($task['id']) ?>">Archive task</button>
                <button type="button" data-task-action="delete" data-task-id="<?= SecurityHelper::e($task['id']) ?>" class="task-menu-danger">Delete permanently</button>
              <?php endif; ?>
            </div>

            <div class="task-title" data-open-task="<?= SecurityHelper::e($task['id']) ?>">
              <?= SecurityHelper::e($task['title']) ?>
            </div>

            <div style="margin-top:8px;">
              <span class="badge badge-brand"><?= SecurityHelper::e($task['client_name']) ?></span>
            </div>

            <div class="task-meta">
              <span><?= SecurityHelper::e($task['va_name']) ?></span>
              <span>Due <?= SecurityHelper::e($task['deadline']) ?></span>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<!-- Embedded JSON payload for task detail inspection (non-executable application/json) -->
<script type="application/json" id="task-details-json"><?= json_encode([
    'tasks'    => $tasksMap,
    'notes'    => $data['notes_by_task'],
    'comments' => $data['comments_by_task'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Modal: Create Deliverable -->
<div id="modal-create-task" class="modal-backdrop is-hidden">
  <div class="modal-box">
    <div class="modal-head">
      <h3>New Deliverable</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>

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
          <label class="form-label">Description & Acceptance Criteria</label>
          <textarea class="textarea" name="description" placeholder="Specify deliverable output and guidelines..."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Initial Instruction / Note (optional)</label>
          <input class="input" name="initial_note" placeholder="Pinned guidance note...">
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Create Deliverable</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: Task Details, VA Notes, Comments & Linked Extra Hours -->
<div id="modal-task-detail" class="modal-backdrop is-hidden">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <h3 id="detail-task-title"></h3>
        <div id="detail-task-meta" class="muted" style="font-size:12px;margin-top:3px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>

    <div class="notice notice-info" id="detail-task-desc"></div>

    <section class="task-activity-section">
      <div class="task-activity-heading">
        <div>
          <h4>Task activity</h4>
          <p class="muted">Notes and team conversation in one timeline.</p>
        </div>
      </div>
      <div id="detail-activity-list" class="task-activity-list"></div>
      <form data-api-form="/api/v1/tasks/{task_id}/activity" data-api-method="POST" class="task-activity-form">
        <input type="hidden" name="task_id" value="">
        <select class="select" name="type" aria-label="Activity type">
          <option value="comment">Comment</option>
          <option value="note">Internal note</option>
        </select>
        <input class="input" name="content" required maxlength="2000" placeholder="Write a comment or internal note...">
        <button type="submit" class="btn btn-primary btn-sm">Send</button>
      </form>
    </section>

    <div style="padding-top:14px;border-top:1px solid var(--border);">
      <h4 style="margin-bottom:8px;">Log Overtime / Extra Hours on Deliverable</h4>
      <form data-api-form="/api/v1/tasks/{task_id}/extra-hours" data-api-method="POST" class="grid-3" style="align-items:end;">
        <input type="hidden" name="task_id" value="">
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Extra Hours</label>
          <input class="input" type="number" step="0.25" min="0.25" name="hours" value="1.0" required>
        </div>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Justification</label>
          <input class="input" name="justification" required placeholder="Reason for overtime">
        </div>
        <button type="submit" class="btn btn-primary">Record Extra Hours</button>
      </form>
    </div>
  </div>
</div>
