<?php
require_once __DIR__ . '/../partials/icons.php';

$threads = $data['threads'] ?? [];
$totalThreads = (int) ($data['total_threads'] ?? count($threads));
$unreadCount = (int) ($data['unread_count'] ?? 0);
$messages = $data['messages'] ?? [];
$attachmentsMap = $data['attachments'] ?? [];
$selectedThread = (string) ($data['selected_thread'] ?? '');
$selectedFolder = (string) ($data['selected_folder'] ?? 'all');
$mailbox = $data['mailbox'] ?? null;
$lastMsg = !empty($messages) ? end($messages) : null;
$linkedEmail = ($mailbox && !empty($mailbox['email'])) ? (string) $mailbox['email'] : '';
$isMailboxActive = $mailbox && (($mailbox['oauth_status'] ?? '') === 'active');

$replyRecipient = '';
if ($lastMsg) {
    $sLow = strtolower((string) $lastMsg['sender_email']);
    $uLow = strtolower((string) $user['email']);
    $mLow = strtolower($linkedEmail);
    $replyRecipient = ($sLow === $uLow || ($mLow !== '' && $sLow === $mLow))
        ? (string) $lastMsg['recipient_email']
        : (string) $lastMsg['sender_email'];
}
$defaultBadgeLabel = $isMailboxActive ? ('Gmail: ' . $linkedEmail) : 'Workspace Mail Ready';
$defaultBadgeClass = $isMailboxActive ? 'badge badge-success' : 'badge badge-neutral';

$renderRichToolbar = static function (string $editorId): void {
    ?>
    <div class="gmail-editor-toolbar" data-rich-toolbar="<?= SecurityHelper::e($editorId) ?>">
      <div class="gmail-toolbar-group">
        <button type="button" class="gmail-tool-btn" data-rich-cmd="bold" title="Bold (Ctrl+B)"><strong>B</strong></button>
        <button type="button" class="gmail-tool-btn" data-rich-cmd="italic" title="Italic (Ctrl+I)"><em>I</em></button>
        <button type="button" class="gmail-tool-btn" data-rich-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
        <button type="button" class="gmail-tool-btn" data-rich-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
      </div>
      <div class="gmail-toolbar-group">
        <select class="gmail-tool-select" data-rich-block="<?= SecurityHelper::e($editorId) ?>" title="Text block style" aria-label="Text block style">
          <option value="P">Normal</option>
          <option value="H2">Heading</option>
          <option value="H3">Subheading</option>
          <option value="BLOCKQUOTE">Quote</option>
          <option value="PRE">&lt;&gt; Code Block</option>
        </select>
        <label class="gmail-color-btn" title="Text color">
          <span>A</span>
          <input type="color" value="#1f2937" data-rich-color="<?= SecurityHelper::e($editorId) ?>" aria-label="Text color">
        </label>
      </div>
      <div class="gmail-toolbar-group">
        <button type="button" class="gmail-tool-btn" data-rich-cmd="insertUnorderedList" title="Bulleted list">• List</button>
        <button type="button" class="gmail-tool-btn" data-rich-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <button type="button" class="gmail-tool-btn" data-rich-inline-code="<?= SecurityHelper::e($editorId) ?>" title="Wrap selection in code &lt;&gt;">&lt;code&gt;</button>
      </div>
      <div class="gmail-toolbar-group">
        <button type="button" class="gmail-tool-btn" data-rich-link-toggle="<?= SecurityHelper::e($editorId) ?>" title="Insert hyperlink">Link</button>
        <button type="button" class="gmail-tool-btn" data-rich-cmd="removeFormat" title="Clear formatting">Clear</button>
        <button type="button" class="gmail-tool-btn gmail-tool-btn-code" data-rich-source-toggle="<?= SecurityHelper::e($editorId) ?>" title="Toggle raw HTML &lt;&gt; source">&lt;&gt; HTML</button>
      </div>
    </div>
    <div class="gmail-link-popover is-hidden" data-rich-link-bar="<?= SecurityHelper::e($editorId) ?>">
      <input type="url" class="input input-sm" data-rich-link-url="<?= SecurityHelper::e($editorId) ?>" placeholder="https://example.com">
      <button type="button" class="btn btn-primary btn-sm" data-rich-link-apply="<?= SecurityHelper::e($editorId) ?>">Apply Link</button>
      <button type="button" class="btn btn-secondary btn-sm" data-rich-link-cancel="<?= SecurityHelper::e($editorId) ?>">Cancel</button>
    </div>
    <?php
};
?>

