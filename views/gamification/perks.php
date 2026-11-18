<?php
require_once __DIR__ . '/../partials/icons.php';

$canManagePerks = in_array($role, ['admin', 'team_leader', 'hrm'], true);
$vas = $data['vas'] ?? [];
$perks = $data['perks'] ?? [];
$grants = $data['grants'] ?? [];
$badges = $data['badges'] ?? [];
$awards = $data['awards'] ?? [];
$history = $data['history'] ?? [];
$availableIcons = $data['available_icons'] ?? ['Award', 'Medal', 'Trophy', 'Star', 'Shield', 'Crown', 'Zap', 'Heart'];

$grantsByPerk = [];
$grantUserIdsByPerk = [];
foreach ($grants as $g) {
    $pid = (string) ($g['perk_id'] ?? '');
    $uid = (string) ($g['user_id'] ?? '');
    if ($pid === '' || $uid === '') {
        continue;
    }
    $grantsByPerk[$pid][] = $g;
    $grantUserIdsByPerk[$pid][$uid] = true;
}

$awardsByBadge = [];
$awardUserIdsByBadge = [];
foreach ($awards as $aw) {
    $bid = (string) ($aw['badge_id'] ?? '');
    $uid = (string) ($aw['user_id'] ?? '');
    if ($bid === '' || $uid === '') {
        continue;
    }
    $awardsByBadge[$bid][] = $aw;
    $awardUserIdsByBadge[$bid][$uid] = true;
}

$historyMeta = static function (string $eventType): array {
    return match ($eventType) {
        'perk_granted'  => ['Perk Granted', 'badge-success'],
        'perk_revoked'  => ['Perk Revoked', 'badge-danger'],
        'badge_awarded' => ['Badge Awarded', 'badge-brand'],
        'perk_created'  => ['Perk Added', 'badge-info'],
        'perk_deleted'  => ['Perk Deleted', 'badge-neutral'],
        'badge_created' => ['Badge Created', 'badge-info'],
        default         => ['Recorded', 'badge-neutral'],
    };
};
?>

