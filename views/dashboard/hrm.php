<?php
$payrollProjection = (float) ($data['payroll_projection'] ?? 0.0);
$complianceClients = $data['clients_compliance'] ?? [];
$overlaps = $data['pto_overlaps'] ?? [];
$rosterHealth = $data['va_roster_health'] ?? [];
?>

<div class="page-header">
  <div>
    <h1>HR Manager &amp; Compliance Hub</h1>
    <p class="muted">Review PTO requests with coverage overlap warnings, monitor VA compensation &amp; SLA health, audit overtime, and verify PDF contracts.</p>
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
    <div class="kpi-sub"><?= count($overlaps) ?> overlap alert(s)</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Extra Hours Entries</div>
    <div class="kpi-value"><?= count($data['extra_hours'] ?? []) ?></div>
    <div class="kpi-sub">Logged in PostgreSQL</div>
  </div>
</div>

<div class="card" style="margin-bottom:22px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
    <div>
      <h3>PTO Approval Queue</h3>
      <p class="muted" style="font-size:12px;">Automatic overlap detection alerts you when two Virtual Assistants request overlapping dates.</p>
    </div>
  </div>

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
            <th>Reason &amp; Coverage</th>
            <th>Status</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['pto_requests'] as $req): ?>
            <?php $reqOverlaps = $overlaps[$req['id']] ?? []; ?>
            <tr>
              <td><strong><?= SecurityHelper::e($req['va_name']) ?></strong></td>
              <td class="mono" style="font-size:12px;"><?= SecurityHelper::e($req['start_date']) ?> → <?= SecurityHelper::e($req['end_date']) ?></td>
              <td class="tabular-nums"><?= (int) $req['business_days_count'] ?>d</td>
              <td class="tabular-nums"><?= (int) $req['pto_balance_days'] ?>d left</td>
              <td>
                <div><?= SecurityHelper::e($req['reason']) ?></div>
                <?php if (!empty($reqOverlaps)): ?>
                  <div class="badge badge-warn" style="margin-top:4px;">
                    Overlaps with: <?= SecurityHelper::e(implode(', ', $reqOverlaps)) ?>
                  </div>
                <?php endif; ?>
              </td>
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
                            data-pto-reject="<?= SecurityHelper::e($req['id']) ?>"
                            data-pto-va="<?= SecurityHelper::e($req['va_name']) ?>">Decline</button>
                  </div>
                <?php else: ?>
                  <span class="muted" style="font-size:12px;">
                    <?= SecurityHelper::e($req['reviewed_by'] ?: 'HRM') ?>
                    <?php if (!empty($req['rejection_note'])): ?>
                      — <?= SecurityHelper::e($req['rejection_note']) ?>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- VA Compensation & Attendance Health Matrix + CSV Export -->
<div class="card" style="margin-bottom:22px;">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
    <div>
      <h3>VA Payroll &amp; Attendance Health Matrix</h3>
      <p class="muted" style="font-size:12px;">Live compensation rates, PTO consumption, logged hours &amp; SLA reliability across all active staff.</p>
    </div>
    <?php if (!empty($rosterHealth)): ?>
      <button type="button"
              class="btn btn-secondary btn-sm"
              data-export-csv="hrm-roster-table"
              data-csv-filename="workly_va_payroll_health.csv">Export CSV</button>
    <?php endif; ?>
  </div>

  <?php if (empty($rosterHealth)): ?>
    <p class="muted">No active VA telemetry rows available yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table id="hrm-roster-table" class="table">
        <thead>
          <tr>
            <th>Staff Member</th>
            <th>Hire Date</th>
            <th>Hourly Rate</th>
            <th>Est. Monthly</th>
            <th>PTO Left</th>
            <th>PTO Taken</th>
            <th>Completed Tasks</th>
            <th>Extra Hours</th>
            <th>SLA Rate</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rosterHealth as $rh): ?>
            <tr>
              <td>
                <strong><?= SecurityHelper::e($rh['va_name']) ?></strong>
                <div class="muted" style="font-size:11.5px;"><?= SecurityHelper::e($rh['va_email']) ?></div>
              </td>
              <td class="mono" style="font-size:12px;"><?= SecurityHelper::e((string) ($rh['hire_date'] ?? '—')) ?></td>
              <td class="tabular-nums">$<?= number_format((float) $rh['hourly_rate'], 2) ?>/hr</td>
              <td class="tabular-nums"><strong>$<?= number_format((float) $rh['hourly_rate'] * 160, 0) ?></strong></td>
              <td class="tabular-nums"><?= (int) $rh['pto_balance_days'] ?>d</td>
              <td class="tabular-nums"><?= (int) $rh['pto_taken_days'] ?>d</td>
              <td class="tabular-nums"><?= (int) $rh['completed_tasks'] ?> / <?= (int) $rh['total_tasks'] ?></td>
              <td class="tabular-nums">+<?= number_format((float) $rh['total_extra_hours'], 1) ?>h</td>
              <td>
                <span class="badge <?= (float) $rh['on_time_rate'] >= 90 ? 'badge-success' : 'badge-warn' ?>">
                  <?= number_format((float) $rh['on_time_rate'], 1) ?>%
                </span>
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
                <td class="tabular-nums"><strong>+<?= number_format((float) $eh['extra_hours'], 2) ?>h</strong></td>
                <td class="mono" style="font-size:12px;"><?= SecurityHelper::e($eh['date']) ?></td>
                <td><?= SecurityHelper::e($eh['justification']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Right-Side Slide-Over Drawer: Custom PTO Rejection Reason -->
<div id="drawer-pto-reject" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Decline PTO Request">
    <div class="drawer-head">
      <div>
        <h3>Decline PTO Request</h3>
        <div class="muted" style="font-size:12px;">Provide an HR rationale for <strong id="pto-reject-target">Staff Member</strong>.</div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>
    <div class="drawer-body">
      <form id="form-pto-reject" data-api-form="/api/v1/pto/0/review" data-api-method="PATCH">
        <input type="hidden" name="status" value="rejected">
        <div class="form-group">
          <label class="form-label">Rejection Reason / Coverage Note</label>
          <textarea class="textarea" name="rejection_note" rows="4" required placeholder="e.g. Overlapping client launch coverage — please propose alternate dates."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-danger">Confirm Decline</button>
        </div>
      </form>
    </div>
  </aside>
</div>
