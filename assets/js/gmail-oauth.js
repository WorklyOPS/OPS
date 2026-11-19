/* ========================================================================
   Workly Gmail OAuth2 & Real-Time Email Hub Client Engine
   Native server-side Google OAuth 2.0 (tokens never leave PHP backend)
   ======================================================================== */
(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';
  const bootstrapEl = document.getElementById('gmail-hub-bootstrap');

  const state = {
    userEmail: bootstrapEl ? (bootstrapEl.getAttribute('data-user-email') || '') : '',
    linkedEmail: bootstrapEl ? (bootstrapEl.getAttribute('data-linked-email') || '') : '',
    folder: bootstrapEl ? (bootstrapEl.getAttribute('data-folder') || 'all') : 'all',
    selectedThread: bootstrapEl ? (bootstrapEl.getAttribute('data-thread') || '') : '',
    unreadCount: bootstrapEl ? parseInt(bootstrapEl.getAttribute('data-unread-count') || '0', 10) : 0,
    connected: bootstrapEl ? (bootstrapEl.getAttribute('data-connected') === '1') : false,
    query: '',
    pollTimer: null,
    pollInFlight: false,
    searchTimer: null,
    knownThreadSnapshot: '',
    knownMessagesSnapshot: '',
    notifiedMessageIds: new Set(),
    toggledMsgCards: new Map(),
    currentMessages: [],
    currentAttachments: {},
    pendingConfirmForm: null,
    savedRanges: { reply: null, compose: null },
    sourceMode: { reply: false, compose: false }
  };

  function showGmailToast(message, type, onClick) {
    if (typeof window.worklyShowToast === 'function' && typeof onClick !== 'function') {
      window.worklyShowToast(message, type);
      return;
    }
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const element = document.createElement('div');
    element.className = 'toast ' + (type === 'error' ? 'error' : 'success') + (typeof onClick === 'function' ? ' is-clickable' : '');
    element.textContent = message;
    if (typeof onClick === 'function') {
      element.addEventListener('click', () => {
        element.remove();
        onClick();
      });
    }
    document.body.appendChild(element);
    window.setTimeout(() => {
      if (element.parentElement) element.remove();
    }, typeof onClick === 'function' ? 5500 : 3500);
  }

  function updateGlobalUnreadBadges(unreadCount) {
    state.unreadCount = Math.max(0, Number(unreadCount || 0));
    const tabPill = document.getElementById('gmail-unread-tab-badge');
    if (tabPill) {
      tabPill.textContent = String(state.unreadCount);
      tabPill.classList.toggle('is-hidden', state.unreadCount <= 0);
    }
    const sideBadge = document.getElementById('sidebar-email-unread-badge');
    if (sideBadge) {
      sideBadge.textContent = String(state.unreadCount);
      sideBadge.classList.toggle('is-hidden', state.unreadCount <= 0);
    }
    if (typeof window.worklyRefreshHeaderNotifications === 'function') {
      window.worklyRefreshHeaderNotifications();
    }
  }

  function updateGmailUiState(connected, email, lastSyncedAt) {
    const badge = document.getElementById('gmail-oauth-status-badge');
    const livePill = document.getElementById('gmail-live-sync-pill');
    const liveText = document.getElementById('gmail-live-sync-text');
    const syncLabel = document.getElementById('gmail-last-sync-label');
    const syncButton = document.getElementById('btn-gmail-sync-now');
    const disconnectButton = document.getElementById('btn-gmail-disconnect');
    const connectButton = document.getElementById('btn-google-signin-gmail');

    state.connected = Boolean(connected);
    state.linkedEmail = connected ? (email || '') : '';

    if (badge) {
      badge.className = connected ? 'badge badge-success' : 'badge badge-neutral';
      badge.textContent = connected ? ('Gmail: ' + (state.linkedEmail || 'Connected')) : 'Workspace Mail Ready';
    }
    if (livePill) livePill.classList.toggle('is-hidden', !connected);
    if (liveText && connected) {
      if (lastSyncedAt) {
        const dt = new Date(lastSyncedAt);
        const hhmm = Number.isNaN(dt.getTime()) ? 'Active' : dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        liveText.textContent = 'Live · ' + hhmm;
      } else {
        liveText.textContent = 'Live Sync Active';
      }
    }
    if (syncLabel && lastSyncedAt) {
      const dt = new Date(lastSyncedAt);
      if (!Number.isNaN(dt.getTime())) {
        syncLabel.textContent = 'Synced ' + dt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      }
    }
    if (syncButton) syncButton.classList.toggle('is-hidden', !connected);
    if (disconnectButton) disconnectButton.classList.toggle('is-hidden', !connected);
    if (connectButton) connectButton.classList.toggle('is-hidden', connected);
  }

  async function requestJson(url, options) {
    const response = await fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }, options || {}));
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Gmail request failed');
    return data;
  }

  // Defense-in-depth client DOM sanitizer for server-sanitized email HTML
  function buildSafeEmailHtmlFragment(rawHtml) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(String(rawHtml || ''), 'text/html');
    const forbidden = doc.querySelectorAll('script, style, iframe, object, embed, applet, form, input, button, textarea, select, meta, link, base, svg, math');
    forbidden.forEach((node) => node.remove());

    const all = doc.body.querySelectorAll('*');
    all.forEach((el) => {
      Array.from(el.attributes).forEach((attr) => {
        const name = attr.name.toLowerCase();
        const val = attr.value.trim();
        if (name.startsWith('on') || name === 'id' || name === 'class' || name === 'srcset') {
          el.removeAttribute(attr.name);
          return;
        }
        if (name === 'href') {
          if (!/^https?:\/\//i.test(val) && !val.startsWith('/') && !/^mailto:/i.test(val)) {
            el.removeAttribute('href');
          }
        }
        if (name === 'src') {
          const ok = val.startsWith('/api/v1/email/attachments/')
            || /^https?:\/\//i.test(val)
            || /^data:image\/(png|jpeg|jpg|gif|webp);base64,/i.test(val.replace(/\s+/g, ''));
          if (!ok) {
            el.remove();
          }
        }
      });
      if (el.tagName === 'A') {
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
      }
      if (el.tagName === 'IMG') {
        el.setAttribute('loading', 'lazy');
      }
    });

    const frag = document.createDocumentFragment();
    while (doc.body.firstChild) {
      frag.appendChild(doc.body.firstChild);
    }
    return frag;
  }

  function formatShortDate(raw) {
    if (!raw) return '';
    const str = String(raw);
    if (str.length >= 16) return str.slice(5, 16);
    return str;
  }

  function formatFullDate(raw) {
    if (!raw) return '';
    return String(raw).slice(0, 16);
  }

  function renderThreadList(threads, totalThreads) {
    const listEl = document.getElementById('gmail-thread-list');
    const countEl = document.getElementById('gmail-thread-count-label');
    const allCountEl = document.getElementById('gmail-all-tab-count');
    if (countEl) countEl.textContent = String(threads.length);
    if (allCountEl && typeof totalThreads === 'number') {
      allCountEl.textContent = String(totalThreads);
    }
    if (!listEl) return;

    const snap = JSON.stringify(threads.map((t) => [t.thread_id, t.is_unread, t.msg_count, t.att_count, t.created_at, t.subject])) + '|' + state.selectedThread;
    if (snap === state.knownThreadSnapshot) return;
    state.knownThreadSnapshot = snap;

    const scrollTop = listEl.scrollTop;
    listEl.replaceChildren();

    if (!threads.length) {
      const empty = document.createElement('div');
      empty.className = 'gmail-empty-state';
      const p = document.createElement('p');
      p.className = 'muted';
      p.style.fontSize = '13px';
      p.textContent = state.query
        ? 'No conversations matched "' + state.query + '".'
        : 'No conversations in this folder yet. Connect Gmail above or click + Compose.';
      empty.appendChild(p);
      listEl.appendChild(empty);
      return;
    }

    threads.forEach((th) => {
      const row = document.createElement('a');
      row.href = '/email?folder=' + encodeURIComponent(state.folder) + '&thread=' + encodeURIComponent(th.thread_id);
      row.setAttribute('data-thread-id', th.thread_id);
      row.className = 'gmail-thread-row'
        + (state.selectedThread === th.thread_id ? ' active' : '')
        + (th.is_unread ? ' is-unread' : '');

      const senderName = th.sender_name || th.sender_email || 'Unknown';
      const avatar = document.createElement('span');
      avatar.className = 'avatar avatar-sm';
      avatar.style.marginTop = '2px';
      avatar.style.flexShrink = '0';
      avatar.textContent = senderName.slice(0, 2).toUpperCase();

      const body = document.createElement('div');
      body.className = 'gmail-thread-row-body';

      const top = document.createElement('div');
      top.className = 'gmail-thread-row-top';
      const senderStrong = document.createElement('strong');
      senderStrong.className = 'gmail-thread-sender';
      senderStrong.textContent = senderName;
      const timeSpan = document.createElement('span');
      timeSpan.className = 'gmail-thread-time mono';
      timeSpan.textContent = formatShortDate(th.created_at);
      top.append(senderStrong, timeSpan);

      const subjLine = document.createElement('div');
      subjLine.className = 'gmail-thread-subject-line';
      if (th.is_unread) {
        const dot = document.createElement('span');
        dot.className = 'gmail-unread-dot';
        subjLine.appendChild(dot);
      }
      const subj = document.createElement('span');
      subj.className = 'gmail-thread-subject';
      subj.textContent = th.subject || '(No Subject)';
      subjLine.appendChild(subj);

      const msgCount = Number(th.msg_count || 1);
      if (msgCount > 1) {
        const chip = document.createElement('span');
        chip.className = 'gmail-thread-count-chip mono';
        chip.textContent = String(msgCount);
        subjLine.appendChild(chip);
      }

      if (Number(th.att_count || 0) > 0) {
        const attTag = document.createElement('span');
        attTag.className = 'gmail-thread-att-icon';
        attTag.title = th.att_count + ' attachment(s)';
        attTag.textContent = '📎';
        subjLine.appendChild(attTag);
      }

      const snip = document.createElement('div');
      snip.className = 'gmail-thread-snippet';
      snip.textContent = th.snippet || '';

      body.append(top, subjLine, snip);
      row.append(avatar, body);
      listEl.appendChild(row);
    });

    listEl.scrollTop = scrollTop;
  }

  function isOwnAddress(email) {
    const low = String(email || '').trim().toLowerCase();
    if (!low) return false;
    return low === state.userEmail.toLowerCase()
      || (state.linkedEmail !== '' && low === state.linkedEmail.toLowerCase());
  }

  function renderActiveThread(threadId, messages, attachmentsMap) {
    const emptyEl = document.getElementById('gmail-reader-empty');
    const activeEl = document.getElementById('gmail-reader-active');
    const stackEl = document.getElementById('gmail-thread-messages');
    const subjEl = document.getElementById('gmail-reader-subject');
    const countEl = document.getElementById('gmail-reader-msg-count');
    const chipEl = document.getElementById('gmail-reader-thread-chip');
    const markUnreadBtn = document.getElementById('btn-gmail-mark-unread');

    state.currentMessages = Array.isArray(messages) ? messages : [];
    state.currentAttachments = attachmentsMap || {};

    if (!emptyEl || !activeEl || !stackEl) return;

    if (!state.currentMessages.length) {
      emptyEl.classList.remove('is-hidden');
      activeEl.classList.add('is-hidden');
      state.knownMessagesSnapshot = '';
      return;
    }

    emptyEl.classList.add('is-hidden');
    activeEl.classList.remove('is-hidden');

    const firstMsg = state.currentMessages[0];
    const lastMsg = state.currentMessages[state.currentMessages.length - 1];
    if (subjEl) subjEl.textContent = firstMsg.subject || '(No Subject)';
    if (countEl) countEl.textContent = state.currentMessages.length + ' message(s)';
    if (chipEl) chipEl.textContent = 'Thread ' + String(threadId || '').slice(0, 14);
    if (markUnreadBtn) markUnreadBtn.setAttribute('data-thread-id', threadId || '');

    // Update reply form targets without clearing user's typed draft when thread is unchanged
    const replyForm = document.getElementById('gmail-reply-form');
    if (replyForm) {
      const tidInput = replyForm.querySelector('[name="thread_id"]');
      const recInput = replyForm.querySelector('[name="recipient_email"]');
      const subInput = replyForm.querySelector('[name="subject"]');
      const dispInput = document.getElementById('gmail-reply-to-display');
      const replyTarget = isOwnAddress(lastMsg.sender_email) ? lastMsg.recipient_email : lastMsg.sender_email;

      if (tidInput) tidInput.value = threadId || '';
      if (recInput) recInput.value = replyTarget || '';
      if (subInput) subInput.value = firstMsg.subject || 'Conversation Thread';
      if (dispInput) dispInput.value = replyTarget || '';
    }

    const msgSnap = String(threadId) + '|' + JSON.stringify(state.currentMessages.map((m) => [m.id, m.body_html ? m.body_html.length : m.body.length]));
    if (msgSnap === state.knownMessagesSnapshot) return;
    state.knownMessagesSnapshot = msgSnap;

    stackEl.replaceChildren();
    const total = state.currentMessages.length;

    state.currentMessages.forEach((msg, idx) => {
      const card = document.createElement('article');
      const mine = isOwnAddress(msg.sender_email);
      const overrideState = state.toggledMsgCards.get(msg.id);
      const expanded = overrideState ? overrideState === 'expanded' : (idx === total - 1);

      card.className = 'gmail-msg-card' + (mine ? ' is-mine' : '') + (expanded ? ' is-expanded' : ' is-collapsed');
      card.setAttribute('data-msg-id', msg.id);

      const head = document.createElement('header');
      head.className = 'gmail-msg-head';
      head.setAttribute('data-toggle-msg-card', '1');

      const sWrap = document.createElement('div');
      sWrap.className = 'gmail-msg-sender-wrap';

      const sName = msg.sender_name || msg.sender_email || 'Sender';
      const av = document.createElement('span');
      av.className = 'avatar avatar-sm';
      av.textContent = sName.slice(0, 2).toUpperCase();

      const info = document.createElement('div');
      info.style.minWidth = '0';
      info.style.flex = '1';

      const senderLine = document.createElement('div');
      senderLine.className = 'gmail-msg-sender-line';
      const strongName = document.createElement('strong');
      strongName.textContent = sName;
      const addrSpan = document.createElement('span');
      addrSpan.className = 'muted gmail-msg-addr';
      addrSpan.textContent = '<' + (msg.sender_email || '') + '>';
      senderLine.append(strongName, addrSpan);

      const recipLine = document.createElement('div');
      recipLine.className = 'gmail-msg-recip-line';
      recipLine.append(document.createTextNode('To: '));
      const toStrong = document.createElement('strong');
      toStrong.textContent = msg.recipient_email || '';
      recipLine.appendChild(toStrong);
      if (msg.cc_email) {
        recipLine.appendChild(document.createTextNode(' · CC: ' + msg.cc_email));
      }

      const collapsedPrev = document.createElement('div');
      collapsedPrev.className = 'gmail-msg-collapsed-preview';
      collapsedPrev.textContent = msg.snippet || String(msg.body || '').slice(0, 90);

      info.append(senderLine, recipLine, collapsedPrev);
      sWrap.append(av, info);

      const timeWrap = document.createElement('div');
      timeWrap.className = 'gmail-msg-time-wrap';
      const timeSpan = document.createElement('span');
      timeSpan.className = 'muted mono';
      timeSpan.style.fontSize = '11.5px';
      timeSpan.textContent = formatFullDate(msg.created_at);
      const chev = document.createElement('span');
      chev.className = 'gmail-msg-chevron';
      chev.setAttribute('aria-hidden', 'true');
      chev.textContent = '▾';
      timeWrap.append(timeSpan, chev);

      head.append(sWrap, timeWrap);

      const bodyWrap = document.createElement('div');
      bodyWrap.className = 'gmail-msg-body-wrap';

      if (msg.body_html && String(msg.body_html).trim() !== '') {
        const htmlFrame = document.createElement('div');
        htmlFrame.className = 'gmail-msg-html-frame';
        htmlFrame.appendChild(buildSafeEmailHtmlFragment(msg.body_html));
        bodyWrap.appendChild(htmlFrame);
      } else {
        const plainBox = document.createElement('div');
        plainBox.className = 'gmail-msg-plain';
        plainBox.textContent = msg.body || '';
        bodyWrap.appendChild(plainBox);
      }

      const msgAtts = (attachmentsMap && attachmentsMap[msg.id]) ? attachmentsMap[msg.id] : [];
      const regularAtts = msgAtts.filter((a) => !a.is_inline);
      if (regularAtts.length) {
        const attTray = document.createElement('div');
        attTray.className = 'gmail-msg-attachments';
        regularAtts.forEach((att) => {
          const isImg = String(att.mime_type || '').toLowerCase().startsWith('image/');
          const attUrl = '/api/v1/email/attachments/' + encodeURIComponent(att.id);
          const kb = Math.max(1, Math.round(Number(att.size_bytes || 0) / 1024));
          if (isImg) {
            const imgCard = document.createElement('div');
            imgCard.className = 'gmail-att-image-card';

            const thumbBtn = document.createElement('button');
            thumbBtn.type = 'button';
            thumbBtn.className = 'gmail-att-thumb-btn';
            thumbBtn.setAttribute('data-preview-img-src', attUrl + '?inline=1');
            thumbBtn.setAttribute('data-preview-img-name', att.filename || 'image');
            thumbBtn.setAttribute('data-download-href', attUrl);
            const imgEl = document.createElement('img');
            imgEl.src = attUrl + '?inline=1';
            imgEl.alt = att.filename || 'Attachment';
            imgEl.loading = 'lazy';
            thumbBtn.appendChild(imgEl);

            const meta = document.createElement('div');
            meta.className = 'gmail-att-image-meta';
            const nm = document.createElement('span');
            nm.className = 'gmail-att-name';
            nm.textContent = att.filename || 'image';
            const dl = document.createElement('a');
            dl.href = attUrl;
            dl.setAttribute('download', att.filename || 'image');
            dl.className = 'gmail-att-dl-link';
            dl.title = 'Download';
            dl.textContent = '↓';
            meta.append(nm, dl);

            imgCard.append(thumbBtn, meta);
            attTray.appendChild(imgCard);
          } else {
            const chip = document.createElement('a');
            chip.className = 'gmail-att-file-chip';
            chip.href = attUrl;
            chip.setAttribute('download', att.filename || 'attachment');
            const nm = document.createElement('span');
            nm.className = 'gmail-att-name';
            nm.textContent = '📎 ' + (att.filename || 'file');
            const sz = document.createElement('span');
            sz.className = 'muted mono';
            sz.style.fontSize = '10.5px';
            sz.textContent = '(' + kb + ' KB)';
            chip.append(nm, sz);
            attTray.appendChild(chip);
          }
        });
        bodyWrap.appendChild(attTray);
      }

      card.append(head, bodyWrap);
      stackEl.appendChild(card);
    });

    stackEl.scrollTop = stackEl.scrollHeight;
  }

  function announceIncomingMessages(newMessages) {
    if (!Array.isArray(newMessages) || !newMessages.length) return;
    const fresh = newMessages.filter((m) => m && m.id && !state.notifiedMessageIds.has(m.id));
    if (!fresh.length) return;
    fresh.forEach((m) => state.notifiedMessageIds.add(m.id));

    const latest = fresh[0];
    const headline = fresh.length === 1
      ? ('New email from ' + (latest.sender_name || latest.sender_email) + ': ' + (latest.subject || '(No Subject)'))
      : (fresh.length + ' new Gmail emails arrived (' + (latest.sender_name || latest.sender_email) + ')');

    showGmailToast(headline, 'success', () => {
      if (latest.thread_id) {
        selectThread(latest.thread_id);
      }
    });

    if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
      try {
        const n = new Notification('New Gmail Message — Workly', {
          body: (latest.sender_name || latest.sender_email) + ': ' + (latest.subject || ''),
          tag: 'workly-mail-' + latest.id
        });
        n.onclick = () => {
          window.focus();
          if (latest.thread_id) selectThread(latest.thread_id);
          n.close();
        };
      } catch (e) {
        // Ignore browser notification restrictions
      }
    }
  }

  async function refreshLiveState(options) {
    const opts = options || {};
    if (state.pollInFlight && !opts.force) return;
    state.pollInFlight = true;
    try {
      const qs = new URLSearchParams({
        sync: opts.sync === false ? '0' : '1',
        folder: state.folder,
        thread: state.selectedThread || '',
        q: state.query || '',
        mark_read: opts.markRead === false ? '0' : '1'
      });
      const data = await requestJson('/api/v1/email/live?' + qs.toString());

      updateGmailUiState(Boolean(data.connected), data.gmail_email || '', data.last_synced || '');
      updateGlobalUnreadBadges(data.unread_count || 0);

      if (data.selected_thread && !state.selectedThread) {
        state.selectedThread = data.selected_thread;
      }

      renderThreadList(data.threads || [], data.total_threads);
      renderActiveThread(data.selected_thread || state.selectedThread, data.messages || [], data.attachments || {});

      if (Array.isArray(data.new_messages) && data.new_messages.length) {
        announceIncomingMessages(data.new_messages);
      }
    } catch (err) {
      if (!opts.silent) {
        showGmailToast(err.message || 'Could not synchronize Email Hub.', 'error');
      }
    } finally {
      state.pollInFlight = false;
    }
  }

  async function selectThread(threadId) {
    if (!threadId) return;
    state.selectedThread = threadId;
    state.knownMessagesSnapshot = '';
    document.querySelectorAll('.gmail-thread-row').forEach((el) => {
      const isActive = el.getAttribute('data-thread-id') === threadId;
      el.classList.toggle('active', isActive);
      if (isActive) el.classList.remove('is-unread');
    });
    const url = '/email?folder=' + encodeURIComponent(state.folder) + '&thread=' + encodeURIComponent(threadId);
    window.history.replaceState({}, document.title, url);
    await refreshLiveState({ sync: false, silent: true, force: true, markRead: true });
  }

  async function switchFolder(folder) {
    state.folder = folder || 'all';
    document.querySelectorAll('[data-email-folder]').forEach((tab) => {
      tab.classList.toggle('active', tab.getAttribute('data-email-folder') === state.folder);
    });
    const url = '/email?folder=' + encodeURIComponent(state.folder) + (state.selectedThread ? '&thread=' + encodeURIComponent(state.selectedThread) : '');
    window.history.replaceState({}, document.title, url);
    await refreshLiveState({ sync: false, silent: true, force: true });
  }

  async function loadGmailStatus() {
    try {
      const data = await requestJson('/api/v1/email/status');
      updateGmailUiState(Boolean(data.connected), data.email || '', data.last_synced_at || '');

      const params = new URLSearchParams(window.location.search);
      if (params.get('gmail_connected') === '1') {
        showGmailToast('Gmail account connected successfully.', 'success');
        window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
        await syncWithGmailApi({ silent: false });
      } else if (params.has('gmail_error')) {
        const error = params.get('gmail_error');
        const messages = {
          config: 'Google OAuth is not configured on the server.',
          state: 'The Google authorization session expired. Please try again.',
          cancelled: 'Google authorization was cancelled.',
          callback: 'Could not complete Gmail authorization. Check server logs and Google OAuth settings.'
        };
        showGmailToast(messages[error] || 'Gmail authorization failed.', 'error');
        window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
      } else if (data.connected) {
        await refreshLiveState({ sync: true, silent: true });
      }
    } catch (error) {
      updateGmailUiState(false, '', '');
      showGmailToast(error.message || 'Could not load Gmail connection status.', 'error');
    }
  }

  function connectGoogleGmail() {
    if ('Notification' in window && Notification.permission === 'default') {
      try { Notification.requestPermission(); } catch (e) {}
    }
    window.location.assign('/api/v1/email/google/connect');
  }

  async function syncWithGmailApi(opts) {
    const button = document.getElementById('btn-gmail-sync-now');
    if (button) button.disabled = true;
    try {
      const data = await requestJson('/api/v1/email/sync-gmail', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
      });
      await refreshLiveState({ sync: false, silent: true, force: true });
      if (!opts || !opts.silent) {
        showGmailToast('Gmail synchronized (' + (data.imported || 0) + ' new messages).', 'success');
      }
      if (Array.isArray(data.new_messages) && data.new_messages.length) {
        announceIncomingMessages(data.new_messages);
      }
    } catch (error) {
      if (!opts || !opts.silent) {
        showGmailToast(error.message || 'Could not synchronize Gmail.', 'error');
      }
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function disconnectGmail() {
    const button = document.getElementById('btn-gmail-disconnect');
    if (button) button.disabled = true;
    try {
      await requestJson('/api/v1/email/disconnect', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
      });
      updateGmailUiState(false, '', '');
      showGmailToast('Gmail disconnected.', 'success');
    } catch (error) {
      showGmailToast(error.message || 'Could not disconnect Gmail.', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  // ----------------------------------------------------------------------
  // Rich Formatting Toolbar (<> Code/HTML, Bold, Lists, Links, Colors)
  // ----------------------------------------------------------------------
  function getEditorParts(name) {
    return {
      surface: document.querySelector('[data-rich-surface="' + name + '"]'),
      source: document.querySelector('[data-rich-source="' + name + '"]'),
      hiddenHtml: document.getElementById('rich-hidden-html-' + name),
      hiddenPlain: document.getElementById('rich-hidden-plain-' + name),
      linkBar: document.querySelector('[data-rich-link-bar="' + name + '"]'),
      linkUrl: document.querySelector('[data-rich-link-url="' + name + '"]'),
      sourceBtn: document.querySelector('[data-rich-source-toggle="' + name + '"]')
    };
  }

  function syncEditorToHiddenFields(name) {
    const parts = getEditorParts(name);
    if (!parts.surface || !parts.hiddenHtml || !parts.hiddenPlain) return { html: '', plain: '' };
    if (state.sourceMode[name] && parts.source) {
      parts.surface.replaceChildren(buildSafeEmailHtmlFragment(parts.source.value));
    }
    const cleanHtml = parts.surface.innerHTML.trim();
    const cleanPlain = (parts.surface.innerText || parts.surface.textContent || '').trim();
    parts.hiddenHtml.value = cleanHtml;
    parts.hiddenPlain.value = cleanPlain;
    return { html: cleanHtml, plain: cleanPlain };
  }

  function resetRichEditor(name) {
    const parts = getEditorParts(name);
    if (parts.surface) parts.surface.replaceChildren();
    if (parts.source) {
      parts.source.value = '';
      parts.source.classList.add('is-hidden');
    }
    if (parts.surface) parts.surface.classList.remove('is-hidden');
    if (parts.sourceBtn) parts.sourceBtn.classList.remove('is-active');
    state.sourceMode[name] = false;
    if (parts.hiddenHtml) parts.hiddenHtml.value = '';
    if (parts.hiddenPlain) parts.hiddenPlain.value = '';
  }

  function saveEditorSelection(name) {
    const parts = getEditorParts(name);
    const sel = window.getSelection();
    if (!parts.surface || !sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    if (parts.surface.contains(range.commonAncestorContainer)) {
      state.savedRanges[name] = range.cloneRange();
    }
  }

  function restoreEditorSelection(name) {
    const parts = getEditorParts(name);
    const range = state.savedRanges[name];
    if (!parts.surface || !range) return;
    parts.surface.focus();
    const sel = window.getSelection();
    if (sel) {
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  function initRichEditors() {
    ['reply', 'compose'].forEach((name) => {
      const parts = getEditorParts(name);
      if (!parts.surface) return;

      parts.surface.addEventListener('mouseup', () => saveEditorSelection(name));
      parts.surface.addEventListener('keyup', () => {
        saveEditorSelection(name);
        syncEditorToHiddenFields(name);
      });
      parts.surface.addEventListener('input', () => syncEditorToHiddenFields(name));
      if (parts.source) {
        parts.source.addEventListener('input', () => {
          parts.surface.replaceChildren(buildSafeEmailHtmlFragment(parts.source.value));
          syncEditorToHiddenFields(name);
        });
      }

      const toolbar = document.querySelector('[data-rich-toolbar="' + name + '"]');
      if (toolbar) {
        toolbar.addEventListener('click', (e) => {
          const cmdBtn = e.target.closest('[data-rich-cmd]');
          if (cmdBtn) {
            if (state.sourceMode[name]) return;
            parts.surface.focus();
            document.execCommand(cmdBtn.getAttribute('data-rich-cmd'), false, null);
            syncEditorToHiddenFields(name);
            return;
          }

          const codeBtn = e.target.closest('[data-rich-inline-code]');
          if (codeBtn) {
            if (state.sourceMode[name]) return;
            parts.surface.focus();
            const sel = window.getSelection();
            const selectedText = sel ? sel.toString() : '';
            const codeEl = document.createElement('code');
            codeEl.textContent = selectedText || 'code';
            if (sel && sel.rangeCount > 0) {
              const range = sel.getRangeAt(0);
              if (parts.surface.contains(range.commonAncestorContainer)) {
                range.deleteContents();
                range.insertNode(codeEl);
                const space = document.createTextNode('\u00A0');
                codeEl.after(space);
              }
            } else {
              parts.surface.appendChild(codeEl);
            }
            syncEditorToHiddenFields(name);
            return;
          }

          const linkToggle = e.target.closest('[data-rich-link-toggle]');
          if (linkToggle && parts.linkBar) {
            saveEditorSelection(name);
            parts.linkBar.classList.toggle('is-hidden');
            if (!parts.linkBar.classList.contains('is-hidden') && parts.linkUrl) {
              parts.linkUrl.focus();
            }
            return;
          }

          const srcBtn = e.target.closest('[data-rich-source-toggle]');
          if (srcBtn && parts.source) {
            const toSource = !state.sourceMode[name];
            state.sourceMode[name] = toSource;
            srcBtn.classList.toggle('is-active', toSource);
            if (toSource) {
              parts.source.value = parts.surface.innerHTML.trim();
              parts.surface.classList.add('is-hidden');
              parts.source.classList.remove('is-hidden');
              parts.source.focus();
            } else {
              parts.surface.replaceChildren(buildSafeEmailHtmlFragment(parts.source.value));
              parts.source.classList.add('is-hidden');
              parts.surface.classList.remove('is-hidden');
              parts.surface.focus();
            }
            syncEditorToHiddenFields(name);
          }
        });

        const blockSel = toolbar.querySelector('[data-rich-block="' + name + '"]');
        if (blockSel) {
          blockSel.addEventListener('change', () => {
            if (state.sourceMode[name]) return;
            parts.surface.focus();
            document.execCommand('formatBlock', false, blockSel.value);
            syncEditorToHiddenFields(name);
          });
        }

        const colorInp = toolbar.querySelector('[data-rich-color="' + name + '"]');
        if (colorInp) {
          colorInp.addEventListener('input', () => {
            if (state.sourceMode[name]) return;
            parts.surface.focus();
            document.execCommand('foreColor', false, colorInp.value);
            syncEditorToHiddenFields(name);
          });
        }
      }

      if (parts.linkBar) {
        const applyBtn = parts.linkBar.querySelector('[data-rich-link-apply="' + name + '"]');
        const cancelBtn = parts.linkBar.querySelector('[data-rich-link-cancel="' + name + '"]');
        if (applyBtn && parts.linkUrl) {
          applyBtn.addEventListener('click', () => {
            const rawUrl = parts.linkUrl.value.trim();
            if (!/^https?:\/\//i.test(rawUrl)) {
              showGmailToast('Enter a valid https:// or http:// link URL.', 'error');
              return;
            }
            restoreEditorSelection(name);
            document.execCommand('createLink', false, rawUrl);
            parts.linkUrl.value = '';
            parts.linkBar.classList.add('is-hidden');
            syncEditorToHiddenFields(name);
          });
        }
        if (cancelBtn) {
          cancelBtn.addEventListener('click', () => {
            parts.linkBar.classList.add('is-hidden');
          });
        }
      }
    });
  }

  async function dispatchEmailForm(form) {
    const url = form.getAttribute('data-gmail-form');
    if (!url) return;
    const editorName = form.id === 'gmail-reply-form' ? 'reply' : 'compose';
    const synced = syncEditorToHiddenFields(editorName);
    if (!synced.plain && !synced.html) {
      showGmailToast('Write a message before sending.', 'error');
      return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;

    try {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' },
        body: new FormData(form)
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Email delivery failed');

      showGmailToast(
        data.gmail_synced ? 'Sent via Gmail and archived to Workly Hub.' : 'Message saved to Workly Email Hub.',
        'success'
      );

      resetRichEditor(editorName);
      const ccInp = form.querySelector('[name="cc_email"]');
      if (ccInp) ccInp.value = '';
      const fileInp = form.querySelector('input[type="file"]');
      if (fileInp) fileInp.value = '';
      const previewBox = form.querySelector('[id$="-files-preview"]');
      if (previewBox) previewBox.replaceChildren();

      if (editorName === 'compose') {
        const subInp = form.querySelector('[name="subject"]');
        const recInp = form.querySelector('[name="recipient_email"]');
        if (subInp) subInp.value = '';
        if (recInp) recInp.value = '';
        const drawer = document.getElementById('drawer-compose-email');
        if (drawer) drawer.classList.add('is-hidden');
      }

      if (data.thread_id) {
        state.selectedThread = data.thread_id;
      }
      state.knownThreadSnapshot = '';
      state.knownMessagesSnapshot = '';
      await refreshLiveState({ sync: false, silent: true, force: true });
    } catch (error) {
      showGmailToast(error.message || 'Could not send message.', 'error');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  }

  function startRealtimePolling() {
    if (state.pollTimer) window.clearInterval(state.pollTimer);
    state.pollTimer = window.setInterval(() => {
      if (document.hidden || !state.connected) return;
      refreshLiveState({ sync: true, silent: true, markRead: true });
    }, 10000);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && state.connected) {
        refreshLiveState({ sync: true, silent: true, markRead: true });
      }
    });
  }

  window.addEventListener('DOMContentLoaded', () => {
    const connectButton = document.getElementById('btn-google-signin-gmail');
    const syncButton = document.getElementById('btn-gmail-sync-now');
    const disconnectButton = document.getElementById('btn-gmail-disconnect');
    const confirmModal = document.getElementById('modal-confirm-gmail-send');
    const confirmProceedButton = document.getElementById('btn-confirm-gmail-send-go');
    const searchInput = document.getElementById('gmail-search-input');
    const lightboxModal = document.getElementById('modal-email-image-preview');

    if (connectButton) connectButton.addEventListener('click', connectGoogleGmail);
    if (syncButton) syncButton.addEventListener('click', () => syncWithGmailApi({ silent: false }));
    if (disconnectButton) disconnectButton.addEventListener('click', disconnectGmail);

    initRichEditors();

    // Folder tab switching without full page reload
    document.querySelectorAll('[data-email-folder]').forEach((tab) => {
      tab.addEventListener('click', (e) => {
        e.preventDefault();
        switchFolder(tab.getAttribute('data-email-folder') || 'all');
      });
    });

    // Live search filtering
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        state.query = searchInput.value.trim();
        if (state.searchTimer) window.clearTimeout(state.searchTimer);
        state.searchTimer = window.setTimeout(() => {
          refreshLiveState({ sync: false, silent: true, force: true });
        }, 200);
      });
    }

    // Delegate thread selection clicks
    const listEl = document.getElementById('gmail-thread-list');
    if (listEl) {
      listEl.addEventListener('click', (e) => {
        const row = e.target.closest('.gmail-thread-row[data-thread-id]');
        if (!row) return;
        e.preventDefault();
        selectThread(row.getAttribute('data-thread-id'));
      });
    }

    // Delegate expand/collapse & inline image preview inside messages stack
    const stackEl = document.getElementById('gmail-thread-messages');
    if (stackEl) {
      stackEl.addEventListener('click', (e) => {
        const thumbBtn = e.target.closest('[data-preview-img-src]');
        if (thumbBtn && lightboxModal) {
          const img = document.getElementById('gmail-lightbox-img');
          const title = document.getElementById('gmail-lightbox-title');
          const dl = document.getElementById('gmail-lightbox-download');
          if (img) img.src = thumbBtn.getAttribute('data-preview-img-src') || '';
          if (title) title.textContent = thumbBtn.getAttribute('data-preview-img-name') || 'Email Image';
          if (dl) dl.href = thumbBtn.getAttribute('data-download-href') || '#';
          lightboxModal.classList.remove('is-closing', 'is-hidden');
          return;
        }

        const inlineImg = e.target.closest('.gmail-msg-html-frame img');
        if (inlineImg && lightboxModal && !e.target.closest('a')) {
          const img = document.getElementById('gmail-lightbox-img');
          const title = document.getElementById('gmail-lightbox-title');
          const dl = document.getElementById('gmail-lightbox-download');
          const src = inlineImg.getAttribute('src') || '';
          if (img) img.src = src;
          if (title) title.textContent = inlineImg.getAttribute('alt') || 'Email Image';
          if (dl) dl.href = src.replace('?inline=1', '');
          lightboxModal.classList.remove('is-closing', 'is-hidden');
          return;
        }

        const head = e.target.closest('[data-toggle-msg-card]');
        if (head) {
          const card = head.closest('.gmail-msg-card');
          if (!card) return;
          const msgId = card.getAttribute('data-msg-id');
          const nextExpanded = card.classList.contains('is-collapsed');
          card.classList.toggle('is-collapsed', !nextExpanded);
          card.classList.toggle('is-expanded', nextExpanded);
          if (msgId) state.toggledMsgCards.set(msgId, nextExpanded ? 'expanded' : 'collapsed');
        }
      });
    }

    // Lightbox close
    document.querySelectorAll('[data-close-email-lightbox]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (lightboxModal) lightboxModal.classList.add('is-hidden');
      });
    });

    // Reader quick actions: Reply, Forward, Mark Unread
    const scrollReplyBtn = document.getElementById('btn-gmail-scroll-reply');
    if (scrollReplyBtn) {
      scrollReplyBtn.addEventListener('click', () => {
        const ed = document.getElementById('rich-editor-reply');
        if (ed) {
          ed.scrollIntoView({ behavior: 'smooth', block: 'center' });
          ed.focus();
        }
      });
    }

    const forwardBtn = document.getElementById('btn-gmail-forward');
    if (forwardBtn) {
      forwardBtn.addEventListener('click', () => {
        const lastMsg = state.currentMessages.length ? state.currentMessages[state.currentMessages.length - 1] : null;
        if (!lastMsg) return;
        const composeForm = document.getElementById('gmail-compose-form');
        const composeParts = getEditorParts('compose');
        if (!composeForm || !composeParts.surface) return;

        const subjInp = composeForm.querySelector('[name="subject"]');
        if (subjInp) {
          const baseSubj = lastMsg.subject || '(No Subject)';
          subjInp.value = /^fwd:/i.test(baseSubj) ? baseSubj : ('Fwd: ' + baseSubj);
        }
        const quoteHeader = '<p><br></p><blockquote><strong>---------- Forwarded message ----------</strong><br>'
          + 'From: ' + (lastMsg.sender_name || '') + ' &lt;' + (lastMsg.sender_email || '') + '&gt;<br>'
          + 'Subject: ' + (lastMsg.subject || '') + '<br><br></blockquote>';
        const innerHtml = lastMsg.body_html
          ? quoteHeader + lastMsg.body_html
          : quoteHeader + '<pre>' + String(lastMsg.body || '').replace(/</g, '&lt;') + '</pre>';
        composeParts.surface.replaceChildren(buildSafeEmailHtmlFragment(innerHtml));
        syncEditorToHiddenFields('compose');

        const openBtn = document.getElementById('btn-open-compose');
        if (openBtn) openBtn.click();
      });
    }

    const markUnreadBtn = document.getElementById('btn-gmail-mark-unread');
    if (markUnreadBtn) {
      markUnreadBtn.addEventListener('click', async () => {
        const tid = markUnreadBtn.getAttribute('data-thread-id') || state.selectedThread;
        if (!tid) return;
        try {
          await requestJson('/api/v1/email/threads/' + encodeURIComponent(tid) + '/read', {
            method: 'PATCH',
            headers: { 'X-CSRF-Token': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ is_read: false })
          });
          showGmailToast('Conversation marked as unread.', 'success');
          state.knownThreadSnapshot = '';
          await refreshLiveState({ sync: false, silent: true, force: true, markRead: false });
        } catch (err) {
          showGmailToast(err.message || 'Could not update conversation.', 'error');
        }
      });
    }

    // Form submit confirmation
    document.querySelectorAll('form[data-gmail-form]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const editorName = form.id === 'gmail-reply-form' ? 'reply' : 'compose';
        const synced = syncEditorToHiddenFields(editorName);
        if (!synced.plain && !synced.html) {
          showGmailToast('Please enter a message body before sending.', 'error');
          return;
        }

        const recipient = form.querySelector('[name="recipient_email"]')?.value.trim() || '';
        const cc = form.querySelector('[name="cc_email"]')?.value.trim() || '';
        const subject = form.querySelector('[name="subject"]')?.value.trim() || '(Thread Reply)';
        const fileInput = form.querySelector('input[type="file"]');
        const fileCount = fileInput && fileInput.files ? fileInput.files.length : 0;

        if (!confirmModal) {
          dispatchEmailForm(form);
          return;
        }
        state.pendingConfirmForm = form;
        const summary = document.getElementById('gmail-confirm-summary');
        if (summary) {
          summary.replaceChildren();
          const addLine = (label, value) => {
            const line = document.createElement('div');
            const strong = document.createElement('strong');
            strong.textContent = label + ': ';
            line.append(strong, document.createTextNode(value));
            summary.appendChild(line);
          };
          addLine('To', recipient);
          if (cc) addLine('CC', cc);
          addLine('Subject', subject);
          addLine('Attachments', fileCount + ' file(s)');
          const mode = document.createElement('div');
          mode.className = 'muted';
          mode.style.marginTop = '6px';
          mode.textContent = state.linkedEmail
            ? 'Delivery Mode: Gmail API (' + state.linkedEmail + ') + Rich HTML Archive'
            : 'Delivery Mode: Workly internal rich archive. Connect Gmail to send externally.';
          summary.appendChild(mode);
        }
        confirmModal.classList.remove('is-closing', 'is-hidden');
      });
    });

    if (confirmProceedButton) {
      confirmProceedButton.addEventListener('click', () => {
        if (confirmModal) confirmModal.classList.add('is-hidden');
        if (state.pendingConfirmForm) {
          const form = state.pendingConfirmForm;
          state.pendingConfirmForm = null;
          dispatchEmailForm(form);
        }
      });
    }
    document.querySelectorAll('[data-close-gmail-confirm]').forEach((button) => {
      button.addEventListener('click', () => {
        state.pendingConfirmForm = null;
        if (confirmModal) confirmModal.classList.add('is-hidden');
      });
    });
    document.querySelectorAll('[data-email-files-input]').forEach((input) => {
      input.addEventListener('change', () => {
        const preview = document.getElementById(input.getAttribute('data-email-files-input'));
        if (!preview) return;
        preview.replaceChildren();
        Array.from(input.files || []).forEach((file) => {
          const chip = document.createElement('span');
          chip.className = 'badge badge-neutral';
          chip.textContent = file.name + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)';
          preview.appendChild(chip);
        });
      });
    });

    loadGmailStatus();
    startRealtimePolling();
  });
})();
