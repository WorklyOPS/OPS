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

<div class="card">
  <h3 style="margin-bottom:12px;">PTO Request History</h3>
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
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['requests'] as $req): ?>
            <tr>
              <td><strong><?= SecurityHelper::e($req['va_name']) ?></strong></td>
              <td><?= SecurityHelper::e($req['start_date']) ?> → <?= SecurityHelper::e($req['end_date']) ?></td>
              <td><?= (int) $req['business_days_count'] ?>d</td>
              <td><?= SecurityHelper::e($req['reason']) ?></td>
              <td>
                <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' || $req['status'] === 'cancelled' ? 'badge-danger' : 'badge-warn') ?>">
                  <?= SecurityHelper::e(ucfirst($req['status'])) ?>
                </span>
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
