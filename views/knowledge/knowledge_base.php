<div class="page-header">
  <div>
    <h1>SOP Knowledge Base</h1>
    <p class="muted">Standard operating procedures and client playbooks stored in PostgreSQL.</p>
  </div>
  <?php if (in_array($role, ['admin', 'team_leader', 'hrm'], true)): ?>
    <div style="display:flex;gap:10px;">
      <button type="button" class="btn btn-secondary" data-open-modal="modal-new-category">+ New Category</button>
      <button type="button" class="btn btn-primary" data-open-modal="modal-new-sop">+ Publish SOP</button>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($data['docs'])): ?>
  <div class="card">
    <p class="muted">No SOP documents published yet.</p>
  </div>
<?php else: ?>
  <div class="grid-2">
    <?php foreach ($data['docs'] as $doc): ?>
      <article class="card card-hover">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
          <div>
            <h3><?= SecurityHelper::e($doc['title']) ?></h3>
            <div class="muted" style="font-size:12px;">
              By <?= SecurityHelper::e($doc['author']) ?> • Updated <?= SecurityHelper::e($doc['last_updated']) ?>
            </div>
          </div>
          <span class="badge badge-brand"><?= SecurityHelper::e($doc['category']) ?></span>
        </div>

        <?php if (!empty($doc['client_name'])): ?>
          <div style="margin-bottom:8px;">
            <span class="badge badge-info">Client Scope: <?= SecurityHelper::e($doc['client_name']) ?></span>
          </div>
        <?php else: ?>
          <div style="margin-bottom:8px;">
            <span class="badge badge-neutral">Global Playbook</span>
          </div>
        <?php endif; ?>

        <p style="white-space:pre-wrap;color:var(--ink-soft);font-size:13.5px;margin-bottom:12px;"><?= SecurityHelper::e($doc['content']) ?></p>

        <?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
          <button type="button"
                  class="btn btn-danger btn-sm"
                  data-action-url="/api/v1/knowledge/<?= SecurityHelper::e($doc['id']) ?>"
                  data-action-method="DELETE">Remove Document</button>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'team_leader', 'hrm'], true)): ?>
  <div id="modal-new-category" class="modal-backdrop is-hidden">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Add SOP Category</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
      <form data-api-form="/api/v1/knowledge/categories" data-api-method="POST">
        <div class="form-group">
          <label class="form-label">Category Name</label>
          <input class="input" name="name" required placeholder="e.g. Client Onboarding, Financial Ops">
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Save Category</button>
        </div>
      </form>
    </div>
  </div>

  <div id="modal-new-sop" class="modal-backdrop is-hidden">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Publish Knowledge Document</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
      <form data-api-form="/api/v1/knowledge" data-api-method="POST">
        <div class="form-group">
          <label class="form-label">Document Title</label>
          <input class="input" name="title" required placeholder="Weekly Executive Inbox SOP">
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Category</label>
            <input class="input" name="category" list="sop-cats" required placeholder="Operations">
            <datalist id="sop-cats">
              <?php foreach ($data['categories'] as $cat): ?>
                <option value="<?= SecurityHelper::e($cat['name']) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="form-group">
            <label class="form-label">Scope (Optional Client)</label>
            <select class="select" name="client_id">
              <option value="">Global (All VAs)</option>
              <?php foreach ($data['clients'] as $c): ?>
                <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">SOP Content</label>
          <textarea class="textarea" name="content" rows="6" required placeholder="Step-by-step standard operating procedure..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Publish SOP</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
