<?php
require_once __DIR__ . '/../partials/icons.php';

$docs = $data['docs'] ?? [];
$categories = $data['categories'] ?? [];
$canManageKb = in_array($role, ['admin', 'team_leader', 'hrm'], true);
$docsMap = [];
foreach ($docs as $d) {
    $docsMap[$d['id']] = $d;
}
?>

<div class="page-header no-print">
  <div>
    <h1>SOP Knowledge Base</h1>
    <p class="muted">Standard operating procedures and client playbooks with live search, category filtering, rich formatting, and PDF export.</p>
  </div>
  <?php if ($canManageKb): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-new-category">+ New Category</button>
      <button type="button" class="btn btn-primary" data-open-drawer="drawer-new-sop">+ Publish SOP</button>
    </div>
  <?php endif; ?>
</div>

<div class="card no-print" style="margin-bottom:18px;padding:14px 16px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <input id="kb-search" type="search" class="input" placeholder="Search SOPs by title, keyword, or client..." style="max-width:380px;">
    <span class="muted" style="font-size:12px;">Click a category tag below or print any SOP directly as PDF.</span>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:6px;" id="kb-category-pills">
    <button type="button" class="tab-btn active" data-kb-cat="">All Categories</button>
    <?php foreach ($categories as $cat): ?>
      <button type="button" class="tab-btn" data-kb-cat="<?= SecurityHelper::e($cat['name']) ?>">
        <?= SecurityHelper::e($cat['name']) ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($docs)): ?>
  <div class="card">
    <p class="muted">No SOP documents published yet.</p>
  </div>
<?php else: ?>
  <div class="grid-2" id="kb-docs-grid">
    <?php foreach ($docs as $doc): ?>
      <?php
        $plainSearch = $doc['title'] . ' ' . $doc['category'] . ' ' . ($doc['client_name'] ?? 'Global') . ' ' . strip_tags((string) $doc['content']);
      ?>
      <article class="card card-hover kb-doc-card"
               data-kb-cat="<?= SecurityHelper::e($doc['category']) ?>"
               data-kb-search="<?= SecurityHelper::e($plainSearch) ?>">
      <div class="section-art-one">
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
          <div style="margin-bottom:10px;">
            <span class="badge badge-info">Client Scope: <?= SecurityHelper::e($doc['client_name']) ?></span>
          </div>
        <?php else: ?>
          <div style="margin-bottom:10px;">
            <span class="badge badge-neutral">Global Playbook</span>
          </div>
        <?php endif; ?>

        <div class="kb-rich-clip" style="margin-bottom:10px;">
          <div class="kb-rich-content">
            <?= SecurityHelper::sanitizeRichHtml((string) $doc['content']) ?>
          </div>
          <div class="kb-rich-fade"></div>
        </div>

      <div class="section-art-two">
        <button type="button" class="btn btn-secondary btn-sm kb-expand-btn no-print" data-kb-expand style="margin-bottom:12px;">View More</button>

        <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);">
          <button type="button" class="btn btn-secondary btn-sm" data-kb-print>
            <?= workly_icon_svg('Download', 14) ?> <span>Download PDF</span>
          </button>
          <?php if ($canManageKb): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-kb-edit="<?= SecurityHelper::e($doc['id']) ?>">
              <?= workly_icon_svg('Edit', 14) ?> <span>Edit</span>
            </button>
          <?php endif; ?>
          <?php if (in_array($role, ['admin', 'team_leader'], true)): ?>
            <button type="button"
                    class="btn btn-danger btn-sm"
                    data-action-url="/api/v1/knowledge/<?= SecurityHelper::e($doc['id']) ?>"
                    data-action-method="DELETE">Remove Document</button>
          <?php endif; ?>
        </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($canManageKb): ?>
  <script type="application/json" id="kb-docs-json"><?= json_encode($docsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

  <div id="drawer-new-category" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Add SOP Category">
      <div class="drawer-head">
        <h3>Add SOP Category</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/knowledge/categories" data-api-method="POST">
          <div class="form-group">
            <label class="form-label">Category Name</label>
            <input class="input" name="name" required placeholder="e.g. Client Onboarding, Financial Ops">
          </div>
          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Category</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

  <!-- Create SOP Drawer with Select Category & WYSIWYG Editor -->
  <div id="drawer-new-sop" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Publish Knowledge Document">
      <div class="drawer-head">
        <h3>Publish Knowledge Document</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/knowledge" data-api-method="POST" data-wysiwyg-form>
          <div class="form-group">
            <label class="form-label">Document Title</label>
            <input class="input" name="title" required placeholder="Weekly Executive Inbox SOP">
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Category</label>
              <select class="select" name="category" required>
                <?php if (empty($categories)): ?>
                  <option value="General SOP">General SOP</option>
                <?php else: ?>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= SecurityHelper::e($cat['name']) ?>"><?= SecurityHelper::e($cat['name']) ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
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
            <label class="form-label">SOP Content (WYSIWYG Editor)</label>
            <input type="hidden" name="content" data-wysiwyg-input required>
            <div class="wysiwyg-wrap" data-wysiwyg>
              <div class="wysiwyg-toolbar">
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="bold" title="Bold">B</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="italic" title="Italic"><em>I</em></button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="formatBlock" data-wysiwyg-val="H3" title="Heading">H3</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="insertUnorderedList" title="Bulleted List">&bull; List</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="insertOrderedList" title="Numbered List">1. List</button>
              </div>
              <div class="wysiwyg-editor" contenteditable="true" data-wysiwyg-editor aria-label="SOP Rich Content Editor"></div>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Publish SOP</button>
          </div>
        </form>
      </div>
    </aside>
  </div>

  <!-- Edit SOP Drawer with Select Category & WYSIWYG Editor -->
  <div id="drawer-edit-sop" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Edit Knowledge Document">
      <div class="drawer-head">
        <h3>Edit Knowledge Document</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form id="form-edit-sop" data-api-form="/api/v1/knowledge" data-api-method="PUT" data-wysiwyg-form>
          <div class="form-group">
            <label class="form-label">Document Title</label>
            <input class="input" name="title" required>
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Category</label>
              <select class="select" name="category" required>
                <?php if (empty($categories)): ?>
                  <option value="General SOP">General SOP</option>
                <?php else: ?>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= SecurityHelper::e($cat['name']) ?>"><?= SecurityHelper::e($cat['name']) ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
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
            <label class="form-label">SOP Content (WYSIWYG Editor)</label>
            <input type="hidden" name="content" data-wysiwyg-input required>
            <div class="wysiwyg-wrap" data-wysiwyg>
              <div class="wysiwyg-toolbar">
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="bold" title="Bold">B</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="italic" title="Italic"><em>I</em></button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="underline" title="Underline"><u>U</u></button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="formatBlock" data-wysiwyg-val="H3" title="Heading">H3</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="insertUnorderedList" title="Bulleted List">&bull; List</button>
                <button type="button" class="wysiwyg-btn" data-wysiwyg-cmd="insertOrderedList" title="Numbered List">1. List</button>
              </div>
              <div class="wysiwyg-editor" contenteditable="true" data-wysiwyg-editor aria-label="Edit SOP Rich Content"></div>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Save SOP Changes</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
