<?php
require_once __DIR__ . '/../partials/icons.php';

$vas = $data['vas'] ?? [];
$specCats = $data['specialty_categories'] ?? [];
$tls = $data['team_leaders'] ?? [];
$isAdmin = ($role === 'admin');

$vaMap = [];
$totalVas = count($vas);
$activeVas = 0;
$suspendedVas = 0;
$slaSum = 0.0;
$rateSum = 0.0;
$totalCompleted = 0;
$totalTasksAll = 0;

foreach ($vas as $v) {
    $isPaused = !empty($v['is_suspended']) || empty($v['is_active']);
    if ($isPaused) {
        $suspendedVas++;
    } else {
        $activeVas++;
    }
    $slaSum += (float) ($v['on_time_rate'] ?? 0);
    $rateSum += (float) ($v['hourly_rate'] ?? 0);
    $totalCompleted += (int) ($v['completed_tasks'] ?? 0);
    $totalTasksAll += (int) ($v['total_tasks'] ?? 0);
    $vaMap[(string) $v['va_id']] = $v;
}

$avgSla = $totalVas > 0 ? round($slaSum / $totalVas, 1) : 0.0;
$avgRate = $totalVas > 0 ? round($rateSum / $totalVas, 2) : 0.0;
?>

<div class="page-header">
  <div>
    <h1>Virtual Assistant Directory</h1>
    <p class="muted">CRM-style Virtual Assistant roster with live status filtering, specialty search, SLA delivery metrics, and pod governance.</p>
  </div>
  <?php if ($isAdmin): ?>
    <div class="page-header-actions">
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-dir-create-va">+ Add Virtual Assistant</button>
    </div>
  <?php endif; ?>
</div>

<div class="grid-4" style="margin-bottom:20px;">
  <div class="card card-hover">
    <div class="kpi-label">Scoped VA Roster</div>
    <div class="kpi-value tabular-nums"><?= $totalVas ?></div>
    <div class="kpi-sub">
      <?= $activeVas ?> active<?= $isAdmin && $suspendedVas > 0 ? (' · ' . $suspendedVas . ' deactivated') : '' ?>
    </div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Average On-Time SLA</div>
    <div class="kpi-value tabular-nums"><?= number_format($avgSla, 1) ?>%</div>
    <div class="kpi-sub">Across assigned roster</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Deliverables Completed</div>
    <div class="kpi-value tabular-nums"><?= $totalCompleted ?></div>
    <div class="kpi-sub"><?= $totalTasksAll ?> total tracked tasks</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Avg Hourly Rate</div>
    <div class="kpi-value tabular-nums">$<?= number_format($avgRate, 2) ?>/hr</div>
    <div class="kpi-sub">Active payroll baseline</div>
  </div>
</div>

