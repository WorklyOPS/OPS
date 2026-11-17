<?php
$payrollProjection = (float) ($data['payroll_projection'] ?? 0.0);
$complianceClients = $data['clients_compliance'] ?? [];
?>

<div class="page-header">
  <div>
    <h1>HR Manager &amp; Compliance Hub</h1>
    <p class="muted">Review Virtual Assistant PTO requests, payroll projections, client PDF contract compliance, and overtime audits.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="/pto" class="btn btn-secondary">PTO Policy Console</a>
    <a href="/perks" class="btn btn-primary">Manage Perks &amp; Benefits</a>
  </div>
</div>

<div class="grid-4" style="margin-bottom:22px;">
  <div class="card card-hover">
    <div class="kpi-label">Active VAs Monitored</div>
    <div class="kpi-value"><?= count($data['vas'] ?? []) ?></div>
    <div class="kpi-sub">Total Virtual Assistants</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Monthly Payroll Projection</div>
    <div class="kpi-value">$<?= number_format($payrollProjection, 0) ?></div>
    <div class="kpi-sub">160h/mo active VA baseline</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Pending PTO Requests</div>
    <div class="kpi-value"><?= (int) ($data['pending_pto'] ?? 0) ?></div>
    <div class="kpi-sub">Requires HR decision</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Extra Hours Entries</div>
    <div class="kpi-value"><?= count($data['extra_hours'] ?? []) ?></div>
    <div class="kpi-sub">Logged in PostgreSQL</div>
  </div>
</div>

<div class="card" style="margin-bottom:22px;">
  <h3 style="margin-bottom:12px;">PTO Approval Queue</h3>
  <?php if (empty($data['pto_requests'])): ?>
    <p class="muted">No PTO requests have been submitted yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Virtual Assistant</th>
            <th>Dates</th>
            <th>Business Days</th>
            <th>Balance</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['pto_requests'] as $req): ?>
            <tr>
              <td><strong><?= SecurityHelper::e($req['va_name']) ?></strong></td>
              <td><?= SecurityHelper::e($req['start_date']) ?> → <?= SecurityHelper::e($req['end_date']) ?></td>
              <td><?= (int) $req['business_days_count'] ?>d</td>
              <td><?= (int) $req['pto_balance_days'] ?>d left</td>
              <td><?= SecurityHelper::e($req['reason']) ?></td>
              <td>
                <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' ? 'badge-danger' : 'badge-warn') ?>">
                  <?= SecurityHelper::e(ucfirst($req['status'])) ?>
                </span>
              </td>
              <td>
                <?php if ($req['status'] === 'pending'): ?>
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
                            data-action-body='{"status":"rejected","rejection_note":"Declined by HR"}'>Decline</button>
                  </div>
                <?php else: ?>
                  <span class="muted">Reviewed by <?= SecurityHelper::e($req['reviewed_by'] ?: 'HRM') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="grid-2">
  <div class="card">
    <h3 style="margin-bottom:12px;">Client Contract Compliance</h3>
    <?php if (empty($complianceClients)): ?>
      <p class="muted">No client contracts recorded yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Agency</th>
              <th>Assigned VA</th>
              <th>Status</th>
              <th>Signed Agreement</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($complianceClients as $cc): ?>
              <tr>
                <td>
                  <strong><?= SecurityHelper::e($cc['agency_name']) ?></strong>
                  <div class="muted" style="font-size:11.5px;"><?= SecurityHelper::e($cc['name']) ?></div>
                </td>
                <td><?= SecurityHelper::e($cc['focus_va_name'] ?: 'Unassigned') ?></td>
                <td>
                  <span class="badge <?= $cc['contract_status'] === 'active' ? 'badge-success' : 'badge-warn' ?>">
                    <?= SecurityHelper::e(ucfirst($cc['contract_status'])) ?>
                  </span>
                </td>
                <td>
                  <?php if (!empty($cc['contract_pdf_url'])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cc['contract_pdf_url'])) ?>" target="_blank" rel="noopener">PDF On File</a>
                  <?php else: ?>
                    <span class="badge badge-danger">Missing PDF</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-bottom:12px;">Overtime &amp; Extra Hours Audit</h3>
    <?php if (empty($data['extra_hours'])): ?>
      <p class="muted">No overtime logs recorded yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>VA</th>
              <th>Client</th>
              <th>Hours</th>
              <th>Date</th>
              <th>Justification</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['extra_hours'] as $eh): ?>
              <tr>
                <td><?= SecurityHelper::e($eh['va_name']) ?></td>
                <td><?= SecurityHelper::e($eh['client_name']) ?></td>
                <td><strong>+<?= number_format((float) $eh['extra_hours'], 2) ?>h</strong></td>
                <td><?= SecurityHelper::e($eh['date']) ?></td>
                <td><?= SecurityHelper::e($eh['justification']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
