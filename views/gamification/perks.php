<?php
require_once __DIR__ . '/../partials/icons.php';

$canManagePerks = in_array($role, ['admin', 'team_leader'], true);
$vas = $data['vas'] ?? [];
$availableIcons = $data['available_icons'] ?? ['Award', 'Medal', 'Trophy', 'Star', 'Shield', 'Crown', 'Zap', 'Heart'];
?>

<div class="page-header">
  <div>
    <h1>Perks, Benefits &amp; Recognition Badges</h1>
    <p class="muted">Tiered minimalist medal badges, benefit programs, and visual VA recognition managed by Admins &amp; Team Leaders.</p>
  </div>
  <?php if ($canManagePerks): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-create-perk">+ Add Perk</button>
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-badge">+ Create Badge</button>
    </div>
  <?php endif; ?>
</div>

<div class="grid-2" style="margin-bottom:22px;">
  <!-- Catalog Card 1: Benefit & Perk Programs -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3>Benefit &amp; Perk Programs</h3>
      <span class="badge badge-success tabular-nums"><?= count($data['perks'] ?? []) ?> active</span>
    </div>
    <?php if (empty($data['perks'])): ?>
      <p class="muted">No perks configured yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:14px;">
        <?php foreach ($data['perks'] as $pk): ?>
          <div class="perk-catalog-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="tier-icon-wrap tier-gold">
                  <?= workly_icon_svg((string) ($pk['icon_name'] ?? 'Award'), 19) ?>
                </span>
                <div>
                  <strong><?= SecurityHelper::e($pk['title']) ?></strong>
                  <div class="muted" style="font-size:11.5px;"><?= SecurityHelper::e($pk['category']) ?></div>
                </div>
              </div>
              <span class="badge badge-success">$<?= number_format((float) $pk['value'], 0) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin:8px 0 12px;"><?= SecurityHelper::e($pk['description']) ?></p>

            <?php if ($canManagePerks && !empty($vas)): ?>
              <form data-api-form="/api/v1/perks/<?= SecurityHelper::e($pk['id']) ?>/grant" data-api-method="POST">
                <div class="va-ms" data-va-multiselect>
                  <div class="va-ms-top">
                    <span class="form-label" style="margin-bottom:0;">Select VA Recipients</span>
                    <span class="muted tabular-nums" style="font-size:11.5px;" data-va-ms-counter>0 selected</span>
                  </div>

                  <div class="va-ms-avatars is-empty" data-va-ms-avatars aria-label="Selected Virtual Assistants">
                    <span class="va-ms-empty-hint">No VAs selected</span>
                  </div>

                  <div class="va-ms-dropdown">
                    <button type="button" class="select va-ms-trigger" data-va-ms-trigger aria-expanded="false">
                      <span data-va-ms-trigger-text>Select Virtual Assistants...</span>
                    </button>
                    <div class="va-ms-menu is-hidden" data-va-ms-menu>
                      <div class="va-ms-menu-head">
                        <input type="search" class="input va-ms-search" data-va-ms-search placeholder="Search VAs...">
                        <button type="button" class="btn-select-all-vas" data-select-all-vas>
                          <?= workly_icon_svg('Check', 13) ?>
                          <span>Select All</span>
                        </button>
                      </div>
                      <div class="va-ms-list" data-va-ms-list>
                        <?php foreach ($vas as $v): ?>
                          <?php $vInit = strtoupper(substr((string) $v['name'], 0, 2)); ?>
                          <label class="va-ms-option" data-va-option-name="<?= SecurityHelper::e(strtolower((string) $v['name'])) ?>">
                            <input type="checkbox"
                                   name="user_ids[]"
                                   value="<?= SecurityHelper::e($v['id']) ?>"
                                   data-va-name="<?= SecurityHelper::e($v['name']) ?>"
                                   data-va-avatar="<?= SecurityHelper::e((string) ($v['avatar_url'] ?? '')) ?>"
                                   data-va-initials="<?= SecurityHelper::e($vInit) ?>">
                            <?php if (!empty($v['avatar_url'])): ?>
                              <img src="<?= SecurityHelper::e($v['avatar_url']) ?>" alt="<?= SecurityHelper::e($v['name']) ?>" class="avatar-xs">
                            <?php else: ?>
                              <span class="avatar-xs"><?= SecurityHelper::e($vInit) ?></span>
                            <?php endif; ?>
                            <span class="va-ms-opt-name"><?= SecurityHelper::e($v['name']) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">Grant Perk to Selected</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Catalog Card 2: Achievement Badges -->
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3>Achievement Badges</h3>
      <span class="badge badge-brand tabular-nums"><?= count($data['badges'] ?? []) ?> medal(s)</span>
    </div>
    <?php if (empty($data['badges'])): ?>
      <p class="muted">No recognition badges created yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:14px;">
        <?php foreach ($data['badges'] as $bdg): ?>
          <?php $tierSlug = strtolower(preg_replace('/[^a-z]/', '', (string) ($bdg['tier'] ?? 'silver')) ?: 'silver'); ?>
          <div class="perk-catalog-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="tier-icon-wrap tier-<?= SecurityHelper::e($tierSlug) ?>">
                  <?= workly_icon_svg((string) ($bdg['icon_name'] ?? 'Medal'), 19) ?>
                </span>
                <div>
                  <strong><?= SecurityHelper::e($bdg['title']) ?></strong>
                  <div class="muted" style="font-size:11.5px;"><?= SecurityHelper::e(ucfirst($tierSlug)) ?> Tier Medal</div>
                </div>
              </div>
              <span class="badge tier-<?= SecurityHelper::e($tierSlug) ?>"><?= SecurityHelper::e(ucfirst($tierSlug)) ?></span>
            </div>
            <p class="muted" style="font-size:12.5px;margin:8px 0 12px;"><?= SecurityHelper::e($bdg['description']) ?></p>

            <?php if ($canManagePerks && !empty($vas)): ?>
              <form data-api-form="/api/v1/perks/badges/<?= SecurityHelper::e($bdg['id']) ?>/award" data-api-method="POST">
                <div class="va-ms" data-va-multiselect>
                  <div class="va-ms-top">
                    <span class="form-label" style="margin-bottom:0;">Select VA Recipients</span>
                    <span class="muted tabular-nums" style="font-size:11.5px;" data-va-ms-counter>0 selected</span>
                  </div>

                  <div class="va-ms-avatars is-empty" data-va-ms-avatars aria-label="Selected Virtual Assistants">
                    <span class="va-ms-empty-hint">No VAs selected</span>
                  </div>

                  <div class="va-ms-dropdown">
                    <button type="button" class="select va-ms-trigger" data-va-ms-trigger aria-expanded="false">
                      <span data-va-ms-trigger-text>Select Virtual Assistants...</span>
                    </button>
                    <div class="va-ms-menu is-hidden" data-va-ms-menu>
                      <div class="va-ms-menu-head">
                        <input type="search" class="input va-ms-search" data-va-ms-search placeholder="Search VAs...">
                        <button type="button" class="btn-select-all-vas" data-select-all-vas>
                          <?= workly_icon_svg('Check', 13) ?>
                          <span>Select All</span>
                        </button>
                      </div>
                      <div class="va-ms-list" data-va-ms-list>
                        <?php foreach ($vas as $v): ?>
                          <?php $vInit = strtoupper(substr((string) $v['name'], 0, 2)); ?>
                          <label class="va-ms-option" data-va-option-name="<?= SecurityHelper::e(strtolower((string) $v['name'])) ?>">
                            <input type="checkbox"
                                   name="user_ids[]"
                                   value="<?= SecurityHelper::e($v['id']) ?>"
                                   data-va-name="<?= SecurityHelper::e($v['name']) ?>"
                                   data-va-avatar="<?= SecurityHelper::e((string) ($v['avatar_url'] ?? '')) ?>"
                                   data-va-initials="<?= SecurityHelper::e($vInit) ?>">
                            <?php if (!empty($v['avatar_url'])): ?>
                              <img src="<?= SecurityHelper::e($v['avatar_url']) ?>" alt="<?= SecurityHelper::e($v['name']) ?>" class="avatar-xs">
                            <?php else: ?>
                              <span class="avatar-xs"><?= SecurityHelper::e($vInit) ?></span>
                            <?php endif; ?>
                            <span class="va-ms-opt-name"><?= SecurityHelper::e($v['name']) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Award Badge to Selected</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Standalone Sections Below Catalog Cards: Active Staff Grants & Recent Badge Awards -->
