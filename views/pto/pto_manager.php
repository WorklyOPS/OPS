<div class="page-header">
  <div>
    <h1>PTO & Leave Requests</h1>
    <p class="muted">Paid Time Off tracker enforcing Monday–Friday business days (maximum 5 days per request).</p>
  </div>
</div>

<?php if ($role === 'va'): ?>
  <div class="grid-3" style="margin-bottom:22px;">
    <div class="card">
      <div class="kpi-label">Available PTO Balance</div>
      <div class="kpi-value"><?= (int) $user['pto_balance_days'] ?> days</div>
    </div>
    <div class="card">
      <div class="kpi-label">PTO Used This Cycle</div>
      <div class="kpi-value"><?= (int) $user['pto_taken_days'] ?> days</div>
    </div>
    <div class="card">
      <div class="kpi-label">Request Limit</div>
      <div class="kpi-value">1 – 5 days</div>
      <div class="kpi-sub">Monday–Friday only</div>
    </div>
  </div>

  <div class="card" style="margin-bottom:22px;">
    <h3 style="margin-bottom:12px;">Submit PTO Request</h3>
    <form data-api-form="/api/v1/pto" data-api-method="POST">
      <div class="grid-3">
        <div class="form-group">
          <label class="form-label">Start Date</label>
          <input id="pto-start" class="input" type="date" name="start_date" required>
        </div>
        <div class="form-group">
          <label class="form-label">End Date</label>
          <input id="pto-end" class="input" type="date" name="end_date" required>
        </div>
        <div class="form-group">
          <label class="form-label">Reason</label>
          <input class="input" name="reason" required placeholder="Personal leave, medical, family...">
        </div>
      </div>
      <div id="pto-days-preview" class="muted" style="font-size:12.5px;margin-bottom:12px;">
        Select start and end dates (Mon–Fri, max 5 business days).
      </div>
      <button type="submit" class="btn btn-primary">Submit Request</button>
    </form>
  </div>
<?php endif; ?>

<?php if (!empty($data['notifications']) && in_array($role, ['team_leader', 'admin'], true)): ?>
  <div class="card" style="margin-bottom:22px;">
    <h3 style="margin-bottom:12px;">Team Lead PTO Notification Triggers</h3>
    <div style="display:grid;gap:10px;">
      <?php foreach ($data['notifications'] as $notif): ?>
        <div class="message-bubble <?= !$notif['is_read'] ? 'mine' : '' ?>" style="margin-bottom:0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;">
              <strong><?= SecurityHelper::e($notif['title']) ?></strong>
              <?php if (!$notif['is_read']): ?>
                <span class="badge badge-brand">Unread</span>
              <?php endif; ?>
            </div>
            <div class="muted" style="font-size:12.5px;margin-top:3px;"><?= SecurityHelper::e($notif['message']) ?></div>
          </div>
          <?php if (!$notif['is_read'] && $role === 'team_leader'): ?>
            <button type="button"
                    class="btn btn-secondary btn-sm"
                    data-action-url="/api/v1/pto/notifications/<?= SecurityHelper::e($notif['id']) ?>/read"
                    data-action-method="PATCH">Mark Read</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-bottom:12px;">PTO Request History & Status Tracking</h3>
  <?php if (empty($data['requests'])): ?>
    <p class="muted">No PTO requests recorded yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Staff Member</th>
            <th>Date Range</th>
            <th>Business Days</th>
            <th>Reason</th>
            <th>Status & Trail</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['requests'] as $req): ?>
            <?php $trail = $data['tracking'][$req['id']] ?? []; ?>
            <tr>
              <td><strong><?= SecurityHelper::e($req['va_name']) ?></strong></td>
              <td><?= SecurityHelper::e($req['start_date']) ?> → <?= SecurityHelper::e($req['end_date']) ?></td>
              <td><?= (int) $req['business_days_count'] ?>d</td>
              <td><?= SecurityHelper::e($req['reason']) ?></td>
              <td>
                <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' || $req['status'] === 'cancelled' ? 'badge-danger' : 'badge-warn') ?>">
                  <?= SecurityHelper::e(ucfirst($req['status'])) ?>
                </span>
                <?php if (!empty($trail)): ?>
                  <div class="muted" style="font-size:11px;margin-top:4px;">
                    <?php
                      $labels = array_map(static fn(array $t) => (string) $t['current_status'], $trail);
                      echo SecurityHelper::e(implode(' → ', $labels));
                    ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($req['status'] === 'pending' && in_array($role, ['hrm', 'admin'], true)): ?>
                  <div style="display:flex;gap:6px;">
                    <button type="button"
                            class="btn btn-primary btn-sm"
                            data-action-url="/api/v1/pto/<?= SecurityHelper::e($req['id']) ?>/review"
                            data-action-method="PATCH"
                            data-action-body='{"status":"approved"}'>Approve</button>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            data-action-url="/api/v1/pto/<?= SecurityHelper::e($req['id']) ?>/review"
                            data-action-method="PATCH"
                            data-action-body='{"status":"rejected","rejection_note":"Coverage conflict"}'>Reject</button>
                  </div>
                <?php elseif ($req['status'] === 'pending' && $req['va_id'] === $user['id']): ?>
                  <button type="button"
                          class="btn btn-secondary btn-sm"
                          data-action-url="/api/v1/pto/<?= SecurityHelper::e($req['id']) ?>"
                          data-action-method="DELETE">Cancel</button>
                <?php else: ?>
                  <span class="muted"><?= SecurityHelper::e($req['reviewed_by'] ? 'Reviewed by ' . $req['reviewed_by'] : '—') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
