<?php
require_once __DIR__ . '/../partials/icons.php';

$threads = $data['threads'] ?? [];
$messages = $data['messages'] ?? [];
$attachmentsMap = $data['attachments'] ?? [];
$selectedThread = $data['selected_thread'] ?? '';
$selectedFolder = $data['selected_folder'] ?? 'all';
$mailbox = $data['mailbox'] ?? null;
$lastMsg = !empty($messages) ? end($messages) : null;
$replyRecipient = '';
if ($lastMsg) {
    $replyRecipient = (strtolower((string) $lastMsg['sender_email']) === strtolower((string) $user['email']) || ($mailbox && strtolower((string) $lastMsg['sender_email']) === strtolower((string) $mailbox['email'])))
        ? (string) $lastMsg['recipient_email']
        : (string) $lastMsg['sender_email'];
}
$defaultBadgeLabel = $mailbox ? ('Linked: ' . $mailbox['email']) : 'Workspace Mail Ready';
$defaultBadgeClass = $mailbox ? 'badge badge-success' : 'badge badge-neutral';
?>

<script type="application/json" id="workly-firebase-config"><?= json_encode($data['firebase_config'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="page-header">
  <div>
    <h1>Email Communications Hub</h1>
    <p class="muted">Synchronized with Gmail via Google OAuth, supporting CC recipients, file attachments, and Gmail-style thread inspection.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <span id="gmail-oauth-status-badge"
          class="<?= $defaultBadgeClass ?>"
          data-default-label="<?= SecurityHelper::e($defaultBadgeLabel) ?>"
          data-default-class="<?= SecurityHelper::e($defaultBadgeClass) ?>">
      <?= SecurityHelper::e($defaultBadgeLabel) ?>
    </span>

    <button type="button" id="btn-google-signin-gmail" class="gsi-material-button">
      <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      <span>Connect Gmail</span>
    </button>

    <button type="button" id="btn-gmail-sync-now" class="btn btn-secondary btn-sm is-hidden">Sync Gmail</button>
    <button type="button" id="btn-gmail-disconnect" class="btn btn-secondary btn-sm is-hidden">Disconnect</button>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-compose-email">+ Compose</button>
  </div>
</div>

<div class="card" style="margin-bottom:16px;padding:12px 16px;">
  <div class="gmail-toolbar">
    <div class="gmail-folder-tabs">
      <a href="/email?folder=all" class="gmail-folder-tab <?= $selectedFolder === 'all' ? 'active' : '' ?>">All Mail (<?= count($threads) ?>)</a>
      <a href="/email?folder=inbox" class="gmail-folder-tab <?= $selectedFolder === 'inbox' ? 'active' : '' ?>">Inbox</a>
      <a href="/email?folder=sent" class="gmail-folder-tab <?= $selectedFolder === 'sent' ? 'active' : '' ?>">Sent</a>
    </div>
    <span class="muted" style="font-size:12px;">
      Sign in with your Google Workspace / Gmail account to pull live threads, reply inline, CC stakeholders, and exchange attachments.
    </span>
  </div>
</div>

<div class="email-layout">
  <div class="card" style="padding:14px;">
    <h4 style="margin-bottom:10px;">Conversations (<?= count($threads) ?>)</h4>
    <?php if (empty($threads)): ?>
      <p class="muted" style="font-size:12.5px;">No messages in this folder yet. Connect Gmail above or compose a message.</p>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:2px;max-height:560px;overflow-y:auto;">
        <?php foreach ($threads as $th): ?>
          <?php
            $isUnread = !$th['is_read'] && strtolower((string) $th['recipient_email']) === strtolower((string) $user['email']);
            $snippet = !empty($th['snippet'])
                ? (string) $th['snippet']
                : mb_substr(trim(strip_tags((string) ($th['body'] ?? ''))), 0, 95, 'UTF-8');
            $senderInit = strtoupper(substr((string) ($th['sender_name'] ?: $th['sender_email']), 0, 2));
          ?>
          <a href="/email?folder=<?= urlencode((string) $selectedFolder) ?>&thread=<?= urlencode((string) $th['thread_id']) ?>"
             class="gmail-thread-row <?= $selectedThread === $th['thread_id'] ? 'active' : '' ?>">
            <span class="avatar avatar-sm" style="margin-top:2px;"><?= SecurityHelper::e($senderInit) ?></span>
            <div style="min-width:0;flex:1;">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                <strong style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?= SecurityHelper::e($th['sender_name'] ?: $th['sender_email']) ?>
                </strong>
                <span class="muted" style="font-size:10.5px;flex-shrink:0;"><?= SecurityHelper::e(substr((string) $th['created_at'], 5, 11)) ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                <span style="font-size:12.5px;font-weight:<?= $isUnread ? '800' : '600' ?>;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <?= SecurityHelper::e($th['subject']) ?>
                </span>
                <?php if ($isUnread): ?>
                  <span class="badge badge-brand" style="font-size:9.5px;padding:1px 6px;">New</span>
                <?php endif; ?>
              </div>
              <div class="muted" style="font-size:11.5px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= SecurityHelper::e($snippet) ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (empty($messages)): ?>
      <p class="muted">Select a conversation on the left or click <strong>+ Compose</strong> to start an email thread.</p>
    <?php else: ?>
      <div style="border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div>
          <h3 style="font-size:19px;"><?= SecurityHelper::e($messages[0]['subject']) ?></h3>
          <span class="muted" style="font-size:12px;"><?= count($messages) ?> message(s) in thread</span>
        </div>
        <span class="badge badge-neutral">Thread <?= SecurityHelper::e(substr((string) $selectedThread, 0, 14)) ?></span>
      </div>

      <div style="max-height:400px;overflow-y:auto;margin-bottom:16px;display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($messages as $msg): ?>
          <?php
            $isMine = (strtolower((string) $msg['sender_email']) === strtolower((string) $user['email']) || ($mailbox && strtolower((string) $msg['sender_email']) === strtolower((string) $mailbox['email'])));
            $msgAtts = $attachmentsMap[$msg['id']] ?? [];
            $sInit = strtoupper(substr((string) ($msg['sender_name'] ?: $msg['sender_email']), 0, 2));
          ?>
          <div class="message-bubble <?= $isMine ? 'mine' : '' ?>" style="margin-bottom:0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:8px;">
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="avatar avatar-sm"><?= SecurityHelper::e($sInit) ?></span>
                <div>
                  <strong style="font-size:13px;"><?= SecurityHelper::e($msg['sender_name']) ?></strong>
                  <span class="muted" style="font-size:12px;"> &lt;<?= SecurityHelper::e($msg['sender_email']) ?>&gt;</span>
                  <div class="muted" style="font-size:11.5px;margin-top:2px;">
                    To: <strong><?= SecurityHelper::e($msg['recipient_email']) ?></strong>
                    <?php if (!empty($msg['cc_email'])): ?>
                      · <span class="badge badge-neutral" style="font-size:10.5px;">CC: <?= SecurityHelper::e($msg['cc_email']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <span class="muted mono" style="font-size:11px;"><?= SecurityHelper::e(substr((string) $msg['created_at'], 0, 16)) ?></span>
            </div>

            <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.58;color:var(--ink);"><?= SecurityHelper::e($msg['body']) ?></div>

            <?php if (!empty($msgAtts)): ?>
              <div style="margin-top:10px;padding-top:8px;border-top:1px solid var(--border);display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ($msgAtts as $att): ?>
                  <a class="btn btn-secondary btn-sm"
                     href="/api/v1/email/attachments/<?= urlencode((string) $att['id']) ?>"
                     download="<?= SecurityHelper::e($att['filename']) ?>">
                    <?= workly_icon_svg('Paperclip', 13) ?>
                    <span><?= SecurityHelper::e($att['filename']) ?></span>
                    <span class="muted" style="font-size:10.5px;">(<?= max(1, (int) round(((int) $att['size_bytes']) / 1024)) ?> KB)</span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <form data-gmail-form="/api/v1/email/reply" enctype="multipart/form-data" style="border-top:1px solid var(--border);padding-top:14px;">
        <input type="hidden" name="thread_id" value="<?= SecurityHelper::e($selectedThread) ?>">
        <input type="hidden" name="recipient_email" value="<?= SecurityHelper::e($replyRecipient) ?>">
        <input type="hidden" name="subject" value="<?= SecurityHelper::e($messages[0]['subject']) ?>">

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Reply To</label>
            <input class="input" value="<?= SecurityHelper::e($replyRecipient) ?>" readonly>
          </div>
          <div class="form-group">
            <label class="form-label">CC (comma-separated, optional)</label>
            <input class="input" type="text" name="cc_email" placeholder="lead@company.com, ops@company.com">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Reply Message</label>
          <textarea class="textarea" name="body" rows="3" required placeholder="Write your reply inside this Gmail thread..."></textarea>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
          <div>
            <input class="input" type="file" name="attachments[]" multiple data-email-files-input="reply-files-preview" style="max-width:290px;padding:6px 10px;">
            <div id="reply-files-preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;"></div>
          </div>
          <button type="submit" class="btn btn-primary">Send Reply</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Right-Side Drawer: Compose New Email (with CC & File Attachments) -->
<div id="drawer-compose-email" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="New Email Message">
    <div class="drawer-head">
      <h3>New Email Message</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>
    <div class="drawer-body">
      <form data-gmail-form="/api/v1/email/compose" enctype="multipart/form-data">
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

        <div class="form-group">
          <label class="form-label">Subject</label>
          <input class="input" name="subject" required placeholder="Topic or deliverable inquiry">
        </div>

        <div class="form-group">
          <label class="form-label">Message Body</label>
          <textarea class="textarea" name="body" rows="6" required placeholder="Type your message..."></textarea>
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
