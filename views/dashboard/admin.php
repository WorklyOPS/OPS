<?php
$usersMap = [];
foreach (($data['all_users'] ?? []) as $uRow) {
    $usersMap[$uRow['id']] = $uRow;
}
$sec = $data['secrets_status'] ?? [];
$specCats = $data['specialty_categories'] ?? [];
$roleCats = $data['roles'] ?? [
    ['slug' => 'va', 'label' => 'Virtual Assistant (VA)'],
    ['slug' => 'team_leader', 'label' => 'Team Leader'],
    ['slug' => 'hrm', 'label' => 'HR Manager'],
    ['slug' => 'admin', 'label' => 'System Admin'],
];
?>

<div class="page-header">
  <div>
    <h1>System Administrator Governance</h1>
    <p class="muted">Manage staff accounts, numeric IDs, role assignments, VA specialty categories, Gmail linkage, and security audit logs.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button type="button" class="btn btn-secondary" data-open-drawer="drawer-link-gmail">Link Staff Mailbox</button>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-user">+ Add Staff Account</button>
  </div>
</div>

<div class="grid-4" style="margin-bottom:22px;">
  <div class="card card-hover">
    <div class="kpi-label">Active Staff Roster</div>
    <div class="kpi-value"><?= count($data['all_users'] ?? []) ?></div>
    <div class="kpi-sub"><?= count($data['vas'] ?? []) ?> Virtual Assistants</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Active Client Accounts</div>
    <div class="kpi-value"><?= (int) ($data['client_count'] ?? 0) ?></div>
    <div class="kpi-sub">Active CRM Portfolio</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Deliverables Completed</div>
    <div class="kpi-value"><?= (int) ($data['completed_tasks'] ?? 0) ?> / <?= (int) ($data['total_tasks'] ?? 0) ?></div>
    <div class="kpi-sub">Platform-wide</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Pending PTO Queue</div>
    <div class="kpi-value"><?= (int) ($data['pending_pto'] ?? 0) ?></div>
    <div class="kpi-sub">Active Cache Keys: <?= (int) ($data['cache_entries'] ?? 0) ?></div>
  </div>
</div>

<div class="tab-strip">
  <button type="button" class="tab-btn active" data-tab-group="admin-tabs" data-tab-btn="roster">Staff Governance Roster</button>
  <button type="button" class="tab-btn" data-tab-group="admin-tabs" data-tab-btn="audit">Security &amp; Audit Logs</button>
  <button type="button" class="tab-btn" data-tab-group="admin-tabs" data-tab-btn="security">Environment &amp; OAuth Status</button>
</div>

