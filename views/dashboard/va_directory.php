<div class="page-header">
  <div>
    <h1>Virtual Assistant & Client Directory</h1>
    <p class="muted">Comprehensive visual directory of active Virtual Assistants and their contracted client capacities.</p>
  </div>
</div>

<h3 style="margin-bottom:12px;">Virtual Assistants</h3>
<?php if (empty($data['vas'])): ?>
  <div class="card" style="margin-bottom:24px;"><p class="muted">No active Virtual Assistants registered in PostgreSQL yet.</p></div>
<?php else: ?>
  <div class="grid-3" style="margin-bottom:26px;">
    <?php foreach ($data['vas'] as $va): ?>
      <div class="card card-hover">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px;">
          <?php if (!empty($va['avatar_url'])): ?>
            <img src="<?= SecurityHelper::e($va['avatar_url']) ?>" alt="Avatar" class="avatar avatar-lg">
          <?php else: ?>
            <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $va['va_name'], 0, 2))) ?></span>
          <?php endif; ?>
          <div>
            <strong style="font-size:15px;"><?= SecurityHelper::e($va['va_name']) ?></strong>
            <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($va['va_email']) ?></div>
            <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;">
              <span class="badge badge-success"><?= number_format((float) $va['on_time_rate'], 1) ?>% SLA</span>
              <span class="badge badge-neutral">$<?= number_format((float) $va['hourly_rate'], 2) ?>/hr</span>
            </div>
          </div>
        </div>

        <?php if (!empty($va['specialties_csv'])): ?>
          <div style="margin-bottom:12px;display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach (explode(', ', (string) $va['specialties_csv']) as $sp): ?>
              <span class="badge badge-brand"><?= SecurityHelper::e($sp) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ink-soft);margin-bottom:12px;">
          <span>Completed: <strong><?= (int) $va['completed_tasks'] ?></strong></span>
          <span>Active: <strong><?= (int) $va['in_progress_tasks'] ?></strong></span>
          <span>PTO Left: <strong><?= (int) $va['pto_balance_days'] ?>d</strong></span>
        </div>

        <a href="/profile?va_id=<?= urlencode((string) $va['va_id']) ?>" class="btn btn-secondary btn-sm" style="width:100%;">View Full Profile</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3 style="margin-bottom:12px;">Client Account Allocations</h3>
<?php if (empty($data['clients'])): ?>
  <div class="card"><p class="muted">No client accounts created yet.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($data['clients'] as $c): ?>
      <div class="card card-hover">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
          <strong><?= SecurityHelper::e($c['agency_name']) ?></strong>
          <span class="badge badge-brand"><?= SecurityHelper::e(ucfirst($c['contract_status'])) ?></span>
        </div>
        <div class="muted" style="font-size:12px;margin-bottom:8px;">Contact: <?= SecurityHelper::e($c['executive_contact']) ?></div>
        <div style="font-size:12.5px;margin-bottom:8px;">Assigned VA: <strong><?= SecurityHelper::e($c['focus_va_name'] ?: 'Unassigned') ?></strong></div>
        <div class="progress-track" style="margin-bottom:6px;">
          <div class="progress-fill" style="width:<?= min(100, (float) ($c['utilization_pct'] ?? 0)) ?>%;"></div>
        </div>
        <div class="muted" style="font-size:11.5px;">
          <?= number_format((float) $c['consumed_hours_month'], 1) ?> / <?= number_format((float) $c['contracted_hours_per_month'], 1) ?> hrs used
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
