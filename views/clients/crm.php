<?php
require_once __DIR__ . '/../partials/icons.php';

$clients = $data['clients'] ?? [];
$vas = $data['vas'] ?? [];
$canEditClients = in_array($role, ['admin', 'team_leader', 'hrm'], true);
$canCreateClients = in_array($role, ['admin', 'team_leader'], true);

$totalClients = count($clients);
$activeClients = 0;
$totalContractedHrs = 0.0;
$totalConsumedHrs = 0.0;
$clientsMap = [];
$vasMap = [];

foreach ($vas as $v) {
    $vasMap[(string) $v['id']] = $v;
}

foreach ($clients as $cl) {
    if (($cl['contract_status'] ?? 'active') === 'active') {
        $activeClients++;
    }
    $totalContractedHrs += (float) ($cl['contracted_hours_per_month'] ?? 0);
    $totalConsumedHrs += (float) ($cl['consumed_hours_month'] ?? 0);
    $slots = [];
    if (!empty($cl['availability_slots'])) {
        $decodedSlots = json_decode((string) $cl['availability_slots'], true);
        if (is_array($decodedSlots)) {
            $slots = $decodedSlots;
        }
    }
    $cl['availability_slots_list'] = $slots;
    $clientsMap[(string) $cl['id']] = $cl;
}
$portfolioUtil = $totalContractedHrs > 0 ? round(($totalConsumedHrs / $totalContractedHrs) * 100, 1) : 0.0;
?>

<div class="page-header">
  <div>
    <h1>Client CRM &amp; Contacts</h1>
    <p class="muted">Manage executive client accounts, live VA associations, retainer utilization, and per-client communication timelines.</p>
  </div>
  <?php if ($canCreateClients): ?>
    <div class="page-header-actions">
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-bulk-clients">Bulk CSV Import</button>
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-client">+ Add Client Account</button>
    </div>
  <?php endif; ?>
</div>

<div class="grid-4" style="margin-bottom:20px;">
  <div class="card card-hover">
    <div class="kpi-label">Scoped Client Roster</div>
    <div class="kpi-value tabular-nums"><?= $totalClients ?></div>
    <div class="kpi-sub"><?= $activeClients ?> active contract(s)</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Contracted Capacity</div>
    <div class="kpi-value tabular-nums"><?= number_format($totalContractedHrs, 0) ?>h</div>
    <div class="kpi-sub">Monthly retainer hours</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Consumed Hours</div>
    <div class="kpi-value tabular-nums"><?= number_format($totalConsumedHrs, 1) ?>h</div>
    <div class="kpi-sub">Logged deliverables</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Portfolio Utilization</div>
    <div class="kpi-value tabular-nums"><?= $portfolioUtil ?>%</div>
    <div class="kpi-sub">Retainer capacity used</div>
  </div>
</div>