<!-- Tab 1: Staff Governance Roster -->
<div class="card" data-tab-group="admin-tabs" data-tab-panel="roster">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <div>
      <h3>Staff Account Governance</h3>
      <p class="muted" style="font-size:12px;">Edit rates, PTO balances, Team Leader pods, profile photos, suspend/delete accounts, and export user logs.</p>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Staff Member</th>
          <th>Role</th>
          <th>Status</th>
          <th>Connected Mailbox</th>
          <th>Rate / PTO</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (($data['all_users'] ?? []) as $u): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <?php if (!empty($u['avatar_url'])): ?>
                  <img src="<?= SecurityHelper::e($u['avatar_url']) ?>" alt="Avatar" class="avatar">
                <?php else: ?>
                  <span class="avatar"><?= SecurityHelper::e(strtoupper(substr((string) $u['name'], 0, 2))) ?></span>
                <?php endif; ?>
                <div>
                  <strong><?= SecurityHelper::e($u['name']) ?> <span class="muted mono" style="font-size:11px;font-weight:500;">#<?= SecurityHelper::e($u['id']) ?></span></strong>
                  <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($u['email']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-brand"><?= SecurityHelper::e(strtoupper((string) $u['role'])) ?></span></td>
            <td>
              <?php if ($u['is_suspended'] || !$u['is_active']): ?>
                <span class="badge badge-danger">Suspended</span>
              <?php else: ?>
                <span class="badge badge-success">Active</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($u['connected_gmail'])): ?>
                <span class="badge badge-info"><?= SecurityHelper::e($u['connected_gmail']) ?></span>
              <?php else: ?>
                <span class="muted">Not linked</span>
              <?php endif; ?>
            </td>
            <td>
              $<?= number_format((float) $u['hourly_rate'], 2) ?>/hr • <?= (int) $u['pto_balance_days'] ?>d PTO
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="btn btn-secondary btn-sm" data-edit-user="<?= SecurityHelper::e($u['id']) ?>">Edit</button>
                <a class="btn btn-secondary btn-sm" href="/profile?va_id=<?= urlencode((string) $u['id']) ?>">Profile</a>
                <a class="btn btn-secondary btn-sm" href="/api/v1/logs?download=1&user_id=<?= urlencode((string) $u['id']) ?>">Log .txt</a>

                <?php if ($u['id'] !== $user['id']): ?>
                  <?php if ($u['is_suspended'] || !$u['is_active']): ?>
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            data-action-url="/api/v1/users/<?= SecurityHelper::e($u['id']) ?>/reactivate"
                            data-action-method="PATCH">Reactivate</button>
                  <?php else: ?>
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            data-action-url="/api/v1/users/<?= SecurityHelper::e($u['id']) ?>/suspend"
                            data-action-method="PATCH"
                            data-action-body='{"reason":"Administrative hold"}'>Suspend</button>
                  <?php endif; ?>

                  <button type="button"
                          class="btn btn-danger btn-sm"
                          data-action-url="/api/v1/users/<?= SecurityHelper::e($u['id']) ?>"
                          data-action-method="DELETE">Delete</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Tab 2: Audit Logs -->
