<?php
require_once __DIR__ . '/../partials/icons.php';

$clients = $data['clients'] ?? [];
$associatedVas = $data['associated_vas'] ?? [];
$commLogs = $data['comm_logs'] ?? [];
$canEditClients = in_array($role, ['admin', 'team_leader', 'hrm'], true);
$canCreateClients = in_array($role, ['admin', 'team_leader'], true);
$totalClients = count($clients);
$activeClients = 0;
$totalContractedHrs = 0.0;
$totalConsumedHrs = 0.0;
$clientsMap = [];

foreach ($clients as $cl) {
    if ($cl['contract_status'] === 'active') {
        $activeClients++;
    }
    $totalContractedHrs += (float) $cl['contracted_hours_per_month'];
    $totalConsumedHrs += (float) $cl['consumed_hours_month'];
    $slots = [];
    if (!empty($cl['availability_slots'])) {
        $decodedSlots = json_decode((string) $cl['availability_slots'], true);
        if (is_array($decodedSlots)) {
            $slots = $decodedSlots;
        }
    }
    $cl['availability_slots_list'] = $slots;
    $clientsMap[$cl['id']] = $cl;
}
$portfolioUtil = $totalContractedHrs > 0 ? round(($totalConsumedHrs / $totalContractedHrs) * 100, 1) : 0.0;
?>

<div class="page-header">
  <div>
    <h1>Client CRM &amp; Contracts</h1>
    <p class="muted">Active executive accounts, associated Virtual Assistants, communication history logs, and interactive dossiers.</p>
  </div>
  <?php if ($canCreateClients): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-bulk-clients">Bulk CSV Import</button>
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-client">+ Add Client Account</button>
    </div>
  <?php endif; ?>
</div>

<div class="grid-4" style="margin-bottom:20px;">
  <div class="card card-hover">
    <div class="kpi-label">Scoped Client Roster</div>
    <div class="kpi-value"><?= $totalClients ?></div>
    <div class="kpi-sub"><?= $activeClients ?> active contract(s)</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Contracted Capacity</div>
    <div class="kpi-value"><?= number_format($totalContractedHrs, 0) ?>h</div>
    <div class="kpi-sub">Monthly retainer hours</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Consumed Hours</div>
    <div class="kpi-value"><?= number_format($totalConsumedHrs, 1) ?>h</div>
    <div class="kpi-sub">Logged deliverables</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Portfolio Utilization</div>
    <div class="kpi-value"><?= $portfolioUtil ?>%</div>
    <div class="kpi-sub">Retainer capacity used</div>
  </div>
</div>