<!-- Conventional Modern CRM Contact List Workspace -->
<section class="card crm-board-card" aria-label="Client CRM Contact List">
  <div class="crm-toolbar">
    <div class="crm-toolbar-left">
      <div class="crm-search-wrap">
        <?= workly_icon_svg('Search', 15) ?>
        <input id="crm-contact-search"
               type="search"
               class="input crm-search-input"
               autocomplete="off"
               value="<?= SecurityHelper::e($data['search'] ?? '') ?>"
               placeholder="Search company, contact, email, phone, or assigned VA...">
      </div>

      <div class="crm-status-tabs" role="group" aria-label="Filter by contract status">
        <button type="button" class="crm-status-tab is-active" data-crm-status-filter="all">All</button>
        <button type="button" class="crm-status-tab" data-crm-status-filter="active">Active</button>
        <button type="button" class="crm-status-tab" data-crm-status-filter="pending">Pending</button>
        <button type="button" class="crm-status-tab" data-crm-status-filter="cancelled">Cancelled</button>
      </div>
    </div>

    <div class="crm-toolbar-right">
      <select id="crm-va-filter" class="select crm-filter-select" aria-label="Filter by assigned Virtual Assistant">
        <option value="all">All Assigned VAs</option>
        <option value="unassigned">Unassigned Only</option>
        <?php foreach ($vas as $v): ?>
          <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="crm-sort-select" class="select crm-filter-select" aria-label="Sort contacts">
        <option value="name_asc">Sort: Company (A–Z)</option>
        <option value="util_desc">Sort: Highest Utilization</option>
        <option value="hours_desc">Sort: Retainer Hours</option>
        <option value="activity_desc">Sort: Latest Activity</option>
      </select>

      <span id="crm-visible-count" class="muted tabular-nums crm-count-label"><?= $totalClients ?> of <?= $totalClients ?></span>
      <button type="button" id="crm-reset-filters" class="btn btn-secondary btn-sm is-hidden">Reset</button>
    </div>
  </div>

  <?php if (empty($clients)): ?>
    <div class="crm-empty-state">
      <strong>No client accounts found</strong>
      <p class="muted">Onboard a new client account or import a CSV batch to start managing VA associations and retainer timelines.</p>
      <?php if ($canCreateClients): ?>
        <button type="button" class="btn btn-primary btn-sm" data-open-drawer="drawer-create-client">+ Add Client Account</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap crm-table-wrap">
      <table class="table crm-contacts-table" id="crm-contacts-table">
        <thead>
          <tr>
            <th>Client Account</th>
            <th>Primary Contact</th>
            <th>Assigned VA</th>
            <th>Status</th>
            <th>Retainer Usage</th>
            <th>Last Activity</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody id="crm-contacts-tbody">
          <?php foreach ($clients as $cl): ?>
            <?php
              $cid = (string) $cl['id'];
              $contracted = (float) ($cl['contracted_hours_per_month'] ?? 0);
              $consumed = (float) ($cl['consumed_hours_month'] ?? 0);
              $pct = $contracted > 0 ? min(100, round(($consumed / $contracted) * 100, 1)) : 0.0;
              $statusVal = (string) ($cl['contract_status'] ?? 'active');
              $statusLabel = ucfirst($statusVal);
              $statusClass = $statusVal === 'active'
                  ? 'crm-st-active'
                  : ($statusVal === 'pending' ? 'crm-st-pending' : 'crm-st-cancelled');
              $focusVaId = (string) ($cl['focus_va_id'] ?? '');
              $focusVaName = (string) ($cl['focus_va_name'] ?? '');
              $focusVaAvatar = (string) ($cl['focus_va_avatar'] ?? '');
              $vaInitials = strtoupper(substr($focusVaName !== '' ? $focusVaName : 'UA', 0, 2));
              $locText = trim((!empty($cl['state']) ? $cl['state'] . ', ' : '') . (string) ($cl['country'] ?? ''));
              $lastCommAt = (string) ($cl['last_comm_at'] ?? '');
              $lastCommPreview = (string) ($cl['last_comm_summary'] ?? '');
              $commCount = (int) ($cl['comm_count'] ?? 0);
              $openTasks = (int) ($cl['open_task_count'] ?? 0);

              $searchHay = strtolower(trim(implode(' ', [
                  (string) $cl['agency_name'],
                  (string) $cl['name'],
                  (string) $cl['email'],
                  (string) ($cl['additional_email'] ?? ''),
                  (string) ($cl['phone'] ?? ''),
                  $focusVaName,
                  (string) ($cl['tl_name'] ?? ''),
                  $locText,
              ])));
            ?>
            <tr class="crm-contact-row"
                data-client-row="<?= SecurityHelper::e($cid) ?>"
                data-search="<?= SecurityHelper::e($searchHay) ?>"
                data-status="<?= SecurityHelper::e($statusVal) ?>"
                data-va-id="<?= SecurityHelper::e($focusVaId !== '' ? $focusVaId : 'unassigned') ?>"
                data-name="<?= SecurityHelper::e(strtolower((string) $cl['agency_name'])) ?>"
                data-util="<?= SecurityHelper::e((string) $pct) ?>"
                data-hours="<?= SecurityHelper::e((string) $contracted) ?>"
                data-activity="<?= SecurityHelper::e($lastCommAt) ?>">
              <td>
                <div class="crm-account-cell">
                  <?php if (!empty($cl['avatar_url'])): ?>
                    <img src="<?= SecurityHelper::e($cl['avatar_url']) ?>" alt="<?= SecurityHelper::e($cl['agency_name']) ?>" class="avatar">
                  <?php else: ?>
                    <span class="avatar"><?= SecurityHelper::e(strtoupper(substr((string) $cl['agency_name'], 0, 2))) ?></span>
                  <?php endif; ?>
                  <div class="crm-cell-stack">
                    <button type="button" class="crm-company-btn" data-open-client="<?= SecurityHelper::e($cid) ?>">
                      <?= SecurityHelper::e($cl['agency_name']) ?>
                    </button>
                    <div class="muted crm-subline">
                      <span><?= SecurityHelper::e($locText !== '' ? $locText : 'Remote Account') ?></span>
                      <?php if ($openTasks > 0): ?>
                        <span>·</span>
                        <span class="tabular-nums"><?= $openTasks ?> open task<?= $openTasks === 1 ? '' : 's' ?></span>
                      <?php endif; ?>
                      <?php if (!empty($cl['website'])): ?>
                        <span>·</span>
                        <a href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cl['website'])) ?>" target="_blank" rel="noopener">Site</a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </td>

              <td>
                <div class="crm-cell-stack">
                  <strong class="crm-contact-name"><?= SecurityHelper::e($cl['name']) ?></strong>
                  <div class="muted crm-subline">
                    <a href="mailto:<?= SecurityHelper::e($cl['email']) ?>"><?= SecurityHelper::e($cl['email']) ?></a>
                    <?php if (!empty($cl['phone'])): ?>
                      <span>·</span>
                      <span class="tabular-nums"><?= SecurityHelper::e($cl['phone']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <td>
                <div class="crm-va-assoc-cell">
                  <span data-row-va-avatar="<?= SecurityHelper::e($cid) ?>">
                    <?php if ($focusVaAvatar !== ''): ?>
                      <img src="<?= SecurityHelper::e($focusVaAvatar) ?>" alt="<?= SecurityHelper::e($focusVaName) ?>" class="avatar-xs">
                    <?php else: ?>
                      <span class="avatar-xs"><?= SecurityHelper::e($vaInitials) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php if ($canEditClients): ?>
                    <select class="select crm-inline-va-select"
                            data-inline-va-client="<?= SecurityHelper::e($cid) ?>"
                            data-prev-va="<?= SecurityHelper::e($focusVaId) ?>"
                            aria-label="Assigned VA for <?= SecurityHelper::e($cl['agency_name']) ?>">
                      <option value="" <?= $focusVaId === '' ? 'selected' : '' ?>>Unassigned</option>
                      <?php foreach ($vas as $v): ?>
                        <option value="<?= SecurityHelper::e($v['id']) ?>" <?= $focusVaId === (string) $v['id'] ? 'selected' : '' ?>>
                          <?= SecurityHelper::e($v['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <span class="crm-va-readonly-name"><?= SecurityHelper::e($focusVaName !== '' ? $focusVaName : 'Unassigned') ?></span>
                  <?php endif; ?>
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
                    <strong><?= number_format($consumed, 1) ?> / <?= number_format($contracted, 0) ?>h</strong>
                    <span class="muted"><?= $pct ?>%</span>
                  </div>
                  <div class="progress-track" style="height:5px;">
                    <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
                  </div>
                </div>
              </td>

              <td>
                <div class="crm-cell-stack">
                  <?php if ($lastCommAt !== ''): ?>
                    <span class="mono tabular-nums" style="font-size:11.5px;font-weight:600;color:var(--ink);">
                      <?= SecurityHelper::e(substr($lastCommAt, 0, 10)) ?>
                    </span>
                    <div class="muted crm-subline" style="max-width:200px;">
                      <?= SecurityHelper::e($lastCommPreview !== '' ? $lastCommPreview : ($commCount . ' recorded log(s)')) ?>
                    </div>
                  <?php else: ?>
                    <span class="muted" style="font-size:12px;">No activity yet</span>
                  <?php endif; ?>
                </div>
              </td>

              <td style="text-align:right;white-space:nowrap;">
                <div style="display:inline-flex;align-items:center;gap:6px;">
                  <button type="button" class="btn btn-secondary btn-sm" data-open-client="<?= SecurityHelper::e($cid) ?>">
                    <?= $canEditClients ? 'Manage' : 'Open' ?>
                  </button>
                  <?php if ($role === 'admin'): ?>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            data-action-url="/api/v1/clients/<?= SecurityHelper::e($cid) ?>"
                            data-action-method="DELETE">Delete</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="crm-filter-empty" class="crm-empty-state is-hidden">
      <strong>No accounts match your active filters</strong>
      <p class="muted">Adjust your search query, contract status, or assigned Virtual Assistant filter.</p>
      <button type="button" id="crm-empty-clear-btn" class="btn btn-secondary btn-sm">Clear Filters</button>
    </div>
  <?php endif; ?>
</section>

<script type="application/json" id="clients-dossier-json" data-can-edit="<?= $canEditClients ? '1' : '0' ?>" data-user-role="<?= SecurityHelper::e($role) ?>" data-user-id="<?= SecurityHelper::e((string) $user['id']) ?>"><?= json_encode([
    'clients'    => $clientsMap,
    'activities' => $data['activities'] ?? [],
    'emails'     => $data['emails'] ?? [],
    'tasks'      => $data['tasks'] ?? [],
    'vas'        => $vasMap,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Right-Side Tabbed Client Record Drawer -->
<div id="drawer-client-dossier" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Client Account Record">
    <div class="drawer-head">
      <div style="display:flex;align-items:center;gap:11px;min-width:0;">
        <div id="dossier-header-avatar">
          <span class="avatar">CL</span>
        </div>
        <div style="min-width:0;">
          <h3 id="dossier-agency" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Client Record</h3>
          <div id="dossier-contact" class="muted" style="font-size:12px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
        <a id="dossier-pdf-link" class="btn btn-secondary btn-sm is-hidden" href="#" target="_blank" rel="noopener">Contract PDF</a>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
    </div>

    <div class="drawer-body">
      <div class="tab-strip" style="margin-bottom:16px;">
        <button type="button" class="tab-btn active" data-tab-group="crm-dossier" data-tab-btn="details">
          <?= $canEditClients ? 'Details &amp; Edit' : 'Account Details' ?>
        </button>
        <button type="button" class="tab-btn" data-tab-group="crm-dossier" data-tab-btn="timeline">
          Comms &amp; Timeline <span class="tabular-nums" id="dossier-comms-badge">0</span>
        </button>
        <button type="button" class="tab-btn" data-tab-group="crm-dossier" data-tab-btn="team">
          VA &amp; Deliverables <span class="tabular-nums" id="dossier-tasks-badge">0</span>
        </button>
      </div>

      <!-- Tab 1: Account Details & Edit -->
      <div data-tab-group="crm-dossier" data-tab-panel="details">
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
                  <div class="photo-upload-picker" data-photo-upload>
                    <input id="dossier-client-avatar-file" class="photo-upload-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required data-photo-preview-target="#dossier-avatar-preview">
                    <label for="dossier-client-avatar-file" class="photo-upload-trigger" title="Select image">
                      <span class="photo-upload-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                          <polyline points="17 8 12 3 7 8"/>
                          <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                      </span>
                      <span class="photo-upload-text" data-photo-label data-default-label="Select photo">Select photo</span>
                    </label>
                  </div>
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
                  <option value="">Unassigned</option>
                  <?php foreach ($vas as $v): ?>
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

            <div class="grid-2 grid-2-mobile">
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

          <form id="form-upload-contract" data-api-form="/api/v1/uploads/contract" data-api-method="POST" enctype="multipart/form-data" style="padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;">
            <input type="hidden" name="client_id" value="">
            <label class="form-label" style="display:block;margin-bottom:6px;">Upload / Replace Signed Contract (.PDF max 15MB)</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <input class="input" type="file" name="contract_pdf" accept="application/pdf" required style="flex:1;padding:6px;">
              <button type="submit" class="btn btn-secondary btn-sm">Upload PDF</button>
            </div>
          </form>
        <?php else: ?>
          <div id="dossier-va-readonly" class="card" style="background:var(--bg);padding:14px;"></div>
        <?php endif; ?>
      </div>

      <!-- Tab 2: Unified Per-Client Communications & Activity Timeline -->
      <div class="is-hidden" data-tab-group="crm-dossier" data-tab-panel="timeline">
        <form id="form-client-activity" data-no-reload="1" class="crm-activity-composer" style="margin-bottom:16px;">
          <input type="hidden" name="client_id" value="">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
            <select name="type" class="select" style="flex:0 0 140px;">
              <option value="note">Note</option>
              <option value="meeting">Call / Meeting</option>
              <option value="contract">Contract Update</option>
              <option value="update">Status Update</option>
            </select>
            <span class="muted" style="font-size:12px;">Logs directly to this client's CRM record in real time.</span>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input class="input" name="description" required placeholder="Log call summary, client feedback, or renewal update..." style="flex:1 1 220px;min-width:0;">
            <button type="submit" class="btn btn-primary btn-sm">Log Entry</button>
          </div>
        </form>

        <div id="dossier-activities" class="crm-timeline-list"></div>
      </div>

      <!-- Tab 3: Associated Virtual Assistant & Client Deliverables -->
      <div class="is-hidden" data-tab-group="crm-dossier" data-tab-panel="team">
        <div id="dossier-va-card" style="margin-bottom:18px;"></div>
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h4>Client Deliverables</h4>
            <a href="/tasks" class="btn btn-secondary btn-sm">Open Kanban</a>
          </div>
          <div id="dossier-tasks-list" style="display:grid;gap:8px;"></div>
        </div>
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
        <?php if (empty($vas)): ?>
          <div class="notice notice-warn">Create at least one Virtual Assistant account before onboarding a client.</div>
        <?php else: ?>
          <form id="form-create-client" data-api-form="/api/v1/clients" data-api-method="POST">
            <div class="photo-preview-box">
              <div id="create-client-avatar-preview" class="photo-preview-thumb">
                <span class="avatar avatar-lg">CL</span>
              </div>
              <div class="photo-preview-meta">
                <h4>Optional Client Logo / Image</h4>
                <p>Select an image (JPG, PNG, WEBP) to upload automatically when saved.</p>
                <div class="photo-upload-picker" data-photo-upload>
                  <input id="create-client-avatar-file" class="photo-upload-input" type="file" accept="image/jpeg,image/png,image/webp" data-photo-preview-target="#create-client-avatar-preview">
                  <label for="create-client-avatar-file" class="photo-upload-trigger" title="Select image">
                    <span class="photo-upload-icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                      </svg>
                    </span>
                    <span class="photo-upload-text" data-photo-label data-default-label="Select image">Select image</span>
                  </label>
                </div>
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

            <div class="form-group">
              <label class="form-label">Website</label>
              <input class="input" name="website" placeholder="https://agency.com">
            </div>

            <div class="grid-2 grid-2-mobile">
              <div class="form-group">
                <label class="form-label">Country</label>
                <input class="input" name="country" value="United States">
              </div>
              <div class="form-group">
                <label class="form-label">Monthly Hours</label>
                <input class="input" type="number" step="1" min="1" name="contracted_hours_per_month" value="80">
              </div>
            </div>

            <div class="grid-2 grid-2-mobile">
              <div class="form-group">
                <label class="form-label">Assigned VA</label>
                <select class="select" name="focus_va_id" required>
                  <?php foreach ($vas as $v): ?>
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
        <?php if (empty($vas)): ?>
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
                <?php foreach ($vas as $v): ?>
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