<section class="card crm-board-card" aria-label="Virtual Assistant Roster Directory">
  <div class="crm-toolbar">
    <div class="crm-toolbar-left">
      <div class="crm-search-wrap">
        <?= workly_icon_svg('Search', 15) ?>
        <input id="va-dir-search"
               type="search"
               class="input crm-search-input"
               autocomplete="off"
               placeholder="Search VA by name, email, phone, team leader, or skill...">
      </div>

      <div class="crm-status-tabs" role="group" aria-label="Filter by VA account status">
        <button type="button" class="crm-status-tab is-active" data-va-status-filter="all">All</button>
        <button type="button" class="crm-status-tab" data-va-status-filter="active">Active</button>
        <?php if ($isAdmin): ?>
          <button type="button" class="crm-status-tab" data-va-status-filter="suspended">Deactivated</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="crm-toolbar-right">
      <select id="va-dir-spec-filter" class="select crm-filter-select" aria-label="Filter by VA specialty">
        <option value="">All Specialties</option>
        <?php foreach ($specCats as $cat): ?>
          <option value="<?= SecurityHelper::e($cat) ?>"><?= SecurityHelper::e($cat) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="va-dir-sort-select" class="select crm-filter-select" aria-label="Sort Virtual Assistants">
        <option value="name_asc">Sort: Name (A–Z)</option>
        <option value="sla_desc">Sort: Highest SLA</option>
        <option value="tasks_desc">Sort: Tasks Completed</option>
        <option value="rate_desc">Sort: Hourly Rate</option>
      </select>

      <span id="va-dir-count" class="muted tabular-nums crm-count-label"><?= $totalVas ?> of <?= $totalVas ?></span>
      <button type="button" id="va-dir-reset-filters" class="btn btn-secondary btn-sm is-hidden">Reset</button>
    </div>
  </div>

  <?php if (empty($vas)): ?>
    <div class="crm-empty-state">
      <strong>No Virtual Assistants registered yet</strong>
      <p class="muted">Provision Virtual Assistant accounts to monitor SLA rates, specialties, and client capacity.</p>
      <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-primary btn-sm" data-open-drawer="drawer-dir-create-va">+ Add Virtual Assistant</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap crm-table-wrap">
      <table class="table crm-contacts-table" id="va-dir-table">
        <thead>
          <tr>
            <th>Virtual Assistant</th>
            <th>Team Leader &amp; Pod</th>
            <th>Status</th>
            <th>Workload &amp; SLA</th>
            <th>Rate &amp; PTO</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody id="va-dir-tbody">
          <?php foreach ($vas as $va): ?>
            <?php
              $vid = (string) $va['va_id'];
              $specsArr = $va['specialties'] ?? [];
              $specsLower = strtolower(implode('||', $specsArr));
              $isPaused = !empty($va['is_suspended']) || empty($va['is_active']);
              $statusVal = $isPaused ? 'suspended' : 'active';
              $statusLabel = $isPaused ? 'Deactivated' : 'Active';
              $statusClass = $isPaused ? 'crm-st-cancelled' : 'crm-st-active';
              $slaVal = round((float) ($va['on_time_rate'] ?? 0), 1);
              $slaPct = min(100, max(0, $slaVal));
              $completed = (int) ($va['completed_tasks'] ?? 0);
              $totalTasks = (int) ($va['total_tasks'] ?? 0);
              $inProgress = (int) ($va['in_progress_tasks'] ?? 0);
              $rateVal = (float) ($va['hourly_rate'] ?? 0);
              $ptoLeft = (int) ($va['pto_balance_days'] ?? 0);
              $tlName = trim((string) ($va['tl_name'] ?? ''));
              $canInspect = $isAdmin || ($role === 'team_leader' && (string) ($va['team_leader_id'] ?? '') === (string) $user['id']);

              $searchHay = strtolower(trim(implode(' ', [
                  (string) $va['va_name'],
                  (string) $va['va_email'],
                  (string) ($va['phone'] ?? ''),
                  $tlName,
                  (string) ($va['specialties_csv'] ?? ''),
              ])));
            ?>
            <tr class="crm-contact-row"
                data-va-row="<?= SecurityHelper::e($vid) ?>"
                data-va-search="<?= SecurityHelper::e($searchHay) ?>"
                data-va-specs="<?= SecurityHelper::e($specsLower) ?>"
                data-va-status="<?= SecurityHelper::e($statusVal) ?>"
                data-va-name="<?= SecurityHelper::e(strtolower((string) $va['va_name'])) ?>"
                data-va-sla="<?= SecurityHelper::e((string) $slaVal) ?>"
                data-va-tasks="<?= SecurityHelper::e((string) $completed) ?>"
                data-va-rate="<?= SecurityHelper::e((string) $rateVal) ?>">
              <td>
                <div class="crm-account-cell">
                  <?php if (!empty($va['avatar_url'])): ?>
                    <img src="<?= SecurityHelper::e($va['avatar_url']) ?>" alt="<?= SecurityHelper::e($va['va_name']) ?>" class="avatar">
                  <?php else: ?>
                    <span class="avatar"><?= SecurityHelper::e(strtoupper(substr((string) $va['va_name'], 0, 2))) ?></span>
                  <?php endif; ?>
                  <div class="crm-cell-stack">
                    <?php if ($isAdmin): ?>
                      <button type="button" class="crm-company-btn" data-dir-edit-va="<?= SecurityHelper::e($vid) ?>">
                        <?= SecurityHelper::e($va['va_name']) ?>
                      </button>
                    <?php elseif ($canInspect): ?>
                      <a href="/profile?va_id=<?= urlencode($vid) ?>" class="crm-company-btn" style="text-decoration:none;">
                        <?= SecurityHelper::e($va['va_name']) ?>
                      </a>
                    <?php else: ?>
                      <strong class="crm-contact-name"><?= SecurityHelper::e($va['va_name']) ?></strong>
                    <?php endif; ?>

                    <div class="muted crm-subline">
                      <span><?= SecurityHelper::e($va['va_email']) ?></span>
                      <?php if (!empty($va['phone'])): ?>
                        <span>·</span>
                        <span class="tabular-nums"><?= SecurityHelper::e($va['phone']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="muted crm-subline">
                      <?php if (empty($specsArr)): ?>
                        <span>General Operations</span>
                      <?php else: ?>
                        <span><?= SecurityHelper::e(implode(' · ', $specsArr)) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>

              <td>
                <div class="crm-cell-stack">
                  <strong class="crm-contact-name"><?= SecurityHelper::e($tlName !== '' ? $tlName : 'Unassigned Pod') ?></strong>
                  <div class="muted crm-subline">
                    <?php if (!empty($va['hire_date'])): ?>
                      <span>Joined <?= SecurityHelper::e(substr((string) $va['hire_date'], 0, 10)) ?></span>
                    <?php else: ?>
                      <span>Operations Roster</span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <td>
                <span class="crm-status-label <?= SecurityHelper::e($statusClass) ?>">
                  <span class="crm-status-dot" aria-hidden="true"></span>
                  <span><?= SecurityHelper::e($statusLabel) ?></span>
                </span>
              </td>

              <td>
                <div class="crm-retainer-cell">
                  <div class="crm-retainer-top tabular-nums">
                    <strong><?= number_format($slaVal, 1) ?>% SLA</strong>
                    <span class="muted"><?= $completed ?>/<?= $totalTasks ?> done · <?= $inProgress ?> active</span>
                  </div>
                  <div class="progress-track" style="height:5px;">
                    <div class="progress-fill" style="width:<?= $slaPct ?>%;"></div>
                  </div>
                </div>
              </td>

              <td>
                <div class="crm-cell-stack tabular-nums">
                  <strong class="crm-contact-name">$<?= number_format($rateVal, 2) ?>/hr</strong>
                  <div class="muted crm-subline"><?= $ptoLeft ?>d PTO left</div>
                </div>
              </td>

              <td style="text-align:right;white-space:nowrap;">
                <?php if ($isAdmin): ?>
                  <div class="action-dropdown" data-action-dropdown>
                    <button type="button"
                            class="btn btn-secondary btn-sm action-dropdown-trigger"
                            data-action-menu-trigger
                            aria-haspopup="true"
                            aria-expanded="false">
                      <span>Actions</span>
                      <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="action-dropdown-menu is-hidden" data-action-menu role="menu">
                      <a class="action-dropdown-item" role="menuitem" href="/profile?va_id=<?= urlencode($vid) ?>">View Profile</a>
                      <button type="button" class="action-dropdown-item" role="menuitem" data-dir-edit-va="<?= SecurityHelper::e($vid) ?>">Edit VA</button>
                      <div class="action-dropdown-divider" role="separator"></div>
                      <?php if ($isPaused): ?>
                        <button type="button"
                                class="action-dropdown-item"
                                role="menuitem"
                                data-action-url="/api/v1/users/<?= SecurityHelper::e($vid) ?>/reactivate"
                                data-action-method="PATCH">Reactivate VA</button>
                      <?php else: ?>
                        <button type="button"
                                class="action-dropdown-item"
                                role="menuitem"
                                data-action-url="/api/v1/users/<?= SecurityHelper::e($vid) ?>/suspend"
                                data-action-method="PATCH"
                                data-action-body='{"reason":"Deactivated via VA Directory"}'
                                data-action-confirm="Deactivate Virtual Assistant||Deactivate <?= SecurityHelper::e($va['va_name']) ?>? They will lose access until reactivated by an Admin.||Deactivate VA||warn">Deactivate VA</button>
                      <?php endif; ?>
                      <button type="button"
                              class="action-dropdown-item is-danger"
                              role="menuitem"
                              data-action-url="/api/v1/users/<?= SecurityHelper::e($vid) ?>"
                              data-action-method="DELETE"
                              data-action-confirm="Delete Virtual Assistant||Permanently delete <?= SecurityHelper::e($va['va_name']) ?>? All assigned user records will be removed and cannot be undone.||Delete VA||danger">Delete VA</button>
                    </div>
                  </div>
                <?php elseif ($canInspect): ?>
                  <a href="/profile?va_id=<?= urlencode($vid) ?>" class="btn btn-secondary btn-sm">View Profile</a>
                <?php else: ?>
                  <span class="muted" style="font-size:12px;">Read-only</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="va-dir-filter-empty" class="crm-empty-state is-hidden">
      <strong>No Virtual Assistants match your active filters</strong>
      <p class="muted">Adjust your search query, status filter, or specialty selection.</p>
      <button type="button" id="va-dir-empty-clear" class="btn btn-secondary btn-sm">Clear Filters</button>
    </div>
  <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
  <script type="application/json" id="va-dir-json"><?= json_encode($vaMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

  <!-- Right-Side Drawer: Admin Add Virtual Assistant -->
  <div id="drawer-dir-create-va" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Add Virtual Assistant">
      <div class="drawer-head">
        <h3>Add Virtual Assistant</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/users" data-api-method="POST">
          <input type="hidden" name="role" value="va">

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input class="input" name="name" required placeholder="VA Full Name">
            </div>
            <div class="form-group">
              <label class="form-label">Work Email</label>
              <input class="input" type="email" name="email" required placeholder="va@company.com">
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Hourly Rate ($)</label>
              <input class="input" type="number" step="0.50" min="0" name="hourly_rate" value="18.00" required>
            </div>
            <div class="form-group">
              <label class="form-label">Assign Team Leader</label>
              <select class="select" name="team_leader_id">
                <option value="">None</option>
                <?php foreach ($tls as $tl): ?>
                  <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Initial Password (min 8 chars)</label>
            <input class="input" type="password" name="password" minlength="8" required placeholder="Strong password">
          </div>

          <div class="form-group">
            <label class="form-label">VA Specialties (Select Categories)</label>
            <div class="choice-grid" data-spec-grid>
              <?php foreach ($specCats as $cat): ?>
                <label class="choice-pill">
                  <input type="checkbox" name="specialties[]" value="<?= SecurityHelper::e($cat) ?>">
                  <span><?= SecurityHelper::e($cat) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="spec-add-row">
              <input class="input" type="text" data-new-spec-input placeholder="Create new VA specialty category...">
              <button type="button" class="btn btn-secondary btn-sm" data-add-spec-btn>+ Add Category</button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Responsibilities (one per line)</label>
            <textarea class="textarea" name="responsibilities_text" rows="3" placeholder="Inbox &amp; Calendar Triage&#10;CRM Operations"></textarea>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Create Virtual Assistant</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

  <!-- Right-Side Drawer: Admin Edit Virtual Assistant -->
  <div id="drawer-dir-edit-va" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Edit Virtual Assistant">
      <div class="drawer-head">
        <h3>Edit Virtual Assistant</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <div class="photo-preview-box">
          <div id="dir-va-avatar-preview" class="photo-preview-thumb">
            <span class="avatar avatar-lg">VA</span>
          </div>
          <div class="photo-preview-meta">
            <h4>Virtual Assistant Photo</h4>
            <p>Preview current photo, remove it, or upload a new image.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <form id="form-dir-upload-avatar" data-api-form="/api/v1/uploads/avatar" data-api-method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="user_id" id="dir-va-avatar-id">
                <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="flex:1 1 180px;min-width:0;max-width:100%;padding:6px 10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Upload</button>
              </form>
              <button type="button" id="btn-dir-delete-avatar" class="btn btn-danger btn-sm">Delete Photo</button>
            </div>
          </div>
        </div>

        <form id="form-dir-edit-va" data-api-form="/api/v1/users" data-api-method="PUT">
          <input type="hidden" name="role" value="va">
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input class="input" name="name" required>
            </div>
            <div class="form-group">
              <label class="form-label">Work Email</label>
              <input class="input" type="email" name="email" required>
            </div>
          </div>

          <div class="grid-3">
            <div class="form-group">
              <label class="form-label">Hourly Rate ($)</label>
              <input class="input" type="number" step="0.50" min="0" name="hourly_rate" required>
            </div>
            <div class="form-group">
              <label class="form-label">Team Leader</label>
              <select class="select" name="team_leader_id">
                <option value="">None</option>
                <?php foreach ($tls as $tl): ?>
                  <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input class="input" name="phone" placeholder="+1 (555) 000-0000">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">VA Specialties</label>
            <div class="choice-grid" data-spec-grid>
              <?php foreach ($specCats as $cat): ?>
                <label class="choice-pill">
                  <input type="checkbox" name="specialties[]" value="<?= SecurityHelper::e($cat) ?>">
                  <span><?= SecurityHelper::e($cat) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="spec-add-row">
              <input class="input" type="text" data-new-spec-input placeholder="Create new VA specialty category...">
              <button type="button" class="btn btn-secondary btn-sm" data-add-spec-btn>+ Add Category</button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Operational Bio</label>
            <textarea class="textarea" name="bio" rows="2"></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Reset Password (optional)</label>
            <input class="input" type="password" name="password" minlength="8" placeholder="Leave blank to keep unchanged">
          </div>

          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);">
            <div id="dir-va-danger-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
              <button type="button" id="btn-dir-va-suspend" class="btn btn-secondary btn-sm" style="color:var(--warn);">Deactivate VA</button>
              <button type="button" id="btn-dir-va-reactivate" class="btn btn-secondary btn-sm is-hidden" style="color:var(--success);">Reactivate VA</button>
              <button type="button" id="btn-dir-va-delete" class="btn btn-danger btn-sm">Delete VA</button>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
              <button type="submit" class="btn btn-primary">Save VA Profile</button>
            </div>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
