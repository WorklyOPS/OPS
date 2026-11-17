/* ==========================================================================
   Workly Gmail Workspace Integration (Client-Side Firebase Google OAuth)
   Zero TypeScript · Zero Inline Scripts · In-Memory Access Token Only
   ========================================================================== */
(function () {
  'use strict';

  const cfgEl = document.getElementById('workly-firebase-config');
  if (!cfgEl || typeof window.firebase === 'undefined') return;

  let fbConfig = null;
  try {
    fbConfig = JSON.parse(cfgEl.textContent || 'null');
  } catch (_e) {
    fbConfig = null;
  }
  if (!fbConfig || !fbConfig.apiKey || !fbConfig.projectId) return;

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const CSRF_TOKEN = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';

  // Store OAuth access token strictly in JS memory (never localStorage/sessionStorage)
  let gmailAccessToken = '';
  let gmailConnectedEmail = '';
  let pendingConfirmForm = null;

  if (!window.firebase.apps || window.firebase.apps.length === 0) {
    window.firebase.initializeApp(fbConfig);
  }

  function showGmailToast(msg, type) {
    if (typeof window.worklyShowToast === 'function') {
      window.worklyShowToast(msg, type);
      return;
    }
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : 'success');
    el.textContent = msg;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), 3500);
  }

  function updateGmailUiState() {
    const statusBadge = document.getElementById('gmail-oauth-status-badge');
    const syncBtn = document.getElementById('btn-gmail-sync-now');
    const disconnectBtn = document.getElementById('btn-gmail-disconnect');
    const connectBtn = document.getElementById('btn-google-signin-gmail');

    if (gmailAccessToken !== '') {
      if (statusBadge) {
        statusBadge.className = 'badge badge-success';
        statusBadge.textContent = 'Gmail Live: ' + (gmailConnectedEmail || 'Connected');
      }
      if (syncBtn) syncBtn.classList.remove('is-hidden');
      if (disconnectBtn) disconnectBtn.classList.remove('is-hidden');
      if (connectBtn) connectBtn.classList.add('is-hidden');
    } else {
      if (statusBadge && statusBadge.dataset.defaultLabel) {
        statusBadge.className = statusBadge.dataset.defaultClass || 'badge badge-neutral';
        statusBadge.textContent = statusBadge.dataset.defaultLabel;
      }
      if (syncBtn) syncBtn.classList.add('is-hidden');
      if (disconnectBtn) disconnectBtn.classList.add('is-hidden');
      if (connectBtn) connectBtn.classList.remove('is-hidden');
    }
  }

  async function syncWithGmailApi() {
    const syncBtn = document.getElementById('btn-gmail-sync-now');
    if (syncBtn) syncBtn.disabled = true;
    try {
      const headers = {
        'X-CSRF-Token': CSRF_TOKEN,
        'Accept': 'application/json'
      };
      if (gmailAccessToken !== '') {
        headers['X-Gmail-Access-Token'] = gmailAccessToken;
      }
      const res = await fetch('/api/v1/email/sync-gmail', {
        method: 'POST',
        headers
      });
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Gmail sync failed');
      }
      if (data.gmail_email) {
        gmailConnectedEmail = data.gmail_email;
      }
      showGmailToast('Synced Gmail (' + (data.imported || 0) + ' new messages)', 'success');
      window.setTimeout(() => window.location.reload(), 450);
    } catch (err) {
      showGmailToast(err.message || 'Could not sync Gmail', 'error');
    } finally {
      if (syncBtn) syncBtn.disabled = false;
    }
  }

  async function connectGoogleGmail() {
    const btn = document.getElementById('btn-google-signin-gmail');
    if (btn) btn.disabled = true;
    try {
      const provider = new window.firebase.auth.GoogleAuthProvider();
      provider.addScope('https://www.googleapis.com/auth/gmail.readonly');
      provider.addScope('https://www.googleapis.com/auth/gmail.send');
      provider.addScope('https://www.googleapis.com/auth/gmail.modify');
      provider.setCustomParameters({ prompt: 'select_account' });

      const result = await window.firebase.auth().signInWithPopup(provider);
      const cred = result && result.credential ? result.credential : null;
      const accessToken = cred && cred.accessToken ? cred.accessToken : '';
      if (!accessToken) {
        throw new Error('Google did not return an OAuth access token. Ensure popups are allowed.');
      }

      gmailAccessToken = accessToken;
      gmailConnectedEmail = (result.user && result.user.email) ? result.user.email : '';
      updateGmailUiState();
      await syncWithGmailApi();
    } catch (err) {
      showGmailToast(err.message || 'Google sign-in cancelled or failed', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function dispatchEmailForm(form) {
    const url = form.getAttribute('data-gmail-form');
    if (!url) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const fd = new FormData(form);
      const headers = {
        'X-CSRF-Token': CSRF_TOKEN,
        'Accept': 'application/json'
      };
      if (gmailAccessToken !== '') {
        headers['X-Gmail-Access-Token'] = gmailAccessToken;
      }

      const res = await fetch(url, {
        method: 'POST',
        headers,
        body: fd
      });
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Email delivery failed');
      }
      showGmailToast(
        data.gmail_synced ? 'Sent via Gmail & archived in PostgreSQL' : 'Message saved to Workly Email Hub',
        'success'
      );
      const nextUrl = data.thread_id
        ? '/email?thread=' + encodeURIComponent(data.thread_id)
        : '/email';
      window.setTimeout(() => { window.location.href = nextUrl; }, 420);
    } catch (err) {
      showGmailToast(err.message || 'Could not send message', 'error');
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  window.addEventListener('DOMContentLoaded', () => {
    const connectBtn = document.getElementById('btn-google-signin-gmail');
    const syncBtn = document.getElementById('btn-gmail-sync-now');
    const disconnectBtn = document.getElementById('btn-gmail-disconnect');
    const confirmModal = document.getElementById('modal-confirm-gmail-send');
    const confirmProceedBtn = document.getElementById('btn-confirm-gmail-send-go');
    const confirmCancelBtns = document.querySelectorAll('[data-close-gmail-confirm]');

    if (connectBtn) {
      connectBtn.addEventListener('click', connectGoogleGmail);
    }
    if (syncBtn) {
      syncBtn.addEventListener('click', syncWithGmailApi);
    }
    if (disconnectBtn) {
      disconnectBtn.addEventListener('click', () => {
        gmailAccessToken = '';
        gmailConnectedEmail = '';
        if (window.firebase.auth) {
          window.firebase.auth().signOut().catch(() => {});
        }
        updateGmailUiState();
        showGmailToast('Disconnected live Gmail session token', 'success');
      });
    }

    // Intercept compose & reply forms with mandatory confirmation dialog before dispatch
    document.querySelectorAll('form[data-gmail-form]').forEach((form) => {
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const recInp = form.querySelector('[name="recipient_email"]');
        const ccInp = form.querySelector('[name="cc_email"]');
        const subjInp = form.querySelector('[name="subject"]');
        const filesInp = form.querySelector('input[type="file"]');

        const recipient = recInp ? recInp.value.trim() : '';
        const cc = ccInp ? ccInp.value.trim() : '';
        const subject = subjInp ? subjInp.value.trim() : '(Thread Reply)';
        const fileCount = (filesInp && filesInp.files) ? filesInp.files.length : 0;

        if (!confirmModal) {
          dispatchEmailForm(form);
          return;
        }

        pendingConfirmForm = form;
        const sumEl = document.getElementById('gmail-confirm-summary');
        if (sumEl) {
          sumEl.innerHTML = '';
          const p1 = document.createElement('div');
          p1.innerHTML = '<strong>To:</strong> ';
          p1.appendChild(document.createTextNode(recipient));
          sumEl.appendChild(p1);

          if (cc) {
            const pCc = document.createElement('div');
            pCc.innerHTML = '<strong>CC:</strong> ';
            pCc.appendChild(document.createTextNode(cc));
            sumEl.appendChild(pCc);
          }

          const p2 = document.createElement('div');
          p2.innerHTML = '<strong>Subject:</strong> ';
          p2.appendChild(document.createTextNode(subject));
          sumEl.appendChild(p2);

          const p3 = document.createElement('div');
          p3.innerHTML = '<strong>Attachments:</strong> ';
          p3.appendChild(document.createTextNode(fileCount + ' file(s)'));
          sumEl.appendChild(p3);

          const p4 = document.createElement('div');
          p4.style.marginTop = '6px';
          p4.className = 'muted';
          p4.textContent = gmailAccessToken
            ? 'Delivery Mode: Live Gmail API (' + (gmailConnectedEmail || 'Authorized Google Account') + ') + PostgreSQL Archive'
            : 'Delivery Mode: Internal Workly Email Hub (Connect Google Account above to dispatch external Gmail)';
          sumEl.appendChild(p4);
        }

        confirmModal.classList.remove('is-closing', 'is-hidden');
      });
    });

    if (confirmProceedBtn) {
      confirmProceedBtn.addEventListener('click', () => {
        if (confirmModal) confirmModal.classList.add('is-hidden');
        if (pendingConfirmForm) {
          const target = pendingConfirmForm;
          pendingConfirmForm = null;
          dispatchEmailForm(target);
        }
      });
    }

    confirmCancelBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        pendingConfirmForm = null;
        if (confirmModal) confirmModal.classList.add('is-hidden');
      });
    });

    // Attachment filename preview chips
    document.querySelectorAll('[data-email-files-input]').forEach((inp) => {
      inp.addEventListener('change', () => {
        const previewId = inp.getAttribute('data-email-files-input');
        const box = previewId ? document.getElementById(previewId) : null;
        if (!box) return;
        box.innerHTML = '';
        Array.from(inp.files || []).forEach((f) => {
          const chip = document.createElement('span');
          chip.className = 'badge badge-neutral';
          chip.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)';
          box.appendChild(chip);
        });
      });
    });

    updateGmailUiState();
  });
})();
