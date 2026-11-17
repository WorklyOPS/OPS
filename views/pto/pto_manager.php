<?php
require_once __DIR__ . '/../partials/icons.php';

$requests = $data['requests'] ?? [];
$notifications = $data['notifications'] ?? [];
$vaRegister = $data['va_register'] ?? [];
$months = $data['months'] ?? [];
$selectedMonth = (string) ($data['selected_month'] ?? date('Y-m'));
$monthLabel = (string) ($data['month_label'] ?? date('F Y'));
$canManageHr = in_array($role, ['admin', 'hrm'], true);

$totalMonthlyDays = 0;
$totalCycleUsed = 0;
foreach ($vaRegister as $vr) {
    $totalMonthlyDays += (int) ($vr['monthly_days'] ?? 0);
    $totalCycleUsed += (int) ($vr['pto_taken_days'] ?? 0);
}
$pendingCount = 0;
foreach ($requests as $r) {
    if (($r['status'] ?? '') === 'pending') {
        $pendingCount++;
    }
}
?>

<div class="page-header">
  <div>
    <h1>PTO &amp; Leave Management</h1>
    <p class="muted">Monday–Friday business day tracker, HR monthly allowance register, and instant Team Lead notifications.</p>
  </div>
</div>

<?php if ($role === 'va'): ?>
  <div class="grid-3" style="margin-bottom:20px;">
    <div class="card card-hover">
      <div class="kpi-label">Available Free / PTO Days</div>
      <div class="kpi-value"><?= (int) $user['pto_balance_days'] ?> days</div>
      <div class="kpi-sub">Current annual balance</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">PTO Used This Cycle</div>
      <div class="kpi-value"><?= (int) $user['pto_taken_days'] ?> days</div>
      <div class="kpi-sub">Approved business days taken</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Policy Window</div>
      <div class="kpi-value">1 – 5 days</div>
      <div class="kpi-sub">Monday–Friday business days</div>
    </div>
  </div>

  <div class="pto-layout-grid">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div>
          <h3>Submit PTO Request</h3>
          <p class="muted" style="font-size:12px;">Pick your leave range; weekends are automatically excluded.</p>
        </div>
        <span class="badge badge-brand">Mon–Fri</span>
      </div>

      <form data-api-form="/api/v1/pto" data-api-method="POST">
        <div class="pto-range-box">
          <div>
            <label class="form-label" for="pto-start">Start Date</label>
            <input id="pto-start" class="input" type="date" name="start_date" required>
          </div>
          <div class="pto-range-arrow" aria-hidden="true">
            <?= workly_icon_svg('ArrowRight', 16) ?>
          </div>
          <div>
            <label class="form-label" for="pto-end">End Date</label>
            <input id="pto-end" class="input" type="date" name="end_date" required>
          </div>
        </div>

        <div id="pto-days-preview" class="notice notice-info" style="margin:12px 0;font-size:12.5px;">
          Select start and end dates (Mon–Fri, max 5 business days).
        </div>

        <div class="form-group">
          <label class="form-label">Leave Reason / Coverage Note</label>
          <input class="input" name="reason" required placeholder="Personal leave, medical appointment, family event...">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">Submit PTO Request</button>
      </form>
    </div>

    <div class="card">
      <h3 style="margin-bottom:12px;">My PTO Request History</h3>
      <?php if (empty($requests)): ?>
        <p class="muted">No PTO requests recorded yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Date Range</th>
                <th>Days</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $req): ?>
                <tr>
                  <td>
                    <span class="pto-date-pill"><?= SecurityHelper::e($req['start_date']) ?> &rarr; <?= SecurityHelper::e($req['end_date']) ?></span>
                  </td>
                  <td class="tabular-nums"><strong><?= (int) $req['business_days_count'] ?>d</strong></td>
                  <td><?= SecurityHelper::e($req['reason']) ?></td>
                  <td>
                    <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' || $req['status'] === 'cancelled' ? 'badge-danger' : 'badge-warn') ?>">
                      <?= SecurityHelper::e(ucfirst($req['status'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($req['status'] === 'pending'): ?>
                      <button type="button"
                              class="btn btn-secondary btn-sm"
                              data-action-url="/api/v1/pto/<?= SecurityHelper::e($req['id']) ?>"
                              data-action-method="DELETE">Cancel</button>
                    <?php else: ?>
                      <span class="muted"><?= SecurityHelper::e($req['reviewed_by'] ? 'By ' . $req['reviewed_by'] : '—') ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php elseif ($canManageHr): ?>
  <!-- Admin & HRM Governance Workspace -->
  <div class="grid-4" style="margin-bottom:20px;">
    <div class="card card-hover">
      <div class="kpi-label">Active VAs Tracked</div>
      <div class="kpi-value"><?= count($vaRegister) ?></div>
      <div class="kpi-sub">PTO allowance register</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">PTO Taken (<?= SecurityHelper::e($monthLabel) ?>)</div>
      <div class="kpi-value"><?= $totalMonthlyDays ?>d</div>
      <div class="kpi-sub">Approved days in month</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Total Cycle Days Used</div>
      <div class="kpi-value"><?= $totalCycleUsed ?>d</div>
      <div class="kpi-sub">Cumulative staff usage</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Pending Approval</div>
      <div class="kpi-value"><?= $pendingCount ?></div>
      <div class="kpi-sub">Awaiting HR / Admin action</div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/pto_calendar_card.php'; ?>

  <!-- VA Search + Monthly PTO Usage Category Register -->
  <div class="card" style="margin-bottom:22px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
      <div>
        <h3>VA Monthly PTO Register &amp; Free Days Governance</h3>
        <p class="muted" style="font-size:12.5px;">Track monthly leave utilization per Virtual Assistant and adjust available PTO / free days.</p>
      </div>
      <form method="GET" action="/pto" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <input id="pto-va-search"
               type="search"
               class="input"
               placeholder="Search VA by name..."
               style="min-width:220px;">
        <select name="month" class="select" data-auto-submit style="min-width:170px;">
          <?php foreach ($months as $m): ?>
            <option value="<?= SecurityHelper::e($m['value']) ?>" <?= $m['value'] === $selectedMonth ? 'selected' : '' ?>>
              <?= SecurityHelper::e($m['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if (empty($vaRegister)): ?>
      <p class="muted">No active Virtual Assistants found.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Virtual Assistant</th>
              <th>Team Leader</th>
              <th><?= SecurityHelper::e($monthLabel) ?> PTO</th>
              <th>Cycle Used</th>
              <th>Free Days Available</th>
              <th>Adjust</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($vaRegister as $vr): ?>
              <?php $vaHay = strtolower(trim($vr['name'] . ' ' . $vr['email'] . ' ' . ($vr['tl_name'] ?? ''))); ?>
              <tr class="pto-va-row" data-va-name="<?= SecurityHelper::e($vaHay) ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:9px;">
                    <?php if (!empty($vr['avatar_url'])): ?>
                      <img src="<?= SecurityHelper::e($vr['avatar_url']) ?>" alt="<?= SecurityHelper::e($vr['name']) ?>" class="avatar-xs">
                    <?php else: ?>
                      <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $vr['name'], 0, 2))) ?></span>
                    <?php endif; ?>
                    <div>
                      <strong><?= SecurityHelper::e($vr['name']) ?></strong>
                      <div class="muted" style="font-size:11.5px;"><?= SecurityHelper::e($vr['email']) ?></div>
                    </div>
                  </div>
                </td>
                <td><?= SecurityHelper::e($vr['tl_name'] ?: 'Unassigned') ?></td>
                <td>
                  <span class="badge <?= (int) $vr['monthly_days'] > 0 ? 'badge-brand' : 'badge-neutral' ?> tabular-nums">
                    <?= (int) $vr['monthly_days'] ?> day(s)
                  </span>
                </td>
                <td class="tabular-nums"><?= (int) $vr['pto_taken_days'] ?>d</td>
                <td>
                  <span class="badge <?= (int) $vr['pto_balance_days'] >= 5 ? 'badge-success' : 'badge-warn' ?> tabular-nums">
                    <?= (int) $vr['pto_balance_days'] ?>d left
                  </span>
                </td>
                <td>
                  <button type="button"
                          class="btn btn-secondary btn-sm"
                          data-edit-pto-va="<?= SecurityHelper::e($vr['id']) ?>"
                          data-va-label="<?= SecurityHelper::e($vr['name']) ?>"
                          data-va-balance="<?= (int) $vr['pto_balance_days'] ?>"
                          data-va-taken="<?= (int) $vr['pto_taken_days'] ?>">Edit Days</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="<?= !empty($notifications) ? 'pto-layout-grid-wide' : '' ?>">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h3>PTO Request Queue &amp; History</h3>
        <span class="badge badge-neutral tabular-nums"><?= count($requests) ?> request(s)</span>
      </div>
      <?php if (empty($requests)): ?>
        <p class="muted">No PTO requests recorded yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Staff Member</th>
                <th>Date Range</th>
                <th>Days</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Review</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $req): ?>
                <?php $reqHay = strtolower(trim($req['va_name'] . ' ' . ($req['va_email'] ?? '') . ' ' . $req['reason'])); ?>
                <tr class="pto-req-row" data-va-name="<?= SecurityHelper::e($reqHay) ?>">
                  <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                      <?php if (!empty($req['avatar_url'])): ?>
                        <img src="<?= SecurityHelper::e($req['avatar_url']) ?>" alt="<?= SecurityHelper::e($req['va_name']) ?>" class="avatar-xs">
                      <?php else: ?>
                        <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $req['va_name'], 0, 2))) ?></span>
                      <?php endif; ?>
                      <strong><?= SecurityHelper::e($req['va_name']) ?></strong>
                    </div>
                  </td>
                  <td><span class="pto-date-pill"><?= SecurityHelper::e($req['start_date']) ?> &rarr; <?= SecurityHelper::e($req['end_date']) ?></span></td>
                  <td class="tabular-nums"><?= (int) $req['business_days_count'] ?>d</td>
                  <td><?= SecurityHelper::e($req['reason']) ?></td>
                  <td>
                    <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' || $req['status'] === 'cancelled' ? 'badge-danger' : 'badge-warn') ?>">
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
                        <?= SecurityHelper::e($req['reviewed_by'] ? 'By ' . $req['reviewed_by'] : '—') ?>
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

    <?php if (!empty($notifications)): ?>
      <div class="card">
        <h3 style="margin-bottom:12px;">Team Lead PTO Alerts Feed</h3>
        <div style="display:grid;gap:10px;">
          <?php foreach ($notifications as $notif): ?>
            <div class="message-bubble <?= !$notif['is_read'] ? 'mine' : '' ?>" style="margin-bottom:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <strong><?= SecurityHelper::e($notif['title']) ?></strong>
                <?php if (!$notif['is_read']): ?>
                  <span class="badge badge-brand">Unread</span>
                <?php endif; ?>
              </div>
              <div class="muted" style="font-size:12.5px;margin-top:4px;"><?= SecurityHelper::e($notif['message']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right-Side Drawer: Decline PTO with Note -->
  <div id="drawer-pto-reject" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Decline PTO Request">
      <div class="drawer-head">
        <div>
          <h3>Decline PTO Request</h3>
          <div class="muted" style="font-size:12px;">Provide rationale for <strong id="pto-reject-target">Staff Member</strong>.</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form id="form-pto-reject" data-api-form="/api/v1/pto/0/review" data-api-method="PATCH">
          <input type="hidden" name="status" value="rejected">
          <div class="form-group">
            <label class="form-label">Rejection Reason / Coverage Note</label>
            <textarea class="textarea" name="rejection_note" rows="4" required placeholder="e.g. Overlapping client coverage — please select alternate dates."></textarea>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-danger">Confirm Decline</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

  <!-- Right-Side Drawer: Edit VA Free / PTO Days -->
  <div id="drawer-edit-pto-balance" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Adjust VA PTO Days">
      <div class="drawer-head">
        <div>
          <h3>Adjust VA PTO &amp; Free Days</h3>
          <div class="muted" style="font-size:12px;">Updating allowance for <strong id="pto-balance-target">Virtual Assistant</strong>.</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form id="form-edit-pto-balance" data-api-form="/api/v1/pto/balances/0" data-api-method="PATCH">
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Available Free / PTO Days</label>
              <input class="input" type="number" min="0" max="365" name="pto_balance_days" required>
            </div>
            <div class="form-group">
              <label class="form-label">Cycle PTO Days Taken</label>
              <input class="input" type="number" min="0" max="365" name="pto_taken_days" required>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Days Allowance</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

<?php else: ?>
  <!-- Team Leader View -->
  <?php require __DIR__ . '/../partials/pto_calendar_card.php'; ?>

  <div class="pto-layout-grid-wide">
    <div class="card">
      <h3 style="margin-bottom:12px;">Pod PTO Request History</h3>
      <?php if (empty($requests)): ?>
        <p class="muted">No PTO requests recorded for your pod yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Staff Member</th>
                <th>Date Range</th>
                <th>Days</th>
                <th>Reason</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($requests as $req): ?>
                <tr>
                  <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                      <?php if (!empty($req['avatar_url'])): ?>
                        <img src="<?= SecurityHelper::e($req['avatar_url']) ?>" alt="<?= SecurityHelper::e($req['va_name']) ?>" class="avatar-xs">
                      <?php else: ?>
                        <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $req['va_name'], 0, 2))) ?></span>
                      <?php endif; ?>
                      <strong><?= SecurityHelper::e($req['va_name']) ?></strong>
                    </div>
                  </td>
                  <td><span class="pto-date-pill"><?= SecurityHelper::e($req['start_date']) ?> &rarr; <?= SecurityHelper::e($req['end_date']) ?></span></td>
                  <td class="tabular-nums"><?= (int) $req['business_days_count'] ?>d</td>
                  <td><?= SecurityHelper::e($req['reason']) ?></td>
                  <td>
                    <span class="badge <?= $req['status'] === 'approved' ? 'badge-success' : ($req['status'] === 'rejected' || $req['status'] === 'cancelled' ? 'badge-danger' : 'badge-warn') ?>">
                      <?= SecurityHelper::e(ucfirst($req['status'])) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin-bottom:12px;">Team Lead PTO Notification Triggers</h3>
      <?php if (empty($notifications)): ?>
        <p class="muted">No unread or recent PTO triggers for your pod.</p>
      <?php else: ?>
        <div style="display:grid;gap:10px;">
          <?php foreach ($notifications as $notif): ?>
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
              <?php if (!$notif['is_read']): ?>
                <button type="button"
                        class="btn btn-secondary btn-sm"
                        data-action-url="/api/v1/pto/notifications/<?= SecurityHelper::e($notif['id']) ?>/read"
                        data-action-method="PATCH">Mark Read</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
