<?php
$vas = $data['vas'] ?? [];
$specCats = $data['specialty_categories'] ?? [];
$tls = $data['team_leaders'] ?? [];
$vaMap = [];
foreach ($vas as $v) {
    $vaMap[$v['va_id']] = $v;
}
?>

<div class="page-header">
  <div>
    <h1>Virtual Assistant Directory</h1>
    <p class="muted">Active Virtual Assistants, specialties, SLA performance, and direct profile inspection.</p>
  </div>
  <?php if ($role === 'admin'): ?>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-dir-create-va">+ Add Virtual Assistant</button>
  <?php endif; ?>
</div>

<?php if (empty($vas)): ?>
  <div class="card"><p class="muted">No active Virtual Assistants registered yet.</p></div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px;padding:14px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
      <div class="va-dir-filter-controls">
        <input id="va-dir-search"
               type="search"
               class="input"
               placeholder="Search Virtual Assistant by name, email, or skill...">
        <select id="va-dir-spec-filter" class="select">
          <option value="">All Specialty Categories</option>
          <?php foreach ($specCats as $cat): ?>
            <option value="<?= SecurityHelper::e($cat) ?>"><?= SecurityHelper::e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="muted" style="font-size:12.5px;">
        Showing <strong id="va-dir-count"><?= count($vas) ?></strong> of <?= count($vas) ?> Virtual Assistant(s)
      </div>
    </div>
  </div>

  <div class="va-dir-list" id="va-dir-list">
    <?php foreach ($vas as $va): ?>
      <?php
        $specsArr = $va['specialties'] ?? [];
        $specsLower = strtolower(implode('||', $specsArr));
        $hay = strtolower(trim($va['va_name'] . ' ' . $va['va_email'] . ' ' . ($va['specialties_csv'] ?? '')));
      ?>
      <article class="card card-hover va-dir-row"
               data-va-search="<?= SecurityHelper::e($hay) ?>"
               data-va-specs="<?= SecurityHelper::e($specsLower) ?>">
        <div class="va-dir-identity">
          <?php if (!empty($va['avatar_url'])): ?>
            <img src="<?= SecurityHelper::e($va['avatar_url']) ?>" alt="<?= SecurityHelper::e($va['va_name']) ?>" class="avatar avatar-lg">
          <?php else: ?>
            <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $va['va_name'], 0, 2))) ?></span>
          <?php endif; ?>
          <div style="min-width:0;">
            <strong style="font-size:15px;display:block;"><?= SecurityHelper::e($va['va_name']) ?></strong>
            <div class="muted" style="font-size:12px;word-break:break-word;"><?= SecurityHelper::e($va['va_email']) ?></div>
            <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">
              <?php if (empty($specsArr)): ?>
                <span class="badge badge-neutral">General Operations</span>
              <?php else: ?>
                <?php foreach ($specsArr as $sp): ?>
                  <span class="badge badge-brand"><?= SecurityHelper::e($sp) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="va-dir-metrics">
          <div>
            <span class="va-metric-lbl">SLA Rate</span>
            <span class="badge badge-success"><?= number_format((float) $va['on_time_rate'], 1) ?>%</span>
          </div>
          <div>
            <span class="va-metric-lbl">Rate</span>
            <strong class="tabular-nums">$<?= number_format((float) $va['hourly_rate'], 2) ?>/hr</strong>
          </div>
          <div>
            <span class="va-metric-lbl">Completed</span>
            <strong class="tabular-nums"><?= (int) $va['completed_tasks'] ?></strong>
          </div>
          <div>
            <span class="va-metric-lbl">Active</span>
            <strong class="tabular-nums"><?= (int) $va['in_progress_tasks'] ?></strong>
          </div>
          <div>
            <span class="va-metric-lbl">PTO Left</span>
            <strong class="tabular-nums"><?= (int) $va['pto_balance_days'] ?>d</strong>
          </div>
        </div>

        <div class="va-dir-actions">
          <?php if ($role === 'admin' || ($role === 'team_leader' && (string) ($va['team_leader_id'] ?? '') === (string) $user['id'])): ?>
            <a href="/profile?va_id=<?= urlencode((string) $va['va_id']) ?>" class="btn btn-secondary btn-sm">View Profile</a>
          <?php endif; ?>
          <?php if ($role === 'admin'): ?>
            <button type="button" class="btn btn-primary btn-sm" data-dir-edit-va="<?= SecurityHelper::e($va['va_id']) ?>">Edit VA</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
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

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save VA Profile</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