<div class="grid-2">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3>Active Staff Grants</h3>
      <span class="badge badge-neutral tabular-nums"><?= count($data['grants'] ?? []) ?> active</span>
    </div>
    <?php if (empty($data['grants'])): ?>
      <p class="muted">No staff benefit grants issued yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Virtual Assistant</th>
              <th>Perk Benefit</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['grants'] as $g): ?>
              <tr>
                <td>
                  <div style="display:flex;align-items:center;gap:8px;">
                    <?php if (!empty($g['va_avatar'])): ?>
                      <img src="<?= SecurityHelper::e($g['va_avatar']) ?>" alt="<?= SecurityHelper::e($g['va_name']) ?>" class="avatar-xs">
                    <?php else: ?>
                      <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $g['va_name'], 0, 2))) ?></span>
                    <?php endif; ?>
                    <strong><?= SecurityHelper::e($g['va_name']) ?></strong>
                  </div>
                </td>
                <td><span class="badge badge-success"><?= SecurityHelper::e($g['perk_title'] ?? $g['perk_id']) ?></span></td>
                <td>
                  <?php if ($canManagePerks): ?>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            data-action-url="/api/v1/perks/<?= SecurityHelper::e($g['perk_id']) ?>/grant?user_id=<?= urlencode((string) $g['user_id']) ?>"
                            data-action-method="DELETE">Revoke</button>
                  <?php else: ?>
                    <span class="muted">Active</span>
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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3>Recent Badge Awards</h3>
      <span class="badge badge-neutral tabular-nums"><?= count($data['awards'] ?? []) ?> awarded</span>
    </div>
    <?php if (empty($data['awards'])): ?>
      <p class="muted">No recognition badges awarded yet.</p>
    <?php else: ?>
      <div style="display:grid;gap:8px;">
        <?php foreach ($data['awards'] as $aw): ?>
          <?php $awTier = strtolower(preg_replace('/[^a-z]/', '', (string) ($aw['tier'] ?? 'silver')) ?: 'silver'); ?>
          <div style="padding:9px 11px;border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:9px;">
              <?php if (!empty($aw['va_avatar'])): ?>
                <img src="<?= SecurityHelper::e($aw['va_avatar']) ?>" alt="<?= SecurityHelper::e($aw['va_name']) ?>" class="avatar-xs">
              <?php else: ?>
                <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $aw['va_name'], 0, 2))) ?></span>
              <?php endif; ?>
              <span style="font-size:13px;">
                <strong><?= SecurityHelper::e($aw['va_name']) ?></strong> earned
                <span class="badge tier-<?= SecurityHelper::e($awTier) ?>">
                  <?= workly_icon_svg((string) ($aw['icon_name'] ?? 'Medal'), 12) ?>
                  <?= SecurityHelper::e($aw['badge_title']) ?>
                </span>
              </span>
            </div>
            <span class="muted mono" style="font-size:11px;"><?= SecurityHelper::e(substr((string) $aw['earned_at'], 0, 10)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canManagePerks): ?>
  <div id="drawer-create-perk" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Create Benefit or Perk">
      <div class="drawer-head">
        <h3>Create Benefit / Perk</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
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

          <div class="form-group" data-icon-picker>
            <label class="form-label">Minimalist Medal / Icon</label>
            <input type="hidden" name="icon_name" value="Award" data-icon-value>
            <div class="icon-picker-grid">
              <?php foreach ($availableIcons as $ic): ?>
                <button type="button"
                        class="icon-picker-btn <?= $ic === 'Award' ? 'is-selected' : '' ?>"
                        data-icon-option="<?= SecurityHelper::e($ic) ?>">
                  <?= workly_icon_svg($ic, 18) ?>
                  <span><?= SecurityHelper::e($ic) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="textarea" name="description" rows="4" required placeholder="Eligibility rules and stipend details..."></textarea>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Create Perk</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

  <div id="drawer-create-badge" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Create Recognition Badge">
      <div class="drawer-head">
        <h3>Create Recognition Badge</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/perks/badges" data-api-method="POST">
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Badge Title</label>
              <input class="input" name="title" required placeholder="Zero-Miss SLA Streak">
            </div>
            <div class="form-group">
              <label class="form-label">Tier (Controls Medal Color)</label>
              <select class="select" name="tier">
                <option value="bronze">Bronze</option>
                <option value="silver" selected>Silver</option>
                <option value="gold">Gold</option>
                <option value="diamond">Diamond</option>
              </select>
            </div>
          </div>

          <div class="form-group" data-icon-picker>
            <label class="form-label">Minimalist Medal / Icon</label>
            <input type="hidden" name="icon_name" value="Medal" data-icon-value>
            <div class="icon-picker-grid">
              <?php foreach ($availableIcons as $ic): ?>
                <button type="button"
                        class="icon-picker-btn <?= $ic === 'Medal' ? 'is-selected' : '' ?>"
                        data-icon-option="<?= SecurityHelper::e($ic) ?>">
                  <?= workly_icon_svg($ic, 18) ?>
                  <span><?= SecurityHelper::e($ic) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Criteria Description</label>
            <textarea class="textarea" name="description" rows="4" required placeholder="Awarded for 100% on-time deliverables..."></textarea>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Create Badge</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
