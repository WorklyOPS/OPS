<?php
$threads = $data['threads'] ?? [];
$messages = $data['messages'] ?? [];
$selectedThread = $data['selected_thread'] ?? '';
$mailbox = $data['mailbox'] ?? null;
$lastMsg = !empty($messages) ? end($messages) : null;
$replyRecipient = '';
if ($lastMsg) {
    $replyRecipient = ($lastMsg['sender_email'] === $user['email'])
        ? $lastMsg['recipient_email']
        : $lastMsg['sender_email'];
}
?>

<div class="page-header">
  <div>
    <h1>Email Communications Hub</h1>
    <p class="muted">PostgreSQL-backed threaded conversations with contextual replies inside each thread.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <?php if ($mailbox): ?>
      <span class="badge badge-success">Linked: <?= SecurityHelper::e($mailbox['email']) ?></span>
      <button type="button" class="btn btn-secondary btn-sm" data-action-url="/api/v1/email/sync-gmail" data-action-method="POST">Sync Mailbox</button>
    <?php else: ?>
      <span class="badge badge-neutral">Internal Workspace Mail</span>
    <?php endif; ?>
    <button type="button" class="btn btn-primary" data-open-drawer="drawer-compose-email">+ Compose Message</button>
  </div>
</div>

<div class="email-layout">
  <div class="card" style="padding:14px;">
    <h4 style="margin-bottom:10px;">Threads (<?= count($threads) ?>)</h4>
    <?php if (empty($threads)): ?>
      <p class="muted" style="font-size:12.5px;">No email threads in your inbox yet.</p>
    <?php else: ?>
      <?php foreach ($threads as $th): ?>
        <a href="/email?thread=<?= urlencode((string) $th['thread_id']) ?>"
           class="thread-item <?= $selectedThread === $th['thread_id'] ? 'active' : '' ?>">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
            <strong style="font-size:13px;"><?= SecurityHelper::e($th['subject']) ?></strong>
            <?php if (!$th['is_read'] && $th['recipient_email'] === $user['email']): ?>
              <span class="badge badge-brand">New</span>
            <?php endif; ?>
          </div>
          <div class="muted" style="font-size:12px;margin-top:3px;">
            <?= SecurityHelper::e($th['sender_name']) ?> → <?= SecurityHelper::e($th['recipient_email']) ?>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <?php if (empty($messages)): ?>
      <p class="muted">Select a thread from the left or compose a new message to begin.</p>
    <?php else: ?>
      <div style="border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:14px;">
        <h3><?= SecurityHelper::e($messages[0]['subject']) ?></h3>
        <span class="muted" style="font-size:12px;">Thread ID: <?= SecurityHelper::e($selectedThread) ?></span>
      </div>

      <div style="max-height:360px;overflow-y:auto;margin-bottom:16px;">
        <?php foreach ($messages as $msg): ?>
          <?php $isMine = ($msg['sender_email'] === $user['email']); ?>
          <div class="message-bubble <?= $isMine ? 'mine' : '' ?>">
            <div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:6px;">
              <strong><?= SecurityHelper::e($msg['sender_name']) ?> <span class="muted" style="font-weight:400;">&lt;<?= SecurityHelper::e($msg['sender_email']) ?>&gt;</span></strong>
              <span class="muted" style="font-size:11.5px;"><?= SecurityHelper::e(substr((string) $msg['created_at'], 0, 16)) ?></span>
            </div>
            <div style="white-space:pre-wrap;font-size:13.5px;"><?= SecurityHelper::e($msg['body']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <form data-api-form="/api/v1/email/reply" data-api-method="POST" style="border-top:1px solid var(--border);padding-top:14px;">
        <input type="hidden" name="thread_id" value="<?= SecurityHelper::e($selectedThread) ?>">
        <input type="hidden" name="recipient_email" value="<?= SecurityHelper::e($replyRecipient) ?>">
        <input type="hidden" name="subject" value="<?= SecurityHelper::e($messages[0]['subject']) ?>">

        <div class="form-group">
          <label class="form-label">Reply in Thread (to <?= SecurityHelper::e($replyRecipient) ?>)</label>
          <textarea class="textarea" name="body" required placeholder="Write your reply inside this conversation..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Send Reply in Thread</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div id="drawer-compose-email" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="New Email Thread">
    <div class="drawer-head">
      <h3>New Email Thread</h3>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>
    <div class="drawer-body">
      <form data-api-form="/api/v1/email/compose" data-api-method="POST">
        <div class="form-group">
          <label class="form-label">Recipient Email</label>
          <input class="input" type="email" name="recipient_email" list="workly-contacts" required placeholder="recipient@company.com">
          <datalist id="workly-contacts">
            <?php foreach ($data['contacts'] as $ct): ?>
              <option value="<?= SecurityHelper::e($ct['email']) ?>"><?= SecurityHelper::e($ct['name']) ?> (<?= SecurityHelper::e($ct['role']) ?>)</option>
            <?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label class="form-label">Subject</label>
          <input class="input" name="subject" required placeholder="Topic or deliverable inquiry">
        </div>
        <div class="form-group">
          <label class="form-label">Message Body</label>
          <textarea class="textarea" name="body" rows="6" required placeholder="Type your message..."></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-primary">Send Message</button>
        </div>
      </form>
    </div>
  </aside>
</div>