<div id="workly-perks-view" data-can-manage="<?= $canManagePerks ? '1' : '0' ?>">
  <div class="page-header">
    <div>
      <h1>Perks, Benefits &amp; Recognition</h1>
      <p class="muted">Manage employee benefits, tiered achievement badges, live VA recipients, and recognition audit logs.</p>
    </div>
    <?php if ($canManagePerks): ?>
      <div class="page-header-actions">
        <button type="button" class="btn btn-secondary" data-open-drawer="drawer-create-perk">+ Add Perk</button>
        <button type="button" class="btn btn-primary" data-open-drawer="drawer-create-badge">+ Create Badge</button>
      </div>
    <?php endif; ?>
  </div>

  <!-- Clean 4-Metric KPI Strip -->
  <div class="grid-4" style="margin-bottom:18px;">
    <div class="card card-hover">
      <div class="kpi-label">Benefit Programs</div>
      <div class="kpi-value tabular-nums" id="perks-kpi-programs"><?= count($perks) ?></div>
      <div class="kpi-sub">Active company perks</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Active VA Grants</div>
      <div class="kpi-value tabular-nums" id="perks-kpi-grants"><?= count($grants) ?></div>
      <div class="kpi-sub">Assigned benefit enrollments</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Badge Catalog</div>
      <div class="kpi-value tabular-nums" id="perks-kpi-badges"><?= count($badges) ?></div>
      <div class="kpi-sub">Tiered achievement medals</div>
    </div>
    <div class="card card-hover">
      <div class="kpi-label">Badges Earned</div>
      <div class="kpi-value tabular-nums" id="perks-kpi-awards"><?= count($awards) ?></div>
      <div class="kpi-sub">Total recognition milestones</div>
    </div>
  </div>

  <!-- Tabbed Recognition & Benefits Hub -->
  <div class="tab-strip" style="margin-bottom:18px;">
    <button type="button" class="tab-btn active" data-tab-group="perks-workspace" data-tab-btn="perks">
      Perks &amp; Benefits <span class="tabular-nums" data-perks-tab-count="perks"><?= count($perks) ?></span>
    </button>
    <button type="button" class="tab-btn" data-tab-group="perks-workspace" data-tab-btn="badges">
      Achievement Badges <span class="tabular-nums" data-perks-tab-count="badges"><?= count($badges) ?></span>
    </button>
    <button type="button" class="tab-btn" data-tab-group="perks-workspace" data-tab-btn="grants">
      Active Grants &amp; Awards <span class="tabular-nums" data-perks-tab-count="grants"><?= count($grants) + count($awards) ?></span>
    </button>
    <button type="button" class="tab-btn" data-tab-group="perks-workspace" data-tab-btn="history">
      History Log <span class="tabular-nums" data-perks-tab-count="history"><?= count($history) ?></span>
    </button>
  </div>

  <!-- Tab 1: Perks & Benefits -->
  <section data-tab-group="perks-workspace" data-tab-panel="perks" aria-label="Benefit and Perk Programs">
    <?php if (empty($perks)): ?>
      <div class="card crm-empty-state">
        <strong>No benefit programs configured yet</strong>
        <p class="muted">Create your first stipend, wellness credit, or equipment benefit to assign to Virtual Assistants.</p>
        <?php if ($canManagePerks): ?>
          <button type="button" class="btn btn-primary btn-sm" data-open-drawer="drawer-create-perk">+ Add Perk</button>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="perks-card-grid">
        <?php foreach ($perks as $pk): ?>
          <?php
            $pid = (string) $pk['id'];
            $holders = $grantsByPerk[$pid] ?? [];
            $holderIds = $grantUserIdsByPerk[$pid] ?? [];
          ?>
          <article class="card perk-program-card" data-perk-card="<?= SecurityHelper::e($pid) ?>">
            <div class="perk-card-head">
              <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                <span class="tier-icon-wrap tier-gold">
                  <?= workly_icon_svg((string) ($pk['icon_name'] ?? 'Award'), 19) ?>
                </span>
                <div style="min-width:0;">
                  <h3 class="perk-card-title"><?= SecurityHelper::e($pk['title']) ?></h3>
                  <div class="perk-card-meta">
                    <span><?= SecurityHelper::e($pk['category']) ?></span>
                    <span>·</span>
                    <strong class="tabular-nums">$<?= number_format((float) $pk['value'], 0) ?> value</strong>
                  </div>
                </div>
              </div>
              <?php if ($canManagePerks): ?>
                <button type="button"
                        class="btn btn-secondary btn-sm perk-card-del-btn"
                        title="Delete Perk Program"
                        data-perk-delete-url="/api/v1/perks/<?= SecurityHelper::e($pid) ?>"
                        data-perk-delete-id="<?= SecurityHelper::e($pid) ?>">Delete</button>
              <?php endif; ?>
            </div>

            <p class="perk-card-desc"><?= SecurityHelper::e($pk['description']) ?></p>

            <?php if ($canManagePerks && !empty($vas)): ?>
              <form data-api-form="/api/v1/perks/<?= SecurityHelper::e($pid) ?>/grant"
                    data-api-method="POST"
                    data-no-reload="1"
                    data-perk-assign-form="perk"
                    data-perk-item-id="<?= SecurityHelper::e($pid) ?>">
                <input type="hidden" name="sync_recipients" value="1">
                <div class="va-ms" data-va-multiselect data-ms-kind="perk" data-ms-item-id="<?= SecurityHelper::e($pid) ?>">
                  <div class="va-ms-top">
                    <span class="form-label" style="margin-bottom:0;">Select VA Recipients</span>
                    <span class="muted tabular-nums" style="font-size:11.5px;" data-va-ms-counter><?= count($holderIds) ?> selected</span>
                  </div>

                  <div class="va-ms-avatars <?= empty($holderIds) ? 'is-empty' : '' ?>" data-va-ms-avatars aria-label="Selected Virtual Assistants">
                    <span class="va-ms-empty-hint">No VAs selected</span>
                  </div>

                  <div class="perk-assign-bar">
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
                          <button type="button" class="btn btn-secondary btn-sm" data-va-ms-done>Done</button>
                        </div>
                        <div class="va-ms-list" data-va-ms-list>
                          <?php foreach ($vas as $v): ?>
                            <?php
                              $vid = (string) $v['id'];
                              $vInit = strtoupper(substr((string) $v['name'], 0, 2));
                              $isChecked = isset($holderIds[$vid]);
                            ?>
                            <label class="va-ms-option" data-va-option-name="<?= SecurityHelper::e(strtolower((string) $v['name'])) ?>">
                              <input type="checkbox"
                                     name="user_ids[]"
                                     value="<?= SecurityHelper::e($vid) ?>"
                                     data-va-name="<?= SecurityHelper::e($v['name']) ?>"
                                     data-va-avatar="<?= SecurityHelper::e((string) ($v['avatar_url'] ?? '')) ?>"
                                     data-va-initials="<?= SecurityHelper::e($vInit) ?>"
                                     <?= $isChecked ? 'checked' : '' ?>>
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
                    <button type="submit" class="btn btn-primary btn-sm perk-assign-submit">Save Recipients</button>
                  </div>
                </div>
              </form>
            <?php elseif ($canManagePerks): ?>
              <p class="muted" style="font-size:12px;">No active Virtual Assistants available to assign.</p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Tab 2: Achievement Badges -->
  <section class="is-hidden" data-tab-group="perks-workspace" data-tab-panel="badges" aria-label="Achievement Badges">
    <?php if (empty($badges)): ?>
      <div class="card crm-empty-state">
        <strong>No recognition badges created yet</strong>
        <p class="muted">Create tiered recognition medals to reward SLA excellence, tenure, and client praise.</p>
        <?php if ($canManagePerks): ?>
          <button type="button" class="btn btn-primary btn-sm" data-open-drawer="drawer-create-badge">+ Create Badge</button>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="perks-card-grid">
        <?php foreach ($badges as $bdg): ?>
          <?php
            $bid = (string) $bdg['id'];
            $tierSlug = strtolower(preg_replace('/[^a-z]/', '', (string) ($bdg['tier'] ?? 'silver')) ?: 'silver');
            $holders = $awardsByBadge[$bid] ?? [];
            $holderIds = $awardUserIdsByBadge[$bid] ?? [];
          ?>
          <article class="card perk-program-card" data-badge-card="<?= SecurityHelper::e($bid) ?>">
        <div class="perk-info">
          <div class="perk-card-head">
              <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                <span class="tier-icon-wrap tier-<?= SecurityHelper::e($tierSlug) ?>">
                  <?= workly_icon_svg((string) ($bdg['icon_name'] ?? 'Medal'), 19) ?>
                </span>
                <div style="min-width:0;">
                  <h3 class="perk-card-title"><?= SecurityHelper::e($bdg['title']) ?></h3>
                  <div class="perk-card-meta">
                    <span class="perk-tier-tag tier-<?= SecurityHelper::e($tierSlug) ?>"><?= SecurityHelper::e(ucfirst($tierSlug)) ?> Tier</span>
                    <span>·</span>
                    <span>Recognition Medal</span>
                  </div>
                </div>
              </div>
            </div>

            <p class="perk-card-desc"><?= SecurityHelper::e($bdg['description']) ?></p>
        </div>

            <?php if ($canManagePerks && !empty($vas)): ?>
            <div class="form-perks-va">
              <form data-api-form="/api/v1/perks/badges/<?= SecurityHelper::e($bid) ?>/award"
                    data-api-method="POST"
                    data-no-reload="1"
                    data-perk-assign-form="badge"
                    data-perk-item-id="<?= SecurityHelper::e($bid) ?>">
                <input type="hidden" name="sync_recipients" value="1">
                <div class="va-ms" data-va-multiselect data-ms-kind="badge" data-ms-item-id="<?= SecurityHelper::e($bid) ?>">
                  <div class="va-ms-top">
                    <span class="form-label" style="margin-bottom:0;">Select VA Recipients</span>
                    <span class="muted tabular-nums" style="font-size:11.5px;" data-va-ms-counter><?= count($holderIds) ?> selected</span>
                  </div>

                  <div class="va-ms-avatars <?= empty($holderIds) ? 'is-empty' : '' ?>" data-va-ms-avatars aria-label="Selected Virtual Assistants">
                    <span class="va-ms-empty-hint">No VAs selected</span>
                  </div>

                  <div class="perk-assign-bar">
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
                          <button type="button" class="btn btn-secondary btn-sm" data-va-ms-done>Done</button>
                        </div>
                        <div class="va-ms-list" data-va-ms-list>
                          <?php foreach ($vas as $v): ?>
                            <?php
                              $vid = (string) $v['id'];
                              $vInit = strtoupper(substr((string) $v['name'], 0, 2));
                              $isChecked = isset($holderIds[$vid]);
                            ?>
                            <label class="va-ms-option" data-va-option-name="<?= SecurityHelper::e(strtolower((string) $v['name'])) ?>">
                              <input type="checkbox"
                                     name="user_ids[]"
                                     value="<?= SecurityHelper::e($vid) ?>"
                                     data-va-name="<?= SecurityHelper::e($v['name']) ?>"
                                     data-va-avatar="<?= SecurityHelper::e((string) ($v['avatar_url'] ?? '')) ?>"
                                     data-va-initials="<?= SecurityHelper::e($vInit) ?>"
                                     <?= $isChecked ? 'checked' : '' ?>>
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
                    <button type="submit" class="btn btn-primary btn-sm perk-assign-submit">Save Awardees</button>
                  </div>
                </div>
              </form>
            </div>
            <?php elseif ($canManagePerks): ?>
              <p class="muted" style="font-size:12px;">No active Virtual Assistants available to assign.</p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Tab 3: Active Grants & Awards Roster -->
  <section class="is-hidden" data-tab-group="perks-workspace" data-tab-panel="grants" aria-label="Active Grants and Awards">
    <div class="grid-2">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
          <h3>Active Staff Grants</h3>
          <span id="perks-grants-count" class="badge badge-neutral tabular-nums"><?= count($grants) ?> active</span>
        </div>
        <div id="perks-grants-container">
          <?php if (empty($grants)): ?>
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
                  <?php foreach ($grants as $g): ?>
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
                                  data-perk-revoke="/api/v1/perks/<?= SecurityHelper::e($g['perk_id']) ?>/grant?user_id=<?= urlencode((string) $g['user_id']) ?>">Revoke</button>
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
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;">
          <h3>Badge Recognition Roster</h3>
          <span id="perks-awards-count" class="badge badge-neutral tabular-nums"><?= count($awards) ?> awarded</span>
        </div>
        <div id="perks-awards-container">
          <?php if (empty($awards)): ?>
            <p class="muted">No recognition badges awarded yet.</p>
          <?php else: ?>
            <div style="display:grid;gap:8px;">
              <?php foreach ($awards as $aw): ?>
                <?php $awTier = strtolower(preg_replace('/[^a-z]/', '', (string) ($aw['tier'] ?? 'silver')) ?: 'silver'); ?>
                <div class="perk-award-row">
                  <div style="display:flex;align-items:center;gap:9px;min-width:0;flex-wrap:wrap;">
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
                  <span class="muted mono tabular-nums" style="font-size:11px;"><?= SecurityHelper::e(substr((string) $aw['earned_at'], 0, 10)) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Tab 4: Assignment & Recognition History Log -->
  <section class="card is-hidden" data-tab-group="perks-workspace" data-tab-panel="history" aria-label="Assignment and Recognition History">
    <div class="perks-history-toolbar">
      <div>
        <h3>Assignment &amp; Recognition History</h3>
        <p class="muted" style="font-size:12.5px;">Live audit timeline of all perks granted, revoked, and badges awarded to Virtual Assistants.</p>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input id="perks-history-search" type="search" class="input" placeholder="Search VA, perk, or badge..." style="width:210px;max-width:100%;">
        <div class="collab-filter-pills" role="group" aria-label="Filter history type">
          <button type="button" class="collab-filter-chip is-active" data-perks-history-filter="all">All</button>
          <button type="button" class="collab-filter-chip" data-perks-history-filter="perk">Perks</button>
          <button type="button" class="collab-filter-chip" data-perks-history-filter="badge">Badges</button>
        </div>
        <span id="perks-history-count" class="badge badge-brand tabular-nums"><?= count($history) ?> event(s)</span>
      </div>
    </div>

    <div id="perks-history-container">
      <?php if (empty($history)): ?>
        <div class="collab-empty">No perk or badge assignment events recorded yet. Select VAs in the Perks or Badges tab to assign and log in real time.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Event</th>
                <th>Perk / Badge</th>
                <th>Virtual Assistant</th>
                <th>Assigned By</th>
                <th>Recorded</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $ev): ?>
                <?php [$evLabel, $evClass] = $historyMeta((string) ($ev['event_type'] ?? '')); ?>
                <?php $evHay = strtolower(trim(($ev['item_title'] ?? '') . ' ' . ($ev['va_name'] ?? '') . ' ' . ($ev['actor_name'] ?? ''))); ?>
                <tr data-history-item-type="<?= SecurityHelper::e((string) ($ev['item_type'] ?? 'perk')) ?>"
                    data-history-search="<?= SecurityHelper::e($evHay) ?>">
                  <td><span class="badge <?= SecurityHelper::e($evClass) ?>"><?= SecurityHelper::e($evLabel) ?></span></td>
                  <td>
                    <strong><?= SecurityHelper::e($ev['item_title']) ?></strong>
                    <?php if (!empty($ev['item_meta'])): ?>
                      <div class="muted" style="font-size:11px;"><?= SecurityHelper::e($ev['item_meta']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($ev['va_name'])): ?>
                      <div style="display:flex;align-items:center;gap:8px;">
                        <?php if (!empty($ev['va_avatar'])): ?>
                          <img src="<?= SecurityHelper::e($ev['va_avatar']) ?>" alt="<?= SecurityHelper::e($ev['va_name']) ?>" class="avatar-xs">
                        <?php else: ?>
                          <span class="avatar-xs"><?= SecurityHelper::e(strtoupper(substr((string) $ev['va_name'], 0, 2))) ?></span>
                        <?php endif; ?>
                        <span><?= SecurityHelper::e($ev['va_name']) ?></span>
                      </div>
                    <?php else: ?>
                      <span class="muted">Catalog Update</span>
                    <?php endif; ?>
                  </td>
                  <td><?= SecurityHelper::e($ev['actor_name'] ?? 'Staff') ?></td>
                  <td class="muted mono tabular-nums" style="font-size:11.5px;"><?= SecurityHelper::e(substr((string) $ev['created_at'], 0, 16)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
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
