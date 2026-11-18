/* ========================================================================
   Workly Gmail OAuth2 — server-side authorization
   Google tokens never leave the PHP backend.
   ======================================================================== */
(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const csrfToken = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';
  let connectedEmail = '';
  let pendingConfirmForm = null;

  function showGmailToast(message, type) {
    if (typeof window.worklyShowToast === 'function') {
      window.worklyShowToast(message, type);
      return;
    }
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const element = document.createElement('div');
    element.className = 'toast ' + (type === 'error' ? 'error' : 'success');
    element.textContent = message;
    document.body.appendChild(element);
    window.setTimeout(() => element.remove(), 3500);
  }

  function updateGmailUiState(connected, email) {
    const badge = document.getElementById('gmail-oauth-status-badge');
    const syncButton = document.getElementById('btn-gmail-sync-now');
    const disconnectButton = document.getElementById('btn-gmail-disconnect');
    const connectButton = document.getElementById('btn-google-signin-gmail');

    connectedEmail = connected ? (email || '') : '';
    if (badge) {
      badge.className = connected ? 'badge badge-success' : 'badge badge-neutral';
      badge.textContent = connected ? ('Gmail connected: ' + (connectedEmail || 'Connected')) : 'Gmail not connected';
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

  async function loadGmailStatus() {
    try {
      const data = await requestJson('/api/v1/email/status');
      updateGmailUiState(Boolean(data.connected), data.email || '');

      const params = new URLSearchParams(window.location.search);
      if (params.get('gmail_connected') === '1') {
        showGmailToast('Gmail account connected successfully.', 'success');
        window.history.replaceState({}, document.title, window.location.pathname + window.location.hash);
        await syncWithGmailApi();
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
      }
    } catch (error) {
      updateGmailUiState(false, '');
      showGmailToast(error.message || 'Could not load Gmail connection status.', 'error');
    }
  }

  function connectGoogleGmail() {
    window.location.assign('/api/v1/email/google/connect');
  }

  async function syncWithGmailApi() {
    const button = document.getElementById('btn-gmail-sync-now');
    if (button) button.disabled = true;
    try {
      const data = await requestJson('/api/v1/email/sync-gmail', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, 'Accept': 'application/json' }
      });
      showGmailToast('Gmail synchronized (' + (data.imported || 0) + ' new messages).', 'success');
      window.setTimeout(() => window.location.reload(), 500);
    } catch (error) {
      showGmailToast(error.message || 'Could not synchronize Gmail.', 'error');
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
      updateGmailUiState(false, '');
      showGmailToast('Gmail disconnected.', 'success');
    } catch (error) {
      showGmailToast(error.message || 'Could not disconnect Gmail.', 'error');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function dispatchEmailForm(form) {
    const url = form.getAttribute('data-gmail-form');
    if (!url) return;
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
      const nextUrl = data.thread_id ? '/email?thread=' + encodeURIComponent(data.thread_id) : '/email';
      window.setTimeout(() => { window.location.href = nextUrl; }, 450);
    } catch (error) {
      showGmailToast(error.message || 'Could not send message.', 'error');
      if (submitButton) submitButton.disabled = false;
    }
  }

  window.addEventListener('DOMContentLoaded', () => {
    const connectButton = document.getElementById('btn-google-signin-gmail');
    const syncButton = document.getElementById('btn-gmail-sync-now');
    const disconnectButton = document.getElementById('btn-gmail-disconnect');
    const confirmModal = document.getElementById('modal-confirm-gmail-send');
    const confirmProceedButton = document.getElementById('btn-confirm-gmail-send-go');

    if (connectButton) connectButton.addEventListener('click', connectGoogleGmail);
    if (syncButton) syncButton.addEventListener('click', syncWithGmailApi);
    if (disconnectButton) disconnectButton.addEventListener('click', disconnectGmail);

    document.querySelectorAll('form[data-gmail-form]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const recipient = form.querySelector('[name="recipient_email"]')?.value.trim() || '';
        const cc = form.querySelector('[name="cc_email"]')?.value.trim() || '';
        const subject = form.querySelector('[name="subject"]')?.value.trim() || '(Thread Reply)';
        const fileInput = form.querySelector('input[type="file"]');
        const fileCount = fileInput && fileInput.files ? fileInput.files.length : 0;

        if (!confirmModal) {
          dispatchEmailForm(form);
          return;
        }
        pendingConfirmForm = form;
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
          mode.textContent = connectedEmail
            ? 'Delivery Mode: Gmail API (' + connectedEmail + ') + Workly Archive'
            : 'Delivery Mode: Workly internal archive. Connect Gmail to send externally.';
          summary.appendChild(mode);
        }
        confirmModal.classList.remove('is-closing', 'is-hidden');
      });
    });

    if (confirmProceedButton) {
      confirmProceedButton.addEventListener('click', () => {
        if (confirmModal) confirmModal.classList.add('is-hidden');
        if (pendingConfirmForm) {
          const form = pendingConfirmForm;
          pendingConfirmForm = null;
          dispatchEmailForm(form);
        }
      });
    }
    document.querySelectorAll('[data-close-gmail-confirm]').forEach((button) => {
      button.addEventListener('click', () => {
        pendingConfirmForm = null;
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

    updateGmailUiState(false, '');
    loadGmailStatus();
  });
})();
