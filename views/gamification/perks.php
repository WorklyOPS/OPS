<div class="page-header">
  <div>
    <h1>Perks, Benefits & Recognition Badges</h1>
    <p class="muted">Company benefit allocations and milestone achievements managed in PostgreSQL.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (in_array($role, ['admin', 'hrm'], true)): ?>
      <button type="button" class="btn btn-secondary" data-open-modal="modal-create-perk">+ Add Perk</button>
    <?php endif; ?>
    <?php if (in_array($role, ['admin', 'hrm', 'team_leader'], true)): ?>
      <button type="button" class="btn btn-primary" data-open-modal="modal-create-badge">+ Create Badge</button>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h3 style="margin-bottom:12px;">Benefit & Perk Programs</h3>
    <?php if (empty($data['perks'])): ?>
      <p class="muted">No perks configured yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($data['perks'] as $pk): ?>
          <div class="message-bubble" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong><?= SecurityHelper::e($pk['title']) ?></strong>
              <span class="badge badge-success">$<?= number_format((float) $pk['value'], 0) ?> • <?= SecurityHelper::e($pk['category']) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin:6px 0 10px;"><?= SecurityHelper::e($pk['description']) ?></p>

            <?php if (in_array($role, ['admin', 'hrm'], true) && !empty($data['vas'])): ?>
              <form data-api-form="/api/v1/perks/<?= SecurityHelper::e($pk['id']) ?>/grant" data-api-method="POST" style="display:flex;gap:8px;align-items:center;">
                <select name="user_id" class="select" required style="flex:1;">
                  <?php foreach ($data['vas'] as $v): ?>
                    <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">Grant to VA</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($data['grants'])): ?>
      <h4 style="margin:18px 0 8px;">Active Staff Grants</h4>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>VA</th><th>Perk ID</th><th>Action</th></tr></thead>
          <tbody>
            <?php foreach ($data['grants'] as $g): ?>
              <tr>
                <td><?= SecurityHelper::e($g['va_name']) ?></td>
                <td><?= SecurityHelper::e($g['perk_id']) ?></td>
                <td>
                  <?php if (in_array($role, ['admin', 'hrm'], true)): ?>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            data-action-url="/api/v1/perks/<?= SecurityHelper::e($g['perk_id']) ?>/grant?user_id=<?= urlencode((string) $g['user_id']) ?>"
                            data-action-method="DELETE">Revoke</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3 style="margin-bottom:12px;">Achievement Badges</h3>
    <?php if (empty($data['badges'])): ?>
      <p class="muted">No recognition badges created yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($data['badges'] as $bdg): ?>
          <div class="message-bubble" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong><?= SecurityHelper::e($bdg['title']) ?></strong>
              <span class="badge badge-brand"><?= SecurityHelper::e(ucfirst($bdg['tier'])) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin:6px 0 10px;"><?= SecurityHelper::e($bdg['description']) ?></p>

            <?php if (in_array($role, ['admin', 'hrm', 'team_leader'], true) && !empty($data['vas'])): ?>
              <form data-api-form="/api/v1/perks/badges/<?= SecurityHelper::e($bdg['id']) ?>/award" data-api-method="POST" style="display:flex;gap:8px;">
                <select name="user_id" class="select" required style="flex:1;">
                  <?php foreach ($data['vas'] as $v): ?>
                    <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Award Badge</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($data['awards'])): ?>
      <h4 style="margin:18px 0 8px;">Recent Badge Awards</h4>
      <?php foreach ($data['awards'] as $aw): ?>
        <div style="font-size:12.5px;padding:6px 0;border-bottom:1px solid var(--border);">
          <strong><?= SecurityHelper::e($aw['va_name']) ?></strong> earned <span class="badge badge-brand"><?= SecurityHelper::e($aw['badge_title']) ?></span> on <?= SecurityHelper::e($aw['earned_at']) ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (in_array($role, ['admin', 'hrm'], true)): ?>
  <div id="modal-create-perk" class="modal-backdrop is-hidden">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Create Benefit / Perk</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
      <form data-api-form="/api/v1/perks" data-api-method="POST">
        <div class="form-group">
          <label class="form-label">Title</label>
          <input class="input" name="title" required placeholder="Home Office Stipend">
        </div>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Category</label>
            <input class="input" name="category" value="Equipment & Hardware">
          </div>
          <div class="form-group">
            <label class="form-label">Monetary Value ($)</label>
            <input class="input" type="number" step="10" min="0" name="value" value="250">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea class="textarea" name="description" required placeholder="Eligibility rules and stipend details..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Create Perk</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'hrm', 'team_leader'], true)): ?>
  <div id="modal-create-badge" class="modal-backdrop is-hidden">
    <div class="modal-box">
      <div class="modal-head">
        <h3>Create Recognition Badge</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
      <form data-api-form="/api/v1/perks/badges" data-api-method="POST">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Badge Title</label>
            <input class="input" name="title" required placeholder="Zero-Miss SLA Streak">
          </div>
          <div class="form-group">
            <label class="form-label">Tier</label>
            <select class="select" name="tier">
              <option value="bronze">Bronze</option>
              <option value="silver" selected>Silver</option>
              <option value="gold">Gold</option>
              <option value="diamond">Diamond</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Criteria Description</label>
          <textarea class="textarea" name="description" required placeholder="Awarded for 100% on-time deliverables..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Create Badge</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