<div class="crm-shell">
  <aside class="crm-sidebar card" aria-label="CRM Navigation">
    <div class="kpi-label" style="margin-bottom:8px;">CRM Workspace</div>
    <button type="button" class="crm-nav-btn active" data-crm-tab="directory">
      <span style="display:inline-flex;align-items:center;gap:8px;">
        <?= workly_icon_svg('Shield', 15) ?>
        <span>Clients Directory</span>
      </span>
      <span class="badge badge-brand tabular-nums"><?= $totalClients ?></span>
    </button>
    <button type="button" class="crm-nav-btn" data-crm-tab="vas">
      <span style="display:inline-flex;align-items:center;gap:8px;">
        <?= workly_icon_svg('Award', 15) ?>
        <span>Associated VAs</span>
      </span>
      <span class="badge badge-neutral tabular-nums"><?= count($associatedVas) ?></span>
    </button>
    <button type="button" class="crm-nav-btn" data-crm-tab="comms">
      <span style="display:inline-flex;align-items:center;gap:8px;">
        <?= workly_icon_svg('Message', 15) ?>
        <span>Communication Logs</span>
      </span>
      <span class="badge badge-neutral tabular-nums"><?= count($commLogs) ?></span>
    </button>
  </aside>

  <div class="crm-main">
    <!-- Panel 1: Clients Directory -->
    <section data-crm-panel="directory">
      <div class="card" style="margin-bottom:16px;padding:14px;">
        <form method="GET" action="/clients" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <input id="client-live-search"
                 class="input"
                 type="search"
                 name="q"
                 autocomplete="off"
                 value="<?= SecurityHelper::e($data['search'] ?? '') ?>"
                 placeholder="Type 2+ letters to filter by agency, contact, or email..."
                 style="flex:1 1 220px;min-width:0;max-width:420px;">
          <button type="submit" class="btn btn-secondary">Search Clients</button>
          <?php if (!empty($data['search'])): ?>
            <a href="/clients" class="btn btn-secondary">Reset</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (empty($clients)): ?>
        <div class="card">
          <p class="muted">No client accounts found for your scope.</p>
        </div>
      <?php else: ?>
        <div class="grid-2" id="clients-cards-grid">
          <?php foreach ($clients as $cl): ?>
            <?php
              $pct = (float) $cl['contracted_hours_per_month'] > 0
                  ? min(100, round(((float) $cl['consumed_hours_month'] / (float) $cl['contracted_hours_per_month']) * 100, 1))
                  : 0;
              $acts = $data['activities'][$cl['id']] ?? [];
              $searchHay = trim(implode(' ', [
                  (string) $cl['agency_name'],
                  (string) $cl['name'],
                  (string) $cl['email'],
                  (string) ($cl['additional_email'] ?? ''),
              ]));
            ?>
            <article class="card card-hover client-card-item" data-client-search="<?= SecurityHelper::e($searchHay) ?>">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
                <div style="display:flex;align-items:center;gap:11px;min-width:0;">
                  <?php if (!empty($cl['avatar_url'])): ?>
                    <img src="<?= SecurityHelper::e($cl['avatar_url']) ?>" alt="<?= SecurityHelper::e($cl['agency_name']) ?>" class="avatar">
                  <?php else: ?>
                    <span class="avatar"><?= SecurityHelper::e(strtoupper(substr((string) $cl['agency_name'], 0, 2))) ?></span>
                  <?php endif; ?>
                  <div style="min-width:0;">
                    <h3 style="font-size:17px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= SecurityHelper::e($cl['agency_name']) ?></h3>
                    <div class="muted" style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?= SecurityHelper::e($cl['name']) ?> • <?= SecurityHelper::e($cl['email']) ?>
                    </div>
                  </div>
                </div>
                <span class="badge <?= $cl['contract_status'] === 'active' ? 'badge-success' : ($cl['contract_status'] === 'pending' ? 'badge-warn' : 'badge-danger') ?>">
                  <?= SecurityHelper::e(ucfirst($cl['contract_status'])) ?>
                </span>
              </div>

              <div class="crm-card-meta-grid">
                <div>Assigned VA: <strong><?= SecurityHelper::e($cl['focus_va_name'] ?: 'Unassigned') ?></strong></div>
                <div>Team Leader: <strong><?= SecurityHelper::e($cl['tl_name'] ?: 'Unassigned') ?></strong></div>
                <div>Location: <?= SecurityHelper::e(trim(($cl['state'] ? $cl['state'] . ', ' : '') . $cl['country'])) ?></div>
                <div>Signed: <?= SecurityHelper::e($cl['contract_signed_date']) ?></div>
              </div>

              <div style="margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
                  <span class="muted">Monthly Utilization</span>
                  <strong><?= number_format((float) $cl['consumed_hours_month'], 1) ?> / <?= number_format((float) $cl['contracted_hours_per_month'], 1) ?> hrs (<?= $pct ?>%)</strong>
                </div>
                <div class="progress-track">
                  <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
                </div>
              </div>

              <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding-top:12px;border-top:1px solid var(--border);">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <button type="button" class="btn btn-primary btn-sm" data-open-client="<?= SecurityHelper::e($cl['id']) ?>">
                    <?= $canEditClients ? 'Open Dossier & Edit' : 'View Dossier' ?>
                  </button>
                  <?php if (!empty($cl['contract_pdf_url'])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cl['contract_pdf_url'])) ?>" target="_blank" rel="noopener">Signed PDF</a>
                  <?php endif; ?>
                  <?php if (!empty($cl['website'])): ?>
                    <a class="btn btn-secondary btn-sm" href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cl['website'])) ?>" target="_blank" rel="noopener">Website</a>
                  <?php endif; ?>
                </div>

                <div style="display:flex;gap:8px;align-items:center;">
                  <span class="badge badge-neutral"><?= count($acts) ?> log(s)</span>
                  <?php if ($role === 'admin'): ?>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            data-action-url="/api/v1/clients/<?= SecurityHelper::e($cl['id']) ?>"
                            data-action-method="DELETE">Delete</button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Panel 2: Associated Virtual Assistants -->
    <section class="is-hidden" data-crm-panel="vas">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
          <div>
            <h3>Associated Virtual Assistants</h3>
            <p class="muted" style="font-size:12.5px;">Specialists mapped directly to the executive client accounts in your scope.</p>
          </div>
          <span class="badge badge-brand"><?= count($associatedVas) ?> mapped VA(s)</span>
        </div>

        <?php if (empty($associatedVas)): ?>
          <p class="muted">No Virtual Assistants assigned to scoped client accounts yet.</p>
        <?php else: ?>
          <div style="display:grid;gap:12px;">
            <?php foreach ($associatedVas as $av): ?>
              <article class="va-dir-row card" style="padding:14px 16px;">
                <div class="va-dir-identity">
                  <?php if (!empty($av['avatar_url'])): ?>
                    <img src="<?= SecurityHelper::e($av['avatar_url']) ?>" alt="<?= SecurityHelper::e($av['name']) ?>" class="avatar avatar-lg">
                  <?php else: ?>
                    <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $av['name'], 0, 2))) ?></span>
                  <?php endif; ?>
                  <div style="min-width:0;">
                    <strong style="font-size:15px;display:block;"><?= SecurityHelper::e($av['name']) ?></strong>
                    <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($av['email']) ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px;">
                      <?php if (!empty($av['specialties_csv'])): ?>
                        <?php foreach (explode(', ', (string) $av['specialties_csv']) as $sp): ?>
                          <span class="badge badge-brand"><?= SecurityHelper::e($sp) ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="badge badge-neutral">General Operations</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="crm-va-accounts-col">
                  <div class="muted" style="font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:5px;">
                    Assigned Accounts (<?= (int) $av['client_count'] ?> • <?= number_format((float) $av['total_retainer_hours'], 0) ?>h/mo)
                  </div>
                  <div style="display:flex;flex-wrap:wrap;gap:5px;">
                    <?php foreach ($av['clients'] as $mc): ?>
                      <button type="button" class="badge badge-info" style="border:none;cursor:pointer;" data-open-client="<?= SecurityHelper::e($mc['id']) ?>">
                        <?= SecurityHelper::e($mc['agency_name']) ?>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </div>

                <?php if ($role === 'admin' || ($role === 'team_leader' && (string) ($av['team_leader_id'] ?? '') === (string) $user['id'])): ?>
                  <div class="va-dir-actions">
                    <a href="/profile?va_id=<?= urlencode((string) $av['id']) ?>" class="btn btn-secondary btn-sm">View Profile</a>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Panel 3: Communication History Logs -->
    <section class="is-hidden" data-crm-panel="comms">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
          <div>
            <h3>Communication History Logs</h3>
            <p class="muted" style="font-size:12.5px;">Unified timeline combining client email correspondence and logged CRM dossier notes.</p>
          </div>
          <div class="crm-comm-controls">
            <input id="crm-comm-search" type="search" class="input crm-comm-filter-input" placeholder="Filter messages or contacts...">
            <select id="crm-comm-client-filter" class="select crm-comm-filter-select">
              <option value="">All Client Accounts</option>
              <?php foreach ($clients as $cl): ?>
                <option value="<?= SecurityHelper::e($cl['id']) ?>"><?= SecurityHelper::e($cl['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php if (empty($commLogs)): ?>
          <p class="muted">No communication logs or email threads matched your active accounts yet.</p>
        <?php else: ?>
          <div id="crm-comm-feed" style="display:grid;gap:10px;">
            <?php foreach ($commLogs as $log): ?>
              <?php
                $isEmail = ($log['source'] ?? '') === 'email';
                $logHay = strtolower(trim(($log['client_name'] ?? '') . ' ' . ($log['actor'] ?? '') . ' ' . ($log['subject'] ?? '') . ' ' . ($log['summary'] ?? '')));
              ?>
              <article class="crm-comm-row"
                       data-comm-client="<?= SecurityHelper::e($log['client_id']) ?>"
                       data-comm-search="<?= SecurityHelper::e($logHay) ?>">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                  <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                    <span class="badge <?= $isEmail ? 'badge-brand' : 'badge-neutral' ?>">
                      <?= $isEmail ? 'Email Thread' : SecurityHelper::e(ucfirst((string) ($log['type'] ?? 'Activity'))) ?>
                    </span>
                    <span class="badge badge-info"><?= SecurityHelper::e($log['client_name']) ?></span>
                    <strong style="font-size:13.5px;"><?= SecurityHelper::e($log['subject']) ?></strong>
                  </div>
                  <span class="muted mono" style="font-size:11.5px;"><?= SecurityHelper::e(substr((string) $log['created_at'], 0, 16)) ?></span>
                </div>
                <div style="font-size:13px;color:var(--ink-soft);margin-bottom:6px;"><?= SecurityHelper::e($log['summary']) ?></div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;">
                  <span class="muted">Actor: <strong><?= SecurityHelper::e($log['actor']) ?></strong></span>
                  <?php if ($isEmail && !empty($log['thread_id'])): ?>
                    <a href="/email?thread=<?= urlencode((string) $log['thread_id']) ?>" class="btn btn-secondary btn-sm">Open Email Thread</a>
                  <?php else: ?>
                    <button type="button" class="btn btn-secondary btn-sm" data-open-client="<?= SecurityHelper::e($log['client_id']) ?>">View Account Dossier</button>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<script type="application/json" id="clients-dossier-json" data-can-edit="<?= $canEditClients ? '1' : '0' ?>"><?= json_encode([
    'clients'    => $clientsMap,
    'activities' => $data['activities'] ?? [],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Right-Side Drawer: Client Dossier (Read-Only for VA; Full Edit + Top Photo Preview + Bottom Save for Admin/TL/HRM) -->
<div id="drawer-client-dossier" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Client Dossier">
    <div class="drawer-head">
      <div>
        <h3 id="dossier-agency">Client Dossier</h3>
        <div id="dossier-contact" class="muted" style="font-size:12px;margin-top:2px;"></div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>

    <div class="drawer-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <strong><?= $canEditClients ? 'Account Logo & Profile Configuration' : 'Read-Only Account Overview' ?></strong>
        <a id="dossier-pdf-link" class="btn btn-secondary btn-sm is-hidden" href="#" target="_blank" rel="noopener">View Signed Contract PDF</a>
      </div>

      <?php if ($canEditClients): ?>
        <div class="photo-preview-box">
          <div id="dossier-avatar-preview" class="photo-preview-thumb">
            <span class="avatar avatar-lg">CL</span>
          </div>
          <div class="photo-preview-meta">
            <h4>Client Logo / Avatar</h4>
            <p>Optional company logo (JPG, PNG, WEBP max 5MB).</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <form id="form-upload-client-avatar" data-api-form="/api/v1/uploads/client-avatar" data-api-method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="client_id" value="">
                <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="flex:1 1 180px;min-width:0;max-width:100%;padding:6px 10px;">
                <button type="submit" class="btn btn-secondary btn-sm">Upload</button>
              </form>
              <button type="button" id="btn-delete-client-avatar" class="btn btn-danger btn-sm">Delete Photo</button>
            </div>
          </div>
        </div>

        <form id="form-edit-client" data-api-form="/api/v1/clients" data-api-method="PUT" style="margin-bottom:20px;">
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Agency / Company</label>
              <input class="input" name="agency_name" required>
            </div>
            <div class="form-group">
              <label class="form-label">Executive Contact</label>
              <input class="input" name="name" required>
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Primary Email</span>
                <button type="button" class="icon-chip-btn" data-toggle-extra="edit-add-email" title="Add Secondary Email">+ Secondary Email</button>
              </label>
              <input class="input" type="email" name="email" required>
              <div id="edit-add-email-wrap" class="is-hidden" style="margin-top:8px;">
                <input class="input" type="email" name="additional_email" placeholder="secondary@agency.com">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Primary Phone</span>
                <button type="button" class="icon-chip-btn" data-toggle-extra="edit-add-phone" title="Add Secondary Phone">+ Secondary Phone</button>
              </label>
              <input class="input" name="phone" placeholder="+1 ...">
              <div id="edit-add-phone-wrap" class="is-hidden" style="margin-top:8px;">
                <input class="input" name="additional_phone" placeholder="+1 (secondary phone)">
              </div>
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Website</label>
              <input class="input" name="website" placeholder="https://agency.com">
            </div>
            <div class="form-group">
              <label class="form-label">Assigned Focus VA</label>
              <select class="select" name="focus_va_id">
                <?php foreach ($data['vas'] as $v): ?>
                  <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Contract Status</label>
            <div class="status-radio-grid">
              <label class="status-pill status-active" data-status-tone="active">
                <input type="radio" name="contract_status" value="active">
                <span class="status-dot"></span>
                <span>Active</span>
              </label>
              <label class="status-pill status-pending" data-status-tone="pending">
                <input type="radio" name="contract_status" value="pending">
                <span class="status-dot"></span>
                <span>Pending</span>
              </label>
              <label class="status-pill status-cancelled" data-status-tone="cancelled">
                <input type="radio" name="contract_status" value="cancelled">
                <span class="status-dot"></span>
                <span>Cancelled</span>
              </label>
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Contracted Hrs/Mo</label>
              <input class="input" type="number" step="1" min="1" name="contracted_hours_per_month" required>
            </div>
            <div class="form-group">
              <label class="form-label">Consumed Hrs</label>
              <input class="input" type="number" step="0.5" min="0" name="consumed_hours_month" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
              <span>Client Available Hours (Synced with Open Slot Scanner)</span>
              <button type="button" class="btn btn-secondary btn-sm" data-add-slot-btn="edit-client-slots-list">+ Add Slot</button>
            </label>
            <div id="edit-client-slots-list" data-slots-container></div>
          </div>

          <div class="form-group">
            <label class="form-label">Internal Account Notes</label>
            <textarea class="textarea" name="internal_notes" rows="3"></textarea>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:8px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Client Profile</button>
          </div>
        </form>

        <form id="form-upload-contract" data-api-form="/api/v1/uploads/contract" data-api-method="POST" enctype="multipart/form-data" style="padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:20px;">
          <input type="hidden" name="client_id" value="">
          <label class="form-label" style="display:block;margin-bottom:6px;">Upload / Replace Signed Contract (.PDF max 15MB)</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input class="input" type="file" name="contract_pdf" accept="application/pdf" required style="flex:1;padding:6px;">
            <button type="submit" class="btn btn-secondary btn-sm">Upload PDF</button>
          </div>
        </form>
      <?php else: ?>
        <div id="dossier-va-readonly" class="card" style="background:var(--bg);margin-bottom:18px;padding:14px;"></div>
      <?php endif; ?>

      <div style="border-top:1px solid var(--border);padding-top:16px;">
        <h4 style="margin-bottom:10px;">Activity Stream</h4>
        <div id="dossier-activities" style="margin-bottom:12px;"></div>
        <form id="form-client-activity" data-api-form="/api/v1/clients" data-api-method="POST" style="display:flex;gap:8px;flex-wrap:wrap;">
          <input class="input" name="description" required placeholder="Log meeting update, call summary, or renewal note..." style="flex:1 1 200px;min-width:0;">
          <button type="submit" class="btn btn-primary btn-sm">Log Activity</button>
        </form>
      </div>
    </div>
  </aside>
</div>

<?php if ($canCreateClients): ?>
  <!-- Right-Side Drawer: Create Client -->
  <div id="drawer-create-client" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Onboard Client Account">
      <div class="drawer-head">
        <h3>Onboard Client Account</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>

      <div class="drawer-body">
        <?php if (empty($data['vas'])): ?>
          <div class="notice notice-warn">Create at least one Virtual Assistant account before onboarding a client.</div>
        <?php else: ?>
          <form id="form-create-client" data-api-form="/api/v1/clients" data-api-method="POST">
            <div class="photo-preview-box">
              <div class="photo-preview-thumb">
                <span class="avatar avatar-lg">CL</span>
              </div>
              <div class="photo-preview-meta">
                <h4>Optional Client Logo / Image</h4>
                <p>Select an image (JPG, PNG, WEBP) to upload automatically when saved.</p>
                <input id="create-client-avatar-file" class="input" type="file" accept="image/jpeg,image/png,image/webp" style="max-width:100%;padding:6px 10px;">
              </div>
            </div>

            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">Agency / Company Name</label>
                <input class="input" name="agency_name" required placeholder="Client Agency LLC">
              </div>
              <div class="form-group">
                <label class="form-label">Executive Contact</label>
                <input class="input" name="name" required placeholder="Full name">
              </div>
            </div>

            <div class="grid-2">
              <div class="form-group">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                  <span>Primary Email</span>
                  <button type="button" class="icon-chip-btn" data-toggle-extra="create-add-email">+ Add Email</button>
                </label>
                <input class="input" type="email" name="email" required placeholder="executive@agency.com">
                <div id="create-add-email-wrap" class="is-hidden" style="margin-top:8px;">
                  <input class="input" type="email" name="additional_email" placeholder="secondary@agency.com">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                  <span>Primary Phone</span>
                  <button type="button" class="icon-chip-btn" data-toggle-extra="create-add-phone">+ Add Phone</button>
                </label>
                <input class="input" name="phone" placeholder="+1 ...">
                <div id="create-add-phone-wrap" class="is-hidden" style="margin-top:8px;">
                  <input class="input" name="additional_phone" placeholder="+1 (secondary phone)">
                </div>
              </div>
            </div>

            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">Website</label>
                <input class="input" name="website" placeholder="https://agency.com">
              </div>
              <div class="form-group">
                <label class="form-label">Country</label>
                <input class="input" name="country" value="United States">
              </div>
            </div>

            <div class="grid-3">
              <div class="form-group">
                <label class="form-label">Assigned VA</label>
                <select class="select" name="focus_va_id" required>
                  <?php foreach ($data['vas'] as $v): ?>
                    <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Team Leader</label>
                <select class="select" name="team_leader_id" required>
                  <?php if ($role === 'team_leader'): ?>
                    <option value="<?= SecurityHelper::e($user['id']) ?>"><?= SecurityHelper::e($user['name']) ?></option>
                  <?php else: ?>
                    <?php foreach ($data['team_leaders'] as $tl): ?>
                      <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
                    <?php endforeach; ?>
                    <?php if (empty($data['team_leaders'])): ?>
                      <option value="<?= SecurityHelper::e($user['id']) ?>"><?= SecurityHelper::e($user['name']) ?></option>
                    <?php endif; ?>
                  <?php endif; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Monthly Hours</label>
                <input class="input" type="number" step="1" min="1" name="contracted_hours_per_month" value="80">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Contract Status</label>
              <div class="status-radio-grid">
                <label class="status-pill status-active is-selected" data-status-tone="active">
                  <input type="radio" name="contract_status" value="active" checked>
                  <span class="status-dot"></span>
                  <span>Active</span>
                </label>
                <label class="status-pill status-pending" data-status-tone="pending">
                  <input type="radio" name="contract_status" value="pending">
                  <span class="status-dot"></span>
                  <span>Pending</span>
                </label>
                <label class="status-pill status-cancelled" data-status-tone="cancelled">
                  <input type="radio" name="contract_status" value="cancelled">
                  <span class="status-dot"></span>
                  <span>Cancelled</span>
                </label>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" style="display:flex;justify-content:space-between;align-items:center;">
                <span>Client Available Hours (for Open Slot Scanner)</span>
                <button type="button" class="btn btn-secondary btn-sm" data-add-slot-btn="create-client-slots-list">+ Add Slot</button>
              </label>
              <div id="create-client-slots-list" data-slots-container>
                <div class="schedule-slot-row" data-slot-row>
                  <select class="select" data-slot-day>
                    <option value="Mon">Mon</option>
                    <option value="Tue">Tue</option>
                    <option value="Wed">Wed</option>
                    <option value="Thu">Thu</option>
                    <option value="Fri">Fri</option>
                    <option value="Sat">Sat</option>
                    <option value="Sun">Sun</option>
                  </select>
                  <input class="input" type="time" data-slot-start value="09:00">
                  <input class="input" type="time" data-slot-end value="17:00">
                  <button type="button" class="btn btn-danger btn-sm" data-remove-slot>&times;</button>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label">Internal Notes</label>
              <textarea class="textarea" name="internal_notes" placeholder="SOP scope, timezone, expectations..."></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
              <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
              <button type="submit" class="btn btn-primary">Save Client</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <!-- Right-Side Drawer: 3-Step Queued Bulk CSV Client Import Wizard -->
  <div id="drawer-bulk-clients" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Bulk Client CSV Import">
      <div class="drawer-head">
        <div>
          <h3>Bulk Client CSV Import</h3>
          <div class="muted" style="font-size:12px;">Analyze CSV headers, verify column mapping, and process in batched background jobs.</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>

      <div class="drawer-body">
        <?php if (empty($data['vas'])): ?>
          <div class="notice notice-warn">Create at least one Virtual Assistant account before importing clients.</div>
        <?php else: ?>
          <div class="wizard-steps" id="bulk-wizard-steps">
            <div class="wizard-step active" data-wizard-indicator="1">1. Upload &amp; Analyze</div>
            <div class="wizard-step" data-wizard-indicator="2">2. Verify Mapping</div>
            <div class="wizard-step" data-wizard-indicator="3">3. Batch Queue</div>
          </div>

          <!-- Step 1: Upload CSV or Paste Rows -->
          <div id="bulk-step-1">
            <div class="form-group">
              <label class="form-label">Upload CSV File (.csv)</label>
              <input id="bulk-csv-file" class="input" type="file" accept=".csv,text/csv">
            </div>

            <div class="form-group">
              <label class="form-label">Or Paste CSV Rows (Comma or Semicolon delimited)</label>
              <textarea id="bulk-csv-text" class="textarea mono" rows="8" placeholder="agency_name,contact_name,email,phone,monthly_hours&#10;Northstar Logistics,Elena Vance,elena@northstar.io,+1-555-0192,80"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
              <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
              <button type="button" id="btn-bulk-analyze" class="btn btn-primary">Analyze CSV &rarr;</button>
            </div>
          </div>

          <!-- Step 2: Column Mapping Verification Form -->
          <div id="bulk-step-2" class="is-hidden">
            <div class="notice notice-info" id="bulk-analyze-summary" style="margin-bottom:14px;"></div>

            <div class="form-group">
              <label class="form-label">Default Focus VA (used when VA column is not mapped)</label>
              <select id="bulk-default-va" class="select" required>
                <?php foreach ($data['vas'] as $v): ?>
                  <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Verify Detected CSV Labels &rarr; Client Fields</label>
              <div id="bulk-mapping-grid" class="csv-map-grid"></div>
            </div>

            <div class="form-group">
              <label class="form-label">Data Preview (First 5 Rows)</label>
              <div class="table-wrap">
                <table class="table" id="bulk-preview-table"></table>
              </div>
            </div>

            <div style="display:flex;justify-content:space-between;gap:10px;margin-top:14px;">
              <button type="button" id="btn-bulk-back-1" class="btn btn-secondary">&larr; Back</button>
              <button type="button" id="btn-bulk-enqueue" class="btn btn-primary">Verify &amp; Start Worker Queue</button>
            </div>
          </div>

          <!-- Step 3: Batch Queue Worker Progress -->
          <div id="bulk-step-3" class="is-hidden">
            <div style="margin-bottom:12px;">
              <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                <strong id="bulk-worker-status">Queued batch worker running...</strong>
                <span id="bulk-worker-pct" class="tabular-nums">0%</span>
              </div>
              <div class="progress-track">
                <div class="progress-fill" id="bulk-progress-fill" style="width:0%;"></div>
              </div>
            </div>

            <div class="grid-3" style="margin-bottom:14px;">
              <div class="card" style="padding:10px 12px;">
                <div class="kpi-label">Processed</div>
                <div class="kpi-value" style="font-size:20px;" id="bulk-stat-processed">0 / 0</div>
              </div>
              <div class="card" style="padding:10px 12px;">
                <div class="kpi-label">Imported</div>
                <div class="kpi-value" style="font-size:20px;color:var(--success);" id="bulk-stat-succeeded">0</div>
              </div>
              <div class="card" style="padding:10px 12px;">
                <div class="kpi-label">Skipped / Errors</div>
                <div class="kpi-value" style="font-size:20px;color:var(--danger);" id="bulk-stat-failed">0</div>
              </div>
            </div>

            <div id="bulk-errors-list" style="max-height:180px;overflow-y:auto;margin-bottom:14px;"></div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
              <button type="button" id="btn-bulk-done" class="btn btn-primary is-hidden">Done &amp; Refresh Roster</button>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?php endif; ?>
