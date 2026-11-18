/* ==========================================================================
   Workly Platform — Production Client Orchestrator (Vanilla JS, Zero TS)
   Global Skeleton & Progress Coordinator, Portal Drawers/Modals, Header Bell
   & Profile Popovers, Hardened JSON Fetch, Swimlanes + Kanban Board, Client
   CRM Availability Slots, Unified Calendar Slot Scanner, Knowledge Base
   Knowledge Studio/PDF, Perks Icon Picker, Command Palette & SVG Charts.
   ========================================================================== */

(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const CSRF_TOKEN = csrfMeta ? (csrfMeta.getAttribute('content') || '') : '';
  const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const EYE_OPEN_SVG = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_OFF_SVG = '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a21.78 21.78 0 0 1 5.06-5.94"/><path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c6.5 0 10 7 10 7a21.78 21.78 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  // ------------------------------------------------------------------------
  // Global Loading State Coordinator (Progress Bar + Module Skeleton Screens)
  // ------------------------------------------------------------------------
  const WorklyLoader = (function () {
    let activeRequests = 0;
    let hideTimer = null;

    function getBar() {
      return document.getElementById('workly-global-progress');
    }

    function start() {
      activeRequests++;
      if (hideTimer) {
        window.clearTimeout(hideTimer);
        hideTimer = null;
      }
      const bar = getBar();
      if (!bar) return;
      bar.classList.add('is-active');
      bar.style.width = '68%';
    }

    function done() {
      activeRequests = Math.max(0, activeRequests - 1);
      if (activeRequests > 0) return;
      const bar = getBar();
      if (!bar) return;
      bar.style.width = '100%';
      hideTimer = window.setTimeout(() => {
        bar.classList.remove('is-active');
        bar.style.width = '0%';
      }, 220);
    }

    function showSkeleton() {
      start();
      const skel = document.getElementById('workly-skeleton');
      const content = document.getElementById('workly-view-content');
      if (skel && content) {
        content.classList.add('is-hidden');
        skel.classList.remove('is-hidden', 'is-leaving');
      }
    }

    function revealContent() {
      const skel = document.getElementById('workly-skeleton');
      const content = document.getElementById('workly-view-content');
      if (!skel || !content) return;
      skel.classList.add('is-leaving');
      window.setTimeout(() => {
        skel.classList.add('is-hidden');
        skel.classList.remove('is-leaving');
        content.classList.remove('is-hidden');
      }, reducedMotion ? 0 : 140);
    }

    return { start, done, showSkeleton, revealContent };
  })();

  window.addEventListener('DOMContentLoaded', () => {
    portalOverlaysToBody();
    initSkeletonStaging();
    initAuthControls();
    initLoginSecurityEnhancements();
    initCookieBanner();
    initMobileDrawer();
    initHeaderPopovers();
    initDrawersAndModals();
    initTabs();
    initCommandPalette();
    initChartTooltipsAndToggles();
    initKanbanBoard();
    initCalendarScheduler();
    initClientDossiers();
    initAdminEditDrawers();
    initVaDirectory();
    initKnowledgeBase();
    initPerksControls();
    initHrmPtoRejection();
    initPtoApprovalCalendar();
    initCsvExports();
    initForms();
    initInteractiveButtons();
    initOnboardingTour();
  });

  window.addEventListener('pageshow', (e) => {
    if (e.persisted) {
      if (document.getElementById('login-form')) {
        window.location.reload();
        return;
      }
      WorklyLoader.done();
      WorklyLoader.revealContent();
    }
  });

  // Portal fixed drawers, modals & floating menus to document.body so parent
  // animations/transforms never clip or offset fixed right-side panels.
  function portalOverlaysToBody() {
    document.querySelectorAll('.drawer-backdrop, .modal-backdrop, #kanban-context-menu').forEach((el) => {
      if (el.parentElement !== document.body) {
        document.body.appendChild(el);
      }
    });

    const viewContent = document.getElementById('workly-view-content');
    if (viewContent) {
      viewContent.addEventListener('animationend', () => {
        viewContent.classList.remove('view-animate');
      }, { once: true });
    }
  }

  // ------------------------------------------------------------------------
  // Staged Skeleton Loader
  // ------------------------------------------------------------------------
  function initSkeletonStaging() {
    const skel = document.getElementById('workly-skeleton');
    const content = document.getElementById('workly-view-content');
    if (skel && content) {
      const settleMs = reducedMotion ? 10 : 210;
      window.setTimeout(() => {
        WorklyLoader.revealContent();
      }, settleMs);

      document.querySelectorAll('.sidebar-nav a.nav-link, .header-popover a, .notif-item').forEach((link) => {
        link.addEventListener('click', (e) => {
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
          const href = link.getAttribute('href');
          if (!href || href.startsWith('#') || href === '/logout') return;
          WorklyLoader.showSkeleton();
        });
      });

      document.querySelectorAll('.page-content form[method="GET"]').forEach((form) => {
        form.addEventListener('submit', () => {
          WorklyLoader.showSkeleton();
        });
      });
    }
  }

  // ------------------------------------------------------------------------
  // Auth Screen Helpers
  // ------------------------------------------------------------------------
  function initAuthControls() {
    const refreshBtn = document.getElementById('btn-refresh-captcha');
    const captchaImg = document.getElementById('captcha-svg-img');
    if (refreshBtn && captchaImg) {
      refreshBtn.addEventListener('click', () => {
        captchaImg.src = '/captcha.svg?t=' + Date.now();
      });
    }

    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
      btn.innerHTML = EYE_OPEN_SVG;
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-toggle-password');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;
        const isPass = input.type === 'password';
        input.type = isPass ? 'text' : 'password';
        btn.innerHTML = isPass ? EYE_OFF_SVG : EYE_OPEN_SVG;
        btn.setAttribute('aria-pressed', isPass ? 'true' : 'false');
      });
    });
  }

  function initLoginSecurityEnhancements() {
    const form = document.getElementById('login-form');
    if (!form) return;

    const clientAlert = document.getElementById('login-client-alert');
    const serverAlert = document.getElementById('login-server-alert');
    const emailInput = document.getElementById('login-email');
    const passInput = document.getElementById('login-password');
    const captchaInput = document.getElementById('login-captcha');

    const showLoginError = (msg, focusEl) => {
      if (serverAlert) serverAlert.classList.add('is-hidden');
      if (clientAlert) {
        clientAlert.textContent = msg;
        clientAlert.classList.remove('is-hidden');
      } else {
        showToast(msg, 'error');
      }
      if (focusEl && typeof focusEl.focus === 'function') {
        focusEl.focus();
      }
    };

    const clearClientAlert = () => {
      if (clientAlert) {
        clientAlert.classList.add('is-hidden');
        clientAlert.textContent = '';
      }
    };

    [emailInput, passInput, captchaInput].forEach((el) => {
      if (el) el.addEventListener('input', clearClientAlert);
    });

    form.addEventListener('submit', (e) => {
      const email = emailInput ? String(emailInput.value || '').trim() : '';
      const pass = passInput ? String(passInput.value || '') : '';
      const captcha = captchaInput ? String(captchaInput.value || '').trim() : '';

      if (!email) {
        e.preventDefault();
        showLoginError('Please enter your work email address.', emailInput);
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        e.preventDefault();
        showLoginError('Please enter a valid work email address.', emailInput);
        return;
      }
      if (!pass) {
        e.preventDefault();
        showLoginError('Please enter your password to sign in.', passInput);
        return;
      }
      if (captchaInput && !captcha) {
        e.preventDefault();
        showLoginError('Please enter the security verification code shown above.', captchaInput);
        return;
      }

      const csrfField = form.querySelector('input[name="_csrf"]');
      if (csrfField && !String(csrfField.value || '').trim()) {
        e.preventDefault();
        showLoginError('Your login session expired. Refreshing security token...', null);
        window.setTimeout(() => window.location.reload(), 650);
      }
    });
  }

  // ------------------------------------------------------------------------
  // Essential Session Cookie Consent Popup
  // ------------------------------------------------------------------------
  function initCookieBanner() {
    const banner = document.getElementById('workly-cookie-banner');
    if (!banner) return;

    const getStoredConsent = () => {
      const m = document.cookie.match(/(?:^|;\s*)workly_cookie_consent=(accepted|declined)(?:;|$)/);
      if (m) return m[1];
      try {
        const ls = localStorage.getItem('workly_cookie_consent');
        if (ls === 'accepted' || ls === 'declined') return ls;
      } catch (_e) {
      }
      return '';
    };

    if (getStoredConsent() !== '') {
      banner.remove();
      return;
    }

    const submitConsent = async (choice) => {
      const acceptBtn = document.getElementById('btn-cookie-accept');
      const declineBtn = document.getElementById('btn-cookie-decline');
      if (acceptBtn) acceptBtn.disabled = true;
      if (declineBtn) declineBtn.disabled = true;

      const cookieFlags = window.location.protocol === 'https:' ? '; SameSite=None; Secure' : '; SameSite=Lax';
      document.cookie = 'workly_cookie_consent=' + choice + '; path=/; max-age=15552000' + cookieFlags;
      try {
        localStorage.setItem('workly_cookie_consent', choice);
      } catch (_e) {
      }

      try {
        await apiFetch('/api/v1/auth/cookie-consent', {
          method: 'POST',
          body: JSON.stringify({ consent: choice })
        });
      } catch (_err) {
        // Cookie is already saved locally if session was not active yet
      }

      banner.classList.add('is-leaving');
      window.setTimeout(() => banner.remove(), reducedMotion ? 0 : 200);
      showToast(
        choice === 'accepted'
          ? 'Essential session cookies enabled'
          : 'Persistent cookies declined — using tab session only',
        'success'
      );
    };

    const acceptBtn = document.getElementById('btn-cookie-accept');
    const declineBtn = document.getElementById('btn-cookie-decline');
    if (acceptBtn) acceptBtn.addEventListener('click', () => submitConsent('accepted'));
    if (declineBtn) declineBtn.addEventListener('click', () => submitConsent('declined'));
  }

  // ------------------------------------------------------------------------
  // Toast & Hardened CSRF API Fetch Helper
  // ------------------------------------------------------------------------
  function showToast(message, type) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : 'success');
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(() => {
      el.classList.add('is-leaving');
      window.setTimeout(() => el.remove(), reducedMotion ? 0 : 210);
    }, 3400);
  }
  window.worklyShowToast = showToast;

  async function apiFetch(url, options) {
    const opts = options || {};
    const headers = Object.assign({
      'X-CSRF-Token': CSRF_TOKEN,
      'Accept': 'application/json'
    }, opts.headers || {});

    if (!(opts.body instanceof FormData) && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    WorklyLoader.start();
    try {
      const res = await fetch(url, Object.assign({}, opts, { headers }));
      const rawText = await res.text();
      let data = null;

      if (rawText !== '') {
        try {
          data = JSON.parse(rawText);
        } catch (_parseErr) {
          const stripped = rawText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
          const fallbackMsg = stripped
            ? stripped.slice(0, 140)
            : 'Unexpected non-JSON response from server (HTTP ' + res.status + ')';
          throw new Error(fallbackMsg);
        }
      }

      if (!res.ok) {
        const msg = (data && data.error) ? data.error : 'Request failed (' + res.status + ')';
        throw new Error(msg);
      }
      return data || {};
    } finally {
      WorklyLoader.done();
    }
  }

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  function renderAvatarMarkup(url, name) {
    if (url) {
      return '<img src="' + escapeHtml(url) + '" alt="Avatar">';
    }
    const parts = String(name || 'WK').trim().split(/\s+/).filter(Boolean);
    const init = parts.length >= 2
      ? (parts[0][0] + parts[1][0]).toUpperCase()
      : String(name || 'WK').slice(0, 2).toUpperCase();
    return '<span class="avatar avatar-lg">' + escapeHtml(init) + '</span>';
  }

  // ------------------------------------------------------------------------
  // Mobile Navigation Drawer
  // ------------------------------------------------------------------------
  function initMobileDrawer() {
    const btn = document.getElementById('mobile-menu-toggle');
    const sidebar = document.getElementById('workly-sidebar');
    if (!btn || !sidebar) return;
    btn.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 860 && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && !btn.contains(e.target)) {
          sidebar.classList.remove('open');
        }
      }
    });
  }

  // ------------------------------------------------------------------------
  // Header Notifications Bell & Profile Dropdown Popovers
  // ------------------------------------------------------------------------
  function closeHeaderPopovers() {
    const notifPop = document.getElementById('notif-popover');
    const profPop = document.getElementById('profile-popover');
    const notifBtn = document.getElementById('btn-notif-bell');
    const profBtn = document.getElementById('btn-profile-menu');
    if (notifPop) notifPop.classList.add('is-hidden');
    if (profPop) profPop.classList.add('is-hidden');
    if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
    if (profBtn) profBtn.setAttribute('aria-expanded', 'false');
  }

  function initHeaderPopovers() {
    const notifBtn = document.getElementById('btn-notif-bell');
    const notifPop = document.getElementById('notif-popover');
    const profBtn = document.getElementById('btn-profile-menu');
    const profPop = document.getElementById('profile-popover');
    const markReadBtn = document.getElementById('btn-mark-notifs-read');

    if (notifBtn && notifPop) {
      notifBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const willOpen = notifPop.classList.contains('is-hidden');
        closeHeaderPopovers();
        if (willOpen) {
          notifPop.classList.remove('is-hidden');
          notifBtn.setAttribute('aria-expanded', 'true');
        }
      });
      notifPop.addEventListener('click', (e) => e.stopPropagation());
    }

    if (profBtn && profPop) {
      profBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const willOpen = profPop.classList.contains('is-hidden');
        closeHeaderPopovers();
        if (willOpen) {
          profPop.classList.remove('is-hidden');
          profBtn.setAttribute('aria-expanded', 'true');
        }
      });
      profPop.addEventListener('click', (e) => e.stopPropagation());
    }

    if (markReadBtn) {
      markReadBtn.addEventListener('click', async () => {
        try {
          markReadBtn.disabled = true;
          await apiFetch('/api/v1/notifications/read', { method: 'PATCH' });
          const badge = document.getElementById('notif-badge-count');
          if (badge) badge.remove();
          document.querySelectorAll('#notif-popover .notif-item.is-unread').forEach((item) => {
            item.classList.remove('is-unread');
          });
          const sub = notifPop ? notifPop.querySelector('.popover-head .muted') : null;
          if (sub) sub.textContent = '0 unread alert(s)';
          markReadBtn.classList.add('is-hidden');
          showToast('All notifications marked read', 'success');
        } catch (err) {
          showToast(err.message, 'error');
          markReadBtn.disabled = false;
        }
      });
    }

    document.addEventListener('click', () => {
      closeHeaderPopovers();
    });
  }

  // ------------------------------------------------------------------------
  // Right-Side Slide-Over Drawer & Centered Modal Popup System
  // ------------------------------------------------------------------------
  function openDrawer(id) {
    closeAllDrawers(true);
    closeAllModals(true);
    const el = document.getElementById(id);
    if (el) {
      if (el.parentElement !== document.body) document.body.appendChild(el);
      el.classList.remove('is-closing', 'is-hidden');
    }
  }

  function closeAllDrawers(immediate) {
    stopCollabPolling();
    document.querySelectorAll('.drawer-backdrop:not(.is-hidden)').forEach((d) => {
      if (immediate || reducedMotion) {
        d.classList.remove('is-closing');
        d.classList.add('is-hidden');
        return;
      }
      d.classList.add('is-closing');
      window.setTimeout(() => {
        d.classList.remove('is-closing');
        d.classList.add('is-hidden');
      }, 175);
    });
  }

  function openModal(id) {
    closeAllDrawers(true);
    closeAllModals(true);
    const el = document.getElementById(id);
    if (el) {
      if (el.parentElement !== document.body) document.body.appendChild(el);
      el.classList.remove('is-closing', 'is-hidden');
    }
  }

  function closeAllModals(immediate) {
    document.querySelectorAll('.modal-backdrop:not(.is-hidden)').forEach((m) => {
      if (immediate || reducedMotion) {
        m.classList.remove('is-closing');
        m.classList.add('is-hidden');
        return;
      }
      m.classList.add('is-closing');
      window.setTimeout(() => {
        m.classList.remove('is-closing');
        m.classList.add('is-hidden');
      }, 155);
    });
  }

  function initDrawersAndModals() {
    document.querySelectorAll('[data-open-drawer]').forEach((btn) => {
      btn.addEventListener('click', () => openDrawer(btn.getAttribute('data-open-drawer')));
    });
    document.querySelectorAll('[data-close-drawer]').forEach((btn) => {
      btn.addEventListener('click', () => closeAllDrawers(false));
    });
    document.querySelectorAll('.drawer-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeAllDrawers(false);
      });
    });

    document.querySelectorAll('[data-close-modal]').forEach((btn) => {
      btn.addEventListener('click', () => closeAllModals(false));
    });
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeAllModals(false);
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeAllDrawers(false);
        closeAllModals(false);
        hideContextMenu();
        closeCommandPalette();
        closeHeaderPopovers();
      }
    });
  }

  // ------------------------------------------------------------------------
  // Reusable Tab Strip Switcher (scoped via data-tab-group)
  // ------------------------------------------------------------------------
  function initTabs() {
    document.querySelectorAll('[data-tab-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const group = btn.getAttribute('data-tab-group');
        const target = btn.getAttribute('data-tab-btn');
        document.querySelectorAll('[data-tab-group="' + group + '"][data-tab-btn]').forEach((b) => {
          b.classList.toggle('active', b === btn);
        });
        document.querySelectorAll('[data-tab-group="' + group + '"][data-tab-panel]').forEach((panel) => {
          const isMatch = panel.getAttribute('data-tab-panel') === target;
          panel.classList.toggle('is-hidden', !isMatch);
          if (isMatch) {
            panel.classList.remove('view-animate');
            void panel.offsetWidth;
            panel.classList.add('view-animate');
          }
        });
      });
    });
  }

  function activateTab(group, tabKey) {
    const btn = document.querySelector('[data-tab-group="' + group + '"][data-tab-btn="' + tabKey + '"]');
    if (btn) btn.click();
  }

  // ------------------------------------------------------------------------
  // Command Palette Quick Launcher (Ctrl+K / Cmd+K)
  // ------------------------------------------------------------------------
  function closeCommandPalette() {
    const bd = document.getElementById('workly-cmdk');
    if (!bd || bd.classList.contains('is-hidden')) return;
    if (reducedMotion) {
      bd.classList.add('is-hidden');
      return;
    }
    bd.classList.add('is-closing');
    window.setTimeout(() => {
      bd.classList.remove('is-closing');
      bd.classList.add('is-hidden');
    }, 140);
  }

  function initCommandPalette() {
    const bd = document.getElementById('workly-cmdk');
    const openBtn = document.getElementById('btn-open-cmdk');
    const input = document.getElementById('cmdk-search-input');
    const list = document.getElementById('cmdk-results');
    if (!bd || !input || !list) return;

    function openCmdk() {
      bd.classList.remove('is-closing', 'is-hidden');
      input.value = '';
      filterItems('');
      window.setTimeout(() => input.focus(), 30);
    }

    function filterItems(q) {
      const term = q.trim().toLowerCase();
      list.querySelectorAll('.cmdk-item').forEach((item) => {
        const hay = (item.textContent || '').toLowerCase();
        item.classList.toggle('is-hidden', term !== '' && !hay.includes(term));
      });
    }

    if (openBtn) openBtn.addEventListener('click', openCmdk);
    bd.addEventListener('click', (e) => {
      if (e.target === bd) closeCommandPalette();
    });
    input.addEventListener('input', () => filterItems(input.value));

    list.addEventListener('click', (e) => {
      const btn = e.target.closest('.cmdk-item');
      if (!btn) return;
      const url = btn.getAttribute('data-cmdk-href');
      const drawer = btn.getAttribute('data-cmdk-drawer');
      closeCommandPalette();
      if (url) {
        WorklyLoader.showSkeleton();
        window.location.href = url;
      } else if (drawer) {
        openDrawer(drawer);
      }
    });

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        if (bd.classList.contains('is-hidden')) {
          openCmdk();
        } else {
          closeCommandPalette();
        }
      }
    });
  }

  // ------------------------------------------------------------------------
  // Interactive SVG Chart Tooltips & Click Targets
  // ------------------------------------------------------------------------
  function initChartTooltipsAndToggles() {
    let tip = document.getElementById('workly-chart-tooltip');
    if (!tip) {
      tip = document.createElement('div');
      tip.id = 'workly-chart-tooltip';
      tip.className = 'chart-tooltip is-hidden';
      document.body.appendChild(tip);
    }

    document.querySelectorAll('[data-chart-tip]').forEach((el) => {
      el.addEventListener('mouseenter', () => {
        tip.textContent = el.getAttribute('data-chart-tip') || '';
        tip.classList.remove('is-hidden');
      });
      el.addEventListener('mousemove', (e) => {
        tip.style.left = Math.min(window.innerWidth - 260, e.clientX + 14) + 'px';
        tip.style.top = Math.max(10, e.clientY - 38) + 'px';
      });
      el.addEventListener('mouseleave', () => {
        tip.classList.add('is-hidden');
      });
      const href = el.getAttribute('data-chart-href');
      if (href) {
        el.addEventListener('click', () => {
          WorklyLoader.showSkeleton();
          window.location.href = href;
        });
      }
    });
  }

  // ------------------------------------------------------------------------
  // Kanban Swimlanes + 5-Column Board + Cursor Context Menu + Split Windows
  // ------------------------------------------------------------------------
  let contextTaskId = null;

  function hideContextMenu() {
    const cm = document.getElementById('kanban-context-menu');
    if (!cm || cm.classList.contains('is-hidden')) return;
    contextTaskId = null;
    if (reducedMotion) {
      cm.classList.add('is-hidden');
      return;
    }
    cm.classList.add('is-closing');
    window.setTimeout(() => {
      cm.classList.remove('is-closing');
      cm.classList.add('is-hidden');
    }, 120);
  }

  function updateBoardCounters(filterTerm) {
    const hasFilter = Boolean(filterTerm && filterTerm.trim() !== '');
    document.querySelectorAll('.kanban-col, .swimlane-col').forEach((col) => {
      const cards = Array.from(col.querySelectorAll('.task-card'));
      const visibleCount = cards.filter((c) => c.style.display !== 'none').length;
      const badge = col.querySelector('.col-count');
      if (badge) badge.textContent = String(visibleCount);
    });

    document.querySelectorAll('.swimlane-section').forEach((lane) => {
      const cards = Array.from(lane.querySelectorAll('.task-card'));
      const visibleCount = cards.filter((c) => c.style.display !== 'none').length;
      const laneBadge = lane.querySelector('.swimlane-count');
      if (laneBadge) laneBadge.textContent = visibleCount + (visibleCount === 1 ? ' task' : ' tasks');
      lane.style.display = (hasFilter && visibleCount === 0) ? 'none' : '';
    });
  }

  function getTaskDataset() {
    const store = document.getElementById('task-details-json');
    if (!store) return null;
    try {
      return JSON.parse(store.textContent || '{}');
    } catch (_e) {
      return null;
    }
  }

  let activeEditTaskId = null;

  function openTaskEditModal(taskId) {
    const allData = getTaskDataset();
    const task = allData && allData.tasks ? allData.tasks[taskId] : null;
    const modal = document.getElementById('modal-task-edit');
    if (!task || !modal) return;

    activeEditTaskId = taskId;
    const tEl = modal.querySelector('#modal-edit-task-title');
    const mEl = modal.querySelector('#modal-edit-task-meta');
    if (tEl) tEl.textContent = task.title || 'Edit Deliverable Details';
    if (mEl) mEl.textContent = (task.client_name || '') + ' · ' + (task.va_name || '') + ' · Due ' + (task.deadline || '');

    const editForm = modal.querySelector('#form-edit-task');
    if (editForm) {
      editForm.setAttribute('data-api-form', '/api/v1/tasks/' + encodeURIComponent(taskId));
      const setVal = (sel, val) => {
        const inp = editForm.querySelector(sel);
        if (inp) inp.value = val != null ? val : '';
      };
      setVal('[name="title"]', task.title || '');
      setVal('[name="status"]', task.status || 'todo');
      setVal('[name="priority"]', task.priority || 'medium');
      if (task.client_id) setVal('[name="client_id"]', task.client_id);
      setVal('[name="deadline"]', task.deadline || '');
      setVal('[name="estimated_hours"]', task.estimated_hours || 4);
      setVal('[name="description"]', task.description || '');
    }

    openModal('modal-task-edit');
  }

  let activeCollabTaskId = null;
  let collabPollTimer = null;

  function stopCollabPolling() {
    activeCollabTaskId = null;
    if (collabPollTimer) {
      window.clearInterval(collabPollTimer);
      collabPollTimer = null;
    }
  }

  function renderSmallAvatarHtml(url, name) {
    if (url) {
      return '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name || 'User') + '" class="avatar-xs">';
    }
    const parts = String(name || 'WK').trim().split(/\s+/).filter(Boolean);
    const init = parts.length >= 2
      ? (parts[0][0] + parts[1][0]).toUpperCase()
      : String(name || 'WK').slice(0, 2).toUpperCase();
    return '<span class="avatar-xs">' + escapeHtml(init) + '</span>';
  }

  function formatShortTs(ts) {
    if (!ts) return '';
    return String(ts).replace('T', ' ').slice(0, 16);
  }

  function formatTime(ts) {
      if (!ts) return '';
      return String(ts).replace('T', ' ').slice(11, 16);
  }

  function saveTaskDataset(allData) {
    const store = document.getElementById('task-details-json');
    if (store && allData) {
      store.textContent = JSON.stringify(allData);
    }
  }

  function collectTaskMentionNames(taskId, allData) {
    const seen = new Set();
    const names = [];
    const pushName = (n) => {
      const clean = String(n || '').trim();
      if (!clean) return;
      const key = clean.toLowerCase();
      if (seen.has(key)) return;
      seen.add(key);
      names.push(clean);
    };

    const data = allData || {};
    ((data.mention_candidates || {})[taskId] || []).forEach((c) => pushName(c.name));
    const task = (data.tasks || {})[taskId];
    if (task && task.va_name) pushName(task.va_name);
    ((data.comment_authors || {})[taskId] || []).forEach((a) => pushName(a.name));
    ((data.notes || {})[taskId] || []).forEach((n) => pushName(n.author_name));
    ((data.comments || {})[taskId] || []).forEach((c) => pushName(c.author_name));
    if (data.current_user_name) pushName(data.current_user_name);

    names.sort((a, b) => b.length - a.length);
    return names;
  }

  function formatTextWithMentionsHtml(rawText, taskId, allData) {
    const text = String(rawText || '');
    if (!text.includes('@')) return escapeHtml(text);

    const knownNames = collectTaskMentionNames(taskId, allData);
    const selfLower = String((allData && allData.current_user_name) || '').trim().toLowerCase();
    const lowerText = text.toLowerCase();

    let out = '';
    let i = 0;
    let plainStart = 0;

    while (i < text.length) {
      if (text[i] === '@') {
        const prevChar = i > 0 ? text[i - 1] : '';
        const validBoundaryBefore = i === 0 || /[\s(\[{"',:;]/.test(prevChar);

        if (validBoundaryBefore) {
          let matchedLen = 0;
          let matchedText = '';

          for (let k = 0; k < knownNames.length; k++) {
            const candidate = knownNames[k];
            const candLower = candidate.toLowerCase();
            if (lowerText.startsWith(candLower, i + 1)) {
              const nextIdx = i + 1 + candidate.length;
              const nextChar = nextIdx < text.length ? text[nextIdx] : '';
              if (nextIdx === text.length || /[\s.,!?:;)\]}"']/.test(nextChar)) {
                matchedLen = 1 + candidate.length;
                matchedText = text.slice(i, i + matchedLen);
                break;
              }
            }
          }

          if (!matchedLen) {
            const rest = text.slice(i + 1);
            const m = rest.match(/^([A-Za-z\u00C0-\u024F0-9_-]{2,32})/);
            if (m) {
              matchedLen = 1 + m[1].length;
              matchedText = '@' + m[1];
            }
          }

          if (matchedLen > 1) {
            if (i > plainStart) {
              out += escapeHtml(text.slice(plainStart, i));
            }
            const mentionBody = matchedText.slice(1).trim().toLowerCase();
            const isSelf = selfLower !== '' && (
              mentionBody === selfLower ||
              selfLower.split(/\s+/)[0] === mentionBody
            );
            out += '<span class="mention-tag' + (isSelf ? ' is-self' : '') + '">' + escapeHtml(matchedText) + '</span>';
            i += matchedLen;
            plainStart = i;
            continue;
          }
        }
      }
      i++;
    }

    if (plainStart < text.length) {
      out += escapeHtml(text.slice(plainStart));
    }
    return out;
  }

  function renderSubtaskContainer(taskId, listEl, counterEl, progressEl, subtasks) {
    const items = Array.isArray(subtasks) ? subtasks : [];
    const total = items.length;
    const done = items.filter((s) => Boolean(s.is_completed)).length;
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;

    if (counterEl) {
      counterEl.textContent = done + ' / ' + total + ' done';
    }
    if (progressEl) {
      progressEl.style.width = pct + '%';
    }
    if (!listEl) return;

    listEl.innerHTML = '';
    if (total === 0) {
      listEl.innerHTML = '<div class="collab-empty">No sub-tasks yet. Break this deliverable into smaller steps below.</div>';
      return;
    }

    items.forEach((st) => {
      const row = document.createElement('div');
      row.className = 'subtask-item' + (st.is_completed ? ' is-done' : '');
      row.setAttribute('data-subtask-id', st.id || '');
      row.setAttribute('data-subtask-task-id', taskId);

      const label = document.createElement('label');
      label.className = 'subtask-check-label';

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.className = 'subtask-checkbox';
      cb.checked = Boolean(st.is_completed);
      cb.setAttribute('data-subtask-toggle', st.id || '');
      cb.setAttribute('data-subtask-task-id', taskId);

      const titleSpan = document.createElement('span');
      titleSpan.className = 'subtask-title';
      titleSpan.textContent = st.title || '';

      label.appendChild(cb);
      label.appendChild(titleSpan);

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'subtask-del-btn';
      delBtn.setAttribute('data-subtask-delete', st.id || '');
      delBtn.setAttribute('data-subtask-task-id', taskId);
      delBtn.setAttribute('title', 'Delete sub-task');
      delBtn.setAttribute('aria-label', 'Delete sub-task');
      delBtn.textContent = '×';

      row.appendChild(label);
      row.appendChild(delBtn);
      listEl.appendChild(row);
    });
  }

  function getSuggestionsForVa(vaId, allData) {
    const data = allData || {};
    const map = (data.suggested_by_va && typeof data.suggested_by_va === 'object') ? data.suggested_by_va : {};
    const entry = (vaId && map[String(vaId)]) ? map[String(vaId)] : (map.__default__ || {});
    return {
      steps: Array.isArray(entry.steps) ? entry.steps : [],
      tasks: Array.isArray(entry.tasks) ? entry.tasks : []
    };
  }

  let activeSubtasksTaskId = null;

  function renderSubtaskSuggestionsForModal(taskId, allData) {
    const wrap = document.getElementById('modal-subtask-suggestions-tags');
    const box = document.getElementById('modal-subtask-suggestions');
    if (!wrap) return;

    const data = allData || getTaskDataset() || {};
    const task = (data.tasks || {})[taskId] || {};
    const vaId = String(task.assigned_va_id || data.current_user_id || '');
    const existingSet = new Set(
      ((data.subtasks || {})[taskId] || []).map((s) => String(s.title || '').trim().toLowerCase())
    );

    const resolved = getSuggestionsForVa(vaId, data);
    if (box) {
      box.classList.toggle('is-hidden', resolved.steps.length === 0);
    }

    wrap.innerHTML = '';
    resolved.steps.forEach((stepTitle) => {
      const cleanTitle = String(stepTitle || '').trim();
      if (!cleanTitle) return;
      const isAdded = existingSet.has(cleanTitle.toLowerCase());
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'subtask-suggest-tag' + (isAdded ? ' is-added' : '');
      btn.setAttribute('data-modal-suggest-step', cleanTitle);
      btn.setAttribute('title', isAdded ? 'Already in checklist' : 'Click to add step');
      if (isAdded) btn.disabled = true;

      const iconSpan = document.createElement('span');
      iconSpan.className = 'subtask-suggest-plus';
      iconSpan.textContent = isAdded ? '✓' : '+';
      const txtSpan = document.createElement('span');
      txtSpan.textContent = cleanTitle;

      btn.appendChild(iconSpan);
      btn.appendChild(txtSpan);
      wrap.appendChild(btn);
    });
  }

  function renderSubtasksForTask(taskId, allData) {
    const subtasks = ((allData && allData.subtasks) ? allData.subtasks[taskId] : null) || [];
    const modal = document.getElementById('modal-task-subtasks');
    if (modal && activeSubtasksTaskId === taskId) {
      renderSubtaskContainer(
        taskId,
        modal.querySelector('#subtasks-modal-list'),
        modal.querySelector('#subtasks-modal-counter'),
        modal.querySelector('#subtasks-modal-progress'),
        subtasks
      );
      renderSubtaskSuggestionsForModal(taskId, allData);
    }
  }

  async function openTaskSubtasksModal(taskId) {
    const allData = getTaskDataset() || {};
    const task = allData.tasks ? allData.tasks[taskId] : null;
    const modal = document.getElementById('modal-task-subtasks');
    if (!task || !modal) return;

    activeSubtasksTaskId = taskId;
    const tEl = modal.querySelector('#subtasks-modal-task-title');
    const mEl = modal.querySelector('#subtasks-modal-task-meta');
    if (tEl) tEl.textContent = (task.title || 'Sub-Tasks Checklist');
    if (mEl) mEl.textContent = (task.client_name || '') + ' · ' + (task.va_name || '') + ' · Due ' + (task.deadline || '');

    renderSubtasksForTask(taskId, allData);
    openModal('modal-task-subtasks');

    const inp = modal.querySelector('#subtasks-modal-input');
    if (inp) window.setTimeout(() => inp.focus(), 60);

    try {
      const res = await fetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/subtasks', {
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }
      });
      if (res.ok) {
        const data = await res.json();
        if (data && data.success && Array.isArray(data.subtasks) && activeSubtasksTaskId === taskId) {
          const fresh = getTaskDataset() || {};
          fresh.subtasks = fresh.subtasks || {};
          fresh.subtasks[taskId] = data.subtasks;
          saveTaskDataset(fresh);
          renderSubtasksForTask(taskId, fresh);
          syncTaskCardIndicators(taskId, fresh);
        }
      }
    } catch (_e) {
    }
  }

  function syncTaskCardIndicators(taskId, allData) {
    const notes = ((allData.notes || {})[taskId]) || [];
    const comments = ((allData.comments || {})[taskId]) || [];
    const authors = ((allData.comment_authors || {})[taskId]) || [];
    const subtasks = ((allData.subtasks || {})[taskId]) || [];
    const subTotal = subtasks.length;
    const subDone = subtasks.filter((s) => Boolean(s.is_completed)).length;
    const subPct = subTotal > 0 ? Math.round((subDone / subTotal) * 100) : 0;

    document.querySelectorAll('[data-task-note-count="' + taskId + '"]').forEach((el) => {
      el.textContent = String(notes.length);
    });
    document.querySelectorAll('[data-task-comment-count="' + taskId + '"]').forEach((el) => {
      el.textContent = String(comments.length);
    });
    document.querySelectorAll('[data-task-subtask-count="' + taskId + '"]').forEach((el) => {
      el.textContent = subDone + '/' + subTotal;
    });
    document.querySelectorAll('[data-task-subtask-bar="' + taskId + '"]').forEach((bar) => {
      bar.classList.toggle('is-hidden', subTotal === 0);
    });
    document.querySelectorAll('[data-task-subtask-fill="' + taskId + '"]').forEach((fill) => {
      fill.style.width = subPct + '%';
    });
    document.querySelectorAll('[data-task-subtask-pct="' + taskId + '"]').forEach((pctEl) => {
      pctEl.textContent = subPct + '%';
    });
    document.querySelectorAll('[data-task-commenter-avatars="' + taskId + '"]').forEach((wrap) => {
      wrap.innerHTML = '';
      authors.slice(0, 3).forEach((ca) => {
        const init = String(ca.name || 'U').slice(0, 2).toUpperCase();
        if (ca.avatar_url) {
          const img = document.createElement('img');
          img.src = ca.avatar_url;
          img.alt = ca.name || 'User';
          img.title = 'Commented by ' + (ca.name || 'User');
          img.className = 'avatar avatar-xs commenter-avatar-item';
          wrap.appendChild(img);
        } else {
          const sp = document.createElement('span');
          sp.className = 'avatar avatar-xs commenter-avatar-item';
          sp.title = 'Commented by ' + (ca.name || 'User');
          sp.textContent = init;
          wrap.appendChild(sp);
        }
      });
      if (authors.length > 3) {
        const more = document.createElement('span');
        more.className = 'commenter-more';
        more.textContent = '+' + (authors.length - 3);
        wrap.appendChild(more);
      }
    });
  }

  function buildCollabActionBtn(label, action, attrName, attrVal) {
    return '<button type="button" class="collab-card-action" data-collab-action="' + action + '" ' +
      attrName + '="' + escapeHtml(attrVal || '') + '">' + escapeHtml(label) + '</button>';
  }

  function renderTaskCollabLists(taskId, allData) {
    const drawer = document.getElementById('drawer-task-collab');
    if (!drawer || !allData) return;

    const currentUid = String(allData.current_user_id || '');
    const notes = ((allData.notes || {})[taskId]) || [];
    const comments = ((allData.comments || {})[taskId]) || [];

    // Combined Timeline: Notes carry clean warm highlight; Comments align right for self and left for others
    const combinedList = drawer.querySelector('#detail-combined-list');
    if (combinedList) {
      combinedList.innerHTML = '';
      const merged = [];
      notes.forEach((n) => {
        merged.push({
          kind: 'note',
          author_id: n.author_id || '',
          author_name: n.author_name || 'Staff',
          author_role: n.author_role || 'va',
          avatar_url: n.avatar_url || '',
          text: n.content || '',
          created_at: n.created_at || ''
        });
      });
      comments.forEach((c) => {
        merged.push({
          kind: 'comment',
          author_id: c.author_id || '',
          author_name: c.author_name || 'Staff',
          author_role: c.author_role || 'va',
          avatar_url: c.avatar_url || '',
          text: c.message || '',
          created_at: c.created_at || ''
        });
      });
      merged.sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
      
      let previousDay = '';

      if (merged.length === 0) {
        combinedList.innerHTML = '<div class="collab-empty">No notes or comments on this deliverable yet.</div>';
      } else {
        merged.forEach((item) => {
          const currentDay = String(item.created_at || '').slice(0, 10);
          const el = document.createElement('article');
          const rolePretty = String(item.author_role || 'va').replace('_', ' ');

          if (currentDay && currentDay !== previousDay) {
            const separator = document.createElement('div');
            separator.className = 'collab-day-separator';
            separator.textContent = formatShortTs(item.created_at);

            combinedList.appendChild(separator);
            previousDay = currentDay;
          }

          if (item.kind === 'note') {
            el.className = 'collab-note-item';
            el.innerHTML =
              renderSmallAvatarHtml(item.avatar_url, item.author_name) +
              '<div class="collab-entry-body">' +
                '<div class="collab-entry-head">' +
                  '<div class="collab-entry-author">' +
                    '<strong>' + escapeHtml(item.author_name) + '</strong>' +
                    '<span class="collab-note-tag">Note</span>' +
                    '<span class="muted mono collab-entry-ts">' + escapeHtml(formatShortTs(item.created_at)) + '</span>' +
                  '</div>' +
                  '<div class="collab-entry-actions">' +
                    buildCollabActionBtn('Reply', 'reply', 'data-author-name', item.author_name) +
                  '</div>' +
                '</div>' +
                '<p class="collab-entry-text">' + formatTextWithMentionsHtml(item.text, taskId, allData) + '</p>' +
              '</div>';
          } else {
            const isMine = currentUid !== '' && String(item.author_id || '') === currentUid;
            el.className = 'collab-comment-item ' + (isMine ? 'is-mine' : 'is-other');
            el.innerHTML =
              renderSmallAvatarHtml(item.avatar_url, item.author_name) +
              '<div class="collab-entry-body">' +
                '<div class="collab-entry-head">' +
                  '<div class="collab-entry-author">' +
                    '<strong>' + escapeHtml(item.author_name) + '</strong>' +
                    '<span class="muted collab-role-sep">·</span>' +
                    '<span class="muted" style="font-size:11.5px;text-transform:capitalize;">' + escapeHtml(rolePretty) + '</span>' +
                    '<span class="muted collab-role-sep">·</span>' +
                    '<span class="muted mono collab-entry-ts">' + escapeHtml(formatTime(item.created_at)) + '</span>' +
                  '</div>' +
                  '<div class="collab-entry-actions">' +
                    buildCollabActionBtn('Reply', 'reply', 'data-author-name', item.author_name) +
                  '</div>' +
                '</div>' +
                '<p class="collab-entry-text">' + formatTextWithMentionsHtml(item.text, taskId, allData) + '</p>' +
              '</div>';
          }
          combinedList.appendChild(el);
        });
      }
    }

    const notesList = drawer.querySelector('#detail-notes-list');
    if (notesList) {
      notesList.innerHTML = '';
      if (notes.length === 0) {
        notesList.innerHTML = '<div class="collab-empty">No execution notes added yet.</div>';
      } else {
        notes.forEach((n) => {
          const div = document.createElement('article');
          div.className = 'collab-note-item';
          div.innerHTML =
            renderSmallAvatarHtml(n.avatar_url, n.author_name) +
            '<div class="collab-entry-body">' +
              '<div class="collab-entry-head">' +
                '<div class="collab-entry-author">' +
                  '<strong>' + escapeHtml(n.author_name) + '</strong>' +
                  '<span class="collab-note-tag">Note</span>' +
                  '<span class="muted mono collab-entry-ts">' + escapeHtml(formatShortTs(n.created_at)) + '</span>' +
                '</div>' +
                '<div class="collab-entry-actions">' +
                  buildCollabActionBtn('Copy', 'copy', 'data-copy-text', n.content) +
                '</div>' +
              '</div>' +
              '<p class="collab-entry-text">' + formatTextWithMentionsHtml(n.content, taskId, allData) + '</p>' +
            '</div>';
          notesList.appendChild(div);
        });
      }
    }

    const commentsList = drawer.querySelector('#detail-comments-list');
    if (commentsList) {
      commentsList.innerHTML = '';
      if (comments.length === 0) {
        commentsList.innerHTML = '<div class="collab-empty">No discussion comments yet.</div>';
      } else {
        comments.forEach((c) => {
          const div = document.createElement('article');
          const isMine = currentUid !== '' && String(c.author_id || '') === currentUid;
          div.className = 'collab-comment-item ' + (isMine ? 'is-mine' : 'is-other');
          const rolePretty = String(c.author_role || 'va').replace('_', ' ');
          div.innerHTML =
            renderSmallAvatarHtml(c.avatar_url, c.author_name) +
            '<div class="collab-entry-body">' +
              '<div class="collab-entry-head">' +
                '<div class="collab-entry-author">' +
                  '<strong>' + escapeHtml(c.author_name) + '</strong>' +
                  '<span class="muted collab-role-sep">·</span>' +
                  '<span class="muted" style="font-size:11.5px;text-transform:capitalize;">' + escapeHtml(rolePretty) + '</span>' +
                  '<span class="muted collab-role-sep">·</span>' +
                  '<span class="muted mono collab-entry-ts">' + escapeHtml(formatTime(c.created_at)) + '</span>' +
                '</div>' +
                '<div class="collab-entry-actions">' +
                  buildCollabActionBtn('Reply', 'reply', 'data-author-name', c.author_name) +
                  buildCollabActionBtn('Copy', 'copy', 'data-copy-text', c.message) +
                '</div>' +
              '</div>' +
              '<p class="collab-entry-text">' + formatTextWithMentionsHtml(c.message, taskId, allData) + '</p>' +
            '</div>';
          commentsList.appendChild(div);
        });
      }
    }

    syncTaskCardIndicators(taskId, allData);
  }

  async function refreshTaskActivityLive(taskId) {
    if (!taskId) return;
    try {
      const res = await fetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/activity', {
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data || !data.success || activeCollabTaskId !== taskId) return;

      const allData = getTaskDataset() || {};
      allData.notes = allData.notes || {};
      allData.comments = allData.comments || {};
      allData.comment_authors = allData.comment_authors || {};
      allData.mention_candidates = allData.mention_candidates || {};
      allData.subtasks = allData.subtasks || {};

      const prevN = (allData.notes[taskId] || []).length;
      const prevC = (allData.comments[taskId] || []).length;
      const prevS = JSON.stringify(allData.subtasks[taskId] || []);
      allData.notes[taskId] = Array.isArray(data.notes) ? data.notes : [];
      allData.comments[taskId] = Array.isArray(data.comments) ? data.comments : [];
      allData.comment_authors[taskId] = Array.isArray(data.comment_authors) ? data.comment_authors : [];
      if (Array.isArray(data.mention_candidates)) {
        allData.mention_candidates[taskId] = data.mention_candidates;
      }
      if (Array.isArray(data.subtasks)) {
        allData.subtasks[taskId] = data.subtasks;
      }

      saveTaskDataset(allData);
      if (allData.notes[taskId].length !== prevN || allData.comments[taskId].length !== prevC) {
        renderTaskCollabLists(taskId, allData);
      }
      if (JSON.stringify(allData.subtasks[taskId]) !== prevS) {
        renderSubtasksForTask(taskId, allData);
        syncTaskCardIndicators(taskId, allData);
      }
    } catch (_err) {
    }
  }

  function appendCollabItemLocal(taskId, kind, item) {
    const allData = getTaskDataset() || {};
    allData.notes = allData.notes || {};
    allData.comments = allData.comments || {};
    allData.comment_authors = allData.comment_authors || {};

    if (kind === 'note') {
      allData.notes[taskId] = allData.notes[taskId] || [];
      allData.notes[taskId].push(item);
    } else {
      allData.comments[taskId] = allData.comments[taskId] || [];
      allData.comments[taskId].push(item);

      allData.comment_authors[taskId] = allData.comment_authors[taskId] || [];
      const aid = String(item.author_id || '');
      if (aid && !allData.comment_authors[taskId].some((a) => String(a.id) === aid)) {
        allData.comment_authors[taskId].push({
          id: aid,
          name: item.author_name || 'Staff',
          avatar_url: item.avatar_url || ''
        });
      }
    }

    saveTaskDataset(allData);
    renderTaskCollabLists(taskId, allData);
  }

  function openTaskCollabDrawer(taskId, initialTab) {
    if (initialTab === 'subtasks') {
      openTaskSubtasksModal(taskId);
      return;
    }
    const allData = getTaskDataset();
    const task = allData && allData.tasks ? allData.tasks[taskId] : null;
    const drawer = document.getElementById('drawer-task-collab');
    if (!task || !drawer) return;

    const tEl = drawer.querySelector('#collab-task-title');
    const mEl = drawer.querySelector('#collab-task-meta');
    if (tEl) tEl.textContent = task.title || 'Deliverable Conversation';
    if (mEl) mEl.textContent = (task.client_name || '') + ' · ' + (task.va_name || '') + ' · Due ' + (task.deadline || '');

    activeCollabTaskId = taskId;
    renderTaskCollabLists(taskId, allData);

    drawer.querySelectorAll('input[name="task_id"]').forEach((inp) => { inp.value = taskId; });
    openDrawer('drawer-task-collab');

    const tabKey = (initialTab === 'comments' || initialTab === 'notes') ? initialTab : 'combined';
    activateTab('task-collab', tabKey);

    refreshTaskActivityLive(taskId);
    if (collabPollTimer) window.clearInterval(collabPollTimer);
    collabPollTimer = window.setInterval(() => {
      if (!activeCollabTaskId || drawer.classList.contains('is-hidden')) {
        stopCollabPolling();
        return;
      }
      refreshTaskActivityLive(activeCollabTaskId);
    }, 4000);
  }

  function openTaskOvertimeModal(taskId) {
    const allData = getTaskDataset();
    const task = allData && allData.tasks ? allData.tasks[taskId] : null;
    const modal = document.getElementById('modal-task-overtime');
    if (!task || !modal) return;

    const tEl = modal.querySelector('#overtime-task-title');
    const mEl = modal.querySelector('#overtime-task-meta');
    if (tEl) tEl.textContent = (task.title || 'Overtime & Extra Hours');
    if (mEl) mEl.textContent = (task.client_name || '') + ' · Est ' + (task.estimated_hours || 0) + 'h';

    const ehList = modal.querySelector('#detail-extrahours-list');
    if (ehList) {
      ehList.innerHTML = '';
      const logs = (allData.extra_hours || {})[taskId] || [];
      if (logs.length === 0) {
        ehList.innerHTML = '<p class="muted">No overtime hours logged on this deliverable yet.</p>';
      } else {
        logs.forEach((eh) => {
          const div = document.createElement('div');
          div.className = 'comment-card';
          div.innerHTML = '<strong>+' + escapeHtml(eh.extra_hours) + 'h</strong> <span class="muted">(' +
            escapeHtml(eh.date) + ')</span><p style="margin-top:4px;">' + escapeHtml(eh.justification) + '</p>';
          ehList.appendChild(div);
        });
      }
    }

    modal.querySelectorAll('input[name="task_id"]').forEach((inp) => { inp.value = taskId; });
    openModal('modal-task-overtime');
  }

  function showContextMenuAt(taskId, clientX, clientY) {
    const cm = document.getElementById('kanban-context-menu');
    if (!cm) return;
    contextTaskId = taskId;
    cm.classList.remove('is-closing', 'is-hidden');

    const menuW = cm.offsetWidth || 205;
    const menuH = cm.offsetHeight || 310;
    const left = Math.max(8, Math.min(clientX, window.innerWidth - menuW - 8));
    const top = Math.max(8, Math.min(clientY, window.innerHeight - menuH - 8));
    cm.style.left = left + 'px';
    cm.style.top = top + 'px';
  }

  function initKanbanBoard() {
    const surfaces = Array.from(document.querySelectorAll('.kanban-surface'));
    if (surfaces.length === 0) return;

    let draggedCard = null;
    let activeSurface = null;
    const placeholder = document.createElement('div');
    placeholder.className = 'drop-placeholder';

    // Collapsible Swimlanes
    document.querySelectorAll('[data-toggle-swimlane]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const lane = btn.closest('.swimlane-section');
        if (!lane) return;
        const collapsed = lane.classList.toggle('is-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      });
    });

    // Quick-add Deliverable directly inside a Client Swimlane
    document.querySelectorAll('[data-create-for-client]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const cid = btn.getAttribute('data-create-for-client') || '';
        const drawer = document.getElementById('drawer-create-task');
        if (drawer && cid) {
          const clSelect = drawer.querySelector('select[name="client_id"]');
          if (clSelect) clSelect.value = cid;
        }
        openDrawer('drawer-create-task');
      });
    });

    // Real-time search & priority filter across Swimlanes and 4-Column Board
    const searchInput = document.getElementById('kanban-search');
    const priorityFilter = document.getElementById('kanban-priority-filter');
    const applyKanbanFilters = () => {
      const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
      const prio = priorityFilter ? priorityFilter.value : 'all';
      surfaces.forEach((surface) => {
        surface.querySelectorAll('.task-card').forEach((card) => {
          const text = (card.getAttribute('data-search') || '').toLowerCase();
          const cardPrio = (card.getAttribute('data-priority') || 'medium').toLowerCase();
          const matchText = term === '' || text.includes(term);
          const matchPrio = prio === 'all' || cardPrio === prio;
          card.style.display = (matchText && matchPrio) ? '' : 'none';
        });
      });
      updateBoardCounters(term || (prio !== 'all' ? prio : ''));
    };
    if (searchInput) searchInput.addEventListener('input', applyKanbanFilters);
    if (priorityFilter) priorityFilter.addEventListener('change', applyKanbanFilters);

    // Drag & drop + right-click menu + split windows click delegation
    surfaces.forEach((surface) => {
      surface.querySelectorAll('.task-card').forEach((card) => {
        card.addEventListener('dragstart', (e) => {
          hideContextMenu();
          draggedCard = card;
          activeSurface = surface;
          surface.classList.add('is-dragging');
          card.classList.add('dragging');
          if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
          }
        });

        card.addEventListener('dragend', () => {
          surface.classList.remove('is-dragging');
          card.classList.remove('dragging');
          if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
          document.querySelectorAll('.kanban-col, .swimlane-col').forEach((c) => c.classList.remove('drag-over'));
          draggedCard = null;
          activeSurface = null;
        });

        card.addEventListener('contextmenu', (e) => {
          e.preventDefault();
          const tid = card.getAttribute('data-task-id');
          if (tid) showContextMenuAt(tid, e.clientX, e.clientY);
        });
      });

      surface.querySelectorAll('.kanban-col, .swimlane-col').forEach((col) => {
        const container = col.querySelector('.kanban-col-cards, .swimlane-col-cards');

        col.addEventListener('dragover', (e) => {
          if (!draggedCard || (activeSurface && !activeSurface.contains(col))) return;
          e.preventDefault();
          col.classList.add('drag-over');
          if (container && !container.contains(placeholder)) {
            container.appendChild(placeholder);
          }
        });

        col.addEventListener('dragleave', (e) => {
          if (!col.contains(e.relatedTarget)) {
            col.classList.remove('drag-over');
            if (container && container.contains(placeholder)) {
              container.removeChild(placeholder);
            }
          }
        });

        col.addEventListener('drop', async (e) => {
          e.preventDefault();
          col.classList.remove('drag-over');
          surface.classList.remove('is-dragging');
          if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
          if (!draggedCard || !container || (activeSurface && !activeSurface.contains(col))) return;

          const dropped = draggedCard;
          const oldCol = dropped.closest('.kanban-col, .swimlane-col');
          const oldStatus = oldCol ? (oldCol.getAttribute('data-status') || '') : '';
          const oldClientId = oldCol ? (oldCol.getAttribute('data-client-id') || '') : (dropped.getAttribute('data-client-id') || '');
          const newStatus = col.getAttribute('data-status') || '';
          const newClientId = col.getAttribute('data-client-id') || '';
          const taskId = dropped.getAttribute('data-task-id') || '';

          const statusChanged = newStatus !== '' && newStatus !== oldStatus;
          const clientChanged = newClientId !== '' && oldClientId !== '' && newClientId !== oldClientId;

          if (!taskId || (!statusChanged && !clientChanged)) return;

          container.appendChild(dropped);
          dropped.classList.add('just-dropped');
          window.setTimeout(() => dropped.classList.remove('just-dropped'), 360);
          if (clientChanged) dropped.setAttribute('data-client-id', newClientId);

          document.querySelectorAll('.task-card[data-task-id="' + taskId + '"]').forEach((twin) => {
            if (twin === dropped) return;
            const twinColParent = twin.closest('.kanban-surface');
            if (!twinColParent) return;
            let destSelector = '[data-status="' + newStatus + '"]';
            if (twinColParent.id === 'workly-kanban-swimlanes') {
              const targetCid = newClientId || twin.getAttribute('data-client-id') || oldClientId;
              destSelector = '.swimlane-col[data-client-id="' + targetCid + '"][data-status="' + newStatus + '"]';
            } else {
              destSelector = '.kanban-col[data-status="' + newStatus + '"]';
            }
            const destCol = twinColParent.querySelector(destSelector);
            const destCards = destCol ? destCol.querySelector('.kanban-col-cards, .swimlane-col-cards') : null;
            if (destCards) destCards.appendChild(twin);
          });

          updateBoardCounters(searchInput ? searchInput.value : '');

          try {
            if (clientChanged) {
              await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId), {
                method: 'PATCH',
                body: JSON.stringify({ status: newStatus, client_id: newClientId })
              });
              showToast('Moved deliverable to new client swimlane & stage', 'success');
              window.setTimeout(() => window.location.reload(), 280);
            } else {
              await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/status', {
                method: 'PATCH',
                body: JSON.stringify({ status: newStatus })
              });
              showToast('Moved deliverable to ' + newStatus.replace('_', ' '), 'success');
            }
          } catch (err) {
            showToast(err.message, 'error');
            window.setTimeout(() => window.location.reload(), 380);
          }
        });
      });

      surface.addEventListener('click', (e) => {
        const menuBtn = e.target.closest('.task-menu-btn');
        if (menuBtn) {
          e.stopPropagation();
          const tid = menuBtn.getAttribute('data-task-id');
          if (tid) showContextMenuAt(tid, e.clientX, e.clientY);
          return;
        }

        const editBtn = e.target.closest('[data-open-task-edit]');
        if (editBtn) {
          openTaskEditModal(editBtn.getAttribute('data-open-task-edit'));
          return;
        }

        const subtasksBtn = e.target.closest('[data-open-task-subtasks]');
        if (subtasksBtn) {
          openTaskSubtasksModal(subtasksBtn.getAttribute('data-open-task-subtasks'));
          return;
        }

        const collabBtn = e.target.closest('[data-open-task-collab]');
        if (collabBtn) {
          openTaskCollabDrawer(
            collabBtn.getAttribute('data-open-task-collab'),
            collabBtn.getAttribute('data-task-tab') || 'notes'
          );
          return;
        }

        const overtimeBtn = e.target.closest('[data-open-task-overtime]');
        if (overtimeBtn) {
          openTaskOvertimeModal(overtimeBtn.getAttribute('data-open-task-overtime'));
        }
      });
    });

    // Context Menu Action Handlers
    const cm = document.getElementById('kanban-context-menu');
    if (cm) {
      cm.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-cm-action]');
        if (!btn || !contextTaskId) return;
        const tid = contextTaskId;
        const action = btn.getAttribute('data-cm-action');
        hideContextMenu();

        if (action === 'open-edit') {
          openTaskEditModal(tid);
        } else if (action === 'open-subtasks') {
          openTaskSubtasksModal(tid);
        } else if (action === 'status') {
          const status = btn.getAttribute('data-status');
          try {
            await apiFetch('/api/v1/tasks/' + encodeURIComponent(tid) + '/status', {
              method: 'PATCH',
              body: JSON.stringify({ status })
            });
            showToast('Stage updated', 'success');
            window.location.reload();
          } catch (err) {
            showToast(err.message, 'error');
          }
        } else if (action === 'priority') {
          const priority = btn.getAttribute('data-priority');
          try {
            await apiFetch('/api/v1/tasks/' + encodeURIComponent(tid), {
              method: 'PATCH',
              body: JSON.stringify({ priority })
            });
            showToast('Priority updated to ' + priority, 'success');
            window.location.reload();
          } catch (err) {
            showToast(err.message, 'error');
          }
        } else if (action === 'delete') {
          try {
            await apiFetch('/api/v1/tasks/' + encodeURIComponent(tid), { method: 'DELETE' });
            showToast('Deliverable deleted', 'success');
            window.location.reload();
          } catch (err) {
            showToast(err.message, 'error');
          }
        }
      });
    }

    document.addEventListener('click', (e) => {
      if (cm && !cm.contains(e.target)) hideContextMenu();
    });

    // Deep link from Notification Bell (e.g. /tasks?open_task=task-1&tab=combined)
    const params = new URLSearchParams(window.location.search);
    const openTaskId = params.get('open_task');
    if (openTaskId) {
      const requestedTab = params.get('tab') || 'combined';
      window.setTimeout(() => {
        openTaskCollabDrawer(openTaskId, requestedTab);
        params.delete('open_task');
        params.delete('tab');
        const nextQs = params.toString();
        const cleanUrl = window.location.pathname + (nextQs ? '?' + nextQs : '');
        window.history.replaceState({}, '', cleanUrl);
      }, 240);
    }

    // Combined Timeline No-Reload Reply & Copy Actions
    const collabDrawer = document.getElementById('drawer-task-collab');
    if (collabDrawer) {
      collabDrawer.addEventListener('click', (e) => {
        const actionBtn = e.target.closest('[data-collab-action]');
        if (!actionBtn) return;
        const act = actionBtn.getAttribute('data-collab-action');
        if (act === 'reply') {
          const author = actionBtn.getAttribute('data-author-name') || '';
          const combinedTa = collabDrawer.querySelector('#form-task-combined textarea[name="body"]');
          const commentsTa = collabDrawer.querySelector('#form-task-comment textarea[name="message"]');
          const activeTa = (combinedTa && combinedTa.offsetParent !== null) ? combinedTa : (commentsTa || combinedTa);
          if (activeTa) {
            const mention = author ? ('@' + author + ' ') : '';
            if (mention && !activeTa.value.startsWith(mention)) {
              activeTa.value = mention + activeTa.value;
            }
            activeTa.focus();
          }
        } else if (act === 'copy') {
          const text = actionBtn.getAttribute('data-copy-text') || '';
          if (navigator.clipboard && text) {
            navigator.clipboard.writeText(text).then(() => {
              const orig = actionBtn.textContent;
              actionBtn.textContent = 'Copied';
              window.setTimeout(() => { actionBtn.textContent = orig; }, 1200);
            }).catch(() => {});
          }
        }
      });
    }

    // @Mention Autocomplete Popover Controller for Task Comments & Notes
    if (collabDrawer) {
      const closeAllMentionMenus = () => {
        collabDrawer.querySelectorAll('.mention-popover').forEach((pop) => {
          pop.classList.add('is-hidden');
          pop.innerHTML = '';
        });
      };

      collabDrawer.querySelectorAll('.mention-field-wrap').forEach((wrap) => {
        const ta = wrap.querySelector('textarea');
        const pop = wrap.querySelector('.mention-popover');
        if (!ta || !pop) return;

        let activeIdx = 0;
        let currentMatch = null; // { start, end, items }

        const insertMention = (candidate) => {
          if (!currentMatch || !candidate) return;
          const val = ta.value;
          const before = val.slice(0, currentMatch.start);
          const after = val.slice(currentMatch.end);
          const tag = '@' + candidate.name + ' ';
          ta.value = before + tag + after;
          const nextPos = before.length + tag.length;
          ta.focus();
          if (typeof ta.setSelectionRange === 'function') {
            ta.setSelectionRange(nextPos, nextPos);
          }
          currentMatch = null;
          pop.classList.add('is-hidden');
          pop.innerHTML = '';
        };

        const renderMenu = () => {
          const pos = ta.selectionStart || 0;
          const beforeCursor = ta.value.slice(0, pos);
          const m = beforeCursor.match(/(?:^|[\s(])@([^\s@\n]{0,24})$/);
          if (!m) {
            currentMatch = null;
            pop.classList.add('is-hidden');
            return;
          }

          const query = m[1].toLowerCase();
          const atStart = beforeCursor.lastIndexOf('@');
          const allData = getTaskDataset() || {};
          const rawList = (allData.mention_candidates && activeCollabTaskId)
            ? (allData.mention_candidates[activeCollabTaskId] || [])
            : [];
          const filtered = rawList.filter((c) => String(c.name || '').toLowerCase().includes(query)).slice(0, 8);

          currentMatch = { start: atStart, end: pos, items: filtered };
          activeIdx = 0;
          pop.innerHTML = '';

          if (filtered.length === 0) {
            pop.innerHTML = '<div class="mention-empty">No eligible client teammates or Team Leader available to mention.</div>';
            pop.classList.remove('is-hidden');
            return;
          }

          filtered.forEach((cand, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mention-item' + (idx === 0 ? ' is-active' : '');
            btn.setAttribute('role', 'option');
            const roleText = cand.role === 'team_leader' ? 'Team Leader' : (cand.role === 'admin' ? 'Admin' : 'VA');
            btn.innerHTML =
              '<span class="mention-item-meta">' +
                renderSmallAvatarHtml(cand.avatar_url, cand.name) +
                '<strong>' + escapeHtml(cand.name) + '</strong>' +
              '</span>' +
              '<span class="mention-role">' + escapeHtml(roleText) + '</span>';
            btn.addEventListener('mousedown', (e) => {
              e.preventDefault();
              insertMention(cand);
            });
            pop.appendChild(btn);
          });
          pop.classList.remove('is-hidden');
        };

        ta.addEventListener('input', renderMenu);
        ta.addEventListener('click', renderMenu);
        ta.addEventListener('blur', () => {
          window.setTimeout(() => {
            currentMatch = null;
            pop.classList.add('is-hidden');
          }, 140);
        });

        ta.addEventListener('keydown', (e) => {
          const isOpen = !pop.classList.contains('is-hidden') && currentMatch && currentMatch.items.length > 0;
          if (isOpen) {
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              activeIdx = (activeIdx + 1) % currentMatch.items.length;
              pop.querySelectorAll('.mention-item').forEach((el, idx) => el.classList.toggle('is-active', idx === activeIdx));
              return;
            }
            if (e.key === 'ArrowUp') {
              e.preventDefault();
              activeIdx = (activeIdx - 1 + currentMatch.items.length) % currentMatch.items.length;
              pop.querySelectorAll('.mention-item').forEach((el, idx) => el.classList.toggle('is-active', idx === activeIdx));
              return;
            }
            if ((e.key === 'Enter' && !e.ctrlKey && !e.metaKey) || e.key === 'Tab') {
              e.preventDefault();
              insertMention(currentMatch.items[activeIdx]);
              return;
            }
            if (e.key === 'Escape') {
              e.preventDefault();
              e.stopPropagation();
              closeAllMentionMenus();
              currentMatch = null;
              return;
            }
          }

          if (ta.closest('#form-task-combined') && (e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            ta.closest('#form-task-combined').requestSubmit();
          }
        });
      });
    }

    const combinedForm = document.getElementById('form-task-combined');
    if (combinedForm) {
      const modeOpts = combinedForm.querySelectorAll('.collab-mode-opt');
      const submitBtn = combinedForm.querySelector('#btn-collab-submit');
      const bodyTa = combinedForm.querySelector('textarea[name="body"]');

      const syncModeUi = () => {
        const checked = combinedForm.querySelector('input[name="entry_kind"]:checked');
        const mode = checked ? checked.value : 'comment';
        combinedForm.setAttribute('data-mode', mode);
        modeOpts.forEach((opt) => {
          const r = opt.querySelector('input[type="radio"]');
          opt.classList.toggle('is-active', Boolean(r && r.checked));
        });
        if (submitBtn) {
          submitBtn.textContent = mode === 'note' ? 'Pin Note' : 'Post Comment';
        }
        if (bodyTa) {
          bodyTa.placeholder = mode === 'note'
            ? 'Highlight key execution instruction or @mention teammate...'
            : 'Write a comment or @mention a teammate...';
        }
      };

      combinedForm.querySelectorAll('input[name="entry_kind"]').forEach((radio) => {
        radio.addEventListener('change', syncModeUi);
      });
      syncModeUi();

      combinedForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const tid = (combinedForm.querySelector('input[name="task_id"]') || {}).value || '';
        const kindInp = combinedForm.querySelector('input[name="entry_kind"]:checked');
        const kind = kindInp ? kindInp.value : 'comment';
        const text = bodyTa ? bodyTa.value.trim() : '';
        if (!tid || !text) return;

        if (submitBtn) submitBtn.disabled = true;

        try {
          if (kind === 'note') {
            const res = await apiFetch('/api/v1/tasks/' + encodeURIComponent(tid) + '/notes', {
              method: 'POST',
              body: JSON.stringify({ content: text })
            });
            if (res && res.item) appendCollabItemLocal(tid, 'note', res.item);
            showToast('Execution note pinned', 'success');
          } else {
            const res = await apiFetch('/api/v1/tasks/' + encodeURIComponent(tid) + '/comments', {
              method: 'POST',
              body: JSON.stringify({ message: text })
            });
            if (res && res.item) appendCollabItemLocal(tid, 'comment', res.item);
            showToast('Comment posted', 'success');
          }
          if (bodyTa) bodyTa.value = '';
          refreshTaskActivityLive(tid);
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }

    // Standalone Sub-Tasks Modal CRUD + 1-Click VA Specialty Suggestions
    const addSubtaskTitleToTask = async (taskId, titleText, triggerBtn, inputToClear) => {
      const title = String(titleText || '').trim();
      if (!taskId || !title) {
        if (inputToClear) inputToClear.focus();
        return;
      }
      if (triggerBtn) triggerBtn.disabled = true;
      try {
        const res = await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/subtasks', {
          method: 'POST',
          body: JSON.stringify({ title })
        });
        if (res && Array.isArray(res.subtasks)) {
          const allData = getTaskDataset() || {};
          allData.subtasks = allData.subtasks || {};
          allData.subtasks[taskId] = res.subtasks;
          saveTaskDataset(allData);
          renderSubtasksForTask(taskId, allData);
          syncTaskCardIndicators(taskId, allData);
        }
        if (inputToClear) {
          inputToClear.value = '';
          inputToClear.focus();
        }
        showToast('Sub-task added', 'success');
      } catch (err) {
        showToast(err.message, 'error');
        if (triggerBtn) triggerBtn.disabled = false;
      } finally {
        if (inputToClear && triggerBtn) triggerBtn.disabled = false;
      }
    };

    const subModalInput = document.getElementById('subtasks-modal-input');
    const subModalBtn = document.getElementById('subtasks-modal-add-btn');
    if (subModalBtn && subModalInput) {
      subModalBtn.addEventListener('click', () => addSubtaskTitleToTask(activeSubtasksTaskId, subModalInput.value, subModalBtn, subModalInput));
      subModalInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addSubtaskTitleToTask(activeSubtasksTaskId, subModalInput.value, subModalBtn, subModalInput);
        }
      });
    }

    const modalSuggestTags = document.getElementById('modal-subtask-suggestions-tags');
    if (modalSuggestTags) {
      modalSuggestTags.addEventListener('click', (e) => {
        const tagBtn = e.target.closest('[data-modal-suggest-step]');
        if (!tagBtn || tagBtn.disabled || !activeSubtasksTaskId) return;
        const stepTitle = tagBtn.getAttribute('data-modal-suggest-step') || '';
        addSubtaskTitleToTask(activeSubtasksTaskId, stepTitle, tagBtn, null);
      });
    }

    document.addEventListener('change', async (e) => {
      const cb = e.target.closest('.subtask-checkbox[data-subtask-toggle]');
      if (!cb) return;
      const subId = cb.getAttribute('data-subtask-toggle');
      const taskId = cb.getAttribute('data-subtask-task-id');
      if (!subId || !taskId) return;

      const nextChecked = cb.checked;
      const allData = getTaskDataset() || {};
      allData.subtasks = allData.subtasks || {};
      const list = allData.subtasks[taskId] || [];
      const idx = list.findIndex((s) => String(s.id) === String(subId));
      if (idx !== -1) {
        list[idx].is_completed = nextChecked;
        saveTaskDataset(allData);
        renderSubtasksForTask(taskId, allData);
        syncTaskCardIndicators(taskId, allData);
      }

      try {
        const res = await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/subtasks/' + encodeURIComponent(subId), {
          method: 'PATCH',
          body: JSON.stringify({ is_completed: nextChecked })
        });
        if (res && Array.isArray(res.subtasks)) {
          allData.subtasks[taskId] = res.subtasks;
          saveTaskDataset(allData);
          renderSubtasksForTask(taskId, allData);
          syncTaskCardIndicators(taskId, allData);
        }
      } catch (err) {
        if (idx !== -1) {
          list[idx].is_completed = !nextChecked;
          saveTaskDataset(allData);
          renderSubtasksForTask(taskId, allData);
          syncTaskCardIndicators(taskId, allData);
        }
        showToast(err.message, 'error');
      }
    });

    document.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('[data-subtask-delete]');
      if (!delBtn) return;
      const subId = delBtn.getAttribute('data-subtask-delete');
      const taskId = delBtn.getAttribute('data-subtask-task-id');
      if (!subId || !taskId) return;
      delBtn.disabled = true;

      try {
        const res = await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/subtasks/' + encodeURIComponent(subId), {
          method: 'DELETE'
        });
        const allData = getTaskDataset() || {};
        allData.subtasks = allData.subtasks || {};
        if (res && Array.isArray(res.subtasks)) {
          allData.subtasks[taskId] = res.subtasks;
        } else {
          allData.subtasks[taskId] = (allData.subtasks[taskId] || []).filter((s) => String(s.id) !== String(subId));
        }
        saveTaskDataset(allData);
        renderSubtasksForTask(taskId, allData);
        syncTaskCardIndicators(taskId, allData);
        showToast('Sub-task removed', 'success');
      } catch (err) {
        delBtn.disabled = false;
        showToast(err.message, 'error');
      }
    });

    // Sub-Tasks Draft Builder + Optional Backend Suggestions inside Create Deliverable Drawer
    const createDrawer = document.getElementById('drawer-create-task');
    const createSubInput = document.getElementById('create-subtask-draft-input');
    const createSubBtn = document.getElementById('btn-add-create-subtask');
    const createSubList = document.getElementById('create-subtasks-list');
    const createSuggestBox = document.getElementById('create-subtask-suggestions');
    const createSuggestTags = document.getElementById('create-subtask-suggestions-tags');
    const toggleCreateIdeasBtn = document.getElementById('btn-toggle-create-suggestions');
    const createIdeasPanel = document.getElementById('create-optional-tasks-panel');
    const createIdeasChips = document.getElementById('create-suggested-tasks-chips');

    if (createSubInput && createSubBtn && createSubList) {
      const appendDraftStep = (rawTitle, refocusInput) => {
        const val = String(rawTitle || '').trim();
        if (!val) {
          if (refocusInput) createSubInput.focus();
          return;
        }
        const exists = Array.from(createSubList.querySelectorAll('input[name="subtasks[]"]'))
          .some((inp) => String(inp.value || '').trim().toLowerCase() === val.toLowerCase());
        if (exists) {
          if (refocusInput) {
            createSubInput.value = '';
            createSubInput.focus();
          }
          return;
        }
        const chip = document.createElement('div');
        chip.className = 'subtask-draft-chip';
        const label = document.createElement('span');
        label.textContent = val;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'subtasks[]';
        hidden.value = val;
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'subtask-del-btn';
        rm.setAttribute('aria-label', 'Remove draft step');
        rm.textContent = '×';
        rm.addEventListener('click', () => {
          chip.remove();
          refreshCreateSuggestions();
        });
        chip.appendChild(label);
        chip.appendChild(hidden);
        chip.appendChild(rm);
        createSubList.appendChild(chip);
        if (refocusInput) {
          createSubInput.value = '';
          createSubInput.focus();
        }
        refreshCreateSuggestions();
      };

      const applyStarterIdea = (idea) => {
        if (!idea || !createDrawer) return;
        const titleInp = createDrawer.querySelector('input[name="title"]');
        const descInp = createDrawer.querySelector('textarea[name="description"]');
        const prioSel = createDrawer.querySelector('select[name="priority"]');
        const hrsInp = createDrawer.querySelector('input[name="estimated_hours"]');

        if (titleInp && idea.title) titleInp.value = idea.title;
        if (descInp && idea.description) descInp.value = idea.description;
        if (prioSel && idea.priority) prioSel.value = idea.priority;
        if (hrsInp && idea.estimated_hours) hrsInp.value = String(idea.estimated_hours);

        const steps = Array.isArray(idea.steps) ? idea.steps : [];
        steps.forEach((step) => appendDraftStep(step, false));

        if (createIdeasPanel && toggleCreateIdeasBtn) {
          createIdeasPanel.classList.add('is-hidden');
          toggleCreateIdeasBtn.setAttribute('aria-expanded', 'false');
        }
        showToast('Applied suggested deliverable & checklist', 'success');
      };

      function refreshCreateSuggestions() {
        const allData = getTaskDataset() || {};
        const vaField = createDrawer ? createDrawer.querySelector('[name="assigned_va_id"]') : null;
        const vaId = vaField ? String(vaField.value || '') : String(allData.current_user_id || '');
        const resolved = getSuggestionsForVa(vaId, allData);

        if (createSuggestTags) {
          if (createSuggestBox) {
            createSuggestBox.classList.toggle('is-hidden', resolved.steps.length === 0);
          }
          const existingDrafts = new Set(
            Array.from(createSubList.querySelectorAll('input[name="subtasks[]"]'))
              .map((inp) => String(inp.value || '').trim().toLowerCase())
          );

          createSuggestTags.innerHTML = '';
          resolved.steps.forEach((stepTitle) => {
            const cleanTitle = String(stepTitle || '').trim();
            if (!cleanTitle) return;
            const isAdded = existingDrafts.has(cleanTitle.toLowerCase());
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'subtask-suggest-tag' + (isAdded ? ' is-added' : '');
            btn.setAttribute('data-create-suggest-step', cleanTitle);
            btn.setAttribute('title', isAdded ? 'Already added' : 'Click to add step');
            if (isAdded) btn.disabled = true;

            const iconSpan = document.createElement('span');
            iconSpan.className = 'subtask-suggest-plus';
            iconSpan.textContent = isAdded ? '✓' : '+';
            const txtSpan = document.createElement('span');
            txtSpan.textContent = cleanTitle;

            btn.appendChild(iconSpan);
            btn.appendChild(txtSpan);
            createSuggestTags.appendChild(btn);
          });
        }

        if (createIdeasChips && toggleCreateIdeasBtn) {
          const rowWrap = toggleCreateIdeasBtn.closest('.create-optional-suggest-row');
          if (rowWrap) {
            rowWrap.classList.toggle('is-hidden', resolved.tasks.length === 0);
          }
          createIdeasChips.innerHTML = '';
          resolved.tasks.forEach((idea) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'create-suggest-task-chip';
            btn.textContent = idea.title || 'Suggested task';
            btn.addEventListener('click', () => applyStarterIdea(idea));
            createIdeasChips.appendChild(btn);
          });
        }
      }

      if (toggleCreateIdeasBtn && createIdeasPanel) {
        toggleCreateIdeasBtn.addEventListener('click', () => {
          const willOpen = createIdeasPanel.classList.contains('is-hidden');
          createIdeasPanel.classList.toggle('is-hidden', !willOpen);
          toggleCreateIdeasBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
      }

      createSubBtn.addEventListener('click', () => appendDraftStep(createSubInput.value, true));
      createSubInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          appendDraftStep(createSubInput.value, true);
        }
      });

      if (createSuggestTags) {
        createSuggestTags.addEventListener('click', (e) => {
          const btn = e.target.closest('[data-create-suggest-step]');
          if (!btn || btn.disabled) return;
          appendDraftStep(btn.getAttribute('data-create-suggest-step') || '', false);
        });
      }

      const vaSelect = createDrawer ? createDrawer.querySelector('select[name="assigned_va_id"]') : null;
      if (vaSelect) {
        vaSelect.addEventListener('change', refreshCreateSuggestions);
      }

      // Auto-flush any unadded draft step when submitting New Deliverable
      const createForm = createDrawer ? createDrawer.querySelector('form[data-api-form="/api/v1/tasks"]') : null;
      if (createForm) {
        createForm.addEventListener('submit', () => {
          if (createSubInput.value.trim() !== '') {
            appendDraftStep(createSubInput.value, false);
            createSubInput.value = '';
          }
        }, true);
      }

      refreshCreateSuggestions();
    }
  }

  // ------------------------------------------------------------------------
  // Unified Calendar Scheduler & Client-Synced Open Slot Scanner
  // ------------------------------------------------------------------------
  function initCalendarScheduler() {
    const grid = document.querySelector('.calendar-grid');
    if (!grid) return;

    let dragEventId = null;

    grid.querySelectorAll('.cal-event-chip[draggable="true"][data-meeting-id]').forEach((chip) => {
      chip.addEventListener('dragstart', (e) => {
        e.stopPropagation();
        dragEventId = chip.getAttribute('data-meeting-id') || '';
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', dragEventId);
        }
      });

      chip.addEventListener('dragend', () => {
        dragEventId = null;
        grid.querySelectorAll('.cal-cell').forEach((c) => c.classList.remove('cal-drag-over'));
      });
    });

    grid.querySelectorAll('.cal-cell[data-cal-date]').forEach((cell) => {
      const targetDate = cell.getAttribute('data-cal-date');

      cell.addEventListener('dragover', (e) => {
        if (!dragEventId) return;
        e.preventDefault();
        cell.classList.add('cal-drag-over');
      });

      cell.addEventListener('dragleave', (e) => {
        if (!cell.contains(e.relatedTarget)) {
          cell.classList.remove('cal-drag-over');
        }
      });

      cell.addEventListener('drop', async (e) => {
        e.preventDefault();
        cell.classList.remove('cal-drag-over');
        if (!dragEventId || !targetDate) return;
        const movingId = dragEventId;
        dragEventId = null;

        try {
          const res = await apiFetch('/api/v1/meetings/' + encodeURIComponent(movingId) + '/reschedule', {
            method: 'PATCH',
            body: JSON.stringify({ target_date: targetDate })
          });
          showToast('Rescheduled to ' + (res.start_time || targetDate), 'success');
          window.setTimeout(() => window.location.reload(), 320);
        } catch (err) {
          showToast(err.message, 'error');
        }
      });

      cell.addEventListener('click', (e) => {
        if (e.target.closest('.cal-event-chip') || e.target.closest('.cal-chip-del')) return;
        const schedDrawer = document.getElementById('drawer-schedule-meeting');
        if (!schedDrawer || !targetDate) return;
        const sInp = document.getElementById('sched-start-time');
        const eInp = document.getElementById('sched-end-time');
        if (sInp) sInp.value = targetDate + 'T09:00';
        if (eInp) eInp.value = targetDate + 'T10:00';
        openDrawer('drawer-schedule-meeting');
        loadOpenSlots(targetDate);
      });
    });

    grid.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('.cal-chip-del[data-delete-meeting]');
      if (!delBtn) return;
      e.stopPropagation();
      const mid = delBtn.getAttribute('data-delete-meeting');
      if (!mid) return;
      try {
        delBtn.disabled = true;
        await apiFetch('/api/v1/meetings/' + encodeURIComponent(mid), { method: 'DELETE' });
        showToast('Removed scheduled block', 'success');
        window.setTimeout(() => window.location.reload(), 260);
      } catch (err) {
        showToast(err.message, 'error');
        delBtn.disabled = false;
      }
    });

    const checkBtn = document.getElementById('btn-check-slots');
    const clientSel = document.getElementById('sched-client-id');
    const vaSel = document.getElementById('sched-va-id');

    const triggerScan = () => {
      const sInp = document.getElementById('sched-start-time');
      const dStr = sInp && sInp.value ? sInp.value.slice(0, 10) : new Date().toISOString().slice(0, 10);
      loadOpenSlots(dStr);
    };

    if (checkBtn) checkBtn.addEventListener('click', triggerScan);
    if (clientSel) clientSel.addEventListener('change', triggerScan);
    if (vaSel) vaSel.addEventListener('change', triggerScan);

    async function loadOpenSlots(dateStr) {
      const box = document.getElementById('focus-slots-preview');
      if (!box) return;
      const vaInput = document.getElementById('sched-va-id');
      const clInput = document.getElementById('sched-client-id');
      const params = new URLSearchParams({ date: dateStr });
      if (vaInput && vaInput.value) params.set('va_id', vaInput.value);
      if (clInput && clInput.value) params.set('client_id', clInput.value);

      box.textContent = 'Scanning live schedule & client hours for ' + dateStr + '...';

      try {
        const res = await apiFetch('/api/v1/meetings/availability?' + params.toString(), { method: 'GET' });
        box.innerHTML = '';

        if (res.on_pto) {
          const warn = document.createElement('span');
          warn.className = 'badge badge-danger';
          warn.textContent = 'Approved PTO on ' + dateStr + ' (Scheduling blocked)';
          box.appendChild(warn);
          return;
        }

        const slots = Array.isArray(res.open_slots) ? res.open_slots : [];
        if (slots.length === 0) {
          box.textContent = res.client_filtered
            ? 'No open client-available windows remain on ' + dateStr + '. Edit client availability slots in Clients CRM.'
            : 'No open 1-hour windows remain on ' + dateStr + '.';
          return;
        }

        if (res.client_filtered) {
          const hint = document.createElement('div');
          hint.className = 'muted';
          hint.style.fontSize = '11px';
          hint.style.marginBottom = '6px';
          hint.textContent = 'Filtered by selected CRM client available schedule (' + (res.day_of_week || '') + '):';
          box.appendChild(hint);
        }

        const wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.flexWrap = 'wrap';
        wrap.style.gap = '6px';

        slots.forEach((slotLabel) => {
          const parts = slotLabel.split(' - ');
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-secondary btn-sm';
          btn.textContent = slotLabel;
          btn.addEventListener('click', () => {
            const sInp = document.getElementById('sched-start-time');
            const eInp = document.getElementById('sched-end-time');
            if (sInp && parts[0]) sInp.value = dateStr + 'T' + parts[0];
            if (eInp && parts[1]) eInp.value = dateStr + 'T' + parts[1];
            showToast('Applied open slot ' + slotLabel, 'success');
          });
          wrap.appendChild(btn);
        });
        box.appendChild(wrap);
      } catch (err) {
        box.textContent = 'Could not scan slots: ' + err.message;
      }
    }
  }

  // ------------------------------------------------------------------------
  // Client CRM Right Dossier (2+ Char Live Search, Secondary Contact, Color
  // Status Pills, Availability Slots Builder, Top Photo Preview/Delete)
  // ------------------------------------------------------------------------
  function renderSlotRow(day, start, end) {
    const row = document.createElement('div');
    row.className = 'schedule-slot-row';
    row.setAttribute('data-slot-row', '1');

    const sel = document.createElement('select');
    sel.className = 'select';
    sel.setAttribute('data-slot-day', '1');
    ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach((d) => {
      const opt = document.createElement('option');
      opt.value = d;
      opt.textContent = d;
      if (d === (day || 'Mon')) opt.selected = true;
      sel.appendChild(opt);
    });

    const startInp = document.createElement('input');
    startInp.className = 'input';
    startInp.type = 'time';
    startInp.setAttribute('data-slot-start', '1');
    startInp.value = start || '09:00';

    const endInp = document.createElement('input');
    endInp.className = 'input';
    endInp.type = 'time';
    endInp.setAttribute('data-slot-end', '1');
    endInp.value = end || '17:00';

    const remBtn = document.createElement('button');
    remBtn.type = 'button';
    remBtn.className = 'btn btn-danger btn-sm';
    remBtn.setAttribute('data-remove-slot', '1');
    remBtn.innerHTML = '&times;';

    row.appendChild(sel);
    row.appendChild(startInp);
    row.appendChild(endInp);
    row.appendChild(remBtn);
    return row;
  }

  function syncStatusRadioGrid(gridEl) {
    if (!gridEl) return;
    gridEl.querySelectorAll('.status-pill').forEach((pill) => {
      const radio = pill.querySelector('input[type="radio"]');
      pill.classList.toggle('is-selected', Boolean(radio && radio.checked));
    });
  }

  function initClientDossiers() {
    const store = document.getElementById('clients-dossier-json');
    let dossierData = { clients: {}, activities: {}, emails: {}, tasks: {}, vas: {} };
    if (store) {
      try {
        dossierData = JSON.parse(store.textContent || '{}') || dossierData;
      } catch (_err) {
      }
    }
    const userRole = store ? (store.getAttribute('data-user-role') || 'va') : 'va';
    const userId = store ? (store.getAttribute('data-user-id') || '') : '';
    const canEdit = store ? (store.getAttribute('data-can-edit') === '1') : false;

    const searchInput = document.getElementById('crm-contact-search');
    const vaFilter = document.getElementById('crm-va-filter');
    const sortSelect = document.getElementById('crm-sort-select');
    const visibleCountEl = document.getElementById('crm-visible-count');
    const resetFiltersBtn = document.getElementById('crm-reset-filters');
    const emptyClearBtn = document.getElementById('crm-empty-clear-btn');
    const filterEmptyEl = document.getElementById('crm-filter-empty');
    const contactsTable = document.getElementById('crm-contacts-table');
    const contactsTbody = document.getElementById('crm-contacts-tbody');
    const statusFilterBtns = Array.from(document.querySelectorAll('[data-crm-status-filter]'));

    let activeStatusFilter = 'all';

    function formatShortDate(raw) {
      if (!raw) return '';
      return String(raw).slice(0, 10);
    }

    function smallCrmAvatarHtml(url, name) {
      if (url) {
        return '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name || 'VA') + '" class="avatar-xs">';
      }
      const init = String(name || 'UA').trim().slice(0, 2).toUpperCase() || 'UA';
      return '<span class="avatar-xs">' + escapeHtml(init) + '</span>';
    }

    function applyCrmFiltersAndSort() {
      if (!contactsTbody) return;
      const rows = Array.from(contactsTbody.querySelectorAll('.crm-contact-row'));
      const q = searchInput ? searchInput.value.trim().toLowerCase() : '';
      const vaVal = vaFilter ? vaFilter.value : 'all';
      const sortVal = sortSelect ? sortSelect.value : 'name_asc';

      rows.sort((a, b) => {
        if (sortVal === 'util_desc') {
          return (parseFloat(b.getAttribute('data-util') || '0') || 0) - (parseFloat(a.getAttribute('data-util') || '0') || 0);
        }
        if (sortVal === 'hours_desc') {
          return (parseFloat(b.getAttribute('data-hours') || '0') || 0) - (parseFloat(a.getAttribute('data-hours') || '0') || 0);
        }
        if (sortVal === 'activity_desc') {
          const aa = a.getAttribute('data-activity') || '';
          const ba = b.getAttribute('data-activity') || '';
          if (aa !== ba) return ba.localeCompare(aa);
        }
        return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
      });

      let visibleCount = 0;
      rows.forEach((row) => {
        contactsTbody.appendChild(row);
        const hay = (row.getAttribute('data-search') || '').toLowerCase();
        const st = row.getAttribute('data-status') || 'active';
        const rVa = row.getAttribute('data-va-id') || 'unassigned';

        const matchQ = q === '' || hay.includes(q);
        const matchSt = activeStatusFilter === 'all' || st === activeStatusFilter;
        const matchVa = vaVal === 'all' || rVa === vaVal;

        const show = matchQ && matchSt && matchVa;
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
      });

      if (visibleCountEl) {
        visibleCountEl.textContent = visibleCount + ' of ' + rows.length;
      }
      const hasFilters = q !== '' || activeStatusFilter !== 'all' || vaVal !== 'all';
      if (resetFiltersBtn) resetFiltersBtn.classList.toggle('is-hidden', !hasFilters);
      if (filterEmptyEl) filterEmptyEl.classList.toggle('is-hidden', visibleCount > 0 || rows.length === 0);
      if (contactsTable) contactsTable.classList.toggle('is-hidden', visibleCount === 0 && rows.length > 0);
    }

    function clearCrmFilters() {
      if (searchInput) searchInput.value = '';
      if (vaFilter) vaFilter.value = 'all';
      activeStatusFilter = 'all';
      statusFilterBtns.forEach((b) => b.classList.toggle('is-active', b.getAttribute('data-crm-status-filter') === 'all'));
      applyCrmFiltersAndSort();
    }

    if (searchInput) searchInput.addEventListener('input', applyCrmFiltersAndSort);
    if (vaFilter) vaFilter.addEventListener('change', applyCrmFiltersAndSort);
    if (sortSelect) sortSelect.addEventListener('change', applyCrmFiltersAndSort);
    statusFilterBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        activeStatusFilter = btn.getAttribute('data-crm-status-filter') || 'all';
        statusFilterBtns.forEach((b) => b.classList.toggle('is-active', b === btn));
        applyCrmFiltersAndSort();
      });
    });
    if (resetFiltersBtn) resetFiltersBtn.addEventListener('click', clearCrmFilters);
    if (emptyClearBtn) emptyClearBtn.addEventListener('click', clearCrmFilters);
    if (contactsTbody) applyCrmFiltersAndSort();

    function updateRowLastActivity(cid, activityItem) {
      if (!contactsTbody || !activityItem) return;
      const row = contactsTbody.querySelector('.crm-contact-row[data-client-row="' + CSS.escape(cid) + '"]');
      if (!row) return;
      const ts = String(activityItem.created_at || new Date().toISOString().slice(0, 19).replace('T', ' '));
      row.setAttribute('data-activity', ts);
      const cell = row.cells[5];
      if (cell) {
        cell.innerHTML =
          '<div class="crm-cell-stack">' +
            '<span class="mono tabular-nums" style="font-size:11.5px;font-weight:600;color:var(--ink);">' + escapeHtml(ts.slice(0, 10)) + '</span>' +
            '<div class="muted crm-subline" style="max-width:200px;">' + escapeHtml(activityItem.description || activityItem.summary || 'Logged activity') + '</div>' +
          '</div>';
      }
    }

    // Direct Inline VA Reassignment inside Contact List table
    document.querySelectorAll('.crm-inline-va-select[data-inline-va-client]').forEach((sel) => {
      sel.addEventListener('change', async () => {
        const cid = sel.getAttribute('data-inline-va-client') || '';
        const prevVa = sel.getAttribute('data-prev-va') || '';
        const nextVa = sel.value || '';
        if (!cid) return;

        sel.disabled = true;
        try {
          const res = await apiFetch('/api/v1/clients/' + encodeURIComponent(cid), {
            method: 'PUT',
            body: JSON.stringify({ focus_va_id: nextVa })
          });

          const vaName = (res && res.focus_va_name) ? res.focus_va_name : '';
          const vaAvatar = (res && res.focus_va_avatar) ? res.focus_va_avatar : '';
          const vaEmail = (res && res.focus_va_email) ? res.focus_va_email : '';
          sel.setAttribute('data-prev-va', nextVa);

          const avatarWrap = document.querySelector('[data-row-va-avatar="' + CSS.escape(cid) + '"]');
          if (avatarWrap) {
            avatarWrap.innerHTML = smallCrmAvatarHtml(vaAvatar, vaName || 'Unassigned');
          }

          const row = contactsTbody ? contactsTbody.querySelector('.crm-contact-row[data-client-row="' + CSS.escape(cid) + '"]') : null;
          if (row) {
            row.setAttribute('data-va-id', nextVa !== '' ? nextVa : 'unassigned');
            const cl = (dossierData.clients || {})[cid] || {};
            const hayParts = [
              cl.agency_name || '',
              cl.name || '',
              cl.email || '',
              cl.additional_email || '',
              cl.phone || '',
              vaName,
              cl.tl_name || '',
              cl.state || '',
              cl.country || ''
            ];
            row.setAttribute('data-search', hayParts.join(' ').toLowerCase());
          }

          if (dossierData.clients && dossierData.clients[cid]) {
            dossierData.clients[cid].focus_va_id = nextVa;
            dossierData.clients[cid].focus_va_name = vaName;
            dossierData.clients[cid].focus_va_email = vaEmail;
            dossierData.clients[cid].focus_va_avatar = vaAvatar;
          }

          if (res && res.activity) {
            if (!dossierData.activities) dossierData.activities = {};
            if (!Array.isArray(dossierData.activities[cid])) dossierData.activities[cid] = [];
            dossierData.activities[cid].unshift(res.activity);
            updateRowLastActivity(cid, res.activity);
          }

          applyCrmFiltersAndSort();
          showToast(vaName ? ('Assigned to ' + vaName) : 'Client marked unassigned', 'success');
        } catch (err) {
          sel.value = prevVa;
          showToast(err.message || 'Unable to update VA assignment', 'error');
        } finally {
          sel.disabled = false;
        }
      });
    });

    // "+ Secondary Email / Phone" reveal toggles
    document.querySelectorAll('[data-toggle-extra]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetPrefix = btn.getAttribute('data-toggle-extra');
        const wrap = targetPrefix ? document.getElementById(targetPrefix + '-wrap') : null;
        if (!wrap) return;
        wrap.classList.toggle('is-hidden');
        if (!wrap.classList.contains('is-hidden')) {
          const inp = wrap.querySelector('input');
          if (inp) inp.focus();
        }
      });
    });

    // Color-coded Contract Status Radio Pills
    document.querySelectorAll('.status-radio-grid').forEach((grid) => {
      syncStatusRadioGrid(grid);
      grid.addEventListener('change', () => syncStatusRadioGrid(grid));
    });

    // Weekly Availability Slot Builder buttons
    document.querySelectorAll('[data-add-slot-btn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const containerId = btn.getAttribute('data-add-slot-btn');
        const container = containerId ? document.getElementById(containerId) : null;
        if (container) container.appendChild(renderSlotRow('Mon', '09:00', '17:00'));
      });
    });

    document.addEventListener('click', (e) => {
      const rem = e.target.closest('[data-remove-slot]');
      if (rem) {
        const row = rem.closest('[data-slot-row]');
        if (row) row.remove();
      }
    });

    // Delete Client Avatar Button
    const delClientAvatarBtn = document.getElementById('btn-delete-client-avatar');
    if (delClientAvatarBtn) {
      delClientAvatarBtn.addEventListener('click', async () => {
        const cid = delClientAvatarBtn.getAttribute('data-client-id') || '';
        if (!cid) return;
        try {
          delClientAvatarBtn.disabled = true;
          await apiFetch('/api/v1/uploads/client-avatar', {
            method: 'DELETE',
            body: JSON.stringify({ client_id: cid })
          });
          showToast('Client photo removed', 'success');
          window.setTimeout(() => window.location.reload(), 300);
        } catch (err) {
          showToast(err.message, 'error');
          delClientAvatarBtn.disabled = false;
        }
      });
    }

    function renderClientTimeline(cid) {
      const actList = document.getElementById('dossier-activities');
      const commsBadge = document.getElementById('dossier-comms-badge');
      const acts = ((dossierData.activities || {})[cid] || []).map((a) => ({
        kind: 'activity',
        badge: String(a.type || 'note'),
        title: String(a.author_name || 'Staff'),
        body: String(a.description || ''),
        created_at: String(a.created_at || '')
      }));
      const emails = ((dossierData.emails || {})[cid] || []).map((em) => ({
        kind: 'email',
        badge: 'email',
        title: String(em.subject || 'Email Message'),
        subtitle: String(em.actor || ''),
        body: String(em.summary || ''),
        created_at: String(em.created_at || '')
      }));

      const combined = acts.concat(emails).sort((a, b) => b.created_at.localeCompare(a.created_at));
      if (commsBadge) commsBadge.textContent = String(combined.length);
      if (!actList) return;

      if (combined.length === 0) {
        actList.innerHTML = '<div class="crm-empty-state" style="padding:20px 14px;"><strong>No timeline activity yet</strong><p class="muted">Log a call, meeting, or account note above to start tracking this client\'s history.</p></div>';
        return;
      }

      actList.innerHTML = combined.map((item) => {
        const badgeCls = item.kind === 'email'
          ? 'badge-info'
          : (item.badge === 'meeting' ? 'badge-brand' : (item.badge === 'contract' ? 'badge-success' : 'badge-neutral'));
        const badgeLabel = item.kind === 'email' ? 'Email' : item.badge;
        const subLine = item.subtitle ? '<div class="muted" style="font-size:11.5px;margin-top:2px;">' + escapeHtml(item.subtitle) + '</div>' : '';
        return '<div class="crm-timeline-item">' +
          '<div class="crm-timeline-head">' +
            '<div><strong>' + escapeHtml(item.title) + '</strong>' + subLine + '</div>' +
            '<div style="display:flex;align-items:center;gap:8px;">' +
              '<span class="badge ' + badgeCls + '">' + escapeHtml(badgeLabel) + '</span>' +
              '<span class="muted mono tabular-nums" style="font-size:11px;">' + escapeHtml(formatShortDate(item.created_at)) + '</span>' +
            '</div>' +
          '</div>' +
          '<p class="crm-timeline-body">' + escapeHtml(item.body) + '</p>' +
        '</div>';
      }).join('');
    }

    function renderClientTeamAndTasks(cid) {
      const vaCard = document.getElementById('dossier-va-card');
      const tasksList = document.getElementById('dossier-tasks-list');
      const tasksBadge = document.getElementById('dossier-tasks-badge');
      const cl = (dossierData.clients || {})[cid] || {};
      const vaId = String(cl.focus_va_id || '');
      const va = vaId && dossierData.vas ? dossierData.vas[vaId] : null;

      if (vaCard) {
        if (!va && !cl.focus_va_name) {
          vaCard.innerHTML = '<div class="crm-va-card"><div class="muted" style="font-size:13px;">No Virtual Assistant currently assigned to this client. ' +
            (canEdit ? 'Select a VA directly from the contact table or edit tab to assign coverage.' : '') + '</div></div>';
        } else {
          const vaName = va ? va.name : (cl.focus_va_name || 'Assigned VA');
          const vaEmail = va ? (va.email || '') : (cl.focus_va_email || '');
          const vaAvatar = va ? (va.avatar_url || '') : (cl.focus_va_avatar || '');
          const specs = va && Array.isArray(va.specialties) ? va.specialties : [];
          const canViewProfile = va && (
            userRole === 'admin' ||
            (userRole === 'team_leader' && String(va.team_leader_id || '') === String(userId))
          );
          const profileBtn = canViewProfile
            ? '<a href="/profile?va_id=' + encodeURIComponent(va.id) + '" class="btn btn-secondary btn-sm">View VA Profile</a>'
            : '';
          const specsHtml = specs.length > 0
            ? '<div class="muted" style="font-size:12px;margin-top:6px;">Specialties: ' + specs.map((s) => escapeHtml(s)).join(' · ') + '</div>'
            : '';

          vaCard.innerHTML =
            '<div class="crm-va-card">' +
              '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">' +
                '<div style="display:flex;align-items:center;gap:10px;min-width:0;">' +
                  smallCrmAvatarHtml(vaAvatar, vaName) +
                  '<div style="min-width:0;">' +
                    '<strong style="display:block;">' + escapeHtml(vaName) + '</strong>' +
                    '<div class="muted" style="font-size:12px;">' + escapeHtml(vaEmail || 'Assigned Virtual Assistant') + '</div>' +
                  '</div>' +
                '</div>' +
                profileBtn +
              '</div>' +
              specsHtml +
            '</div>';
        }
      }

      const clientTasks = (dossierData.tasks || {})[cid] || [];
      if (tasksBadge) tasksBadge.textContent = String(clientTasks.length);
      if (tasksList) {
        if (clientTasks.length === 0) {
          tasksList.innerHTML = '<div class="muted" style="font-size:12.5px;padding:10px 0;">No deliverables linked to this client account yet.</div>';
        } else {
          tasksList.innerHTML = clientTasks.map((tk) => {
            const st = String(tk.status || 'todo').replace(/_/g, ' ');
            const stCls = tk.status === 'completed' ? 'badge-success' : (tk.status === 'in_progress' ? 'badge-brand' : 'badge-neutral');
            return '<div class="crm-task-mini-row">' +
              '<div style="min-width:0;">' +
                '<strong style="display:block;font-size:13px;">' + escapeHtml(tk.title) + '</strong>' +
                '<div class="muted" style="font-size:11.5px;">' +
                  escapeHtml(tk.va_name || 'Unassigned') + ' · Due <span class="mono tabular-nums">' + escapeHtml(formatShortDate(tk.deadline)) + '</span>' +
                '</div>' +
              '</div>' +
              '<span class="badge ' + stCls + '">' + escapeHtml(st) + '</span>' +
            '</div>';
          }).join('');
        }
      }
    }

    // Open Tabbed Client Record Drawer
    document.querySelectorAll('[data-open-client]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const cid = btn.getAttribute('data-open-client');
        if (!cid) return;
        const cl = (dossierData.clients || {})[cid];
        if (!cl) return;

        const drawer = document.getElementById('drawer-client-dossier');
        if (!drawer) return;

        const headAvatar = drawer.querySelector('#dossier-header-avatar');
        if (headAvatar) headAvatar.innerHTML = smallCrmAvatarHtml(cl.avatar_url, cl.agency_name);

        const agencyEl = drawer.querySelector('#dossier-agency');
        const contactEl = drawer.querySelector('#dossier-contact');
        if (agencyEl) agencyEl.textContent = cl.agency_name;
        if (contactEl) contactEl.textContent = cl.name + ' · ' + cl.email;

        const previewEl = drawer.querySelector('#dossier-avatar-preview');
        if (previewEl) previewEl.innerHTML = renderAvatarMarkup(cl.avatar_url, cl.agency_name);

        if (delClientAvatarBtn) {
          delClientAvatarBtn.setAttribute('data-client-id', cid);
          delClientAvatarBtn.classList.toggle('is-hidden', !cl.avatar_url);
        }

        const editForm = drawer.querySelector('#form-edit-client');
        if (editForm) {
          editForm.setAttribute('data-api-form', '/api/v1/clients/' + encodeURIComponent(cid));
          const setVal = (sel, val) => {
            const inp = editForm.querySelector(sel);
            if (inp) inp.value = val != null ? val : '';
          };
          setVal('[name="agency_name"]', cl.agency_name || '');
          setVal('[name="name"]', cl.name || '');
          setVal('[name="email"]', cl.email || '');
          setVal('[name="additional_email"]', cl.additional_email || '');
          setVal('[name="phone"]', cl.phone || '');
          setVal('[name="additional_phone"]', cl.additional_phone || '');
          setVal('[name="website"]', cl.website || '');
          setVal('[name="contracted_hours_per_month"]', cl.contracted_hours_per_month || 40);
          setVal('[name="consumed_hours_month"]', cl.consumed_hours_month || 0);
          setVal('[name="focus_va_id"]', cl.focus_va_id || '');
          setVal('[name="internal_notes"]', cl.internal_notes || '');

          const addEmWrap = document.getElementById('edit-add-email-wrap');
          const addPhWrap = document.getElementById('edit-add-phone-wrap');
          if (addEmWrap) addEmWrap.classList.toggle('is-hidden', !cl.additional_email);
          if (addPhWrap) addPhWrap.classList.toggle('is-hidden', !cl.additional_phone);

          const stVal = cl.contract_status || 'active';
          editForm.querySelectorAll('input[name="contract_status"]').forEach((r) => {
            r.checked = (r.value === stVal);
          });
          syncStatusRadioGrid(editForm.querySelector('.status-radio-grid'));

          const slotsBox = document.getElementById('edit-client-slots-list');
          if (slotsBox) {
            slotsBox.innerHTML = '';
            const slots = Array.isArray(cl.availability_slots_list) ? cl.availability_slots_list : [];
            if (slots.length === 0) {
              slotsBox.appendChild(renderSlotRow('Mon', '09:00', '17:00'));
            } else {
              slots.forEach((s) => {
                slotsBox.appendChild(renderSlotRow(s.day || 'Mon', s.start || '09:00', s.end || '17:00'));
              });
            }
          }
        }

        // VA Strictly Read-Only Overview
        const roBox = drawer.querySelector('#dossier-va-readonly');
        if (roBox) {
          const slotsText = Array.isArray(cl.availability_slots_list) && cl.availability_slots_list.length > 0
            ? cl.availability_slots_list.map((s) => escapeHtml(s.day + ' ' + s.start + '-' + s.end)).join(' · ')
            : 'No client hours configured';
          roBox.innerHTML =
            '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">' +
              '<div class="photo-preview-thumb">' + renderAvatarMarkup(cl.avatar_url, cl.agency_name) + '</div>' +
              '<div><strong style="font-size:15px;">' + escapeHtml(cl.agency_name) + '</strong>' +
              '<div class="muted" style="font-size:12px;">' + escapeHtml(cl.name) + ' · ' + escapeHtml(cl.contract_status || 'active') + '</div></div>' +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;">' +
              '<div><strong>Email:</strong> ' + escapeHtml(cl.email) + (cl.additional_email ? '<br>' + escapeHtml(cl.additional_email) : '') + '</div>' +
              '<div><strong>Phone:</strong> ' + escapeHtml(cl.phone || '—') + (cl.additional_phone ? '<br>' + escapeHtml(cl.additional_phone) : '') + '</div>' +
              '<div><strong>Website:</strong> ' + escapeHtml(cl.website || '—') + '</div>' +
              '<div><strong>Retainer:</strong> ' + escapeHtml(cl.consumed_hours_month || 0) + ' / ' + escapeHtml(cl.contracted_hours_per_month || 0) + ' hrs</div>' +
              '<div style="grid-column:1 / -1;"><strong>Available Hours:</strong> ' + slotsText + '</div>' +
              '<div style="grid-column:1 / -1;"><strong>Account Notes:</strong> ' + escapeHtml(cl.internal_notes || 'No notes recorded.') + '</div>' +
            '</div>';
        }

        const pdfForm = drawer.querySelector('#form-upload-contract');
        if (pdfForm) pdfForm.querySelector('[name="client_id"]').value = cid;
        const avatarForm = drawer.querySelector('#form-upload-client-avatar');
        if (avatarForm) avatarForm.querySelector('[name="client_id"]').value = cid;

        const pdfLink = drawer.querySelector('#dossier-pdf-link');
        if (pdfLink) {
          if (cl.contract_pdf_url) {
            pdfLink.href = cl.contract_pdf_url;
            pdfLink.classList.remove('is-hidden');
          } else {
            pdfLink.classList.add('is-hidden');
          }
        }

        const actForm = drawer.querySelector('#form-client-activity');
        if (actForm) {
          const cidHidden = actForm.querySelector('[name="client_id"]');
          if (cidHidden) cidHidden.value = cid;
        }

        renderClientTimeline(cid);
        renderClientTeamAndTasks(cid);
        activateTab('crm-dossier', 'details');
        openDrawer('drawer-client-dossier');
      });
    });

    // Live No-Reload Client Activity Timeline Submission
    const activityForm = document.getElementById('form-client-activity');
    if (activityForm) {
      activityForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const cidInput = activityForm.querySelector('[name="client_id"]');
        const typeInput = activityForm.querySelector('[name="type"]');
        const descInput = activityForm.querySelector('[name="description"]');
        const submitBtn = activityForm.querySelector('button[type="submit"]');
        const cid = cidInput ? cidInput.value : '';
        const desc = descInput ? descInput.value.trim() : '';
        const typeVal = typeInput ? typeInput.value : 'note';
        if (!cid || !desc) return;

        if (submitBtn) submitBtn.disabled = true;
        try {
          const res = await apiFetch('/api/v1/clients/' + encodeURIComponent(cid) + '/activities', {
            method: 'POST',
            body: JSON.stringify({ type: typeVal, description: desc })
          });
          if (descInput) descInput.value = '';
          const newAct = (res && res.activity) ? res.activity : {
            id: (res && res.id) ? res.id : ('act-' + Date.now()),
            client_id: cid,
            author_name: 'You',
            type: typeVal,
            description: desc,
            created_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
          };
          if (!dossierData.activities) dossierData.activities = {};
          if (!Array.isArray(dossierData.activities[cid])) dossierData.activities[cid] = [];
          dossierData.activities[cid].unshift(newAct);
          renderClientTimeline(cid);
          updateRowLastActivity(cid, newAct);
          showToast('CRM entry logged', 'success');
        } catch (err) {
          showToast(err.message || 'Unable to log activity', 'error');
        } finally {
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    }

    // 3-Step Queued Bulk CSV Import Wizard (Analyze -> Verify Mapping -> Batched Queue Worker)
    const step1 = document.getElementById('bulk-step-1');
    const step2 = document.getElementById('bulk-step-2');
    const step3 = document.getElementById('bulk-step-3');
    const analyzeBtn = document.getElementById('btn-bulk-analyze');
    const backBtn = document.getElementById('btn-bulk-back-1');
    const enqueueBtn = document.getElementById('btn-bulk-enqueue');
    const doneBtn = document.getElementById('btn-bulk-done');

    let analyzedBulkState = null;

    function setWizardStep(stepNum) {
      if (step1) step1.classList.toggle('is-hidden', stepNum !== 1);
      if (step2) step2.classList.toggle('is-hidden', stepNum !== 2);
      if (step3) step3.classList.toggle('is-hidden', stepNum !== 3);
      document.querySelectorAll('[data-wizard-indicator]').forEach((ind) => {
        const n = parseInt(ind.getAttribute('data-wizard-indicator') || '1', 10);
        ind.classList.toggle('active', n === stepNum);
        ind.classList.toggle('is-done', n < stepNum);
      });
    }

    const crmFieldOptions = [
      { value: '', label: '— Ignore Column —' },
      { value: 'agency_name', label: 'Agency / Company Name (Required)' },
      { value: 'name', label: 'Executive Contact Name (Required)' },
      { value: 'email', label: 'Primary Email (Required)' },
      { value: 'phone', label: 'Phone Number' },
      { value: 'contracted_hours_per_month', label: 'Monthly Contracted Hours' },
      { value: 'country', label: 'Country' },
      { value: 'state', label: 'State / Region' },
      { value: 'website', label: 'Website URL' },
      { value: 'contract_status', label: 'Contract Status (active/pending)' },
      { value: 'internal_notes', label: 'Internal Notes' }
    ];

    if (analyzeBtn && step1 && step2) {
      analyzeBtn.addEventListener('click', async () => {
        const fileInp = document.getElementById('bulk-csv-file');
        const textInp = document.getElementById('bulk-csv-text');
        const hasFile = fileInp && fileInp.files && fileInp.files[0];
        const rawText = textInp ? textInp.value.trim() : '';

        if (!hasFile && !rawText) {
          showToast('Upload a CSV file or paste CSV rows first', 'error');
          return;
        }

        const fd = new FormData();
        if (hasFile) {
          fd.append('csv_file', fileInp.files[0]);
        } else {
          fd.append('csv_data', rawText);
        }

        analyzeBtn.disabled = true;
        try {
          const res = await apiFetch('/api/v1/clients/bulk/analyze', {
            method: 'POST',
            body: fd
          });
          analyzedBulkState = res;

          const sumEl = document.getElementById('bulk-analyze-summary');
          if (sumEl) {
            sumEl.textContent = 'Detected ' + (res.total_rows || 0) + ' row(s) and ' +
              (res.headers || []).length + ' column(s) in "' + (res.filename || 'upload.csv') +
              '" (delimiter: ' + (res.delimiter || ',') + '). Verify mapped CRM fields below.';
          }

          const mapGrid = document.getElementById('bulk-mapping-grid');
          if (mapGrid) {
            mapGrid.innerHTML = '';
            (res.headers || []).forEach((hdr, idx) => {
              const rowDiv = document.createElement('div');
              rowDiv.className = 'csv-map-row';

              const lblDiv = document.createElement('div');
              const sampleVal = (res.rows && res.rows[0] && res.rows[0][idx]) ? res.rows[0][idx] : '—';
              lblDiv.innerHTML = '<strong style="font-size:12.5px;">' + escapeHtml(hdr) +
                '</strong><div class="muted" style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Sample: ' +
                escapeHtml(sampleVal) + '</div>';

              const sel = document.createElement('select');
              sel.className = 'select';
              sel.setAttribute('data-csv-col-idx', String(idx));
              const suggested = (res.suggested_mapping || {})[String(idx)] || '';
              crmFieldOptions.forEach((optDef) => {
                const opt = document.createElement('option');
                opt.value = optDef.value;
                opt.textContent = optDef.label;
                if (optDef.value === suggested) opt.selected = true;
                sel.appendChild(opt);
              });

              rowDiv.appendChild(lblDiv);
              rowDiv.appendChild(sel);
              mapGrid.appendChild(rowDiv);
            });
          }

          const prevTbl = document.getElementById('bulk-preview-table');
          if (prevTbl) {
            prevTbl.innerHTML = '';
            const thead = document.createElement('thead');
            const htr = document.createElement('tr');
            (res.headers || []).forEach((hdr) => {
              const th = document.createElement('th');
              th.textContent = hdr;
              htr.appendChild(th);
            });
            thead.appendChild(htr);
            prevTbl.appendChild(thead);

            const tbody = document.createElement('tbody');
            (res.rows || []).slice(0, 5).forEach((r) => {
              const tr = document.createElement('tr');
              (res.headers || []).forEach((_h, idx) => {
                const td = document.createElement('td');
                td.textContent = (r[idx] != null && r[idx] !== '') ? r[idx] : '—';
                tr.appendChild(td);
              });
              tbody.appendChild(tr);
            });
            prevTbl.appendChild(tbody);
          }

          setWizardStep(2);
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          analyzeBtn.disabled = false;
        }
      });
    }

    if (backBtn) {
      backBtn.addEventListener('click', () => setWizardStep(1));
    }

    if (enqueueBtn && step3) {
      enqueueBtn.addEventListener('click', async () => {
        if (!analyzedBulkState || !Array.isArray(analyzedBulkState.rows)) return;
        const mapGrid = document.getElementById('bulk-mapping-grid');
        const defaultVaSel = document.getElementById('bulk-default-va');
        const mapping = {};
        if (mapGrid) {
          mapGrid.querySelectorAll('[data-csv-col-idx]').forEach((sel) => {
            const idx = sel.getAttribute('data-csv-col-idx');
            if (sel.value) mapping[idx] = sel.value;
          });
        }
        const mappedValues = Object.values(mapping);
        const missing = ['agency_name', 'name', 'email'].filter((req) => !mappedValues.includes(req));
        if (missing.length > 0) {
          showToast('Please map required columns: ' + missing.join(', '), 'error');
          return;
        }

        enqueueBtn.disabled = true;
        try {
          const jobRes = await apiFetch('/api/v1/clients/bulk/jobs', {
            method: 'POST',
            body: JSON.stringify({
              filename: analyzedBulkState.filename || 'clients_import.csv',
              mapping,
              rows: analyzedBulkState.rows,
              default_va_id: defaultVaSel ? defaultVaSel.value : ''
            })
          });

          setWizardStep(3);
          const jobId = jobRes.job_id;
          const totalRows = jobRes.total_rows || analyzedBulkState.rows.length;
          const fillEl = document.getElementById('bulk-progress-fill');
          const pctEl = document.getElementById('bulk-worker-pct');
          const statusEl = document.getElementById('bulk-worker-status');
          const procEl = document.getElementById('bulk-stat-processed');
          const succEl = document.getElementById('bulk-stat-succeeded');
          const failEl = document.getElementById('bulk-stat-failed');
          const errList = document.getElementById('bulk-errors-list');

          let finished = false;
          while (!finished) {
            const bRes = await apiFetch('/api/v1/clients/bulk/jobs/' + encodeURIComponent(jobId) + '/process', {
              method: 'POST'
            });
            const processed = Number(bRes.processed_rows || 0);
            const total = Math.max(1, Number(bRes.total_rows || totalRows));
            const pct = Math.min(100, Math.round((processed / total) * 100));

            if (fillEl) fillEl.style.width = pct + '%';
            if (pctEl) pctEl.textContent = pct + '%';
            if (procEl) procEl.textContent = processed + ' / ' + total;
            if (succEl) succEl.textContent = String(bRes.succeeded_rows || 0);
            if (failEl) failEl.textContent = String(bRes.failed_rows || 0);

            if (errList && Array.isArray(bRes.errors) && bRes.errors.length > 0) {
              errList.innerHTML = '';
              bRes.errors.forEach((er) => {
                const d = document.createElement('div');
                d.className = 'notice notice-warn';
                d.style.marginBottom = '6px';
                d.style.padding = '7px 10px';
                d.style.fontSize = '12px';
                d.textContent = er;
                errList.appendChild(d);
              });
            }

            if (bRes.status === 'completed' || Number(bRes.remaining_rows || 0) === 0) {
              finished = true;
              if (statusEl) statusEl.textContent = 'Worker queue finished!';
              if (doneBtn) doneBtn.classList.remove('is-hidden');
              showToast('Imported ' + (bRes.succeeded_rows || 0) + ' client account(s)', 'success');
            } else {
              await new Promise((r) => window.setTimeout(r, 120));
            }
          }
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          enqueueBtn.disabled = false;
        }
      });
    }

    if (doneBtn) {
      doneBtn.addEventListener('click', () => window.location.reload());
    }
  }

  // ------------------------------------------------------------------------
  // Admin Right-Side User Drawer (Photo Preview/Delete, VA Specialties,
  // Dynamic Specialty Category Creation & Suspend/Reactivate/Delete)
  // ------------------------------------------------------------------------
  function toggleVaSpecialtiesSection(formEl) {
    if (!formEl) return;
    const roleSel = formEl.querySelector('[data-role-select]');
    const specWrap = formEl.querySelector('[data-va-specialties-wrap]');
    if (roleSel && specWrap) {
      specWrap.classList.toggle('is-hidden', roleSel.value !== 'va');
    }
  }

  function initAdminEditDrawers() {
    document.querySelectorAll('form[data-role-form]').forEach((f) => {
      const roleSel = f.querySelector('[data-role-select]');
      if (roleSel) {
        roleSel.addEventListener('change', () => toggleVaSpecialtiesSection(f));
        toggleVaSpecialtiesSection(f);
      }
    });

    // Dynamic VA Specialty Category Creation (persisted in va_specialty_categories)
    document.querySelectorAll('[data-add-spec-btn]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const wrap = btn.closest('.spec-add-row');
        const input = wrap ? wrap.querySelector('[data-new-spec-input]') : null;
        const name = input ? input.value.trim() : '';
        if (!name) {
          showToast('Enter a VA specialty category name', 'error');
          return;
        }

        try {
          btn.disabled = true;
          const res = await apiFetch('/api/v1/users/specialties', {
            method: 'POST',
            body: JSON.stringify({ name })
          });
          const createdName = res.name || name;
          const parentGroup = btn.closest('.form-group');
          const ownGrid = parentGroup ? parentGroup.querySelector('[data-spec-grid]') : null;

          document.querySelectorAll('[data-spec-grid]').forEach((grid) => {
            const exists = Array.from(grid.querySelectorAll('input[name="specialties[]"]')).some(
              (cb) => cb.value.toLowerCase() === createdName.toLowerCase()
            );
            if (!exists) {
              const label = document.createElement('label');
              label.className = 'choice-pill';
              const cb = document.createElement('input');
              cb.type = 'checkbox';
              cb.name = 'specialties[]';
              cb.value = createdName;
              cb.checked = (grid === ownGrid);
              const span = document.createElement('span');
              span.textContent = createdName;
              label.appendChild(cb);
              label.appendChild(span);
              grid.appendChild(label);
            }
          });

          if (input) input.value = '';
          showToast('Added VA specialty "' + createdName + '"', 'success');
        } catch (err) {
          showToast(err.message, 'error');
        } finally {
          btn.disabled = false;
        }
      });
    });

    const delUserAvatarBtn = document.getElementById('btn-delete-user-avatar');
    if (delUserAvatarBtn) {
      delUserAvatarBtn.addEventListener('click', async () => {
        const uid = delUserAvatarBtn.getAttribute('data-user-id') || '';
        if (!uid) return;
        try {
          delUserAvatarBtn.disabled = true;
          await apiFetch('/api/v1/uploads/avatar', {
            method: 'DELETE',
            body: JSON.stringify({ user_id: uid })
          });
          showToast('Staff photo removed', 'success');
          window.setTimeout(() => window.location.reload(), 300);
        } catch (err) {
          showToast(err.message, 'error');
          delUserAvatarBtn.disabled = false;
        }
      });
    }

    const suspendBtn = document.getElementById('btn-edit-user-suspend');
    const reactivateBtn = document.getElementById('btn-edit-user-reactivate');
    const deleteUserBtn = document.getElementById('btn-edit-user-delete');

    if (suspendBtn) {
      suspendBtn.addEventListener('click', async () => {
        const uid = suspendBtn.getAttribute('data-user-id') || '';
        if (!uid) return;
        try {
          suspendBtn.disabled = true;
          await apiFetch('/api/v1/users/' + encodeURIComponent(uid) + '/suspend', {
            method: 'PATCH',
            body: JSON.stringify({ reason: 'Administrative hold' })
          });
          showToast('Account suspended', 'success');
          window.setTimeout(() => window.location.reload(), 320);
        } catch (err) {
          showToast(err.message, 'error');
          suspendBtn.disabled = false;
        }
      });
    }

    if (reactivateBtn) {
      reactivateBtn.addEventListener('click', async () => {
        const uid = reactivateBtn.getAttribute('data-user-id') || '';
        if (!uid) return;
        try {
          reactivateBtn.disabled = true;
          await apiFetch('/api/v1/users/' + encodeURIComponent(uid) + '/reactivate', { method: 'PATCH' });
          showToast('Account reactivated', 'success');
          window.setTimeout(() => window.location.reload(), 320);
        } catch (err) {
          showToast(err.message, 'error');
          reactivateBtn.disabled = false;
        }
      });
    }

    if (deleteUserBtn) {
      deleteUserBtn.addEventListener('click', async () => {
        const uid = deleteUserBtn.getAttribute('data-user-id') || '';
        if (!uid) return;
        try {
          deleteUserBtn.disabled = true;
          await apiFetch('/api/v1/users/' + encodeURIComponent(uid), { method: 'DELETE' });
          showToast('Account permanently deleted', 'success');
          window.setTimeout(() => window.location.reload(), 320);
        } catch (err) {
          showToast(err.message, 'error');
          deleteUserBtn.disabled = false;
        }
      });
    }

    document.querySelectorAll('[data-edit-user]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const uid = btn.getAttribute('data-edit-user');
        const store = document.getElementById('admin-users-json');
        if (!store || !uid) return;
        const usersMap = JSON.parse(store.textContent || '{}');
        const currentUserId = store.getAttribute('data-current-user-id') || '';
        const u = usersMap[uid];
        if (!u) return;

        const drawer = document.getElementById('drawer-edit-user');
        if (!drawer) return;

        const avatarPreview = drawer.querySelector('#edit-user-avatar-preview');
        if (avatarPreview) avatarPreview.innerHTML = renderAvatarMarkup(u.avatar_url, u.name);

        const avatarUidInput = drawer.querySelector('#edit-user-avatar-id');
        if (avatarUidInput) avatarUidInput.value = uid;

        if (delUserAvatarBtn) {
          delUserAvatarBtn.setAttribute('data-user-id', uid);
          delUserAvatarBtn.classList.toggle('is-hidden', !u.avatar_url);
        }

        const dangerBar = drawer.querySelector('#edit-user-danger-actions');
        if (dangerBar) {
          const isSelf = (uid === currentUserId);
          dangerBar.classList.toggle('is-hidden', isSelf);
          const isSuspended = Boolean(u.is_suspended) || !Boolean(u.is_active);
          if (suspendBtn) {
            suspendBtn.setAttribute('data-user-id', uid);
            suspendBtn.classList.toggle('is-hidden', isSuspended);
          }
          if (reactivateBtn) {
            reactivateBtn.setAttribute('data-user-id', uid);
            reactivateBtn.classList.toggle('is-hidden', !isSuspended);
          }
          if (deleteUserBtn) {
            deleteUserBtn.setAttribute('data-user-id', uid);
          }
        }

        const form = drawer.querySelector('#form-edit-user');
        if (form) {
          form.setAttribute('data-api-form', '/api/v1/users/' + encodeURIComponent(uid));
          form.querySelector('[name="name"]').value = u.name || '';
          form.querySelector('[name="email"]').value = u.email || '';
          form.querySelector('[name="role"]').value = u.role || 'va';
          form.querySelector('[name="hourly_rate"]').value = u.hourly_rate || 0;
          form.querySelector('[name="pto_balance_days"]').value = u.pto_balance_days || 15;
          const tlSel = form.querySelector('[name="team_leader_id"]');
          if (tlSel) tlSel.value = u.team_leader_id || '';
          const phoneInp = form.querySelector('[name="phone"]');
          if (phoneInp) phoneInp.value = u.phone || '';
          const bioInp = form.querySelector('[name="bio"]');
          if (bioInp) bioInp.value = u.bio || '';

          const specs = Array.isArray(u.specialties) ? u.specialties : [];
          form.querySelectorAll('input[name="specialties[]"]').forEach((cb) => {
            cb.checked = specs.includes(cb.value);
          });
          toggleVaSpecialtiesSection(form);
        }

        openDrawer('drawer-edit-user');
      });
    });
  }

  // ------------------------------------------------------------------------
  // VA Directory Search, Specialty Category Filter & Admin Edit VA Drawer
  // ------------------------------------------------------------------------
  function initVaDirectory() {
    const dirSearch = document.getElementById('va-dir-search');
    const dirSpec = document.getElementById('va-dir-spec-filter');
    const dirCount = document.getElementById('va-dir-count');

    if (dirSearch || dirSpec) {
      const filterVas = () => {
        const q = dirSearch ? dirSearch.value.trim().toLowerCase() : '';
        const spec = dirSpec ? dirSpec.value.trim().toLowerCase() : '';
        let visible = 0;
        document.querySelectorAll('.va-dir-row[data-va-search]').forEach((row) => {
          const hay = (row.getAttribute('data-va-search') || '').toLowerCase();
          const specsList = (row.getAttribute('data-va-specs') || '').toLowerCase().split('||').filter(Boolean);
          const matchQ = (q === '' || hay.includes(q));
          const matchS = (spec === '' || specsList.includes(spec));
          const show = matchQ && matchS;
          row.style.display = show ? '' : 'none';
          if (show) visible++;
        });
        if (dirCount) dirCount.textContent = String(visible);
      };
      if (dirSearch) dirSearch.addEventListener('input', filterVas);
      if (dirSpec) dirSpec.addEventListener('change', filterVas);
    }

    const store = document.getElementById('va-dir-json');
    if (!store) return;

    const delDirAvatarBtn = document.getElementById('btn-dir-delete-avatar');
    if (delDirAvatarBtn) {
      delDirAvatarBtn.addEventListener('click', async () => {
        const uid = delDirAvatarBtn.getAttribute('data-user-id') || '';
        if (!uid) return;
        try {
          delDirAvatarBtn.disabled = true;
          await apiFetch('/api/v1/uploads/avatar', {
            method: 'DELETE',
            body: JSON.stringify({ user_id: uid })
          });
          showToast('VA profile photo deleted', 'success');
          window.setTimeout(() => window.location.reload(), 300);
        } catch (err) {
          showToast(err.message, 'error');
          delDirAvatarBtn.disabled = false;
        }
      });
    }

    document.querySelectorAll('[data-dir-edit-va]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const vaId = btn.getAttribute('data-dir-edit-va');
        const map = JSON.parse(store.textContent || '{}');
        const va = map[vaId];
        const drawer = document.getElementById('drawer-dir-edit-va');
        if (!va || !drawer) return;

        const previewEl = drawer.querySelector('#dir-va-avatar-preview');
        if (previewEl) previewEl.innerHTML = renderAvatarMarkup(va.avatar_url, va.va_name);

        const uidInput = drawer.querySelector('#dir-va-avatar-id');
        if (uidInput) uidInput.value = vaId;

        if (delDirAvatarBtn) {
          delDirAvatarBtn.setAttribute('data-user-id', vaId);
          delDirAvatarBtn.classList.toggle('is-hidden', !va.avatar_url);
        }

        const form = drawer.querySelector('#form-dir-edit-va');
        if (form) {
          form.setAttribute('data-api-form', '/api/v1/users/' + encodeURIComponent(vaId));
          form.querySelector('[name="name"]').value = va.va_name || '';
          form.querySelector('[name="email"]').value = va.va_email || '';
          form.querySelector('[name="hourly_rate"]').value = va.hourly_rate || 0;
          const tlSel = form.querySelector('[name="team_leader_id"]');
          if (tlSel) tlSel.value = va.team_leader_id || '';
          const phoneInp = form.querySelector('[name="phone"]');
          if (phoneInp) phoneInp.value = va.phone || '';
          const bioInp = form.querySelector('[name="bio"]');
          if (bioInp) bioInp.value = va.bio || '';

          const specs = String(va.specialties_csv || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);
          form.querySelectorAll('input[name="specialties[]"]').forEach((cb) => {
            cb.checked = specs.includes(cb.value);
          });
        }

        openDrawer('drawer-dir-edit-va');
      });
    });
  }

  // ------------------------------------------------------------------------
  // Knowledge Base: Live Search, Reader Modal, Document Studio & Single PDF
  // ------------------------------------------------------------------------
  function printSopDocument(doc) {
    if (!doc) return;
    let stage = document.getElementById('kb-print-stage');
    if (!stage) {
      stage = document.createElement('div');
      stage.id = 'kb-print-stage';
      stage.className = 'kb-print-stage';
      document.body.appendChild(stage);
    } else if (stage.parentElement !== document.body) {
      document.body.appendChild(stage);
    }

    const scopeLabel = doc.client_name ? ('Client Scope: ' + doc.client_name) : 'Global Playbook';
    stage.innerHTML =
      '<div class="kb-print-header">' +
        '<div class="kb-print-badges">' +
          '<span class="kb-print-badge">' + escapeHtml(doc.category || 'SOP') + '</span>' +
          '<span class="kb-print-badge is-scope">' + escapeHtml(scopeLabel) + '</span>' +
        '</div>' +
        '<h1>' + escapeHtml(doc.title || 'Standard Operating Procedure') + '</h1>' +
        '<div class="kb-print-meta">' +
          'By ' + escapeHtml(doc.author || 'Operations Team') +
          (doc.last_updated ? (' • Updated ' + escapeHtml(doc.last_updated)) : '') +
        '</div>' +
      '</div>' +
      '<div class="kb-rich-content kb-doc-surface">' + (doc.content || '') + '</div>';

    document.body.classList.add('workly-print-sop');
    const cleanup = () => {
      document.body.classList.remove('workly-print-sop');
      stage.innerHTML = '';
    };
    window.addEventListener('afterprint', cleanup, { once: true });
    window.setTimeout(() => {
      window.print();
      window.setTimeout(cleanup, 1200);
    }, 80);
  }

  function initKnowledgeBase() {
    const searchInp = document.getElementById('kb-search');
    const catContainer = document.getElementById('kb-category-pills');
    const docCards = Array.from(document.querySelectorAll('.kb-doc-card'));
    const storeEl = document.getElementById('kb-docs-json');
    let docsMap = {};
    if (storeEl) {
      try {
        docsMap = JSON.parse(storeEl.textContent || '{}');
      } catch (_e) {
        docsMap = {};
      }
    }
    let activeCat = '';
    let activeReaderDocId = '';

    function filterDocs() {
      const q = searchInp ? searchInp.value.trim().toLowerCase() : '';
      docCards.forEach((card) => {
        const cardCat = (card.getAttribute('data-kb-cat') || '').toLowerCase();
        const hay = (card.getAttribute('data-kb-search') || '').toLowerCase();
        const matchCat = (activeCat === '' || cardCat === activeCat.toLowerCase());
        const matchSearch = (q === '' || hay.includes(q));
        card.style.display = (matchCat && matchSearch) ? '' : 'none';
      });
    }

    if (searchInp) searchInp.addEventListener('input', filterDocs);
    if (catContainer) {
      catContainer.addEventListener('click', (e) => {
        const pill = e.target.closest('[data-kb-cat]');
        if (!pill) return;
        activeCat = pill.getAttribute('data-kb-cat') || '';
        catContainer.querySelectorAll('[data-kb-cat]').forEach((b) => {
          b.classList.toggle('active', b === pill);
        });
        filterDocs();
      });
    }

    // Measure 400px/300px clipping on cards for fade & "open full document" cue (without View More button)
    function measureKbClips() {
      document.querySelectorAll('.kb-doc-card').forEach((card) => {
        const clip = card.querySelector('.kb-rich-clip');
        if (!clip) return;
        const hasOverflow = clip.scrollHeight > clip.clientHeight + 8;
        clip.classList.toggle('has-overflow', hasOverflow);
        card.classList.toggle('has-overflow', hasOverflow);
      });
    }
    window.setTimeout(measureKbClips, 240);
    window.addEventListener('resize', measureKbClips);

    // Reader Modal
    const readerModal = document.getElementById('modal-kb-reader');
    const readerTitle = document.getElementById('kb-reader-title');
    const readerCat = document.getElementById('kb-reader-category');
    const readerScope = document.getElementById('kb-reader-scope');
    const readerMeta = document.getElementById('kb-reader-meta');
    const readerContent = document.getElementById('kb-reader-content');
    const readerPdfBtn = document.getElementById('btn-kb-reader-pdf');
    const readerEditBtn = document.getElementById('btn-kb-reader-edit');

    function openKbReader(docId) {
      const doc = docsMap[docId];
      if (!doc || !readerModal) return;
      activeReaderDocId = docId;
      if (readerTitle) readerTitle.textContent = doc.title || 'Standard Operating Procedure';
      if (readerCat) readerCat.textContent = doc.category || 'SOP';
      if (readerScope) {
        readerScope.textContent = doc.client_name ? ('Client Scope: ' + doc.client_name) : 'Global Playbook';
        readerScope.className = doc.client_name ? 'badge badge-info' : 'badge badge-neutral';
      }
      if (readerMeta) {
        readerMeta.textContent = 'By ' + (doc.author || 'Staff') + ' • Updated ' + (doc.last_updated || '');
      }
      if (readerContent) readerContent.innerHTML = doc.content || '';
      openModal('modal-kb-reader');
    }

    docCards.forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('button, a, input, select, textarea')) return;
        const docId = card.getAttribute('data-kb-doc-id');
        if (docId) openKbReader(docId);
      });
      card.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target === card) {
          e.preventDefault();
          const docId = card.getAttribute('data-kb-doc-id');
          if (docId) openKbReader(docId);
        }
      });
    });

    if (readerPdfBtn) {
      readerPdfBtn.addEventListener('click', () => {
        if (activeReaderDocId && docsMap[activeReaderDocId]) {
          printSopDocument(docsMap[activeReaderDocId]);
        }
      });
    }

    // Card Download PDF buttons
    document.querySelectorAll('[data-kb-print]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const docId = btn.getAttribute('data-kb-print');
        if (docId && docsMap[docId]) {
          printSopDocument(docsMap[docId]);
        }
      });
    });

    // Word-Style Document Studio Modal (Create & Edit SOP)
    const studioForm = document.getElementById('form-kb-studio');
    const studioHeading = document.getElementById('kb-studio-heading');
    const studioSubmit = document.getElementById('btn-kb-studio-submit');
    const studioSavePdf = document.getElementById('btn-kb-save-pdf');
    const studioStats = document.getElementById('kb-studio-stats');
    const editor = document.getElementById('kb-studio-editor');
    const hiddenInput = studioForm ? studioForm.querySelector('[data-kb-input]') : null;
    const blockSel = document.getElementById('kb-block-select');
    const fontSel = document.getElementById('kb-font-family');
    const sizeSel = document.getElementById('kb-font-size');
    const foreColorInp = document.getElementById('kb-fore-color');
    const hiliteColorInp = document.getElementById('kb-hilite-color');
    const linkBar = document.getElementById('kb-link-bar');
    const linkUrlInp = document.getElementById('kb-link-url');
    const linkApplyBtn = document.getElementById('btn-kb-apply-link');
    const linkCancelBtn = document.getElementById('btn-kb-cancel-link');
    const linkToggleBtn = document.getElementById('btn-kb-toggle-linkbar');
    let savedRange = null;

    function updateStudioStats() {
      if (!editor) return;
      if (hiddenInput) hiddenInput.value = editor.innerHTML.trim();
      const plain = (editor.textContent || '').replace(/\u00a0/g, ' ').trim();
      const words = plain ? plain.split(/\s+/).filter(Boolean).length : 0;
      const chars = plain.length;
      if (studioStats) {
        studioStats.textContent = words + (words === 1 ? ' word' : ' words') + ' • ' + chars + (chars === 1 ? ' character' : ' characters');
      }
    }

    function saveEditorSelection() {
      if (!editor) return;
      const sel = window.getSelection();
      if (sel && sel.rangeCount > 0) {
        const range = sel.getRangeAt(0);
        if (editor.contains(range.commonAncestorContainer)) {
          savedRange = range.cloneRange();
        }
      }
    }

    function restoreEditorSelection() {
      if (!editor) return null;
      editor.focus();
      const sel = window.getSelection();
      if (!sel) return null;
      if (savedRange) {
        try {
          sel.removeAllRanges();
          sel.addRange(savedRange);
          return savedRange;
        } catch (_err) {
          savedRange = null;
        }
      }
      if (sel.rangeCount > 0) {
        const current = sel.getRangeAt(0);
        if (editor.contains(current.commonAncestorContainer)) {
          return current;
        }
      }
      return null;
    }

    function applySpanStyleToSelection(styleProp, styleVal) {
      if (!editor) return;
      const range = restoreEditorSelection();
      if (!range) return;

      if (range.collapsed) {
        const span = document.createElement('span');
        span.style[styleProp] = styleVal;
        span.appendChild(document.createTextNode('\u200B'));
        range.insertNode(span);
        const nextRange = document.createRange();
        nextRange.setStart(span.firstChild, 1);
        nextRange.collapse(true);
        const sel = window.getSelection();
        if (sel) {
          sel.removeAllRanges();
          sel.addRange(nextRange);
        }
        savedRange = nextRange.cloneRange();
        updateStudioStats();
        return;
      }

      const span = document.createElement('span');
      span.style[styleProp] = styleVal;
      const frag = range.extractContents();
      span.appendChild(frag);
      range.insertNode(span);

      const newRange = document.createRange();
      newRange.selectNodeContents(span);
      const sel = window.getSelection();
      if (sel) {
        sel.removeAllRanges();
        sel.addRange(newRange);
      }
      savedRange = newRange.cloneRange();
      updateStudioStats();
    }

    function getSelectionTextNodes(range) {
      if (!editor || !range) return [];
      const rootNode = range.commonAncestorContainer.nodeType === Node.TEXT_NODE
        ? range.commonAncestorContainer.parentNode
        : range.commonAncestorContainer;
      if (!rootNode || !editor.contains(rootNode)) return [];

      const walker = document.createTreeWalker(rootNode, NodeFilter.SHOW_TEXT);
      const nodes = [];
      let curr = walker.nextNode();
      while (curr) {
        if (curr.nodeValue && range.intersectsNode(curr)) {
          nodes.push(curr);
        }
        curr = walker.nextNode();
      }
      return nodes;
    }

    function transformSelectionCase(mode) {
      if (!editor) return;
      const range = restoreEditorSelection();
      if (!range || range.collapsed) {
        showToast('Select the text you want to convert first', 'error');
        return;
      }

      const transformStr = (s) => {
        if (mode === 'upper') return s.toUpperCase();
        if (mode === 'lower') return s.toLowerCase();
        if (mode === 'title') {
          return s.toLowerCase().replace(/(^|[\s\-\/(\[])(\S)/g, (_m, sep, ch) => sep + ch.toUpperCase());
        }
        // sentence case
        const lowered = s.toLowerCase();
        return lowered.replace(/(^\s*[a-zA-ZÀ-ÿ])|([.!?]\s+[a-zA-ZÀ-ÿ])/g, (m) => m.toUpperCase());
      };

      const startContainer = range.startContainer;
      const startOffset = range.startOffset;
      const endContainer = range.endContainer;
      const endOffset = range.endOffset;

      if (startContainer === endContainer && startContainer.nodeType === Node.TEXT_NODE) {
        const full = startContainer.nodeValue || '';
        const before = full.slice(0, startOffset);
        const mid = transformStr(full.slice(startOffset, endOffset));
        const after = full.slice(endOffset);
        startContainer.nodeValue = before + mid + after;
        const nextRange = document.createRange();
        nextRange.setStart(startContainer, startOffset);
        nextRange.setEnd(startContainer, startOffset + mid.length);
        const sel = window.getSelection();
        if (sel) {
          sel.removeAllRanges();
          sel.addRange(nextRange);
        }
        savedRange = nextRange.cloneRange();
        updateStudioStats();
        return;
      }

      const nodes = getSelectionTextNodes(range);
      nodes.forEach((tn) => {
        const text = tn.nodeValue || '';
        const sIdx = (tn === startContainer) ? startOffset : 0;
        const eIdx = (tn === endContainer) ? endOffset : text.length;
        if (eIdx <= sIdx) return;
        tn.nodeValue = text.slice(0, sIdx) + transformStr(text.slice(sIdx, eIdx)) + text.slice(eIdx);
      });
      saveEditorSelection();
      updateStudioStats();
    }

    function scaleSelectedText(direction) {
      if (!editor) return;
      const range = restoreEditorSelection();
      if (!range || range.collapsed) {
        showToast('Select text first to adjust its font size', 'error');
        return;
      }

      const anchorEl = range.startContainer.nodeType === Node.ELEMENT_NODE
        ? range.startContainer
        : range.startContainer.parentElement;
      const computedPx = anchorEl ? parseFloat(window.getComputedStyle(anchorEl).fontSize || '14') : 14;
      const currentPx = Number.isFinite(computedPx) ? computedPx : 14;
      const step = direction === 'up' ? 2 : -2;
      const nextPx = Math.max(10, Math.min(48, Math.round(currentPx + step)));
      applySpanStyleToSelection('fontSize', nextPx + 'px');
      if (sizeSel) {
        const optExists = Array.from(sizeSel.options).some((o) => o.value === String(nextPx));
        sizeSel.value = optExists ? String(nextPx) : '';
      }
    }

    function insertHtmlAtCursor(html) {
      if (!editor) return;
      const range = restoreEditorSelection();
      if (!range) {
        editor.insertAdjacentHTML('beforeend', html);
        updateStudioStats();
        return;
      }
      range.deleteContents();
      const tpl = document.createElement('template');
      tpl.innerHTML = html;
      const frag = tpl.content;
      const lastNode = frag.lastChild;
      range.insertNode(frag);
      if (lastNode) {
        const after = document.createRange();
        after.setStartAfter(lastNode);
        after.collapse(true);
        const sel = window.getSelection();
        if (sel) {
          sel.removeAllRanges();
          sel.addRange(after);
        }
        savedRange = after.cloneRange();
      }
      updateStudioStats();
    }

    function openKbStudio(mode, doc) {
      if (!studioForm || !editor) return;
      studioForm.dataset.printAfterSave = '0';
      if (linkBar) linkBar.classList.add('is-hidden');

      const titleInp = studioForm.querySelector('[name="title"]');
      const catSel = studioForm.querySelector('[name="category"]');
      const clSel = studioForm.querySelector('[name="client_id"]');

      if (mode === 'edit' && doc) {
        studioForm.setAttribute('data-api-form', '/api/v1/knowledge/' + encodeURIComponent(doc.id));
        studioForm.setAttribute('data-api-method', 'PUT');
        if (studioHeading) studioHeading.textContent = 'Edit Knowledge Document';
        if (studioSubmit) studioSubmit.textContent = 'Save Changes';
        if (titleInp) titleInp.value = doc.title || '';
        if (catSel && doc.category) {
          const hasOpt = Array.from(catSel.options).some((o) => o.value === doc.category);
          if (!hasOpt) {
            const opt = document.createElement('option');
            opt.value = doc.category;
            opt.textContent = doc.category;
            catSel.appendChild(opt);
          }
          catSel.value = doc.category;
        }
        if (clSel) clSel.value = doc.client_id || '';
        editor.innerHTML = doc.content || '';
      } else {
        studioForm.setAttribute('data-api-form', '/api/v1/knowledge');
        studioForm.setAttribute('data-api-method', 'POST');
        if (studioHeading) studioHeading.textContent = 'Publish Knowledge Document';
        if (studioSubmit) studioSubmit.textContent = 'Publish SOP';
        if (titleInp) titleInp.value = '';
        if (catSel && catSel.options.length > 0) catSel.selectedIndex = 0;
        if (clSel) clSel.value = '';
        editor.innerHTML = '';
      }

      if (blockSel) blockSel.value = 'P';
      if (fontSel) fontSel.value = '';
      if (sizeSel) sizeSel.value = '';
      savedRange = null;
      updateStudioStats();
      openModal('modal-kb-studio');
      window.setTimeout(() => {
        if (mode === 'create' && titleInp) {
          titleInp.focus();
        } else {
          editor.focus();
        }
      }, 40);
    }

    if (editor) {
      editor.addEventListener('input', updateStudioStats);
      editor.addEventListener('keyup', saveEditorSelection);
      editor.addEventListener('mouseup', saveEditorSelection);
      editor.addEventListener('blur', () => {
        saveEditorSelection();
        updateStudioStats();
      });
    }

    document.querySelectorAll('#modal-kb-studio [data-kb-cmd]').forEach((btn) => {
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        saveEditorSelection();
      });
      btn.addEventListener('click', () => {
        const cmd = btn.getAttribute('data-kb-cmd');
        const val = btn.getAttribute('data-kb-val') || null;
        restoreEditorSelection();
        if (cmd) document.execCommand(cmd, false, val);
        saveEditorSelection();
        updateStudioStats();
      });
    });

    if (blockSel) {
      blockSel.addEventListener('change', () => {
        const tag = blockSel.value || 'P';
        restoreEditorSelection();
        document.execCommand('formatBlock', false, '<' + tag + '>');
        saveEditorSelection();
        updateStudioStats();
      });
    }

    if (fontSel) {
      fontSel.addEventListener('change', () => {
        if (!fontSel.value) return;
        applySpanStyleToSelection('fontFamily', fontSel.value);
      });
    }

    if (sizeSel) {
      sizeSel.addEventListener('change', () => {
        if (!sizeSel.value) return;
        applySpanStyleToSelection('fontSize', sizeSel.value + 'px');
      });
    }

    document.querySelectorAll('#modal-kb-studio [data-kb-scale]').forEach((btn) => {
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        saveEditorSelection();
      });
      btn.addEventListener('click', () => {
        scaleSelectedText(btn.getAttribute('data-kb-scale'));
      });
    });

    document.querySelectorAll('#modal-kb-studio [data-kb-case]').forEach((btn) => {
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        saveEditorSelection();
      });
      btn.addEventListener('click', () => {
        transformSelectionCase(btn.getAttribute('data-kb-case'));
      });
    });

    if (foreColorInp) {
      foreColorInp.addEventListener('mousedown', saveEditorSelection);
      foreColorInp.addEventListener('input', () => {
        applySpanStyleToSelection('color', foreColorInp.value);
      });
    }

    if (hiliteColorInp) {
      hiliteColorInp.addEventListener('mousedown', saveEditorSelection);
      hiliteColorInp.addEventListener('input', () => {
        applySpanStyleToSelection('backgroundColor', hiliteColorInp.value);
      });
    }

    document.querySelectorAll('#modal-kb-studio [data-kb-insert]').forEach((btn) => {
      btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        saveEditorSelection();
      });
      btn.addEventListener('click', () => {
        const kind = btn.getAttribute('data-kb-insert');
        if (kind === 'hr') {
          insertHtmlAtCursor('<hr><p><br></p>');
        } else if (kind === 'table') {
          insertHtmlAtCursor(
            '<table>' +
              '<thead><tr><th>Step / Owner</th><th>Action Detail</th><th>SLA Target</th></tr></thead>' +
              '<tbody>' +
                '<tr><td>1. Intake</td><td>Verify client instructions</td><td>Same day</td></tr>' +
                '<tr><td>2. Execution</td><td>Complete deliverable checklist</td><td>Within 24h</td></tr>' +
              '</tbody>' +
            '</table><p><br></p>'
          );
        }
      });
    });

    if (linkToggleBtn && linkBar) {
      linkToggleBtn.addEventListener('mousedown', (e) => {
        e.preventDefault();
        saveEditorSelection();
      });
      linkToggleBtn.addEventListener('click', () => {
        linkBar.classList.toggle('is-hidden');
        if (!linkBar.classList.contains('is-hidden') && linkUrlInp) {
          linkUrlInp.focus();
        }
      });
    }

    if (linkCancelBtn && linkBar) {
      linkCancelBtn.addEventListener('click', () => {
        linkBar.classList.add('is-hidden');
        if (linkUrlInp) linkUrlInp.value = '';
      });
    }

    if (linkApplyBtn && linkUrlInp) {
      linkApplyBtn.addEventListener('click', () => {
        const url = linkUrlInp.value.trim();
        if (!url || !/^https?:\/\//i.test(url)) {
          showToast('Enter a valid URL starting with https:// or http://', 'error');
          return;
        }
        const range = restoreEditorSelection();
        if (range && !range.collapsed) {
          document.execCommand('createLink', false, url);
        } else {
          insertHtmlAtCursor('<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(url) + '</a> ');
        }
        linkUrlInp.value = '';
        if (linkBar) linkBar.classList.add('is-hidden');
        updateStudioStats();
      });
    }

    const newSopBtn = document.getElementById('btn-kb-new-sop');
    if (newSopBtn) {
      newSopBtn.addEventListener('click', () => openKbStudio('create', null));
    }

    if (readerEditBtn) {
      readerEditBtn.addEventListener('click', () => {
        if (activeReaderDocId && docsMap[activeReaderDocId]) {
          openKbStudio('edit', docsMap[activeReaderDocId]);
        }
      });
    }

    document.querySelectorAll('[data-kb-edit]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const docId = btn.getAttribute('data-kb-edit');
        if (docId && docsMap[docId]) {
          openKbStudio('edit', docsMap[docId]);
        }
      });
    });

    if (studioSavePdf && studioForm) {
      studioSavePdf.addEventListener('click', () => {
        studioForm.dataset.printAfterSave = '1';
        if (typeof studioForm.requestSubmit === 'function') {
          studioForm.requestSubmit();
        } else {
          studioForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
      });
    }
  }

  // ------------------------------------------------------------------------
  // Perks & Badges Controls (Multi-Choice VA Select + Photo-Only Avatar Strip)
  // ------------------------------------------------------------------------
  function initPerksControls() {
    const allSelects = Array.from(document.querySelectorAll('[data-va-multiselect]'));

    const closeAllMenus = (except) => {
      allSelects.forEach((ms) => {
        if (ms === except) return;
        const menu = ms.querySelector('[data-va-ms-menu]');
        const trig = ms.querySelector('[data-va-ms-trigger]');
        if (menu) menu.classList.add('is-hidden');
        if (trig) trig.setAttribute('aria-expanded', 'false');
      });
    };

    allSelects.forEach((ms) => {
      const trigger = ms.querySelector('[data-va-ms-trigger]');
      const triggerText = ms.querySelector('[data-va-ms-trigger-text]');
      const menu = ms.querySelector('[data-va-ms-menu]');
      const searchInp = ms.querySelector('[data-va-ms-search]');
      const avatarsTray = ms.querySelector('[data-va-ms-avatars]');
      const counter = ms.querySelector('[data-va-ms-counter]');
      const selectAllBtn = ms.querySelector('[data-select-all-vas]');
      const doneBtn = ms.querySelector('[data-va-ms-done]');
      const checkboxes = Array.from(ms.querySelectorAll('input[type="checkbox"][name="user_ids[]"]'));

      const syncMultiselectUi = () => {
        const checkedBoxes = checkboxes.filter((cb) => cb.checked);
        const count = checkedBoxes.length;

        if (counter) counter.textContent = count + ' selected';
        if (triggerText) {
          triggerText.textContent = count === 0
            ? 'Select Virtual Assistants...'
            : (count === 1 ? '1 Virtual Assistant selected' : count + ' Virtual Assistants selected');
        }

        if (avatarsTray) {
          avatarsTray.innerHTML = '';
          if (count === 0) {
            avatarsTray.classList.add('is-empty');
            const hint = document.createElement('span');
            hint.className = 'va-ms-empty-hint';
            hint.textContent = 'No VAs selected';
            avatarsTray.appendChild(hint);
          } else {
            avatarsTray.classList.remove('is-empty');
            checkedBoxes.forEach((cb) => {
              const name = cb.getAttribute('data-va-name') || 'VA';
              const avatarUrl = cb.getAttribute('data-va-avatar') || '';
              const initials = cb.getAttribute('data-va-initials') || name.slice(0, 2).toUpperCase();

              const chip = document.createElement('button');
              chip.type = 'button';
              chip.className = 'va-avatar-chip';
              chip.title = name + ' (click to remove)';
              chip.setAttribute('aria-label', 'Remove ' + name);

              if (avatarUrl) {
                const img = document.createElement('img');
                img.src = avatarUrl;
                img.alt = name;
                img.className = 'avatar';
                chip.appendChild(img);
              } else {
                const sp = document.createElement('span');
                sp.className = 'avatar';
                sp.textContent = initials;
                chip.appendChild(sp);
              }

              const rm = document.createElement('span');
              rm.className = 'va-avatar-chip-rm';
              rm.setAttribute('aria-hidden', 'true');
              rm.textContent = '×';
              chip.appendChild(rm);

              chip.addEventListener('click', () => {
                cb.checked = false;
                syncMultiselectUi();
              });
              avatarsTray.appendChild(chip);
            });
          }
        }

        if (selectAllBtn) {
          const allChecked = checkboxes.length > 0 && checkboxes.every((cb) => cb.checked);
          const span = selectAllBtn.querySelector('span');
          if (span) span.textContent = allChecked ? 'Clear All' : 'Select All';
        }
      };

      if (trigger && menu) {
        trigger.addEventListener('click', (e) => {
          e.stopPropagation();
          const willOpen = menu.classList.contains('is-hidden');
          closeAllMenus(willOpen ? ms : null);
          menu.classList.toggle('is-hidden', !willOpen);
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen && searchInp) {
            window.setTimeout(() => searchInp.focus(), 20);
          }
        });
      }

      if (doneBtn && menu && trigger) {
        doneBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          menu.classList.add('is-hidden');
          trigger.setAttribute('aria-expanded', 'false');
        });
      }

      if (searchInp) {
        searchInp.addEventListener('input', () => {
          const q = searchInp.value.trim().toLowerCase();
          ms.querySelectorAll('.va-ms-option').forEach((opt) => {
            const nm = (opt.getAttribute('data-va-option-name') || '').toLowerCase();
            opt.style.display = (q === '' || nm.includes(q)) ? '' : 'none';
          });
        });
      }

      if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
          const nextChecked = !(checkboxes.length > 0 && checkboxes.every((cb) => cb.checked));
          checkboxes.forEach((cb) => { cb.checked = nextChecked; });
          syncMultiselectUi();
        });
      }

      ms.addEventListener('workly:sync-ms', (ev) => {
        const detail = (ev && ev.detail) || {};
        const selectedSet = new Set(Array.isArray(detail.userIds) ? detail.userIds.map((id) => String(id)) : []);
        checkboxes.forEach((cb) => {
          cb.checked = selectedSet.has(String(cb.value));
        });
        if (detail.closeMenu && menu && trigger) {
          menu.classList.add('is-hidden');
          trigger.setAttribute('aria-expanded', 'false');
        }
        syncMultiselectUi();
      });

      ms.addEventListener('workly:reset-ms', () => {
        checkboxes.forEach((cb) => { cb.checked = false; });
        if (searchInp) {
          searchInp.value = '';
          ms.querySelectorAll('.va-ms-option').forEach((opt) => { opt.style.display = ''; });
        }
        if (menu) menu.classList.add('is-hidden');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        syncMultiselectUi();
      });

      checkboxes.forEach((cb) => cb.addEventListener('change', syncMultiselectUi));
      syncMultiselectUi();
    });

    if (allSelects.length > 0) {
      document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-va-multiselect]')) {
          closeAllMenus(null);
        }
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeAllMenus(null);
      });
    }

    document.querySelectorAll('[data-icon-picker]').forEach((picker) => {
      const hidden = picker.querySelector('[data-icon-value]');
      picker.addEventListener('click', (e) => {
        const opt = e.target.closest('[data-icon-option]');
        if (!opt) return;
        const iconName = opt.getAttribute('data-icon-option') || 'Medal';
        if (hidden) hidden.value = iconName;
        picker.querySelectorAll('[data-icon-option]').forEach((b) => {
          b.classList.toggle('is-selected', b === opt);
        });
      });
    });

    // Real-time Perks & Badges live DOM renderer, revoke handler & background sync
    const perksView = document.getElementById('workly-perks-view');
    if (!perksView) return;

    const kpiProgramsEl = document.getElementById('perks-kpi-programs');
    const kpiGrantsEl = document.getElementById('perks-kpi-grants');
    const kpiBadgesEl = document.getElementById('perks-kpi-badges');
    const kpiAwardsEl = document.getElementById('perks-kpi-awards');
    const grantsCountEl = document.getElementById('perks-grants-count');
    const grantsBoxEl = document.getElementById('perks-grants-container');
    const awardsCountEl = document.getElementById('perks-awards-count');
    const awardsBoxEl = document.getElementById('perks-awards-container');
    const historyCountEl = document.getElementById('perks-history-count');
    const historyBoxEl = document.getElementById('perks-history-container');
    const historySearchEl = document.getElementById('perks-history-search');

    let historyTypeFilter = 'all';
    let lastBoardPayload = null;

    function badgeIconSvg(name, size) {
      const sz = size || 12;
      const bodies = {
        Award: '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
        Medal: '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/>',
        Trophy: '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        Star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        Shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        Crown: '<path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7zm3 16h14"/>',
        Zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        Heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>'
      };
      const body = bodies[name] || bodies.Medal;
      return '<svg viewBox="0 0 24 24" width="' + sz + '" height="' + sz + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + body + '</svg>';
    }

    function smallAvatarHtml(url, name) {
      if (url) {
        return '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name || 'VA') + '" class="avatar-xs">';
      }
      const init = String(name || 'VA').trim().slice(0, 2).toUpperCase() || 'VA';
      return '<span class="avatar-xs">' + escapeHtml(init) + '</span>';
    }

    function historyEventMeta(etype) {
      switch (etype) {
        case 'perk_granted': return ['Perk Granted', 'badge-success'];
        case 'perk_revoked': return ['Perk Revoked', 'badge-danger'];
        case 'badge_awarded': return ['Badge Awarded', 'badge-brand'];
        case 'perk_created': return ['Perk Added', 'badge-info'];
        case 'perk_deleted': return ['Perk Deleted', 'badge-neutral'];
        case 'badge_created': return ['Badge Created', 'badge-info'];
        default: return ['Recorded', 'badge-neutral'];
      }
    }

    function renderPerksBoard(board, opts) {
      if (!board) return;
      const options = opts || {};
      lastBoardPayload = board;
      const canManage = perksView.getAttribute('data-can-manage') === '1' || !!board.can_manage;
      const perksList = Array.isArray(board.perks) ? board.perks : [];
      const badgesList = Array.isArray(board.badges) ? board.badges : [];
      const grants = Array.isArray(board.grants) ? board.grants : [];
      const awards = Array.isArray(board.awards) ? board.awards : [];
      const historyAll = Array.isArray(board.history) ? board.history : [];

      if (kpiProgramsEl) kpiProgramsEl.textContent = String(perksList.length);
      if (kpiGrantsEl) kpiGrantsEl.textContent = String(grants.length);
      if (kpiBadgesEl) kpiBadgesEl.textContent = String(badgesList.length);
      if (kpiAwardsEl) kpiAwardsEl.textContent = String(awards.length);

      const tabCountPerks = perksView.querySelector('[data-perks-tab-count="perks"]');
      const tabCountBadges = perksView.querySelector('[data-perks-tab-count="badges"]');
      const tabCountGrants = perksView.querySelector('[data-perks-tab-count="grants"]');
      const tabCountHistory = perksView.querySelector('[data-perks-tab-count="history"]');
      if (tabCountPerks) tabCountPerks.textContent = String(perksList.length);
      if (tabCountBadges) tabCountBadges.textContent = String(badgesList.length);
      if (tabCountGrants) tabCountGrants.textContent = String(grants.length + awards.length);
      if (tabCountHistory) tabCountHistory.textContent = String(historyAll.length);

      const grantsByPerk = {};
      grants.forEach((g) => {
        const pid = String(g.perk_id || '');
        if (!pid) return;
        if (!grantsByPerk[pid]) grantsByPerk[pid] = [];
        grantsByPerk[pid].push(g);
      });

      const awardsByBadge = {};
      awards.forEach((aw) => {
        const bid = String(aw.badge_id || '');
        if (!bid) return;
        if (!awardsByBadge[bid]) awardsByBadge[bid] = [];
        awardsByBadge[bid].push(aw);
      });

      // Update inside-card Active Recipients avatar strips for every Perk card
      perksView.querySelectorAll('[data-perk-holders]').forEach((strip) => {
        const pid = strip.getAttribute('data-perk-holders') || '';
        const holders = grantsByPerk[pid] || [];
        const cntEl = perksView.querySelector('[data-perk-holder-count="' + CSS.escape(pid) + '"]');
        if (cntEl) cntEl.textContent = holders.length + ' active';
        if (holders.length === 0) {
          strip.innerHTML = '<span class="perk-holders-empty">No VAs currently hold this perk</span>';
        } else {
          strip.innerHTML = holders.map((g) => {
            const rmBtn = canManage
              ? '<button type="button" class="perk-holder-revoke" title="Revoke perk from ' + escapeHtml(g.va_name) + '" aria-label="Revoke perk from ' + escapeHtml(g.va_name) + '" data-perk-revoke="/api/v1/perks/' + encodeURIComponent(pid) + '/grant?user_id=' + encodeURIComponent(g.user_id) + '">×</button>'
              : '';
            return '<span class="perk-holder-chip" title="' + escapeHtml(g.va_name) + '">' +
              smallAvatarHtml(g.va_avatar, g.va_name) + rmBtn +
            '</span>';
          }).join('');
        }
      });

      // Update inside-card Awarded To avatar strips for every Badge card
      perksView.querySelectorAll('[data-badge-holders]').forEach((strip) => {
        const bid = strip.getAttribute('data-badge-holders') || '';
        const holders = awardsByBadge[bid] || [];
        const cntEl = perksView.querySelector('[data-badge-holder-count="' + CSS.escape(bid) + '"]');
        if (cntEl) cntEl.textContent = holders.length + ' earned';
        if (holders.length === 0) {
          strip.innerHTML = '<span class="perk-holders-empty">No VAs have earned this badge yet</span>';
        } else {
          strip.innerHTML = holders.map((aw) => {
            const dt = String(aw.earned_at || '').slice(0, 10);
            const tip = aw.va_name + (dt ? ' · Earned ' + dt : '');
            return '<span class="perk-holder-chip" title="' + escapeHtml(tip) + '">' +
              smallAvatarHtml(aw.va_avatar, aw.va_name) +
            '</span>';
          }).join('');
        }
      });

      // Keep "Select VA Recipients" checkboxes & photo tray synced with persisted DB state
      // (skip overwriting if user currently has that specific dropdown menu open)
      allSelects.forEach((ms) => {
        const kind = ms.getAttribute('data-ms-kind') || '';
        const itemId = ms.getAttribute('data-ms-item-id') || '';
        if (!kind || !itemId) return;
        const menu = ms.querySelector('[data-va-ms-menu]');
        const isMenuOpen = menu && !menu.classList.contains('is-hidden');
        if (isMenuOpen && !options.forceSyncOpenMenus) return;

        const targetList = kind === 'badge' ? (awardsByBadge[itemId] || []) : (grantsByPerk[itemId] || []);
        const userIds = targetList.map((row) => String(row.user_id));
        ms.dispatchEvent(new CustomEvent('workly:sync-ms', {
          detail: { userIds, closeMenu: !!options.closeMenus }
        }));
      });

      if (grantsCountEl) grantsCountEl.textContent = grants.length + ' active';
      if (grantsBoxEl) {
        if (grants.length === 0) {
          grantsBoxEl.innerHTML = '<p class="muted">No staff benefit grants issued yet.</p>';
        } else {
          grantsBoxEl.innerHTML = '<div class="table-wrap"><table class="table"><thead><tr><th>Virtual Assistant</th><th>Perk Benefit</th><th>Action</th></tr></thead><tbody>' +
            grants.map((g) => {
              const actionCell = canManage
                ? '<button type="button" class="btn btn-danger btn-sm" data-perk-revoke="/api/v1/perks/' + encodeURIComponent(g.perk_id) + '/grant?user_id=' + encodeURIComponent(g.user_id) + '">Revoke</button>'
                : '<span class="muted">Active</span>';
              return '<tr>' +
                '<td><div style="display:flex;align-items:center;gap:8px;">' + smallAvatarHtml(g.va_avatar, g.va_name) + '<strong>' + escapeHtml(g.va_name) + '</strong></div></td>' +
                '<td><span class="badge badge-success">' + escapeHtml(g.perk_title || g.perk_id) + '</span></td>' +
                '<td>' + actionCell + '</td>' +
                '</tr>';
            }).join('') +
            '</tbody></table></div>';
        }
      }

      if (awardsCountEl) awardsCountEl.textContent = awards.length + ' awarded';
      if (awardsBoxEl) {
        if (awards.length === 0) {
          awardsBoxEl.innerHTML = '<p class="muted">No recognition badges awarded yet.</p>';
        } else {
          awardsBoxEl.innerHTML = '<div style="display:grid;gap:8px;">' +
            awards.map((aw) => {
              const tierSlug = String(aw.tier || 'silver').toLowerCase().replace(/[^a-z]/g, '') || 'silver';
              const dateLabel = String(aw.earned_at || '').slice(0, 10);
              return '<div class="perk-award-row">' +
                '<div style="display:flex;align-items:center;gap:9px;min-width:0;flex-wrap:wrap;">' +
                  smallAvatarHtml(aw.va_avatar, aw.va_name) +
                  '<span style="font-size:13px;"><strong>' + escapeHtml(aw.va_name) + '</strong> earned ' +
                    '<span class="badge tier-' + escapeHtml(tierSlug) + '">' + badgeIconSvg(aw.icon_name || 'Medal', 12) + ' ' + escapeHtml(aw.badge_title) + '</span>' +
                  '</span>' +
                '</div>' +
                '<span class="muted mono tabular-nums" style="font-size:11px;">' + escapeHtml(dateLabel) + '</span>' +
              '</div>';
            }).join('') +
            '</div>';
        }
      }

      const hQuery = historySearchEl ? historySearchEl.value.trim().toLowerCase() : '';
      const filteredHistory = historyAll.filter((ev) => {
        const matchType = historyTypeFilter === 'all' || String(ev.item_type || '') === historyTypeFilter;
        if (!matchType) return false;
        if (hQuery === '') return true;
        const hay = ((ev.item_title || '') + ' ' + (ev.va_name || '') + ' ' + (ev.actor_name || '')).toLowerCase();
        return hay.includes(hQuery);
      });

      if (historyCountEl) historyCountEl.textContent = filteredHistory.length + ' event(s)';
      if (historyBoxEl) {
        if (filteredHistory.length === 0) {
          historyBoxEl.innerHTML = '<div class="collab-empty">No matching perk or badge history events recorded yet.</div>';
        } else {
          historyBoxEl.innerHTML = '<div class="table-wrap"><table class="table"><thead><tr><th>Event</th><th>Perk / Badge</th><th>Virtual Assistant</th><th>Assigned By</th><th>Recorded</th></tr></thead><tbody>' +
            filteredHistory.map((ev) => {
              const meta = historyEventMeta(ev.event_type);
              const vaCell = ev.va_name
                ? '<div style="display:flex;align-items:center;gap:8px;">' + smallAvatarHtml(ev.va_avatar, ev.va_name) + '<span>' + escapeHtml(ev.va_name) + '</span></div>'
                : '<span class="muted">Catalog Update</span>';
              const subMeta = ev.item_meta
                ? '<div class="muted" style="font-size:11px;">' + escapeHtml(ev.item_meta) + '</div>'
                : '';
              const hay = ((ev.item_title || '') + ' ' + (ev.va_name || '') + ' ' + (ev.actor_name || '')).toLowerCase();
              return '<tr data-history-item-type="' + escapeHtml(ev.item_type || 'perk') + '" data-history-search="' + escapeHtml(hay) + '">' +
                '<td><span class="badge ' + meta[1] + '">' + escapeHtml(meta[0]) + '</span></td>' +
                '<td><strong>' + escapeHtml(ev.item_title) + '</strong>' + subMeta + '</td>' +
                '<td>' + vaCell + '</td>' +
                '<td>' + escapeHtml(ev.actor_name || 'Staff') + '</td>' +
                '<td class="muted mono tabular-nums" style="font-size:11.5px;">' + escapeHtml(String(ev.created_at || '').slice(0, 16)) + '</td>' +
              '</tr>';
            }).join('') +
            '</tbody></table></div>';
        }
      }
    }

    window.worklyRenderPerksBoard = renderPerksBoard;

    // Unified click delegation inside #workly-perks-view for inline card revoke, table revoke, and perk deletion
    perksView.addEventListener('click', async (e) => {
      const revokeBtn = e.target.closest('[data-perk-revoke]');
      if (revokeBtn) {
        const url = revokeBtn.getAttribute('data-perk-revoke');
        if (!url) return;
        try {
          revokeBtn.disabled = true;
          const res = await apiFetch(url, { method: 'DELETE' });
          if (res && res.board) renderPerksBoard(res.board, { forceSyncOpenMenus: true });
          showToast('Perk revoked and logged to history', 'success');
        } catch (err) {
          showToast(err.message, 'error');
          revokeBtn.disabled = false;
        }
        return;
      }

      const delPerkBtn = e.target.closest('[data-perk-delete-url]');
      if (delPerkBtn) {
        const url = delPerkBtn.getAttribute('data-perk-delete-url');
        const pid = delPerkBtn.getAttribute('data-perk-delete-id');
        if (!url) return;
        try {
          delPerkBtn.disabled = true;
          const res = await apiFetch(url, { method: 'DELETE' });
          if (pid) {
            const card = perksView.querySelector('[data-perk-card="' + CSS.escape(pid) + '"]');
            if (card) card.remove();
          }
          if (res && res.board) renderPerksBoard(res.board, { forceSyncOpenMenus: true });
          showToast('Perk program deleted', 'success');
        } catch (err) {
          showToast(err.message, 'error');
          delPerkBtn.disabled = false;
        }
      }
    });

    function applyDomHistoryFilter() {
      if (lastBoardPayload) {
        renderPerksBoard(lastBoardPayload);
        return;
      }
      if (!historyBoxEl) return;
      const q = historySearchEl ? historySearchEl.value.trim().toLowerCase() : '';
      const rows = Array.from(historyBoxEl.querySelectorAll('tr[data-history-item-type]'));
      let visibleCount = 0;
      rows.forEach((tr) => {
        const matchType = historyTypeFilter === 'all' || tr.getAttribute('data-history-item-type') === historyTypeFilter;
        const hay = (tr.getAttribute('data-history-search') || '').toLowerCase();
        const matchQuery = q === '' || hay.includes(q);
        const show = matchType && matchQuery;
        tr.style.display = show ? '' : 'none';
        if (show) visibleCount++;
      });
      if (historyCountEl) historyCountEl.textContent = visibleCount + ' event(s)';
    }

    perksView.querySelectorAll('[data-perks-history-filter]').forEach((chip) => {
      chip.addEventListener('click', () => {
        historyTypeFilter = chip.getAttribute('data-perks-history-filter') || 'all';
        perksView.querySelectorAll('[data-perks-history-filter]').forEach((c) => c.classList.toggle('is-active', c === chip));
        applyDomHistoryFilter();
      });
    });

    if (historySearchEl) {
      historySearchEl.addEventListener('input', applyDomHistoryFilter);
    }

    async function refreshPerksLive() {
      if (document.hidden) return;
      try {
        const res = await fetch('/api/v1/perks', {
          method: 'GET',
          headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && Array.isArray(data.grants)) {
          renderPerksBoard(data);
        }
      } catch (_err) {
      }
    }

    window.setInterval(refreshPerksLive, 12000);
  }

  // ------------------------------------------------------------------------
  // HRM & Admin PTO Controls (Rejection Drawer, VA Search, Edit Free Days)
  // ------------------------------------------------------------------------
  function initHrmPtoRejection() {
    document.querySelectorAll('[data-pto-reject]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const reqId = btn.getAttribute('data-pto-reject');
        const vaName = btn.getAttribute('data-pto-va') || 'Staff Member';
        const drawer = document.getElementById('drawer-pto-reject');
        if (!drawer || !reqId) return;
        const form = drawer.querySelector('#form-pto-reject');
        const label = drawer.querySelector('#pto-reject-target');
        if (label) label.textContent = vaName;
        if (form) {
          form.setAttribute('data-api-form', '/api/v1/pto/' + encodeURIComponent(reqId) + '/review');
        }
        openDrawer('drawer-pto-reject');
      });
    });

    const ptoSearch = document.getElementById('pto-va-search');
    if (ptoSearch) {
      ptoSearch.addEventListener('input', () => {
        const q = ptoSearch.value.trim().toLowerCase();
        document.querySelectorAll('.pto-va-row, .pto-req-row').forEach((row) => {
          const hay = (row.getAttribute('data-va-name') || '').toLowerCase();
          row.style.display = (q === '' || hay.includes(q)) ? '' : 'none';
        });
      });
    }

    document.querySelectorAll('[data-edit-pto-va]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const vaId = btn.getAttribute('data-edit-pto-va');
        const vaName = btn.getAttribute('data-va-label') || 'Virtual Assistant';
        const bal = btn.getAttribute('data-va-balance') || '15';
        const taken = btn.getAttribute('data-va-taken') || '0';
        const drawer = document.getElementById('drawer-edit-pto-balance');
        if (!drawer || !vaId) return;

        const targetLbl = drawer.querySelector('#pto-balance-target');
        if (targetLbl) targetLbl.textContent = vaName;
        const form = drawer.querySelector('#form-edit-pto-balance');
        if (form) {
          form.setAttribute('data-api-form', '/api/v1/pto/balances/' + encodeURIComponent(vaId));
          const bInp = form.querySelector('[name="pto_balance_days"]');
          const tInp = form.querySelector('[name="pto_taken_days"]');
          if (bInp) bInp.value = bal;
          if (tInp) tInp.value = taken;
        }
        openDrawer('drawer-edit-pto-balance');
      });
    });
  }

  // ------------------------------------------------------------------------
  // Visual PTO Approval Calendar (Dynamic Fetch for Managers)
  // ------------------------------------------------------------------------
  function initPtoApprovalCalendar() {
    const calRoot = document.querySelector('[data-pto-calendar]');
    if (!calRoot) return;

    const gridWrap = calRoot.querySelector('[data-pto-cal-grid]');
    const titleEl = calRoot.querySelector('[data-pto-cal-title]');
    const summaryEl = calRoot.querySelector('[data-pto-cal-summary]');
    const upcomingEl = calRoot.querySelector('[data-pto-cal-upcoming]');
    const upcomingCountEl = calRoot.querySelector('[data-pto-upcoming-count]');

    let currentMonth = calRoot.getAttribute('data-initial-month') || new Date().toISOString().slice(0, 7);
    let prevMonth = currentMonth;
    let nextMonth = currentMonth;
    let statusFilter = 'all';
    let lastPayload = null;

    function ymd(y, m, d) {
      return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function renderCalendarView(data) {
      lastPayload = data;
      prevMonth = data.prev_month || currentMonth;
      nextMonth = data.next_month || currentMonth;
      if (titleEl) titleEl.textContent = data.month_label || currentMonth;

      const rawRequests = Array.isArray(data.requests) ? data.requests : [];
      const rawUpcoming = Array.isArray(data.upcoming) ? data.upcoming : [];

      const requests = statusFilter === 'all'
        ? rawRequests
        : rawRequests.filter((r) => r.status === statusFilter);
      const upcoming = statusFilter === 'all'
        ? rawUpcoming
        : rawUpcoming.filter((r) => r.status === statusFilter);

      const pendingCount = rawRequests.filter((r) => r.status === 'pending').length;
      const approvedCount = rawRequests.filter((r) => r.status === 'approved').length;
      if (summaryEl) {
        summaryEl.textContent = approvedCount + ' approved · ' + pendingCount + ' pending this month';
      }

      // Build Mon–Sun calendar cells
      const parts = String(data.month || currentMonth).split('-');
      const yr = parseInt(parts[0], 10);
      const mo = parseInt(parts[1], 10); // 1-12
      const firstDate = new Date(yr, mo - 1, 1);
      const daysInMonth = new Date(yr, mo, 0).getDate();
      const jsDow = firstDate.getDay(); // 0=Sun..6=Sat
      const leadingBlanks = (jsDow === 0) ? 6 : (jsDow - 1);
      const totalCells = Math.ceil((leadingBlanks + daysInMonth) / 7) * 7;
      const todayIso = new Date().toISOString().slice(0, 10);

      let html = '<div class="pto-cal-grid">';
      ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].forEach((dow) => {
        html += '<div class="pto-cal-dow">' + dow + '</div>';
      });

      for (let idx = 0; idx < totalCells; idx++) {
        const dayNum = idx - leadingBlanks + 1;
        if (dayNum < 1 || dayNum > daysInMonth) {
          html += '<div class="pto-cal-day is-outside"></div>';
          continue;
        }
        const dateStr = ymd(yr, mo, dayNum);
        const isToday = (dateStr === todayIso);
        const dayReqs = requests.filter((r) => r.start_date <= dateStr && r.end_date >= dateStr);
        const dowIdx = idx % 7;
        const isWeekend = (dowIdx === 5 || dowIdx === 6);

        html += '<div class="pto-cal-day' + (isToday ? ' is-today' : '') + (isWeekend ? ' is-weekend' : '') + '">';
        html += '<div class="pto-cal-day-head"><span class="pto-cal-day-num tabular-nums">' + dayNum + '</span>';
        if (dayReqs.length > 1) {
          html += '<span class="pto-overlap-flag" title="Multiple VAs scheduled off">Overlap</span>';
        }
        html += '</div>';

        html += '<div class="pto-cal-chips">';
        dayReqs.slice(0, 3).forEach((req) => {
          const firstName = String(req.va_name || 'VA').split(' ')[0];
          const tone = req.status === 'approved' ? 'is-approved' : (req.status === 'rejected' ? 'is-rejected' : 'is-pending');
          const tip = req.va_name + ' (' + req.status + ' · ' + req.business_days_count + 'd): ' + (req.reason || '');
          html += '<div class="pto-cal-chip ' + tone + '" title="' + escapeHtml(tip) + '">' +
            '<span>' + escapeHtml(firstName) + '</span>' +
            '</div>';
        });
        if (dayReqs.length > 3) {
          html += '<div class="pto-cal-more">+' + (dayReqs.length - 3) + ' more</div>';
        }
        html += '</div></div>';
      }
      html += '</div>';
      if (gridWrap) gridWrap.innerHTML = html;

      // Render Upcoming Time-Off Agenda
      if (upcomingCountEl) upcomingCountEl.textContent = upcoming.length + ' upcoming';
      if (upcomingEl) {
        if (upcoming.length === 0) {
          upcomingEl.innerHTML = '<div class="collab-empty">No upcoming pending or approved PTO requests found.</div>';
        } else {
          upcomingEl.innerHTML = upcoming.map((u) => {
            const stCls = u.status === 'approved' ? 'badge-success' : (u.status === 'rejected' ? 'badge-danger' : 'badge-warn');
            const overlaps = Array.isArray(u.overlaps) && u.overlaps.length > 0
              ? '<div class="pto-agenda-overlap">Overlaps with: ' + escapeHtml(u.overlaps.join(', ')) + '</div>'
              : '';
            const actions = (data.can_approve && u.status === 'pending')
              ? '<div class="pto-agenda-actions">' +
                  '<button type="button" class="btn btn-primary btn-sm" data-cal-approve="' + escapeHtml(u.id) + '">Approve</button>' +
                  '<button type="button" class="btn btn-danger btn-sm" data-cal-decline="' + escapeHtml(u.id) + '" data-cal-va="' + escapeHtml(u.va_name) + '">Decline</button>' +
                '</div>'
              : '';
            return '<article class="pto-agenda-item">' +
              '<div class="pto-agenda-top">' +
                '<div style="display:flex;align-items:center;gap:8px;min-width:0;">' +
                  renderSmallAvatarHtml(u.avatar_url, u.va_name) +
                  '<div style="min-width:0;">' +
                    '<strong style="display:block;font-size:13px;">' + escapeHtml(u.va_name) + '</strong>' +
                    '<span class="muted mono" style="font-size:11px;">' + escapeHtml(u.start_date) + ' &rarr; ' + escapeHtml(u.end_date) + ' (' + escapeHtml(u.business_days_count) + 'd)</span>' +
                  '</div>' +
                '</div>' +
                '<span class="badge ' + stCls + '">' + escapeHtml(u.status.charAt(0).toUpperCase() + u.status.slice(1)) + '</span>' +
              '</div>' +
              '<p class="muted" style="font-size:12px;margin:6px 0 0;">' + escapeHtml(u.reason || '') + '</p>' +
              overlaps +
              actions +
            '</article>';
          }).join('');
        }
      }
    }

    async function loadPtoCalendar(month) {
      currentMonth = month;
      try {
        const data = await apiFetch('/api/v1/pto/calendar?month=' + encodeURIComponent(month), { method: 'GET' });
        if (data && data.success) {
          renderCalendarView(data);
        }
      } catch (err) {
        if (gridWrap) {
          gridWrap.innerHTML = '<div class="notice notice-error">' + escapeHtml(err.message || 'Unable to load PTO calendar') + '</div>';
        }
      }
    }

    calRoot.querySelectorAll('[data-pto-cal-nav]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const dir = btn.getAttribute('data-pto-cal-nav');
        if (dir === 'prev') loadPtoCalendar(prevMonth);
        else if (dir === 'next') loadPtoCalendar(nextMonth);
        else loadPtoCalendar(new Date().toISOString().slice(0, 7));
      });
    });

    calRoot.querySelectorAll('[data-pto-cal-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        statusFilter = btn.getAttribute('data-pto-cal-filter') || 'all';
        calRoot.querySelectorAll('[data-pto-cal-filter]').forEach((b) => b.classList.toggle('is-active', b === btn));
        if (lastPayload) renderCalendarView(lastPayload);
      });
    });

    if (upcomingEl) {
      upcomingEl.addEventListener('click', async (e) => {
        const approveBtn = e.target.closest('[data-cal-approve]');
        if (approveBtn) {
          const reqId = approveBtn.getAttribute('data-cal-approve');
          if (!reqId) return;
          try {
            approveBtn.disabled = true;
            await apiFetch('/api/v1/pto/' + encodeURIComponent(reqId) + '/review', {
              method: 'PATCH',
              body: JSON.stringify({ status: 'approved' })
            });
            showToast('PTO request approved', 'success');
            loadPtoCalendar(currentMonth);
          } catch (err) {
            showToast(err.message, 'error');
            approveBtn.disabled = false;
          }
          return;
        }

        const declineBtn = e.target.closest('[data-cal-decline]');
        if (declineBtn) {
          const reqId = declineBtn.getAttribute('data-cal-decline');
          const vaName = declineBtn.getAttribute('data-cal-va') || 'Staff Member';
          const drawer = document.getElementById('drawer-pto-reject');
          if (drawer && reqId) {
            const form = drawer.querySelector('#form-pto-reject');
            const label = drawer.querySelector('#pto-reject-target');
            if (label) label.textContent = vaName;
            if (form) {
              form.setAttribute('data-api-form', '/api/v1/pto/' + encodeURIComponent(reqId) + '/review');
            }
            openDrawer('drawer-pto-reject');
          }
        }
      });
    }

    loadPtoCalendar(currentMonth);
  }

  // ------------------------------------------------------------------------
  // Client-Side CSV Export of Rendered Tables
  // ------------------------------------------------------------------------
  function initCsvExports() {
    document.querySelectorAll('[data-export-csv]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tableId = btn.getAttribute('data-export-csv');
        const fileName = btn.getAttribute('data-csv-filename') || 'workly_export.csv';
        const table = tableId ? document.getElementById(tableId) : null;
        if (!table) return;

        const rows = [];
        table.querySelectorAll('tr').forEach((tr) => {
          const cols = [];
          tr.querySelectorAll('th, td').forEach((cell) => {
            const text = (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim();
            cols.push('"' + text.replace(/"/g, '""') + '"');
          });
          if (cols.length > 0) rows.push(cols.join(','));
        });

        const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('CSV exported', 'success');
      });
    });
  }

  // ------------------------------------------------------------------------
  // AJAX Form Submissions Across Modules
  // ------------------------------------------------------------------------
  function initForms() {
    document.querySelectorAll('[data-auto-submit]').forEach((el) => {
      el.addEventListener('change', () => {
        if (el.form) {
          WorklyLoader.showSkeleton();
          el.form.submit();
        }
      });
    });

    document.querySelectorAll('form[data-api-form]').forEach((form) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const endpoint = form.getAttribute('data-api-form');
        const method = (form.getAttribute('data-api-method') || 'POST').toUpperCase();
        const isMultipart = form.enctype === 'multipart/form-data';
        const submitBtn = form.querySelector('button[type="submit"]');

        // Sync Knowledge Base Document Studio content before serializing
        if (form.hasAttribute('data-kb-studio-form')) {
          const editor = form.querySelector('[data-kb-editor]');
          const hiddenInp = form.querySelector('[data-kb-input]');
          if (editor && hiddenInp) {
            hiddenInp.value = editor.innerHTML.trim();
            if (editor.textContent.trim() === '' && hiddenInp.value === '') {
              form.dataset.printAfterSave = '0';
              showToast('Please enter SOP document content', 'error');
              return;
            }
          }
        }

        if (submitBtn) submitBtn.disabled = true;

        try {
          if (isMultipart) {
            const fd = new FormData(form);
            await apiFetch(endpoint, { method, body: fd });
          } else {
            const fd = new FormData(form);
            const payload = {};

            fd.forEach((val, key) => {
              if (key === 'task_id') return;
              if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                if (!Array.isArray(payload[cleanKey])) payload[cleanKey] = [];
                payload[cleanKey].push(val);
              } else {
                payload[key] = val;
              }
            });

            // Handle checkboxes (arrays keep array format; single checkboxes map boolean)
            const arrayCheckboxKeys = new Set();
            form.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
              if (!cb.name) return;
              if (cb.name.endsWith('[]')) {
                arrayCheckboxKeys.add(cb.name.slice(0, -2));
              } else {
                payload[cb.name] = cb.checked;
              }
            });
            arrayCheckboxKeys.forEach((k) => {
              if (!Array.isArray(payload[k])) payload[k] = [];
            });

            // Collect weekly client availability slots when present in form
            const slotsContainer = form.querySelector('[data-slots-container]');
            if (slotsContainer) {
              const collectedSlots = [];
              slotsContainer.querySelectorAll('[data-slot-row]').forEach((row) => {
                const d = (row.querySelector('[data-slot-day]') || {}).value || '';
                const s = (row.querySelector('[data-slot-start]') || {}).value || '';
                const en = (row.querySelector('[data-slot-end]') || {}).value || '';
                if (d && s && en) {
                  collectedSlots.push({ day: d, start: s, end: en });
                }
              });
              payload.availability_slots = collectedSlots;
            }

            if (typeof payload.specialties_csv === 'string') {
              payload.specialties = payload.specialties_csv.split(',').map((s) => s.trim()).filter(Boolean);
              delete payload.specialties_csv;
            }
            if (typeof payload.responsibilities_text === 'string') {
              payload.responsibilities = payload.responsibilities_text.split('\n').map((s) => s.trim()).filter(Boolean);
              delete payload.responsibilities_text;
            }

            let targetUrl = endpoint;
            let resolvedTid = '';
            if (targetUrl.includes('{task_id}')) {
              const tidInput = form.querySelector('input[name="task_id"]');
              resolvedTid = tidInput ? tidInput.value : '';
              targetUrl = targetUrl.replace('{task_id}', encodeURIComponent(resolvedTid));
            }

            if (form.hasAttribute('data-perk-assign-form') && !payload.sync_recipients && (!Array.isArray(payload.user_ids) || payload.user_ids.length === 0)) {
              showToast('Select at least one Virtual Assistant recipient first', 'error');
              if (submitBtn) submitBtn.disabled = false;
              return;
            }

            const res = await apiFetch(targetUrl, {
              method,
              body: JSON.stringify(payload)
            });

            // Upload optional client logo selected on Create Client form
            if (form.id === 'form-create-client' && res && res.id) {
              const logoInp = document.getElementById('create-client-avatar-file');
              if (logoInp && logoInp.files && logoInp.files[0]) {
                const logoFd = new FormData();
                logoFd.append('client_id', res.id);
                logoFd.append('avatar', logoInp.files[0]);
                await apiFetch('/api/v1/uploads/client-avatar', {
                  method: 'POST',
                  body: logoFd
                });
              }
            }

            // Real-time no-reload update for Perks & Badges assignments (keeps synced recipients checked!)
            if (form.hasAttribute('data-no-reload') && form.hasAttribute('data-perk-assign-form')) {
              if (res && res.board && typeof window.worklyRenderPerksBoard === 'function') {
                window.worklyRenderPerksBoard(res.board, { forceSyncOpenMenus: true, closeMenus: true });
              }
              const added = res && typeof res.granted_count === 'number' ? res.granted_count : 0;
              const removed = res && typeof res.revoked_count === 'number' ? res.revoked_count : 0;
              const total = res && typeof res.count === 'number' ? res.count : 0;
              const kind = form.getAttribute('data-perk-assign-form') === 'badge' ? 'Badge recipients' : 'Perk recipients';
              const msg = (added > 0 || removed > 0)
                ? (kind + ' updated (' + total + ' active)')
                : (kind + ' synchronized (' + total + ' active)');
              showToast(msg, 'success');
              if (submitBtn) submitBtn.disabled = false;
              return;
            }

            // Real-time no-reload update for task notes and comments forms
            if (form.hasAttribute('data-no-reload') && resolvedTid) {
              const ta = form.querySelector('textarea');
              if (ta) ta.value = '';
              if (res && res.item) {
                const kind = targetUrl.includes('/notes') ? 'note' : 'comment';
                appendCollabItemLocal(resolvedTid, kind, res.item);
              }
              refreshTaskActivityLive(resolvedTid);
              showToast('Posted live update', 'success');
              if (submitBtn) submitBtn.disabled = false;
              return;
            }
          }

          if (form.hasAttribute('data-kb-studio-form') && form.dataset.printAfterSave === '1') {
            form.dataset.printAfterSave = '0';
            const clSel = form.querySelector('[name="client_id"]');
            const clName = (clSel && clSel.value && clSel.selectedIndex >= 0) ? clSel.options[clSel.selectedIndex].text : '';
            const savedDoc = {
              title: (form.querySelector('[name="title"]') || {}).value || 'SOP Document',
              category: (form.querySelector('[name="category"]') || {}).value || 'SOP',
              client_name: clName,
              author: 'Workly Operations',
              last_updated: new Date().toISOString().slice(0, 10),
              content: ((form.querySelector('[data-kb-input]') || {}).value) || ''
            };
            closeAllModals(true);
            showToast('Document saved — preparing PDF export...', 'success');
            printSopDocument(savedDoc);
            window.addEventListener('afterprint', () => {
              window.setTimeout(() => window.location.reload(), 250);
            }, { once: true });
            return;
          }

          showToast('Saved successfully', 'success');
          window.setTimeout(() => window.location.reload(), 380);
        } catch (err) {
          if (form.dataset) form.dataset.printAfterSave = '0';
          showToast(err.message, 'error');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });

    const ptoStart = document.getElementById('pto-start');
    const ptoEnd = document.getElementById('pto-end');
    const ptoPreview = document.getElementById('pto-days-preview');
    if (ptoStart && ptoEnd && ptoPreview) {
      const recalc = () => {
        if (!ptoStart.value || !ptoEnd.value) {
          ptoPreview.textContent = 'Select start and end dates (Mon–Fri, max 5 business days).';
          return;
        }
        const s = new Date(ptoStart.value + 'T00:00:00');
        const e = new Date(ptoEnd.value + 'T00:00:00');
        if (e < s) {
          ptoPreview.textContent = 'End date must be on or after start date.';
          return;
        }
        let days = 0;
        const cur = new Date(s);
        while (cur <= e) {
          const dow = cur.getDay();
          if (dow >= 1 && dow <= 5) days++;
          cur.setDate(cur.getDate() + 1);
        }
        ptoPreview.textContent = 'Calculated business days (Mon–Fri): ' + days + (days > 5 ? ' (Exceeds 5-day policy)' : '');
      };
      ptoStart.addEventListener('change', recalc);
      ptoEnd.addEventListener('change', recalc);
    }
  }

  // ------------------------------------------------------------------------
  // Interactive Buttons (Status Toggles, Audit Log Filter, Print PDF)
  // ------------------------------------------------------------------------
  function initInteractiveButtons() {
    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action-url]');
      if (!btn) return;
      const url = btn.getAttribute('data-action-url');
      const method = btn.getAttribute('data-action-method') || 'POST';
      const bodyRaw = btn.getAttribute('data-action-body');

      try {
        btn.disabled = true;
        await apiFetch(url, { method, body: bodyRaw || undefined });
        showToast('Updated successfully', 'success');
        window.setTimeout(() => window.location.reload(), 340);
      } catch (err) {
        showToast(err.message, 'error');
        btn.disabled = false;
      }
    });

    const printBtn = document.getElementById('btn-print-sla');
    if (printBtn) {
      printBtn.addEventListener('click', () => {
        activateTab('cal-rep', 'reports');
        window.setTimeout(() => window.print(), 120);
      });
    }

    const auditFilterBtn = document.getElementById('btn-filter-audit');
    if (auditFilterBtn) {
      auditFilterBtn.addEventListener('click', async () => {
        const month = (document.getElementById('audit-month-filter') || {}).value || '';
        const uid = (document.getElementById('audit-user-filter') || {}).value || '';
        const box = document.getElementById('audit-log-viewer');
        if (!box) return;
        try {
          const res = await apiFetch('/api/v1/logs?month=' + encodeURIComponent(month) + '&user_id=' + encodeURIComponent(uid), {
            method: 'GET'
          });
          box.textContent = res.raw || 'No audit log entries match the selected filter.';
        } catch (err) {
          showToast(err.message, 'error');
        }
      });
    }
  }

  // ------------------------------------------------------------------------
  // Onboarding Spotlight Tour
  // ------------------------------------------------------------------------
  function initOnboardingTour() {
    const startBtn = document.getElementById('btn-start-tour');
    if (!startBtn) return;

    const steps = [
      { sel: '#workly-sidebar', title: 'Role-Scoped Workspace', text: 'Navigate modules tailored to your role with live review & PTO alerts.' },
      { sel: '#workly-view-content', title: 'Clean Operations Hub', text: 'Organize Kanban tasks by Client Swimlanes, scan client-synced open slots, press Ctrl+K for Quick Jump, and use right-side drawers.' },
      { sel: '#btn-profile-menu', title: 'Header Notifications & Profile', text: 'Use the header bell for incoming alerts and click your profile pill to open My Profile or Sign Out.' }
    ];

    let idx = 0;
    const overlay = document.getElementById('tour-overlay');
    const tooltip = document.getElementById('tour-tooltip');

    function cleanup() {
      document.querySelectorAll('.tour-highlight').forEach((el) => el.classList.remove('tour-highlight'));
      if (overlay) overlay.classList.add('is-hidden');
      if (tooltip) tooltip.classList.add('is-hidden');
    }

    function showStep(i) {
      document.querySelectorAll('.tour-highlight').forEach((el) => el.classList.remove('tour-highlight'));
      if (i >= steps.length) {
        cleanup();
        return;
      }
      const step = steps[i];
      const target = document.querySelector(step.sel);
      if (!target || !overlay || !tooltip) {
        cleanup();
        return;
      }
      overlay.classList.remove('is-hidden');
      tooltip.classList.remove('is-hidden');
      target.classList.add('tour-highlight');

      const rect = target.getBoundingClientRect();
      tooltip.style.top = Math.min(window.innerHeight - 190, Math.max(16, rect.top + 16)) + 'px';
      tooltip.style.left = Math.min(window.innerWidth - 340, Math.max(16, rect.left + 20)) + 'px';

      tooltip.querySelector('#tour-title').textContent = step.title;
      tooltip.querySelector('#tour-desc').textContent = step.text;
      tooltip.querySelector('#tour-progress').textContent = 'Step ' + (i + 1) + ' of ' + steps.length;
    }

    startBtn.addEventListener('click', () => { idx = 0; showStep(0); });
    const nextBtn = document.getElementById('tour-next');
    const closeBtn = document.getElementById('tour-close');
    if (nextBtn) nextBtn.addEventListener('click', () => { idx++; showStep(idx); });
    if (closeBtn) closeBtn.addEventListener('click', cleanup);
    if (overlay) overlay.addEventListener('click', cleanup);
  }
})();
