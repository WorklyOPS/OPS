<?php
$p = $data['profile'];
$canUploadAvatar = ($role === 'admin' || $p['id'] === $user['id']);
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:14px;">
    <?php if (!empty($p['avatar_url'])): ?>
      <img src="<?= SecurityHelper::e($p['avatar_url']) ?>" alt="Profile Avatar" class="avatar avatar-lg">
    <?php else: ?>
      <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $p['name'], 0, 2))) ?></span>
    <?php endif; ?>
    <div>
      <h1><?= SecurityHelper::e($p['name']) ?></h1>
      <p class="muted"><?= SecurityHelper::e($p['email']) ?> • Joined <?= SecurityHelper::e($p['hire_date']) ?></p>
    </div>
  </div>
</div>

<div class="grid-4" style="margin-bottom:22px;">
  <div class="card card-hover">
    <div class="kpi-label">Contract Hourly Rate</div>
    <div class="kpi-value">$<?= number_format((float) $p['hourly_rate'], 2) ?>/hr</div>
    <div class="kpi-sub">Total Recorded: $<?= number_format((float) $p['total_earnings'], 2) ?></div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Deliverables Completed</div>
    <div class="kpi-value"><?= (int) ($data['task_stats']['completed_tasks'] ?? 0) ?> / <?= (int) ($data['task_stats']['total_tasks'] ?? 0) ?></div>
    <div class="kpi-sub">PostgreSQL task telemetry</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Earned Achievements</div>
    <div class="kpi-value"><?= count($data['badges'] ?? []) ?></div>
    <div class="kpi-sub"><?= count($data['perks'] ?? []) ?> active benefit(s)</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">PTO Balance</div>
    <div class="kpi-value"><?= (int) $p['pto_balance_days'] ?> days</div>
    <div class="kpi-sub"><?= (int) $p['pto_taken_days'] ?> day(s) taken</div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:22px;">
  <div class="card">
    <h3 style="margin-bottom:10px;">Role Bio, Specialties & Responsibilities</h3>
    <p class="muted" style="margin-bottom:14px;"><?= SecurityHelper::e($p['bio'] ?: 'No biography recorded yet.') ?></p>

    <h4 style="margin-bottom:8px;">Specialties</h4>
    <?php if (empty($data['specialties'])): ?>
      <p class="muted" style="margin-bottom:14px;">None assigned.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
        <?php foreach ($data['specialties'] as $sp): ?>
          <span class="badge badge-brand"><?= SecurityHelper::e($sp) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <h4 style="margin-bottom:8px;">Core Responsibilities</h4>
    <?php if (empty($data['responsibilities'])): ?>
      <p class="muted">No specific responsibilities listed.</p>
    <?php else: ?>
      <ul style="margin:0;padding-left:18px;color:var(--ink-soft);">
        <?php foreach ($data['responsibilities'] as $resp): ?>
          <li style="margin-bottom:4px;"><?= SecurityHelper::e($resp) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-bottom:10px;">Monthly Earnings Ledger</h3>
    <?php if (empty($data['earnings'])): ?>
      <p class="muted" style="margin-bottom:16px;">No monthly payout cycles recorded yet.</p>
    <?php else: ?>
      <div class="table-wrap" style="margin-bottom:16px;">
        <table class="table">
          <thead>
            <tr>
              <th>Period</th>
              <th>Hours Billed</th>
              <th>Payout Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['earnings'] as $row): ?>
              <tr>
                <td><?= SecurityHelper::e($row['record_month']) ?> <?= (int) $row['record_year'] ?></td>
                <td><?= number_format((float) $row['hours_billed'], 1) ?>h</td>
                <td><strong>$<?= number_format((float) $row['amount'], 2) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canUploadAvatar): ?>
      <div style="padding-top:14px;border-top:1px solid var(--border);">
        <h4 style="margin-bottom:8px;">Update Profile Photo</h4>
        <form data-api-form="/api/v1/uploads/avatar" data-api-method="POST" enctype="multipart/form-data">
          <input type="hidden" name="user_id" value="<?= SecurityHelper::e($p['id']) ?>">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="flex:1;">
            <button type="submit" class="btn btn-secondary">Upload Photo</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h3 style="margin-bottom:12px;">Earned Badges</h3>
    <?php if (empty($data['badges'])): ?>
      <p class="muted">No recognition badges earned yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:10px;">
        <?php foreach ($data['badges'] as $b): ?>
          <div class="message-bubble" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong><?= SecurityHelper::e($b['title']) ?></strong>
              <span class="badge badge-brand"><?= SecurityHelper::e(ucfirst($b['tier'])) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin-top:4px;"><?= SecurityHelper::e($b['description']) ?></p>
            <div class="muted" style="font-size:11px;margin-top:6px;">Earned <?= SecurityHelper::e($b['earned_at']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-bottom:12px;">Active Company Perks</h3>
    <?php if (empty($data['perks'])): ?>
      <p class="muted">No perks currently assigned.</p>
    <?php else: ?>
      <div style="display:grid;gap:10px;">
        <?php foreach ($data['perks'] as $pk): ?>
          <div class="message-bubble" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong><?= SecurityHelper::e($pk['title']) ?></strong>
              <span class="badge badge-success">$<?= number_format((float) $pk['value'], 0) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin-top:4px;"><?= SecurityHelper::e($pk['description']) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