<div class="card is-hidden" data-tab-group="admin-tabs" data-tab-panel="audit">
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;">
    <div>
      <h3>Cybersecurity &amp; Audit Logs</h3>
      <p class="muted" style="font-size:12px;">Workspace security audit trail with monthly filtering and .txt export.</p>
    </div>
    <a class="btn btn-secondary btn-sm" href="/api/v1/logs?download=1">Download Full .txt</a>
  </div>

  <div class="grid-3" style="margin-bottom:12px;align-items:end;">
    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label">Month</label>
      <select id="audit-month-filter" class="select">
        <option value="">All Months</option>
        <?php foreach (($data['audit_months'] ?? []) as $m): ?>
          <option value="<?= SecurityHelper::e($m) ?>"><?= SecurityHelper::e(str_replace('_', ' ', $m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="margin-bottom:0;">
      <label class="form-label">Staff Filter</label>
      <select id="audit-user-filter" class="select">
        <option value="">All Users</option>
        <?php foreach (($data['all_users'] ?? []) as $u): ?>
          <option value="<?= SecurityHelper::e($u['id']) ?>"><?= SecurityHelper::e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" id="btn-filter-audit" class="btn btn-primary">Filter Logs</button>
  </div>

  <pre id="audit-log-viewer" class="audit-pre"><?= SecurityHelper::e($data['audit_entries']['raw'] ?: 'No audit events logged yet.') ?></pre>
</div>

<!-- Tab 3: Environment & Security Controls -->
<div class="card is-hidden" data-tab-group="admin-tabs" data-tab-panel="security">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
    <div>
      <h3>Environment Keys, Workspace Metrics &amp; Cache Control</h3>
      <p class="muted" style="font-size:12px;">Live runtime configuration and workspace record metrics without exposing secret keys.</p>
    </div>
    <button type="button"
            class="btn btn-secondary btn-sm"
            data-action-url="/api/v1/logs/flush-cache"
            data-action-method="POST">Flush Cache Store (<?= (int) ($data['cache_entries'] ?? 0) ?> keys)</button>
  </div>

  <?php $dbStats = $data['db_stats'] ?? []; ?>
  <?php if (!empty($dbStats)): ?>
    <div class="grid-4" style="margin-bottom:18px;">
      <?php foreach ($dbStats as $tbl => $cnt): ?>
        <div style="padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
          <div class="muted" style="font-size:11.5px;font-weight:600;"><?= SecurityHelper::e(ucwords(str_replace('_', ' ', (string) $tbl))) ?></div>
          <div class="kpi-value" style="font-size:21px;margin-top:2px;"><?= (int) $cnt ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="display:flex;flex-direction:column;gap:12px;max-width:560px;">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
      <span>Cloudflare Turnstile (or Built-in SVG CAPTCHA fallback)</span>
      <span class="badge <?= !empty($sec['turnstile_configured']) ? 'badge-success' : 'badge-brand' ?>">
        <?= !empty($sec['turnstile_configured']) ? 'Turnstile Active' : 'SVG CAPTCHA Active' ?>
      </span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
      <span>Google Workspace OAuth Keys</span>
      <span class="badge <?= !empty($sec['google_configured']) ? 'badge-success' : 'badge-neutral' ?>">
        <?= !empty($sec['google_configured']) ? 'Configured' : 'Optional / Off' ?>
      </span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
      <span>Outbound SMTP Mailer</span>
      <span class="badge <?= !empty($sec['smtp_configured']) ? 'badge-success' : 'badge-neutral' ?>">
        <?= !empty($sec['smtp_configured']) ? 'Configured' : 'Optional / Off' ?>
      </span>
    </div>
  </div>
</div>

<script type="application/json" id="admin-users-json" data-current-user-id="<?= SecurityHelper::e($user['id']) ?>"><?= json_encode($usersMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Right-Side Drawer: Create Staff User -->
<div id="drawer-create-user" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Provision New Staff Account">
    <div class="drawer-head">
      <h3>Provision New Staff Account</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <form data-api-form="/api/v1/users" data-api-method="POST" data-role-form="create">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Full Name</label>
            <input class="input" name="name" required placeholder="Full staff name">
          </div>
          <div class="form-group">
            <label class="form-label">Work Email</label>
            <input class="input" type="email" name="email" required placeholder="staff@company.com">
          </div>
        </div>

        <div class="grid-3">
          <div class="form-group">
            <label class="form-label">Role</label>
            <select class="select" name="role" data-role-select required>
              <?php foreach ($roleCats as $rc): ?>
                <option value="<?= SecurityHelper::e($rc['slug']) ?>"><?= SecurityHelper::e($rc['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Hourly Rate ($)</label>
            <input class="input" type="number" step="0.50" min="0" name="hourly_rate" value="18.00">
          </div>
          <div class="form-group">
            <label class="form-label">Assign Team Leader (VA)</label>
            <select class="select" name="team_leader_id">
              <option value="">None</option>
              <?php foreach (($data['team_leaders'] ?? []) as $tl): ?>
                <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Initial Password (min 8 chars)</label>
          <input class="input" type="password" name="password" minlength="8" required placeholder="Assign strong password">
        </div>

        <div class="form-group va-specialties-wrap" data-va-specialties-wrap>
          <label class="form-label">VA Specialties (Multiple Choice)</label>
          <div class="choice-grid" data-spec-grid>
            <?php foreach ($specCats as $cat): ?>
              <label class="choice-pill">
                <input type="checkbox" name="specialties[]" value="<?= SecurityHelper::e($cat) ?>">
                <span><?= SecurityHelper::e($cat) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="spec-add-row">
            <input class="input" type="text" data-new-spec-input placeholder="New VA specialty category...">
            <button type="button" class="btn btn-secondary btn-sm" data-add-spec-btn>+ Add Category</button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Responsibilities (one per line)</label>
          <textarea class="textarea" name="responsibilities_text" placeholder="Daily SLA reporting&#10;Client calendar triage"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-primary">Create Account</button>
        </div>
      </form>
    </div>
  </aside>
</div>

<!-- Right-Side Drawer: Edit Staff User (with Photo Preview/Delete + Specialties + Suspend/Delete) -->
<div id="drawer-edit-user" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Edit Staff Account">
    <div class="drawer-head">
      <h3>Edit Staff Account</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <div class="photo-preview-box">
        <div id="edit-user-avatar-preview" class="photo-preview-thumb">
          <span class="avatar avatar-lg">US</span>
        </div>
        <div class="photo-preview-meta">
          <h4>Staff Profile Photo</h4>
          <p>Preview current photo, remove it, or upload a new JPG/PNG/WEBP file.</p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <form id="form-edit-user-avatar" data-api-form="/api/v1/uploads/avatar" data-api-method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <input type="hidden" name="user_id" id="edit-user-avatar-id">
              <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="max-width:215px;padding:6px 10px;">
              <button type="submit" class="btn btn-secondary btn-sm">Upload Photo</button>
            </form>
            <button type="button" id="btn-delete-user-avatar" class="btn btn-danger btn-sm">Delete Photo</button>
          </div>
        </div>
      </div>

      <form id="form-edit-user" data-api-form="/api/v1/users" data-api-method="PUT" data-role-form="edit">
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
            <label class="form-label">Role</label>
            <select class="select" name="role" data-role-select required>
              <?php foreach ($roleCats as $rc): ?>
                <option value="<?= SecurityHelper::e($rc['slug']) ?>"><?= SecurityHelper::e($rc['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Hourly Rate ($)</label>
            <input class="input" type="number" step="0.50" min="0" name="hourly_rate" required>
          </div>
          <div class="form-group">
            <label class="form-label">PTO Balance (Days)</label>
            <input class="input" type="number" step="1" min="0" name="pto_balance_days" required>
          </div>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Team Leader Pod Assignment</label>
            <select class="select" name="team_leader_id">
              <option value="">None</option>
              <?php foreach (($data['team_leaders'] ?? []) as $tl): ?>
                <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input class="input" name="phone" placeholder="+1 (555) 000-0000">
          </div>
        </div>

        <div class="form-group va-specialties-wrap" data-va-specialties-wrap>
          <label class="form-label">VA Specialties (Multiple Choice)</label>
          <div class="choice-grid" data-spec-grid>
            <?php foreach ($specCats as $cat): ?>
              <label class="choice-pill">
                <input type="checkbox" name="specialties[]" value="<?= SecurityHelper::e($cat) ?>">
                <span><?= SecurityHelper::e($cat) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="spec-add-row">
            <input class="input" type="text" data-new-spec-input placeholder="New VA specialty category...">
            <button type="button" class="btn btn-secondary btn-sm" data-add-spec-btn>+ Add Category</button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Bio / Role Notes</label>
          <textarea class="textarea" name="bio" rows="2" placeholder="Operational summary..."></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Reset Password (leave blank to keep unchanged)</label>
          <input class="input" type="password" name="password" minlength="8" placeholder="Optional new password">
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);">
          <div id="edit-user-danger-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" id="btn-edit-user-suspend" class="btn btn-secondary btn-sm">Suspend Account</button>
            <button type="button" id="btn-edit-user-reactivate" class="btn btn-secondary btn-sm is-hidden">Reactivate Account</button>
            <button type="button" id="btn-edit-user-delete" class="btn btn-danger btn-sm">Delete Account</button>
          </div>
          <div style="display:flex;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Update Account</button>
          </div>
        </div>
      </form>
    </div>
  </aside>
</div>

<!-- Right-Side Drawer: Admin Link Gmail / Workspace Mailbox -->
<div id="drawer-link-gmail" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Link Staff Mailbox">
    <div class="drawer-head">
      <h3>Link Staff Email / Google Workspace</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <form data-api-form="/api/v1/email/admin-link-gmail" data-api-method="POST">
        <div class="form-group">
          <label class="form-label">Staff Member</label>
          <select class="select" name="user_id" required>
            <?php foreach (($data['all_users'] ?? []) as $u): ?>
              <option value="<?= SecurityHelper::e($u['id']) ?>"><?= SecurityHelper::e($u['name']) ?> (<?= SecurityHelper::e($u['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">External Email Address</label>
          <input class="input" type="email" name="gmail_email" required placeholder="name@workspace-domain.com">
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Provider</label>
            <select class="select" name="provider">
              <option value="gmail">Gmail</option>
              <option value="google_workspace">Google Workspace</option>
              <option value="outlook">Outlook / M365</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Sync Status</label>
            <select class="select" name="status">
              <option value="active">Active</option>
              <option value="needs_auth">Needs Auth</option>
            </select>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-primary">Save Mailbox Linkage</button>
        </div>
      </form>
    </div>
  </aside>
</div>
