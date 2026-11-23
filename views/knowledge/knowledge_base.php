<?php
require_once __DIR__ . '/../partials/icons.php';

$docs = $data['docs'] ?? [];
$categories = $data['categories'] ?? [];
$canManageKb = in_array($role, ['admin', 'team_leader', 'hrm'], true);
$docsMap = [];
foreach ($docs as $d) {
    $safeHtml = SecurityHelper::sanitizeRichHtml((string) ($d['content'] ?? ''), 60000);
    $d['content'] = $safeHtml;
    $docsMap[$d['id']] = $d;
}
$catCounts = [];
foreach ($docs as $d) {
    $cKey = strtolower(trim((string) ($d['category'] ?? '')));
    if ($cKey !== '') {
        $catCounts[$cKey] = ($catCounts[$cKey] ?? 0) + 1;
    }
}
?>

<div class="page-header no-print">
  <div>
    <h1>SOP Knowledge Base</h1>
    <p class="muted">Standard operating procedures and client playbooks with live search, category filtering, full-document reading, and PDF export.</p>
  </div>
  <?php if ($canManageKb): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-new-category">+ New Category</button>
      <button type="button" class="btn btn-primary" id="btn-kb-new-sop">+ Publish SOP</button>
    </div>
  <?php endif; ?>
</div>

<div class="card kb-filter-card no-print">
  <div class="kb-filter-top">
    <div class="kb-search-box">
      <svg class="kb-search-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
      </svg>
      <input id="kb-search" type="search" class="input kb-search-input" placeholder="Search SOPs by title, keyword, or client...">
    </div>
    <span id="kb-visible-count" class="kb-visible-count mono tabular-nums"><?= count($docs) ?> SOP(s)</span>
  </div>

  <div class="kb-cat-bar">
    <span class="kb-cat-bar-label">Categories</span>
    <div class="kb-category-pills" id="kb-category-pills" role="tablist" aria-label="SOP Categories">
      <button type="button" class="kb-cat-pill active" data-kb-cat="">
        <span>All</span>
        <span class="kb-cat-count"><?= count($docs) ?></span>
      </button>
      <?php foreach ($categories as $cat): ?>
        <?php
          $catName = (string) ($cat['name'] ?? '');
          $catCount = (int) ($catCounts[strtolower(trim($catName))] ?? 0);
        ?>
        <button type="button" class="kb-cat-pill" data-kb-cat="<?= SecurityHelper::e($catName) ?>">
          <span><?= SecurityHelper::e($catName) ?></span>
          <span class="kb-cat-count"><?= $catCount ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (empty($docs)): ?>
  <div class="card no-print">
    <p class="muted">No SOP documents published yet.</p>
  </div>
<?php else: ?>
  <div class="grid-2 no-print" id="kb-docs-grid">
    <?php foreach ($docs as $doc): ?>
      <?php
        $safeDocHtml = $docsMap[$doc['id']]['content'] ?? '';
        $plainSearch = $doc['title'] . ' ' . $doc['category'] . ' ' . ($doc['client_name'] ?? 'Global') . ' ' . strip_tags((string) $doc['content']);
      ?>
      <article class="card-k card-hover kb-doc-card"
               tabindex="0"
               data-kb-doc-id="<?= SecurityHelper::e($doc['id']) ?>"
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
              <?= $safeDocHtml ?>
            </div>
            <div class="kb-rich-fade"></div>
          </div>
        </div>

        <div class="section-art-two">
          <div class="kb-card-read-hint" aria-hidden="true">Click container to read full document</div>
          <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);">
            <button type="button" class="btn btn-secondary btn-sm" data-kb-print="<?= SecurityHelper::e($doc['id']) ?>">
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