<div id="gmail-hub-bootstrap"
     class="is-hidden"
     data-user-email="<?= SecurityHelper::e((string) $user['email']) ?>"
     data-linked-email="<?= SecurityHelper::e($linkedEmail) ?>"
     data-folder="<?= SecurityHelper::e($selectedFolder) ?>"
     data-thread="<?= SecurityHelper::e($selectedThread) ?>"
     data-unread-count="<?= $unreadCount ?>"
     data-connected="<?= $isMailboxActive ? '1' : '0' ?>">
</div>

<div class="page-header gmail-hub-header">
  <div>
    <h1>Email Communications Hub</h1>
    <p class="muted">Real-time Gmail OAuth 2.0 sync, full rich HTML &amp; image rendering, CC delivery, and &lt;&gt; rich composition.</p>
  </div>
  <div class="gmail-header-actions">
    <span id="gmail-live-sync-pill" class="gmail-live-pill <?= $isMailboxActive ? '' : 'is-hidden' ?>" title="Real-time Gmail background synchronization active">
      <span class="gmail-live-dot"></span>
      <span id="gmail-live-sync-text">Live Sync Active</span>
    </span>

    <span id="gmail-oauth-status-badge"
          class="<?= $defaultBadgeClass ?>"
          data-default-label="<?= SecurityHelper::e($defaultBadgeLabel) ?>"
          data-default-class="<?= SecurityHelper::e($defaultBadgeClass) ?>">
      <?= SecurityHelper::e($defaultBadgeLabel) ?>
    </span>

    <button type="button" id="btn-google-signin-gmail" class="gsi-material-button <?= $isMailboxActive ? 'is-hidden' : '' ?>">
      <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      <span>Connect Gmail</span>
    </button>

    <button type="button" id="btn-gmail-sync-now" class="btn btn-secondary btn-sm <?= $isMailboxActive ? '' : 'is-hidden' ?>">Sync Now</button>
    <button type="button" id="btn-gmail-disconnect" class="btn btn-secondary btn-sm <?= $isMailboxActive ? '' : 'is-hidden' ?>">Disconnect</button>
    <button type="button" id="btn-open-compose" class="btn btn-primary" data-open-drawer="drawer-compose-email">+ Compose</button>
  </div>
</div>

<div class="card gmail-command-bar">
  <div class="gmail-toolbar">
    <div class="gmail-folder-tabs" role="tablist" aria-label="Email Folders">
      <a href="/email?folder=all" data-email-folder="all" class="gmail-folder-tab <?= $selectedFolder === 'all' ? 'active' : '' ?>">
        All Mail (<span id="gmail-all-tab-count"><?= $totalThreads ?></span>)
      </a>
      <a href="/email?folder=inbox" data-email-folder="inbox" class="gmail-folder-tab <?= $selectedFolder === 'inbox' ? 'active' : '' ?>">Inbox</a>
      <a href="/email?folder=unread" data-email-folder="unread" class="gmail-folder-tab <?= $selectedFolder === 'unread' ? 'active' : '' ?>">
        Unread <span id="gmail-unread-tab-badge" class="gmail-tab-pill <?= $unreadCount > 0 ? '' : 'is-hidden' ?>"><?= $unreadCount ?></span>
      </a>
      <a href="/email?folder=sent" data-email-folder="sent" class="gmail-folder-tab <?= $selectedFolder === 'sent' ? 'active' : '' ?>">Sent</a>
    </div>

    <div class="gmail-search-wrap">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
      </svg>
      <input type="search" id="gmail-search-input" class="gmail-search-input" placeholder="Search sender, subject, or keywords...">
    </div>
  </div>
