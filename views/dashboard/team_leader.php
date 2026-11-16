<?php
$podVas = $data['pod_vas'] ?? [];
$podClients = $data['pod_clients'] ?? [];
$trend = $data['completion_trend'] ?? [];
?>

<div class="page-header">
  <div>
    <h1>Team Leader Operations Hub</h1>
    <p class="muted">Real-time visibility into your assigned Virtual Assistants, client capacity, and 30-day task velocity.</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="/tasks" class="btn btn-secondary">Open Kanban Board</a>
    <a href="/calendar-reports" class="btn btn-primary">Schedule Meeting & SLA Report</a>
  </div>
</div>

<div class="grid-4" style="margin-bottom:22px;">
  <div class="card card-hover">
    <div class="kpi-label">Assigned Pod VAs</div>
    <div class="kpi-value"><?= count($podVas) ?></div>
    <div class="kpi-sub">Active roster</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Managed Client Accounts</div>
    <div class="kpi-value"><?= count($podClients) ?></div>
    <div class="kpi-sub">Direct pod accounts</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Pending Task Reviews</div>
    <div class="kpi-value"><?= (int) ($alerts['review_tasks'] ?? 0) ?></div>
    <div class="kpi-sub">In Review column</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Pod PTO Alerts</div>
    <div class="kpi-value"><?= (int) ($alerts['pending_pto'] ?? 0) ?></div>
    <div class="kpi-sub">Awaiting HR review</div>
  </div>
</div>

<div class="card" style="margin-bottom:22px;">
  <h3 style="margin-bottom:6px;">30-Day Deliverable Completion Trend</h3>
  <p class="muted" style="font-size:12px;margin-bottom:16px;">PostgreSQL aggregated deliverables due vs. completed across the past 30 days.</p>

  <?php if (empty($trend)): ?>
    <p class="muted">No deliverables recorded in the past 30 days yet.</p>
  <?php else: ?>
    <div style="display:grid;gap:10px;">
      <?php foreach ($trend as $day): ?>
        <?php
          $pct = (int) $day['total_count'] > 0 ? round(((int) $day['done_count'] / (int) $day['total_count']) * 100, 1) : 0;
        ?>
        <div style="display:grid;grid-template-columns:110px 1fr 90px;align-items:center;gap:12px;">
          <span style="font-size:12px;font-weight:600;"><?= SecurityHelper::e($day['day_label']) ?></span>
          <div class="progress-track">
            <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
          </div>
          <span class="muted" style="font-size:12px;text-align:right;"><?= (int) $day['done_count'] ?> / <?= (int) $day['total_count'] ?> done</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<h3 style="margin-bottom:12px;">Assigned Virtual Assistants</h3>
<?php if (empty($podVas)): ?>
  <div class="card" style="margin-bottom:22px;">
    <p class="muted">No Virtual Assistants are assigned to your pod yet. An Administrator can link VAs to you from Governance.</p>
  </div>
<?php else: ?>
  <div class="grid-3" style="margin-bottom:22px;">
    <?php foreach ($podVas as $va): ?>
      <div class="card card-hover">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
          <?php if (!empty($va['avatar_url'])): ?>
            <img src="<?= SecurityHelper::e($va['avatar_url']) ?>" alt="Avatar" class="avatar avatar-lg">
          <?php else: ?>
            <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $va['va_name'], 0, 2))) ?></span>
          <?php endif; ?>
          <div>
            <strong><?= SecurityHelper::e($va['va_name']) ?></strong>
            <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($va['va_email']) ?></div>
            <span class="badge badge-success" style="margin-top:4px;"><?= number_format((float) $va['on_time_rate'], 1) ?>% SLA</span>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;margin-bottom:12px;">
          <div>Completed: <strong><?= (int) $va['completed_tasks'] ?></strong></div>
          <div>In Progress: <strong><?= (int) $va['in_progress_tasks'] ?></strong></div>
          <div>PTO Balance: <strong><?= (int) $va['pto_balance_days'] ?>d</strong></div>
          <div>Rate: <strong>$<?= number_format((float) $va['hourly_rate'], 2) ?>/h</strong></div>
        </div>

        <a href="/profile?va_id=<?= urlencode((string) $va['va_id']) ?>" class="btn btn-secondary btn-sm" style="width:100%;">Inspect VA Profile</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3 style="margin-bottom:12px;">Pod Client Capacity</h3>
<?php if (empty($podClients)): ?>
  <div class="card"><p class="muted">No client accounts assigned to your pod yet.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($podClients as $cl): ?>
      <?php
        $capPct = (float) $cl['contracted_hours_per_month'] > 0
            ? min(100, round(((float) $cl['consumed_hours_month'] / (float) $cl['contracted_hours_per_month']) * 100, 1))
            : 0;
      ?>
      <div class="card card-hover">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <div>
            <strong><?= SecurityHelper::e($cl['agency_name']) ?></strong>
            <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($cl['name']) ?></div>
          </div>
          <span class="badge badge-brand"><?= SecurityHelper::e(ucfirst($cl['contract_status'])) ?></span>
        </div>
        <div class="muted" style="font-size:12px;margin-bottom:8px;">Assigned VA: <?= SecurityHelper::e($cl['focus_va_name'] ?: 'Unassigned') ?></div>
        <div class="progress-track" style="margin-bottom:6px;">
          <div class="progress-fill" style="width:<?= $capPct ?>%;"></div>
        </div>
        <div class="muted" style="font-size:11.5px;"><?= number_format((float) $cl['consumed_hours_month'], 1) ?> / <?= number_format((float) $cl['contracted_hours_per_month'], 1) ?> hrs (<?= $capPct ?>%)</div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
