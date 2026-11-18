<?php
require_once __DIR__ . '/../partials/icons.php';

$p = $data['profile'];
$canManageProfile = ($role === 'admin') || ($role === 'team_leader' && $p['id'] === $user['id']);
$canManagePod = ($role === 'team_leader' && $p['id'] === $user['id']);

$earnings = $data['earnings'] ?? [];
$chartEarnings = array_reverse($earnings);
$maxEarn = 1.0;
foreach ($chartEarnings as $er) {
    $maxEarn = max($maxEarn, (float) $er['amount']);
}

$dailyActivity = $data['daily_task_activity'] ?? [];
$maxDaily = 1;
foreach ($dailyActivity as $da) {
    $maxDaily = max($maxDaily, (int) $da['created'], (int) $da['updated']);
}

$specCats = $data['specialty_categories'] ?? [];
$assignedSpecs = $data['specialties'] ?? [];
$allStaff = $data['all_staff'] ?? [];
$allVas = $data['all_vas'] ?? [];
$tls = $data['team_leaders'] ?? [];
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;min-width:0;">
    <?php if (!empty($p['avatar_url'])): ?>
      <img src="<?= SecurityHelper::e($p['avatar_url']) ?>" alt="Profile Avatar" class="avatar avatar-lg">
    <?php else: ?>
      <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $p['name'], 0, 2))) ?></span>
    <?php endif; ?>
    <div style="min-width:0;">
      <h1><?= SecurityHelper::e($p['name']) ?></h1>
      <p class="muted"><?= SecurityHelper::e(strtoupper((string) $p['role'])) ?> • <?= SecurityHelper::e($p['email']) ?> • Joined <?= SecurityHelper::e($p['hire_date']) ?><?= !empty($p['phone']) ? ' • ' . SecurityHelper::e($p['phone']) : '' ?></p>
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;max-width:100%;">
    <?php if ($role === 'admin' && !empty($allStaff) && count($allStaff) > 1): ?>
      <form method="GET" action="/profile" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;max-width:100%;">
        <select name="va_id" class="select va-inspect-select">
          <?php foreach ($allStaff as $st): ?>
            <option value="<?= SecurityHelper::e($st['id']) ?>"<?= $st['id'] === $p['id'] ? ' selected' : '' ?>>
              <?= SecurityHelper::e($st['name']) ?> (<?= SecurityHelper::e(strtoupper((string) $st['role'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Inspect</button>
      </form>
    <?php endif; ?>

    <?php if ($canManagePod): ?>
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-tl-profile-pod">Manage Pod VAs</button>
    <?php endif; ?>

    <?php if ($canManageProfile): ?>
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-edit-profile">Edit Profile &amp; Photo</button>
    <?php endif; ?>
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
    <div class="kpi-sub">Active delivery tracking</div>
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

<?php if (in_array($p['role'], ['va', 'team_leader'], true) && !empty($dailyActivity)): ?>
  <section class="card" style="margin-bottom:22px;" aria-label="Daily Tasks Created and Updated">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
      <div>
        <h3>Daily Tasks Created &amp; Updated (Last 14 Days)</h3>
        <p class="muted" style="font-size:12px;">Day-by-day task creation and update activity for <?= SecurityHelper::e($p['name']) ?>.</p>
      </div>
      <div style="display:flex;gap:14px;font-size:12px;font-weight:600;">
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:#8822CE;"></span>Created</span>
        <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:3px;background:#2563EB;"></span>Updated</span>
      </div>
    </div>
    <?php
      $slot = 54;
      $svgW = max(720, count($dailyActivity) * $slot + 24);
      $svgH = 190;
      $plotH = 130;
      $baseY = 148;
    ?>
    <div class="svg-chart-wrap">
      <svg width="100%" height="<?= $svgH ?>" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Daily Created and Updated Tasks Chart">
        <line x1="16" y1="<?= $baseY ?>" x2="<?= $svgW - 16 ?>" y2="<?= $baseY ?>" stroke="#D4C8EA" stroke-width="1.2"/>
        <?php foreach ($dailyActivity as $idx => $da): ?>
          <?php
            $cCnt = (int) $da['created'];
            $uCnt = (int) $da['updated'];
            $cH = $cCnt > 0 ? max(8, (int) round(($cCnt / $maxDaily) * $plotH)) : 3;
            $uH = $uCnt > 0 ? max(8, (int) round(($uCnt / $maxDaily) * $plotH)) : 3;
            $gx = 24 + ($idx * $slot);
            $tip = $da['label'] . ': ' . $cCnt . ' created, ' . $uCnt . ' updated';
          ?>
          <g>
            <rect class="chart-bar-rect" x="<?= $gx ?>" y="<?= $baseY - $cH ?>" width="14" height="<?= $cH ?>" rx="4" fill="#8822CE" data-chart-tip="<?= SecurityHelper::e($tip) ?>"/>
            <rect class="chart-bar-rect" x="<?= $gx + 17 ?>" y="<?= $baseY - $uH ?>" width="14" height="<?= $uH ?>" rx="4" fill="#2563EB" data-chart-tip="<?= SecurityHelper::e($tip) ?>"/>
            <?php if ($cCnt > 0): ?>
              <text x="<?= $gx + 7 ?>" y="<?= $baseY - $cH - 4 ?>" text-anchor="middle" font-size="9.5" font-weight="700" fill="#6B21A8"><?= $cCnt ?></text>
            <?php endif; ?>
            <?php if ($uCnt > 0): ?>
              <text x="<?= $gx + 24 ?>" y="<?= $baseY - $uH - 4 ?>" text-anchor="middle" font-size="9.5" font-weight="700" fill="#1D4ED8"><?= $uCnt ?></text>
            <?php endif; ?>
            <text x="<?= $gx + 15 ?>" y="<?= $baseY + 18 ?>" text-anchor="middle" font-size="10" fill="#64748B"><?= SecurityHelper::e($da['label']) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>
    </div>
  </section>
<?php endif; ?>

<div class="grid-2" style="margin-bottom:22px;">
  <div class="card">
    <h3 style="margin-bottom:10px;">Role Bio, Specialties &amp; Responsibilities</h3>
    <p class="muted" style="margin-bottom:14px;"><?= SecurityHelper::e($p['bio'] ?: 'No biography recorded yet.') ?></p>

    <?php if ($p['role'] === 'va'): ?>
      <h4 style="margin-bottom:8px;">VA Specialties</h4>
      <?php if (empty($assignedSpecs)): ?>
        <p class="muted" style="margin-bottom:14px;">None assigned.</p>
      <?php else: ?>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
          <?php foreach ($assignedSpecs as $sp): ?>
            <span class="badge badge-brand"><?= SecurityHelper::e($sp) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
    <h3 style="margin-bottom:10px;">Monthly Compensation &amp; Earnings Ledger</h3>
    <?php if (empty($earnings)): ?>
      <p class="muted">No monthly payout cycles recorded yet.</p>
    <?php else: ?>
      <?php
        $slot = 48;
        $svgW = max(360, count($chartEarnings) * $slot + 24);
        $svgH = 155;
        $maxBarH = 105;
      ?>
      <div class="svg-chart-wrap" style="margin-bottom:14px;">
        <svg width="<?= $svgW ?>" height="<?= $svgH ?>" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" role="img" aria-label="Monthly Compensation Chart">
          <line x1="10" y1="<?= $maxBarH + 18 ?>" x2="<?= $svgW - 10 ?>" y2="<?= $maxBarH + 18 ?>" stroke="#E8E1F2" stroke-width="1"/>
          <?php foreach ($chartEarnings as $i => $row): ?>
            <?php
              $amt = (float) $row['amount'];
              $barH = $amt > 0 ? max(8, (int) round(($amt / $maxEarn) * $maxBarH)) : 4;
              $x = 18 + ($i * $slot);
              $y = ($maxBarH + 18) - $barH;
              $lbl = substr((string) $row['record_month'], 0, 3);
            ?>
            <g>
              <rect class="chart-bar-rect" x="<?= $x ?>" y="<?= $y ?>" width="28" height="<?= $barH ?>" rx="6" fill="#AE09E9" data-chart-tip="<?= SecurityHelper::e($row['record_month'] . ' ' . $row['record_year'] . ': $' . number_format($amt, 2)) ?>"/>
              <text x="<?= $x + 14 ?>" y="<?= $y - 4 ?>" text-anchor="middle" font-size="9.5" font-weight="700" fill="#475569">$<?= number_format($amt, 0) ?></text>
              <text x="<?= $x + 14 ?>" y="<?= $svgH - 10 ?>" text-anchor="middle" font-size="10" fill="#64748B"><?= SecurityHelper::e($lbl) ?></text>
            </g>
          <?php endforeach; ?>
        </svg>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Period</th>
              <th>Hours Billed</th>
              <th>Payout Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($earnings as $row): ?>
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
          <?php $tierSlug = strtolower(preg_replace('/[^a-z]/', '', (string) ($b['tier'] ?? 'silver')) ?: 'silver'); ?>
          <div class="message-bubble" style="margin-bottom:0;display:flex;gap:12px;align-items:flex-start;">
            <span class="tier-icon-wrap tier-<?= SecurityHelper::e($tierSlug) ?>">
              <?= workly_icon_svg((string) ($b['icon_name'] ?? 'Medal'), 18) ?>
            </span>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong><?= SecurityHelper::e($b['title']) ?></strong>
                <span class="badge tier-<?= SecurityHelper::e($tierSlug) ?>"><?= SecurityHelper::e(ucfirst((string) $b['tier'])) ?></span>
              </div>
              <p class="muted" style="font-size:12.5px;margin-top:4px;"><?= SecurityHelper::e($b['description']) ?></p>
              <div class="muted" style="font-size:11px;margin-top:6px;">Earned <?= SecurityHelper::e($b['earned_at']) ?></div>
            </div>
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
          <div class="message-bubble" style="margin-bottom:0;display:flex;gap:12px;align-items:flex-start;">
            <span class="tier-icon-wrap tier-gold">
              <?= workly_icon_svg((string) ($pk['icon_name'] ?? 'Award'), 18) ?>
            </span>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong><?= SecurityHelper::e($pk['title']) ?></strong>
                <span class="badge badge-success">$<?= number_format((float) $pk['value'], 0) ?></span>
              </div>
              <p class="muted" style="font-size:12.5px;margin-top:4px;"><?= SecurityHelper::e($pk['description']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canManageProfile): ?>
  <!-- Right-Side Drawer: Admin / Team Leader Profile + Photo Editor -->
  <div id="drawer-edit-profile" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Edit Staff Profile">
      <div class="drawer-head">
        <div>
          <h3>Edit Staff Profile</h3>
          <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($p['name']) ?> (<?= SecurityHelper::e(strtoupper((string) $p['role'])) ?>)</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <div class="photo-preview-box">
          <div class="photo-preview-thumb">
            <?php if (!empty($p['avatar_url'])): ?>
              <img src="<?= SecurityHelper::e($p['avatar_url']) ?>" alt="Current Avatar">
            <?php else: ?>
              <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $p['name'], 0, 2))) ?></span>
            <?php endif; ?>
          </div>
          <div class="photo-preview-meta">
            <h4>Profile Photo</h4>
            <p>Replace or delete the profile photo stored for this staff member.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <form data-api-form="/api/v1/uploads/avatar" data-api-method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%;">
                <input type="hidden" name="user_id" value="<?= SecurityHelper::e($p['id']) ?>">
                <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="flex:1 1 180px;min-width:0;max-width:100%;padding:6px 10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Upload Photo</button>
              </form>
              <?php if (!empty($p['avatar_url'])): ?>
                <button type="button"
                        class="btn btn-danger btn-sm"
                        data-action-url="/api/v1/uploads/avatar"
                        data-action-method="DELETE"
                        data-action-body='<?= SecurityHelper::e(json_encode(['user_id' => $p['id']], JSON_UNESCAPED_UNICODE)) ?>'>Delete Photo</button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <form data-api-form="/api/v1/users/<?= SecurityHelper::e($p['id']) ?>" data-api-method="PUT">
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input class="input" name="name" required value="<?= SecurityHelper::e($p['name']) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Work Phone / WhatsApp</label>
              <input class="input" name="phone" value="<?= SecurityHelper::e((string) ($p['phone'] ?? '')) ?>" placeholder="+1 (555) 000-0000">
            </div>
          </div>

          <?php if ($role === 'admin'): ?>
            <div class="grid-3">
              <div class="form-group">
                <label class="form-label">Hourly Rate ($)</label>
                <input class="input" type="number" step="0.50" min="0" name="hourly_rate" value="<?= number_format((float) $p['hourly_rate'], 2, '.', '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">PTO Balance (Days)</label>
                <input class="input" type="number" step="1" min="0" name="pto_balance_days" value="<?= (int) $p['pto_balance_days'] ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Team Leader</label>
                <select class="select" name="team_leader_id">
                  <option value="">None</option>
                  <?php foreach ($tls as $tl): ?>
                    <option value="<?= SecurityHelper::e($tl['id']) ?>"<?= ($p['team_leader_id'] ?? '') === $tl['id'] ? ' selected' : '' ?>><?= SecurityHelper::e($tl['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php elseif ($p['role'] === 'va'): ?>
            <div class="form-group">
              <label class="form-label">Team Leader Pod Assignment</label>
              <select class="select" name="team_leader_id">
                <option value="">Unassigned</option>
                <?php foreach ($tls as $tl): ?>
                  <option value="<?= SecurityHelper::e($tl['id']) ?>"<?= ($p['team_leader_id'] ?? '') === $tl['id'] ? ' selected' : '' ?>><?= SecurityHelper::e($tl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <?php if ($p['role'] === 'va'): ?>
            <div class="form-group">
              <label class="form-label">VA Specialties</label>
              <div class="choice-grid">
                <?php foreach ($specCats as $cat): ?>
                  <label class="choice-pill">
                    <input type="checkbox" name="specialties[]" value="<?= SecurityHelper::e($cat) ?>"<?= in_array($cat, $assignedSpecs, true) ? ' checked' : '' ?>>
                    <span><?= SecurityHelper::e($cat) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label">Operational Bio</label>
            <textarea class="textarea" name="bio" rows="3" placeholder="Workflows, tools, and time zone coverage..."><?= SecurityHelper::e((string) ($p['bio'] ?? '')) ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Core Responsibilities (one per line)</label>
            <textarea class="textarea" name="responsibilities_text" rows="3"><?= SecurityHelper::e(implode("\n", $data['responsibilities'] ?? [])) ?></textarea>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Profile Changes</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>

<?php if ($canManagePod): ?>
  <div id="drawer-tl-profile-pod" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Manage Pod VAs">
      <div class="drawer-head">
        <h3>Manage Pod Virtual Assistants</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/users/pod-vas" data-api-method="PATCH">
          <input type="hidden" name="team_leader_id" value="<?= SecurityHelper::e($user['id']) ?>">
          <div class="form-group">
            <label class="form-label">Select VAs Assigned to Your Pod</label>
            <div class="choice-grid">
              <?php foreach ($allVas as $av): ?>
                <?php $inPod = ($av['team_leader_id'] === $user['id']); ?>
                <label class="choice-pill">
                  <input type="checkbox" name="va_ids[]" value="<?= SecurityHelper::e($av['id']) ?>"<?= $inPod ? ' checked' : '' ?>>
                  <span><?= SecurityHelper::e($av['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Pod Assignment</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
