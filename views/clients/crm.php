<?php
$clients = $data['clients'] ?? [];
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
    <p class="muted">Active executive accounts, contracted monthly hours, availability schedules, and interactive client dossiers.</p>
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

<div class="card" style="margin-bottom:18px;padding:14px;">
  <form method="GET" action="/clients" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <input id="client-live-search"
           class="input"
           type="search"
           name="q"
           autocomplete="off"
           value="<?= SecurityHelper::e($data['search'] ?? '') ?>"
           placeholder="Type 2+ letters to filter by agency, contact, or email..."
           style="max-width:400px;">
    <button type="submit" class="btn btn-secondary">Search Clients</button>
    <?php if (!empty($data['search'])): ?>
      <a href="/clients" class="btn btn-secondary">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($clients)): ?>
  <div class="card">
    <p class="muted">No client accounts found in PostgreSQL for your scope.</p>
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

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;margin-bottom:12px;">
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
        <!-- TOP: Client Logo / Photo Preview + Delete + Upload -->
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
                <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="max-width:210px;padding:6px 10px;">
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

          <!-- Save button strictly at the VERY BOTTOM of the Edit Client form -->
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
        <!-- VA strictly read-only summary -->
        <div id="dossier-va-readonly" class="card" style="background:var(--bg);margin-bottom:18px;padding:14px;"></div>
      <?php endif; ?>

      <div style="border-top:1px solid var(--border);padding-top:16px;">
        <h4 style="margin-bottom:10px;">Activity Stream</h4>
        <div id="dossier-activities" style="margin-bottom:12px;"></div>
        <form id="form-client-activity" data-api-form="/api/v1/clients" data-api-method="POST" style="display:flex;gap:8px;">
          <input class="input" name="description" required placeholder="Log meeting update, call summary, or renewal note...">
          <button type="submit" class="btn btn-primary btn-sm">Log Activity</button>
        </form>
      </div>
    </div>
  </aside>
</div>

<?php if ($canCreateClients): ?>
  <!-- Right-Side Drawer: Create Client (Top Optional Logo, + Secondary Email/Phone, Color Contract Status, Website, Availability Slots, Bottom Save) -->
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
                <input id="create-client-avatar-file" class="input" type="file" accept="image/jpeg,image/png,image/webp" style="max-width:250px;padding:6px 10px;">
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

  <!-- Right-Side Drawer: Bulk CSV Import -->
  <div id="drawer-bulk-clients" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Bulk Client CSV Import">
      <div class="drawer-head">
        <div>
          <h3>Bulk Client CSV Import</h3>
          <div class="muted" style="font-size:12px;">Import multiple client agencies directly into PostgreSQL.</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>

      <div class="drawer-body">
        <?php if (empty($data['vas'])): ?>
          <div class="notice notice-warn">Create at least one Virtual Assistant account before importing clients.</div>
        <?php else: ?>
          <form id="form-bulk-clients">
            <div class="form-group">
              <label class="form-label">Default Focus VA (used when VA ID column omitted)</label>
              <select class="select" name="default_va_id" required>
                <?php foreach ($data['vas'] as $v): ?>
                  <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">CSV Rows (agency_name, contact_name, email, phone, monthly_hours)</label>
              <textarea class="textarea mono" name="csv_data" rows="9" required placeholder="agency_name,contact_name,email,phone,monthly_hours&#10;Northstar Logistics,Elena Vance,elena@northstar.io,+1-555-0192,80"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
              <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
              <button type="submit" class="btn btn-primary">Import Clients</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?php endif; ?>
