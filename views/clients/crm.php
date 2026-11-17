<?php
$clients = $data['clients'] ?? [];
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
    $clientsMap[$cl['id']] = $cl;
}
$portfolioUtil = $totalContractedHrs > 0 ? round(($totalConsumedHrs / $totalContractedHrs) * 100, 1) : 0.0;
?>

<div class="page-header">
  <div>
    <h1>Client CRM &amp; Contracts</h1>
    <p class="muted">Active executive accounts, contracted monthly hours, signed PDF agreements, and interactive client dossiers.</p>
  </div>
  <?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
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
    <input class="input" type="search" name="q" value="<?= SecurityHelper::e($data['search'] ?? '') ?>" placeholder="Filter by agency, contact name, or email..." style="max-width:360px;">
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
  <div class="grid-2">
    <?php foreach ($clients as $cl): ?>
      <?php
        $pct = (float) $cl['contracted_hours_per_month'] > 0
            ? min(100, round(((float) $cl['consumed_hours_month'] / (float) $cl['contracted_hours_per_month']) * 100, 1))
            : 0;
        $acts = $data['activities'][$cl['id']] ?? [];
      ?>
      <article class="card card-hover">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
          <div>
            <h3 style="font-size:17px;"><?= SecurityHelper::e($cl['agency_name']) ?></h3>
            <div class="muted" style="font-size:12.5px;">
              <?= SecurityHelper::e($cl['name']) ?> • <?= SecurityHelper::e($cl['email']) ?>
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
              Open Dossier &amp; Edit
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

<script type="application/json" id="clients-dossier-json"><?= json_encode([
    'clients'    => $clientsMap,
    'activities' => $data['activities'] ?? [],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Right-Side Drawer: Client Dossier, Inline Edit, Contract Upload & Activity Stream -->
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
        <strong>Account Configuration</strong>
        <a id="dossier-pdf-link" class="btn btn-secondary btn-sm is-hidden" href="#" target="_blank" rel="noopener">View Signed Contract PDF</a>
      </div>

      <?php if (in_array($role, ['admin', 'team_leader', 'hrm'], true)): ?>
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
              <label class="form-label">Email</label>
              <input class="input" type="email" name="email" required>
            </div>
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input class="input" name="phone">
            </div>
          </div>

          <div class="grid-3">
            <div class="form-group">
              <label class="form-label">Contract Status</label>
              <select class="select" name="contract_status">
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
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
            <label class="form-label">Assigned Focus VA</label>
            <select class="select" name="focus_va_id">
              <?php foreach ($data['vas'] as $v): ?>
                <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label">Internal Account Notes</label>
            <textarea class="textarea" name="internal_notes" rows="3"></textarea>
          </div>

          <button type="submit" class="btn btn-primary">Save Client Profile</button>
        </form>

        <form id="form-upload-contract" data-api-form="/api/v1/uploads/contract" data-api-method="POST" enctype="multipart/form-data" style="padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:20px;">
          <input type="hidden" name="client_id" value="">
          <label class="form-label" style="display:block;margin-bottom:6px;">Upload / Replace Signed Contract (.PDF max 15MB)</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <input class="input" type="file" name="contract_pdf" accept="application/pdf" required style="flex:1;padding:6px;">
            <button type="submit" class="btn btn-secondary btn-sm">Upload PDF</button>
          </div>
        </form>
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

<?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
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
          <form data-api-form="/api/v1/clients" data-api-method="POST">
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
                <label class="form-label">Primary Email</label>
                <input class="input" type="email" name="email" required placeholder="executive@agency.com">
              </div>
              <div class="form-group">
                <label class="form-label">Phone</label>
                <input class="input" name="phone" placeholder="+1 ...">
              </div>
            </div>

            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">Assigned Virtual Assistant</label>
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
            </div>

            <div class="grid-3">
              <div class="form-group">
                <label class="form-label">Monthly Hours</label>
                <input class="input" type="number" step="1" min="1" name="contracted_hours_per_month" value="80">
              </div>
              <div class="form-group">
                <label class="form-label">Country</label>
                <input class="input" name="country" value="United States">
              </div>
              <div class="form-group">
                <label class="form-label">Website</label>
                <input class="input" name="website" placeholder="https://...">
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
