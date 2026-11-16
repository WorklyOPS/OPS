<div class="page-header">
  <div>
    <h1>System Administrator Governance</h1>
    <p class="muted">Manage PostgreSQL staff accounts, RBAC permissions, avatar uploads, Gmail linkage, and security audit logs.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button type="button" class="btn btn-secondary" data-open-modal="modal-link-gmail">Link Staff Mailbox</button>
    <button type="button" class="btn btn-primary" data-open-modal="modal-create-user">+ Add Staff Account</button>
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
    <div class="kpi-sub">PostgreSQL CRM</div>
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

<div class="card" style="margin-bottom:22px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
    <div>
      <h3>Staff Account Governance</h3>
      <p class="muted" style="font-size:12px;">Suspend or remove accounts, upload VA profile avatars, and export per-user activity logs.</p>
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
                  <strong><?= SecurityHelper::e($u['name']) ?></strong>
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

<div class="grid-2">
  <div class="card">
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;">
      <div>
        <h3>Cybersecurity & Audit Logs</h3>
        <p class="muted" style="font-size:12px;">PostgreSQL audit trail with monthly filtering and .txt download.</p>
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

  <div class="card">
    <h3>Environment Keys & Upload Tools</h3>
    <p class="muted" style="font-size:12px;margin-bottom:14px;">Live configuration status read from <code>config/.env</code> without exposing secrets.</p>

    <?php $sec = $data['secrets_status'] ?? []; ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span>Cloudflare Turnstile Protection</span>
        <span class="badge <?= !empty($sec['turnstile_configured']) ? 'badge-success' : 'badge-neutral' ?>">
          <?= !empty($sec['turnstile_configured']) ? 'Configured' : 'Optional / Off' ?>
        </span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span>Google OAuth Workspace Keys</span>
        <span class="badge <?= !empty($sec['google_configured']) ? 'badge-success' : 'badge-neutral' ?>">
          <?= !empty($sec['google_configured']) ? 'Configured' : 'Optional / Off' ?>
        </span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span>Outbound SMTP Mailer</span>
        <span class="badge <?= !empty($sec['smtp_configured']) ? 'badge-success' : 'badge-neutral' ?>">
          <?= !empty($sec['smtp_configured']) ? 'Configured' : 'Optional / Off' ?>
        </span>
      </div>
    </div>

    <div style="padding-top:14px;border-top:1px solid var(--border);">
      <h4 style="margin-bottom:8px;">Upload Staff Avatar</h4>
      <form data-api-form="/api/v1/uploads/avatar" data-api-method="POST" enctype="multipart/form-data">
        <div class="form-group">
          <label class="form-label">Select Staff Account</label>
          <select name="user_id" class="select" required>
            <?php foreach (($data['all_users'] ?? []) as $u): ?>
              <option value="<?= SecurityHelper::e($u['id']) ?>"><?= SecurityHelper::e($u['name']) ?> (<?= SecurityHelper::e($u['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Image File (JPG, PNG, WEBP max 5MB)</label>
          <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
        </div>
        <button type="submit" class="btn btn-secondary">Upload Avatar</button>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Create Staff User -->
<div id="modal-create-user" class="modal-backdrop is-hidden">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Provision New Staff Account</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>

    <form data-api-form="/api/v1/users" data-api-method="POST">
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
          <select class="select" name="role" required>
            <option value="va">Virtual Assistant (VA)</option>
            <option value="team_leader">Team Leader</option>
            <option value="hrm">HR Manager</option>
            <option value="admin">System Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Hourly Rate ($)</label>
          <input class="input" type="number" step="0.50" min="0" name="hourly_rate" value="18.00">
        </div>
        <div class="form-group">
          <label class="form-label">Assign Team Leader (for VA)</label>
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

      <div class="form-group">
        <label class="form-label">VA Specialties (comma-separated)</label>
        <input class="input" name="specialties_csv" placeholder="Executive Inbox, CRM Ops, Bookkeeping">
      </div>

      <div class="form-group">
        <label class="form-label">Responsibilities (one per line)</label>
        <textarea class="textarea" name="responsibilities_text" placeholder="Daily SLA reporting&#10;Client calendar triage"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Admin Link Gmail / Workspace Mailbox -->
<div id="modal-link-gmail" class="modal-backdrop is-hidden">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Link Staff Email / Google Workspace</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>

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
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Mailbox Linkage</button>
      </div>
    </form>
  </div>
</div>