<script type="application/json" id="kb-docs-json"><?= json_encode($docsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Dedicated Single-SOP Printable Sheet (rendered only during PDF print) -->
<div id="kb-print-stage" class="kb-print-stage" aria-hidden="true"></div>

<!-- Centered Full SOP Reader Modal -->
<div id="modal-kb-reader" class="modal-backdrop is-hidden no-print">
  <div class="modal-panel modal-panel-kb-reader" role="dialog" aria-modal="true" aria-labelledby="kb-reader-title">
    <div class="modal-head kb-reader-head">
      <div style="min-width:0;">
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;">
          <span class="badge badge-brand" id="kb-reader-category">SOP</span>
          <span class="badge badge-neutral" id="kb-reader-scope">Global Playbook</span>
        </div>
        <h2 id="kb-reader-title" style="font-size:20px;line-height:1.25;margin-bottom:2px;">SOP Document</h2>
        <div class="muted" id="kb-reader-meta" style="font-size:12px;"></div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0;">
        <button type="button" class="btn btn-secondary btn-sm" id="btn-kb-reader-pdf">
          <?= workly_icon_svg('Download', 14) ?> <span>Download PDF</span>
        </button>
        <?php if ($canManageKb): ?>
          <button type="button" class="btn btn-primary btn-sm" id="btn-kb-reader-edit">
            <?= workly_icon_svg('Edit', 14) ?> <span>Edit Document</span>
          </button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
      </div>
    </div>
    <div class="modal-body kb-reader-body">
      <div class="kb-reader-sheet">
        <div id="kb-reader-content" class="kb-rich-content kb-doc-surface"></div>
      </div>
    </div>
  </div>
</div>

<?php if ($canManageKb): ?>
  <div id="drawer-new-category" class="drawer-backdrop is-hidden no-print">
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

  <!-- Word-Style Document Studio Modal (Create & Edit SOPs) -->
  <div id="modal-kb-studio" class="modal-backdrop is-hidden no-print">
    <div class="modal-panel modal-panel-kb-studio" role="dialog" aria-modal="true" aria-labelledby="kb-studio-heading">
      <div class="modal-head kb-studio-head">
        <div style="min-width:0;">
          <h3 id="kb-studio-heading">Publish Knowledge Document</h3>
          <div class="muted" id="kb-studio-stats" style="font-size:12px;">0 words • 0 characters</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
        </div>
      </div>

      <form id="form-kb-studio"
            class="kb-studio-form"
            data-api-form="/api/v1/knowledge"
            data-api-method="POST"
            data-kb-studio-form>
        <input type="hidden" name="content" data-kb-input>

        <div class="kb-studio-meta-bar">
          <div class="kb-studio-meta-title">
            <label class="form-label" for="kb-studio-title">Document Title</label>
            <input class="input" id="kb-studio-title" name="title" required placeholder="Weekly Executive Inbox & Client Escalation SOP">
          </div>
          <div class="kb-studio-meta-field">
            <label class="form-label" for="kb-studio-category">Category</label>
            <select class="select" id="kb-studio-category" name="category" required>
              <?php if (empty($categories)): ?>
                <option value="General SOP">General SOP</option>
              <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= SecurityHelper::e($cat['name']) ?>"><?= SecurityHelper::e($cat['name']) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="kb-studio-meta-field">
            <label class="form-label" for="kb-studio-client">Scope (Optional Client)</label>
            <select class="select" id="kb-studio-client" name="client_id">
              <option value="">Global (All VAs)</option>
              <?php foreach ($data['clients'] as $c): ?>
                <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="kb-doc-ribbon" role="toolbar" aria-label="Document formatting controls">
          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-cmd="undo" title="Undo">Undo</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="redo" title="Redo">Redo</button>
          </div>

          <div class="kb-ribbon-group">
            <select class="kb-tool-select" id="kb-block-select" aria-label="Text style">
              <option value="P">Normal Text</option>
              <option value="H1">Heading 1</option>
              <option value="H2">Heading 2</option>
              <option value="H3">Heading 3</option>
              <option value="BLOCKQUOTE">Quote</option>
              <option value="PRE">Code Block</option>
            </select>

            <select class="kb-tool-select" id="kb-font-family" aria-label="Font family">
              <option value="">Default Font</option>
              <option value="'Plus Jakarta Sans', sans-serif">Plus Jakarta Sans</option>
              <option value="Georgia, serif">Georgia Serif</option>
              <option value="Arial, Helvetica, sans-serif">Arial</option>
              <option value="'Times New Roman', serif">Times New Roman</option>
              <option value="'Courier New', monospace">Monospace</option>
            </select>

            <select class="kb-tool-select kb-tool-select-sm" id="kb-font-size" aria-label="Font size">
              <option value="">Size</option>
              <option value="12">12px</option>
              <option value="14">14px</option>
              <option value="16">16px</option>
              <option value="18">18px</option>
              <option value="22">22px</option>
              <option value="26">26px</option>
              <option value="32">32px</option>
            </select>

            <button type="button" class="kb-tool-btn" data-kb-scale="up" title="Increase selected text size">A+</button>
            <button type="button" class="kb-tool-btn" data-kb-scale="down" title="Decrease selected text size">A-</button>
          </div>

          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-cmd="bold" title="Bold"><strong>B</strong></button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="italic" title="Italic"><em>I</em></button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="underline" title="Underline"><u>U</u></button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
          </div>

          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-case="upper" title="Convert selected text to UPPERCASE">AA</button>
            <button type="button" class="kb-tool-btn" data-kb-case="lower" title="Convert selected text to lowercase">aa</button>
            <button type="button" class="kb-tool-btn" data-kb-case="title" title="Convert selected text to Title Case">Aa</button>
            <button type="button" class="kb-tool-btn" data-kb-case="sentence" title="Convert selected text to Sentence case">A.</button>
          </div>

          <div class="kb-ribbon-group">
            <label class="kb-color-control" title="Text color">
              <span>A</span>
              <input type="color" id="kb-fore-color" value="#1e293b" aria-label="Text color">
            </label>
            <label class="kb-color-control" title="Highlight color">
              <span>HL</span>
              <input type="color" id="kb-hilite-color" value="#fef08a" aria-label="Highlight color">
            </label>
          </div>

          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-cmd="justifyLeft" title="Align left">Left</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="justifyCenter" title="Align center">Center</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="justifyRight" title="Align right">Right</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="justifyFull" title="Justify">Justify</button>
          </div>

          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-cmd="insertUnorderedList" title="Bulleted list">&bull; List</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="insertOrderedList" title="Numbered list">1. List</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="indent" title="Increase indent">Indent</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="outdent" title="Decrease indent">Outdent</button>
          </div>

          <div class="kb-ribbon-group">
            <button type="button" class="kb-tool-btn" data-kb-insert="hr" title="Insert horizontal divider">Divider</button>
            <button type="button" class="kb-tool-btn" data-kb-insert="table" title="Insert 3x3 table">Table</button>
            <button type="button" class="kb-tool-btn" id="btn-kb-toggle-linkbar" title="Insert link">Link</button>
            <button type="button" class="kb-tool-btn" data-kb-cmd="removeFormat" title="Clear formatting">Clear</button>
          </div>
        </div>

        <div id="kb-link-bar" class="kb-link-bar is-hidden">
          <input type="url" id="kb-link-url" class="input" placeholder="https://example.com/resource">
          <button type="button" class="btn btn-primary btn-sm" id="btn-kb-apply-link">Apply Link</button>
          <button type="button" class="btn btn-secondary btn-sm" id="btn-kb-cancel-link">Cancel</button>
        </div>

        <div class="kb-studio-workspace" id="kb-studio-workspace">
          <div class="kb-studio-page kb-rich-content kb-doc-surface"
               id="kb-studio-editor"
               contenteditable="true"
               tabindex="0"
               role="textbox"
               aria-multiline="true"
               data-kb-editor
               data-placeholder="Start writing your SOP document here... Use headings, tables, font sizing, text case, highlights, and lists just like a word processor."
               aria-label="Knowledge document editor"></div>
        </div>

        <div class="kb-studio-footer">
          <span class="muted" style="font-size:12px;">Saved SOPs can be read in full popup view or downloaded as PDF anytime.</span>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button type="button" class="btn btn-secondary" data-close-modal>Cancel</button>
            <button type="button" class="btn btn-secondary" id="btn-kb-save-pdf">
              <?= workly_icon_svg('Download', 14) ?> <span>Save &amp; Download PDF</span>
            </button>
            <button type="submit" class="btn btn-primary" id="btn-kb-studio-submit">Publish SOP</button>
          </div>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
