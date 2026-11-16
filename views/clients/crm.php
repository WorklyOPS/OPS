<div class="page-header">
  <div>
    <h1>Client CRM & Contracts</h1>
    <p class="muted">Active executive accounts, contracted monthly hours, signed PDF agreements, and relationship notes.</p>
  </div>
  <?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" data-open-modal="modal-import-clients">Import CSV</button>
      <button type="button" class="btn btn-primary" data-open-modal="modal-create-client">+ Add Client Account</button>
    </div>
  <?php endif; ?>
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

<?php if (empty($data['clients'])): ?>
  <div class="card">
    <p class="muted">No client accounts found in PostgreSQL for your scope.</p>
  </div>
<?php else: ?>
  <div class="grid-2">
    <?php foreach ($data['clients'] as $cl): ?>
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

        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
            <span class="muted">Monthly Utilization</span>
            <strong><?= number_format((float) $cl['consumed_hours_month'], 1) ?> / <?= number_format((float) $cl['contracted_hours_per_month'], 1) ?> hrs (<?= $pct ?>%)</strong>
          </div>
          <div class="progress-track">
            <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
          </div>
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
          <?php if (!empty($cl['contract_pdf_url'])): ?>
            <a class="btn btn-secondary btn-sm" href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cl['contract_pdf_url'])) ?>" target="_blank" rel="noopener">View Signed PDF</a>
          <?php endif; ?>
          <?php if (!empty($cl['website'])): ?>
            <a class="btn btn-secondary btn-sm" href="<?= SecurityHelper::e(SecurityHelper::sanitizeUrl($cl['website'])) ?>" target="_blank" rel="noopener">Website</a>
          <?php endif; ?>
          <?php if ($role === 'admin'): ?>
            <button type="button"
                    class="btn btn-danger btn-sm"
                    data-action-url="/api/v1/clients/<?= SecurityHelper::e($cl['id']) ?>"
                    data-action-method="DELETE">Delete Client</button>
          <?php endif; ?>
        </div>

        <?php if (in_array($role, ['admin', 'team_leader', 'hrm'], true)): ?>
          <form data-api-form="/api/v1/uploads/contract" data-api-method="POST" enctype="multipart/form-data" style="padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:8px;margin-bottom:12px;">
            <input type="hidden" name="client_id" value="<?= SecurityHelper::e($cl['id']) ?>">
            <label class="form-label" style="display:block;margin-bottom:6px;">Upload / Replace Signed Contract (.PDF max 15MB)</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <input class="input" type="file" name="contract_pdf" accept="application/pdf" required style="flex:1;padding:6px;">
              <button type="submit" class="btn btn-secondary btn-sm">Upload PDF</button>
            </div>
          </form>
        <?php endif; ?>

        <div style="border-top:1px solid var(--border);padding-top:12px;">
          <strong style="font-size:12.5px;display:block;margin-bottom:8px;">Client Activity Log</strong>
          <?php if (empty($acts)): ?>
            <p class="muted" style="font-size:12px;margin-bottom:8px;">No activities recorded yet.</p>
          <?php else: ?>
            <div style="max-height:140px;overflow-y:auto;margin-bottom:8px;">
              <?php foreach (array_slice($acts, 0, 5) as $act): ?>
                <div style="font-size:12px;padding:6px 0;border-bottom:1px dashed var(--border);">
                  <strong><?= SecurityHelper::e($act['author_name']) ?></strong>: <?= SecurityHelper::e($act['description']) ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form data-api-form="/api/v1/clients/<?= SecurityHelper::e($cl['id']) ?>/activities" data-api-method="POST" style="display:flex;gap:8px;">
            <input class="input" name="description" required placeholder="Log update, call note, or renewal note...">
            <button type="submit" class="btn btn-secondary btn-sm">Add</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
  <div id="modal-import-clients" class="modal-backdrop is-hidden">
    <div class="modal-box" style="max-width:900px;">
      <div class="modal-head">
        <div>
          <h3>Import Client Accounts</h3>
          <p class="muted" style="font-size:12px;">Upload a CSV, map its columns, review the first rows, then import.</p>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
      <div class="form-group">
        <label class="form-label" for="client-csv-file">CSV file</label>
        <input class="input" type="file" id="client-csv-file" accept=".csv,text/csv">
        <small class="muted">Use UTF-8 CSV with a header row. Excel files (.xlsx) are not supported in this import flow.</small>
      </div>
      <div id="client-csv-mapping" class="grid-3" style="margin:14px 0;"></div>
      <div class="grid-2" style="margin-bottom:14px;">
        <div class="form-group">
          <label class="form-label" for="client-csv-va">Default assigned VA</label>
          <select class="select" id="client-csv-va" required>
            <option value="">Select a VA</option>
            <?php foreach ($data['vas'] as $v): ?>
              <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label" for="client-csv-tl">Team Leader</label>
          <select class="select" id="client-csv-tl" required>
            <?php if ($role === 'team_leader'): ?>
              <option value="<?= SecurityHelper::e($user['id']) ?>" selected><?= SecurityHelper::e($user['name']) ?></option>
            <?php else: ?>
              <option value="">Select a Team Leader</option>
              <?php foreach ($data['team_leaders'] as $tl): ?>
                <option value="<?= SecurityHelper::e($tl['id']) ?>"><?= SecurityHelper::e($tl['name']) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
      </div>
      <div id="client-csv-status" class="muted" style="font-size:12px;margin-bottom:10px;" aria-live="polite">Choose a CSV file to begin.</div>
      <div id="client-csv-preview" class="table-wrap" style="max-height:260px;overflow:auto;margin-bottom:14px;"></div>
      <div style="display:flex;justify-content:flex-end;gap:8px;">
        <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
        <button type="button" class="btn btn-primary" id="client-csv-import" disabled>Import clients</button>
      </div>
    </div>
  </div>

  <div id="modal-create-client" class="modal-backdrop is-hidden">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Onboard Client Account</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>

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
            <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Client</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