</div>

<div class="email-layout email-hub-split">
  <!-- Conversation Thread Rail -->
  <div class="card gmail-list-card">
    <div class="gmail-list-head">
      <h4>Conversations (<span id="gmail-thread-count-label"><?= count($threads) ?></span>)</h4>
      <span class="muted mono" id="gmail-last-sync-label" style="font-size:11px;">
        <?= !empty($mailbox['last_synced_at']) ? SecurityHelper::e('Synced ' . substr((string) $mailbox['last_synced_at'], 11, 5)) : 'Ready' ?>
      </span>
    </div>

    <div id="gmail-thread-list" class="gmail-thread-list">
      <?php if (empty($threads)): ?>
        <div class="gmail-empty-state">
          <div class="gmail-empty-icon"><?= workly_icon_svg('Mail', 24) ?></div>
          <p class="muted" style="font-size:13px;">No conversations in this folder yet. Connect Gmail above or click <strong>+ Compose</strong>.</p>
        </div>
      <?php else: ?>
        <?php foreach ($threads as $th): ?>
          <?php
            $isUnread = !empty($th['is_unread']);
            $snippet = !empty($th['snippet'])
                ? (string) $th['snippet']
                : mb_substr(trim(strip_tags((string) ($th['body'] ?? ''))), 0, 110, 'UTF-8');
            $senderName = (string) ($th['sender_name'] ?: $th['sender_email']);
            $senderInit = strtoupper(substr($senderName, 0, 2));
            $msgCount = (int) ($th['msg_count'] ?? 1);
            $attCount = (int) ($th['att_count'] ?? 0);
          ?>
          <a href="/email?folder=<?= urlencode($selectedFolder) ?>&thread=<?= urlencode((string) $th['thread_id']) ?>"
             data-thread-id="<?= SecurityHelper::e((string) $th['thread_id']) ?>"
             class="gmail-thread-row <?= $selectedThread === $th['thread_id'] ? 'active' : '' ?> <?= $isUnread ? 'is-unread' : '' ?>">
            <span class="avatar avatar-sm" style="margin-top:2px;flex-shrink:0;"><?= SecurityHelper::e($senderInit) ?></span>
            <div class="gmail-thread-row-body">
              <div class="gmail-thread-row-top">
                <strong class="gmail-thread-sender"><?= SecurityHelper::e($senderName) ?></strong>
                <span class="gmail-thread-time mono"><?= SecurityHelper::e(substr((string) $th['created_at'], 5, 11)) ?></span>
              </div>
              <div class="gmail-thread-subject-line">
                <?php if ($isUnread): ?><span class="gmail-unread-dot" aria-label="Unread"></span><?php endif; ?>
                <span class="gmail-thread-subject"><?= SecurityHelper::e((string) $th['subject']) ?></span>
                <?php if ($msgCount > 1): ?>
                  <span class="gmail-thread-count-chip mono"><?= $msgCount ?></span>
                <?php endif; ?>
                <?php if ($attCount > 0): ?>
                  <span class="gmail-thread-att-icon" title="<?= $attCount ?> attachment(s)"><?= workly_icon_svg('Paperclip', 12) ?></span>
                <?php endif; ?>
              </div>
              <div class="gmail-thread-snippet"><?= SecurityHelper::e($snippet) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Reading & Reply Canvas -->
  <div class="card gmail-reader-card" id="gmail-reading-pane">
    <div id="gmail-reader-empty" class="gmail-empty-state <?= empty($messages) ? '' : 'is-hidden' ?>" style="min-height:420px;">
      <div class="gmail-empty-icon"><?= workly_icon_svg('Mail', 28) ?></div>
      <h3 style="margin-bottom:6px;">Select an Email Conversation</h3>
      <p class="muted" style="max-width:360px;">Choose a conversation on the left to inspect rich HTML emails &amp; images, or click <strong>+ Compose</strong> to send a new message.</p>
    </div>

    <div id="gmail-reader-active" class="<?= empty($messages) ? 'is-hidden' : '' ?>">
      <div class="gmail-reader-header">
        <div style="min-width:0;flex:1;">
          <h2 id="gmail-reader-subject" class="gmail-reader-subject"><?= !empty($messages) ? SecurityHelper::e((string) $messages[0]['subject']) : '' ?></h2>
          <div class="gmail-reader-meta">
            <span id="gmail-reader-msg-count"><?= count($messages) ?> message(s)</span>
            <span>·</span>
            <span class="mono" id="gmail-reader-thread-chip">Thread <?= SecurityHelper::e(substr($selectedThread, 0, 14)) ?></span>
          </div>
        </div>
        <div class="gmail-reader-actions">
          <button type="button" class="btn btn-secondary btn-sm" id="btn-gmail-scroll-reply">Reply</button>
          <button type="button" class="btn btn-secondary btn-sm" id="btn-gmail-forward">Forward</button>
          <button type="button" class="btn btn-secondary btn-sm" id="btn-gmail-mark-unread" data-thread-id="<?= SecurityHelper::e($selectedThread) ?>">Mark Unread</button>
        </div>
      </div>

      <div id="gmail-thread-messages" class="gmail-messages-stack">
        <?php $totalMsgs = count($messages); ?>
        <?php foreach ($messages as $idx => $msg): ?>
          <?php
            $sLow = strtolower((string) $msg['sender_email']);
            $isMine = ($sLow === strtolower((string) $user['email']) || ($linkedEmail !== '' && $sLow === strtolower($linkedEmail)));
            $isExpanded = ($idx === $totalMsgs - 1);
            $msgAtts = $attachmentsMap[$msg['id']] ?? [];
            $regularAtts = array_values(array_filter($msgAtts, static fn(array $a): bool => empty($a['is_inline'])));
            $sName = (string) ($msg['sender_name'] ?: $msg['sender_email']);
            $sInit = strtoupper(substr($sName, 0, 2));
            $safeHtml = !empty($msg['body_html']) ? SecurityHelper::sanitizeEmailHtml((string) $msg['body_html'], 150000) : '';
            $previewLine = !empty($msg['snippet'])
                ? (string) $msg['snippet']
                : mb_substr(trim(strip_tags((string) ($msg['body'] ?? ''))), 0, 90, 'UTF-8');
          ?>
          <article class="gmail-msg-card <?= $isMine ? 'is-mine' : '' ?> <?= $isExpanded ? 'is-expanded' : 'is-collapsed' ?>"
                   data-msg-id="<?= SecurityHelper::e((string) $msg['id']) ?>">
            <header class="gmail-msg-head" data-toggle-msg-card>
              <div class="gmail-msg-sender-wrap">
                <span class="avatar avatar-sm"><?= SecurityHelper::e($sInit) ?></span>
                <div style="min-width:0;flex:1;">
                  <div class="gmail-msg-sender-line">
                    <strong><?= SecurityHelper::e($sName) ?></strong>
                    <span class="muted gmail-msg-addr">&lt;<?= SecurityHelper::e((string) $msg['sender_email']) ?>&gt;</span>
                  </div>
                  <div class="gmail-msg-recip-line">
                    <span>To: <strong><?= SecurityHelper::e((string) $msg['recipient_email']) ?></strong></span>
                    <?php if (!empty($msg['cc_email'])): ?>
                      <span>· CC: <?= SecurityHelper::e((string) $msg['cc_email']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="gmail-msg-collapsed-preview"><?= SecurityHelper::e($previewLine) ?></div>
                </div>
              </div>
              <div class="gmail-msg-time-wrap">
                <span class="muted mono" style="font-size:11.5px;"><?= SecurityHelper::e(substr((string) $msg['created_at'], 0, 16)) ?></span>
                <span class="gmail-msg-chevron" aria-hidden="true">▾</span>
              </div>
            </header>

            <div class="gmail-msg-body-wrap">
              <?php if ($safeHtml !== ''): ?>
                <div class="gmail-msg-html-frame"><?= $safeHtml ?></div>
              <?php else: ?>
                <div class="gmail-msg-plain"><?= SecurityHelper::e((string) $msg['body']) ?></div>
              <?php endif; ?>

              <?php if (!empty($regularAtts)): ?>
                <div class="gmail-msg-attachments">
                  <?php foreach ($regularAtts as $att): ?>
                    <?php
                      $isImg = str_starts_with(strtolower((string) ($att['mime_type'] ?? '')), 'image/');
                      $attUrl = '/api/v1/email/attachments/' . urlencode((string) $att['id']);
                      $kb = max(1, (int) round(((int) $att['size_bytes']) / 1024));
                    ?>
                    <?php if ($isImg): ?>
                      <div class="gmail-att-image-card">
                        <button type="button" class="gmail-att-thumb-btn" data-preview-img-src="<?= SecurityHelper::e($attUrl . '?inline=1') ?>" data-preview-img-name="<?= SecurityHelper::e((string) $att['filename']) ?>" data-download-href="<?= SecurityHelper::e($attUrl) ?>">
                          <img src="<?= SecurityHelper::e($attUrl . '?inline=1') ?>" alt="<?= SecurityHelper::e((string) $att['filename']) ?>" loading="lazy">
                        </button>
                        <div class="gmail-att-image-meta">
                          <span class="gmail-att-name"><?= SecurityHelper::e((string) $att['filename']) ?></span>
                          <a href="<?= SecurityHelper::e($attUrl) ?>" download="<?= SecurityHelper::e((string) $att['filename']) ?>" class="gmail-att-dl-link" title="Download"><?= workly_icon_svg('Download', 13) ?></a>
                        </div>
                      </div>
                    <?php else: ?>
                      <a class="gmail-att-file-chip" href="<?= SecurityHelper::e($attUrl) ?>" download="<?= SecurityHelper::e((string) $att['filename']) ?>">
                        <?= workly_icon_svg('Paperclip', 13) ?>
                        <span class="gmail-att-name"><?= SecurityHelper::e((string) $att['filename']) ?></span>
                        <span class="muted mono" style="font-size:10.5px;">(<?= $kb ?> KB)</span>
                      </a>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <!-- Inline Rich HTML Reply Composer -->
      <form id="gmail-reply-form" data-gmail-form="/api/v1/email/reply" enctype="multipart/form-data" class="gmail-reply-box">
        <input type="hidden" name="thread_id" value="<?= SecurityHelper::e($selectedThread) ?>">
        <input type="hidden" name="recipient_email" value="<?= SecurityHelper::e($replyRecipient) ?>">
        <input type="hidden" name="subject" value="<?= !empty($messages) ? SecurityHelper::e((string) $messages[0]['subject']) : '' ?>">

        <div class="grid-2" style="margin-bottom:10px;">
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Reply To</label>
            <input class="input" id="gmail-reply-to-display" value="<?= SecurityHelper::e($replyRecipient) ?>" readonly>
          </div>
          <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">CC (comma-separated, optional)</label>
            <input class="input" type="text" name="cc_email" placeholder="lead@company.com, ops@company.com">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:10px;">
          <label class="form-label">Rich Reply Message</label>
          <div class="gmail-editor-shell">
            <?php $renderRichToolbar('reply'); ?>
            <div class="gmail-rich-editor"
                 id="rich-editor-reply"
                 contenteditable="true"
                 data-rich-surface="reply"
                 data-placeholder="Write your reply with bold, lists, quotes, links, or &lt;&gt; code formatting..."></div>
            <textarea class="textarea gmail-html-source is-hidden"
                      id="rich-source-reply"
                      data-rich-source="reply"
                      rows="5"
                      placeholder="<p>Write HTML email markup...</p>"></textarea>
            <textarea name="body_html" id="rich-hidden-html-reply" class="is-hidden"></textarea>
            <textarea name="body" id="rich-hidden-plain-reply" class="is-hidden"></textarea>
          </div>
        </div>

        <div class="gmail-composer-foot">
          <div style="flex:1 1 240px;min-width:0;">
            <input class="input" type="file" name="attachments[]" multiple data-email-files-input="reply-files-preview" style="padding:6px 10px;">
            <div id="reply-files-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
          </div>
          <button type="submit" class="btn btn-primary">Send Reply</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Right-Side Drawer: Rich Compose Email -->
<div id="drawer-compose-email" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel drawer-panel-wide" role="dialog" aria-label="New Email Message">
    <div class="drawer-head">
      <h3>New Email Message</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>
    <div class="drawer-body">
      <form id="gmail-compose-form" data-gmail-form="/api/v1/email/compose" enctype="multipart/form-data">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">To (Recipient Email)</label>
            <input class="input" type="email" name="recipient_email" list="workly-contacts" required placeholder="recipient@company.com">
            <datalist id="workly-contacts">
              <?php foreach ($data['contacts'] as $ct): ?>
                <option value="<?= SecurityHelper::e($ct['email']) ?>"><?= SecurityHelper::e($ct['name']) ?> (<?= SecurityHelper::e($ct['role']) ?>)</option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <div class="form-group">
            <label class="form-label">CC (comma-separated, optional)</label>
            <input class="input" type="text" name="cc_email" placeholder="cc1@company.com, cc2@company.com">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Subject</label>
          <input class="input" name="subject" required placeholder="Topic or deliverable inquiry">
        </div>

        <div class="form-group">
          <label class="form-label">Rich Email Body</label>
          <div class="gmail-editor-shell">
            <?php $renderRichToolbar('compose'); ?>
            <div class="gmail-rich-editor gmail-rich-editor-lg"
                 id="rich-editor-compose"
                 contenteditable="true"
                 data-rich-surface="compose"
                 data-placeholder="Compose a formatted message using bold, colors, lists, links, or &lt;&gt; HTML..."></div>
            <textarea class="textarea gmail-html-source is-hidden"
                      id="rich-source-compose"
                      data-rich-source="compose"
                      rows="8"
                      placeholder="<p>Write HTML email markup...</p>"></textarea>
            <textarea name="body_html" id="rich-hidden-html-compose" class="is-hidden"></textarea>
            <textarea name="body" id="rich-hidden-plain-compose" class="is-hidden"></textarea>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Attach Files (max 5 files, 8MB each)</label>
          <input class="input" type="file" name="attachments[]" multiple data-email-files-input="compose-files-preview" style="padding:6px 10px;">
          <div id="compose-files-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-primary">Send Message</button>
        </div>
      </form>
    </div>
  </aside>
</div>

<!-- Confirmation Modal before External Email Dispatch -->
<div id="modal-confirm-gmail-send" class="modal-backdrop is-hidden">
  <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Confirm Email Dispatch">
    <div class="modal-head">
      <h3>Confirm Email Delivery</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-gmail-confirm>Close</button>
    </div>
    <div class="modal-body">
      <div id="gmail-confirm-summary" style="display:grid;gap:8px;font-size:13.5px;margin-bottom:18px;"></div>
      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" class="btn btn-secondary" data-close-gmail-confirm>Cancel</button>
        <button type="button" id="btn-confirm-gmail-send-go" class="btn btn-primary">Confirm &amp; Send</button>
      </div>
    </div>
  </div>
</div>

<!-- Image Preview Lightbox Modal -->
<div id="modal-email-image-preview" class="modal-backdrop is-hidden">
  <div class="modal-panel modal-panel-kb-reader" role="dialog" aria-modal="true" aria-label="Email Image Preview">
    <div class="modal-head">
      <h3 id="gmail-lightbox-title">Image Attachment</h3>
      <div style="display:flex;gap:8px;">
        <a id="gmail-lightbox-download" href="#" download class="btn btn-secondary btn-sm">Download</a>
        <button type="button" class="btn btn-secondary btn-sm" data-close-email-lightbox>Close</button>
      </div>
    </div>
    <div class="modal-body gmail-lightbox-body">
      <img id="gmail-lightbox-img" src="" alt="Preview">
    </div>
  </div>
</div>
