/* ==========================================================================
   Workly Platform — Production Client Orchestrator (Vanilla JS, Zero TS)
   Global Skeleton & Progress Coordinator, Hardened JSON Fetch, Swimlanes +
   Kanban Drag & Drop, Intelligent Calendar Scheduler, Right-Side Drawers,
   Command Palette, Interactive SVG Charts, CSV Exports & SVG CAPTCHA.
   ========================================================================== */

(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';
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
    initSkeletonStaging();
    initAuthControls();
    initMobileDrawer();
    initDrawers();
    initTabs();
    initCommandPalette();
    initChartTooltipsAndToggles();
    initKanbanBoard();
    initCalendarScheduler();
    initClientDossiers();
    initAdminEditDrawers();
    initHrmPtoRejection();
    initCsvExports();
    initForms();
    initInteractiveButtons();
    initOnboardingTour();
  });

  window.addEventListener('pageshow', (e) => {
    if (e.persisted) {
      WorklyLoader.done();
      WorklyLoader.revealContent();
    }
  });

  // ------------------------------------------------------------------------
  // Staged Skeleton Loader (Visible Before Section Content Reveal & Navigation)
  // ------------------------------------------------------------------------
  function initSkeletonStaging() {
    const skel = document.getElementById('workly-skeleton');
    const content = document.getElementById('workly-view-content');
    if (skel && content) {
      const settleMs = reducedMotion ? 10 : 210;
      window.setTimeout(() => {
        WorklyLoader.revealContent();
      }, settleMs);

      // Show matching skeleton immediately when navigating across workspace sections
      document.querySelectorAll('.sidebar-nav a.nav-link, .topbar a.user-pill').forEach((link) => {
        link.addEventListener('click', (e) => {
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
          const href = link.getAttribute('href');
          if (!href || href.startsWith('#')) return;
          WorklyLoader.showSkeleton();
        });
      });

      // Trigger skeleton before GET filter submissions inside the workspace
      document.querySelectorAll('.page-content form[method="GET"]').forEach((form) => {
        form.addEventListener('submit', () => {
          WorklyLoader.showSkeleton();
        });
      });
    }
  }

  // ------------------------------------------------------------------------
  // Auth Screen Helpers (CAPTCHA Refresh + Minimalist SVG Password Eye Icon)
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

  // ------------------------------------------------------------------------
  // Toast (Entry + Exit Choreography) & Hardened CSRF API Fetch Helper
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
  // Right-Side Slide-Over Drawer System (With Pose-to-Pose Exit Transitions)
  // ------------------------------------------------------------------------
  function openDrawer(id) {
    closeAllDrawers(true);
    const el = document.getElementById(id);
    if (el) {
      el.classList.remove('is-closing', 'is-hidden');
    }
  }

  function closeAllDrawers(immediate) {
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

  function initDrawers() {
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
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeAllDrawers(false);
        hideContextMenu();
        closeCommandPalette();
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
  // Kanban Horizontal Swimlanes + 5-Column Board + Context Menu + Drawer
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

    // Real-time search filter across Swimlanes and 5-Column Board
    const searchInput = document.getElementById('kanban-search');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        const term = searchInput.value.trim().toLowerCase();
        surfaces.forEach((surface) => {
          surface.querySelectorAll('.task-card').forEach((card) => {
            const text = (card.getAttribute('data-search') || '').toLowerCase();
            card.style.display = text.includes(term) ? '' : 'none';
          });
        });
        updateBoardCounters(term);
      });
    }

    // Bind DnD and right-click context menu on every card across surfaces
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
          if (!tid) return;
          showContextMenuAt(tid, e.clientX, e.clientY);
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
          if (clientChanged) {
            dropped.setAttribute('data-client-id', newClientId);
          }

          // Mirror card across sibling view container when status changes so tab switches stay synchronized
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

      // Click delegation for 3-dot card menu and opening right-side detail drawer tabs
      surface.addEventListener('click', (e) => {
        const menuBtn = e.target.closest('.task-menu-btn');
        if (menuBtn) {
          e.stopPropagation();
          const tid = menuBtn.getAttribute('data-task-id');
          const rect = menuBtn.getBoundingClientRect();
          if (tid) showContextMenuAt(tid, rect.left - 165, rect.bottom + 6);
          return;
        }

        const tabTrigger = e.target.closest('[data-open-task]');
        if (tabTrigger) {
          const tid = tabTrigger.getAttribute('data-open-task');
          const initialTab = tabTrigger.getAttribute('data-task-tab') || 'details';
          openTaskDetailDrawer(tid, initialTab);
        }
      });
    });

    // Context Menu Actions
    const cm = document.getElementById('kanban-context-menu');
    if (cm) {
      cm.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-cm-action]');
        if (!btn || !contextTaskId) return;
        const tid = contextTaskId;
        const action = btn.getAttribute('data-cm-action');
        hideContextMenu();

        if (action === 'open') {
          openTaskDetailDrawer(tid, btn.getAttribute('data-cm-tab') || 'details');
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
  }

  function showContextMenuAt(taskId, x, y) {
    const cm = document.getElementById('kanban-context-menu');
    if (!cm) return;
    contextTaskId = taskId;
    cm.classList.remove('is-closing', 'is-hidden');
    const maxLeft = window.innerWidth - 235;
    const maxTop = window.innerHeight - 395;
    cm.style.left = Math.max(10, Math.min(x, maxLeft)) + 'px';
    cm.style.top = Math.max(10, Math.min(y, maxTop)) + 'px';
  }

  function openTaskDetailDrawer(taskId, initialTab) {
    const store = document.getElementById('task-details-json');
    if (!store) return;
    const allData = JSON.parse(store.textContent || '{}');
    const task = (allData.tasks || {})[taskId];
    if (!task) return;

    const drawer = document.getElementById('drawer-task-detail');
    if (!drawer) return;

    drawer.querySelector('#detail-task-title').textContent = task.title;
    drawer.querySelector('#detail-task-meta').textContent =
      task.client_name + ' · ' + task.va_name + ' · Due ' + task.deadline + ' · Est ' + task.estimated_hours + 'h';

    const editForm = drawer.querySelector('#form-edit-task');
    if (editForm) {
      editForm.setAttribute('data-api-form', '/api/v1/tasks/' + encodeURIComponent(taskId));
      editForm.querySelector('[name="title"]').value = task.title || '';
      editForm.querySelector('[name="description"]').value = task.description || '';
      editForm.querySelector('[name="priority"]').value = task.priority || 'medium';
      const stSelect = editForm.querySelector('[name="status"]');
      if (stSelect) stSelect.value = task.status || 'todo';
      const clSelect = editForm.querySelector('[name="client_id"]');
      if (clSelect && task.client_id) clSelect.value = task.client_id;
      editForm.querySelector('[name="deadline"]').value = task.deadline || '';
      editForm.querySelector('[name="estimated_hours"]').value = task.estimated_hours || 4;
    }

    const notesList = drawer.querySelector('#detail-notes-list');
    notesList.innerHTML = '';
    const notes = (allData.notes || {})[taskId] || [];
    if (notes.length === 0) {
      notesList.innerHTML = '<p class="muted">No execution notes added yet.</p>';
    } else {
      notes.forEach((n) => {
        const div = document.createElement('div');
        div.className = 'va-note-card';
        div.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;"><strong>' +
          escapeHtml(n.author_name) + '</strong><span class="badge badge-brand">SLA Note</span></div>' +
          '<p style="margin-top:6px;">' + escapeHtml(n.content) + '</p>';
        notesList.appendChild(div);
      });
    }

    const commentsList = drawer.querySelector('#detail-comments-list');
    commentsList.innerHTML = '';
    const comments = (allData.comments || {})[taskId] || [];
    if (comments.length === 0) {
      commentsList.innerHTML = '<p class="muted">No discussion comments yet.</p>';
    } else {
      comments.forEach((c) => {
        const div = document.createElement('div');
        div.className = 'comment-card';
        div.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;"><strong>' +
          escapeHtml(c.author_name) + '</strong><span class="badge badge-neutral">' + escapeHtml(c.author_role) + '</span></div>' +
          '<p style="margin-top:6px;">' + escapeHtml(c.message) + '</p>';
        commentsList.appendChild(div);
      });
    }

    const ehList = drawer.querySelector('#detail-extrahours-list');
    if (ehList) {
      ehList.innerHTML = '';
      const logs = (allData.extra_hours || {})[taskId] || [];
      if (logs.length === 0) {
        ehList.innerHTML = '<p class="muted">No overtime hours logged on this task.</p>';
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

    drawer.querySelectorAll('input[name="task_id"]').forEach((inp) => { inp.value = taskId; });
    openDrawer('drawer-task-detail');
    activateTab('task-drawer', initialTab || 'details');
  }

  // ------------------------------------------------------------------------
  // Intelligent Drag-and-Drop Calendar Focus Scheduler & CRM Sync
  // ------------------------------------------------------------------------
  function initCalendarScheduler() {
    const grid = document.querySelector('.calendar-grid');
    if (!grid) return;

    let dragState = null;

    // 1. Draggable Quick-Block Focus / Client Availability Templates
    document.querySelectorAll('[data-focus-template][draggable="true"]').forEach((chip) => {
      chip.addEventListener('dragstart', (e) => {
        dragState = {
          kind: 'template',
          title: chip.getAttribute('data-title') || 'Deep Focus Block',
          meeting_type: chip.getAttribute('data-type') || 'focus_block',
          duration_minutes: parseInt(chip.getAttribute('data-duration') || '60', 10) || 60
        };
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'copy';
          e.dataTransfer.setData('text/plain', dragState.title);
        }
      });

      chip.addEventListener('dragend', () => {
        dragState = null;
        grid.querySelectorAll('.cal-cell').forEach((c) => c.classList.remove('cal-drag-over'));
      });
    });

    // 2. Draggable Scheduled Meetings / Focus Blocks for Date Rescheduling
    grid.querySelectorAll('.cal-event-chip[draggable="true"][data-meeting-id]').forEach((chip) => {
      chip.addEventListener('dragstart', (e) => {
        e.stopPropagation();
        dragState = {
          kind: 'event',
          id: chip.getAttribute('data-meeting-id') || ''
        };
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', dragState.id);
        }
      });

      chip.addEventListener('dragend', () => {
        dragState = null;
        grid.querySelectorAll('.cal-cell').forEach((c) => c.classList.remove('cal-drag-over'));
      });
    });

    // 3. Calendar Cell Drop Targets & Quick Slot Inspection
    grid.querySelectorAll('.cal-cell[data-cal-date]').forEach((cell) => {
      const targetDate = cell.getAttribute('data-cal-date');

      cell.addEventListener('dragover', (e) => {
        if (!dragState) return;
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
        if (!dragState || !targetDate) return;

        const payload = dragState;
        dragState = null;

        try {
          if (payload.kind === 'template') {
            const vaInput = document.getElementById('focus-va-id');
            const body = {
              title: payload.title,
              meeting_type: payload.meeting_type,
              duration_minutes: payload.duration_minutes,
              target_date: targetDate,
              auto_slot: true
            };
            if (vaInput && vaInput.value) {
              body.va_id = vaInput.value;
            }
            const res = await apiFetch('/api/v1/meetings', {
              method: 'POST',
              body: JSON.stringify(body)
            });
            showToast('Scheduled ' + payload.title + ' on ' + (res.start_time || targetDate), 'success');
            window.setTimeout(() => window.location.reload(), 320);
          } else if (payload.kind === 'event' && payload.id) {
            const res = await apiFetch('/api/v1/meetings/' + encodeURIComponent(payload.id) + '/reschedule', {
              method: 'PATCH',
              body: JSON.stringify({ target_date: targetDate })
            });
            showToast('Rescheduled to ' + (res.start_time || targetDate), 'success');
            window.setTimeout(() => window.location.reload(), 320);
          }
        } catch (err) {
          showToast(err.message, 'error');
        }
      });

      cell.addEventListener('click', (e) => {
        if (e.target.closest('.cal-event-chip') || e.target.closest('.cal-chip-del')) return;
        const focusDrawer = document.getElementById('drawer-focus-block');
        if (!focusDrawer || !targetDate) return;
        const sInp = document.getElementById('focus-start-time');
        const eInp = document.getElementById('focus-end-time');
        if (sInp) sInp.value = targetDate + 'T09:00';
        if (eInp) eInp.value = targetDate + 'T11:00';
        openDrawer('drawer-focus-block');
        loadOpenSlots(targetDate);
      });
    });

    // 4. Delete Focus Block / Scheduled Event Chips
    grid.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('.cal-chip-del[data-delete-meeting]');
      if (!delBtn) return;
      e.stopPropagation();
      const mid = delBtn.getAttribute('data-delete-meeting');
      if (!mid) return;
      try {
        delBtn.disabled = true;
        await apiFetch('/api/v1/meetings/' + encodeURIComponent(mid), { method: 'DELETE' });
        showToast('Removed calendar block', 'success');
        window.setTimeout(() => window.location.reload(), 260);
      } catch (err) {
        showToast(err.message, 'error');
        delBtn.disabled = false;
      }
    });

    // 5. CRM Client Sync Strip Buttons
    document.querySelectorAll('[data-sync-client]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const cid = btn.getAttribute('data-sync-client') || '';
        const cname = btn.getAttribute('data-sync-client-name') || 'Client';
        const typeSel = document.getElementById('focus-meeting-type');
        const clSel = document.getElementById('focus-client-id');
        const titleInp = document.getElementById('focus-title');
        const sInp = document.getElementById('focus-start-time');
        const eInp = document.getElementById('focus-end-time');

        if (typeSel) typeSel.value = 'availability_window';
        if (clSel && cid) clSel.value = cid;
        if (titleInp) titleInp.value = 'Client Sync Availability — ' + cname;

        const baseDate = sInp && sInp.value ? sInp.value.slice(0, 10) : new Date().toISOString().slice(0, 10);
        if (sInp) sInp.value = baseDate + 'T10:00';
        if (eInp) eInp.value = baseDate + 'T11:00';

        openDrawer('drawer-focus-block');
        loadOpenSlots(baseDate);
      });
    });

    // 6. Live PostgreSQL Open Slot Scanner
    const checkBtn = document.getElementById('btn-check-slots');
    if (checkBtn) {
      checkBtn.addEventListener('click', () => {
        const sInp = document.getElementById('focus-start-time');
        const dStr = sInp && sInp.value ? sInp.value.slice(0, 10) : new Date().toISOString().slice(0, 10);
        loadOpenSlots(dStr);
      });
    }

    async function loadOpenSlots(dateStr) {
      const box = document.getElementById('focus-slots-preview');
      if (!box) return;
      const vaInput = document.getElementById('focus-va-id');
      const vaQuery = vaInput && vaInput.value ? '&va_id=' + encodeURIComponent(vaInput.value) : '';
      box.textContent = 'Scanning PostgreSQL calendar conflicts for ' + dateStr + '...';

      try {
        const res = await apiFetch('/api/v1/meetings/availability?date=' + encodeURIComponent(dateStr) + vaQuery, {
          method: 'GET'
        });
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
          box.textContent = 'No open 1-hour windows remain on ' + dateStr + '.';
          return;
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
            const sInp = document.getElementById('focus-start-time');
            const eInp = document.getElementById('focus-end-time');
            if (sInp && parts[0]) sInp.value = dateStr + 'T' + parts[0];
            if (eInp && parts[1]) eInp.value = dateStr + 'T' + parts[1];
            showToast('Applied slot ' + slotLabel, 'success');
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
  // Client CRM Right Dossier Drawer & CSV Bulk Importer
  // ------------------------------------------------------------------------
  function initClientDossiers() {
    document.querySelectorAll('[data-open-client]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const cid = btn.getAttribute('data-open-client');
        const store = document.getElementById('clients-dossier-json');
        if (!store || !cid) return;
        const data = JSON.parse(store.textContent || '{}');
        const cl = (data.clients || {})[cid];
        if (!cl) return;

        const drawer = document.getElementById('drawer-client-dossier');
        if (!drawer) return;

        drawer.querySelector('#dossier-agency').textContent = cl.agency_name;
        drawer.querySelector('#dossier-contact').textContent = cl.name + ' · ' + cl.email;

        const editForm = drawer.querySelector('#form-edit-client');
        if (editForm) {
          editForm.setAttribute('data-api-form', '/api/v1/clients/' + encodeURIComponent(cid));
          editForm.querySelector('[name="agency_name"]').value = cl.agency_name || '';
          editForm.querySelector('[name="name"]').value = cl.name || '';
          editForm.querySelector('[name="email"]').value = cl.email || '';
          editForm.querySelector('[name="phone"]').value = cl.phone || '';
          editForm.querySelector('[name="contract_status"]').value = cl.contract_status || 'active';
          editForm.querySelector('[name="contracted_hours_per_month"]').value = cl.contracted_hours_per_month || 40;
          editForm.querySelector('[name="consumed_hours_month"]').value = cl.consumed_hours_month || 0;
          const vaSel = editForm.querySelector('[name="focus_va_id"]');
          if (vaSel && cl.focus_va_id) vaSel.value = cl.focus_va_id;
          editForm.querySelector('[name="internal_notes"]').value = cl.internal_notes || '';
        }

        const pdfForm = drawer.querySelector('#form-upload-contract');
        if (pdfForm) {
          pdfForm.querySelector('[name="client_id"]').value = cid;
        }
        const avatarForm = drawer.querySelector('#form-upload-client-avatar');
        if (avatarForm) {
          avatarForm.querySelector('[name="client_id"]').value = cid;
        }
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
          actForm.setAttribute('data-api-form', '/api/v1/clients/' + encodeURIComponent(cid) + '/activities');
        }

        const actList = drawer.querySelector('#dossier-activities');
        if (actList) {
          actList.innerHTML = '';
          const acts = (data.activities || {})[cid] || [];
          if (acts.length === 0) {
            actList.innerHTML = '<p class="muted">No activity entries yet.</p>';
          } else {
            acts.forEach((a) => {
              const div = document.createElement('div');
              div.className = 'comment-card';
              div.innerHTML = '<div style="display:flex;justify-content:space-between;"><strong>' +
                escapeHtml(a.author_name) + '</strong><span class="badge badge-neutral">' +
                escapeHtml(a.type) + '</span></div><p style="margin-top:4px;">' +
                escapeHtml(a.description) + '</p>';
              actList.appendChild(div);
            });
          }
        }

        openDrawer('drawer-client-dossier');
      });
    });

    const bulkForm = document.getElementById('form-bulk-clients');
    if (bulkForm) {
      bulkForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const raw = (bulkForm.querySelector('[name="csv_data"]') || {}).value || '';
        const defaultVa = (bulkForm.querySelector('[name="default_va_id"]') || {}).value || '';
        const lines = raw.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
        if (lines.length === 0) {
          showToast('Paste at least one CSV client line', 'error');
          return;
        }
        const clients = [];
        lines.forEach((line, idx) => {
          const cols = line.split(',').map((c) => c.trim());
          if (idx === 0 && cols[0].toLowerCase().includes('agency')) return;
          if (!cols[0] || !cols[1] || !cols[2]) return;
          clients.push({
            agency_name: cols[0],
            name: cols[1],
            email: cols[2],
            phone: cols[3] || '',
            contracted_hours_per_month: parseFloat(cols[4] || '40') || 40,
            focus_va_id: cols[5] || defaultVa
          });
        });

        try {
          const res = await apiFetch('/api/v1/clients/bulk', {
            method: 'POST',
            body: JSON.stringify({ clients })
          });
          showToast('Imported ' + (res.imported || 0) + ' clients', 'success');
          window.setTimeout(() => window.location.reload(), 420);
        } catch (err) {
          showToast(err.message, 'error');
        }
      });
    }
  }

  // ------------------------------------------------------------------------
  // Admin Right-Side Edit User Drawer
  // ------------------------------------------------------------------------
  function initAdminEditDrawers() {
    document.querySelectorAll('[data-edit-user]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const uid = btn.getAttribute('data-edit-user');
        const store = document.getElementById('admin-users-json');
        if (!store || !uid) return;
        const usersMap = JSON.parse(store.textContent || '{}');
        const u = usersMap[uid];
        if (!u) return;

        const drawer = document.getElementById('drawer-edit-user');
        if (!drawer) return;
        const form = drawer.querySelector('#form-edit-user');
        form.setAttribute('data-api-form', '/api/v1/users/' + encodeURIComponent(uid));
        form.querySelector('[name="name"]').value = u.name || '';
        form.querySelector('[name="email"]').value = u.email || '';
        form.querySelector('[name="role"]').value = u.role || 'va';
        form.querySelector('[name="hourly_rate"]').value = u.hourly_rate || 0;
        form.querySelector('[name="pto_balance_days"]').value = u.pto_balance_days || 15;
        const tlSel = form.querySelector('[name="team_leader_id"]');
        if (tlSel) tlSel.value = u.team_leader_id || '';
        openDrawer('drawer-edit-user');
      });
    });
  }

  // ------------------------------------------------------------------------
  // HRM Custom Rejection Reason Drawer
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
  }

  // ------------------------------------------------------------------------
  // Client-Side CSV Export of Rendered PostgreSQL Tables
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
              payload[key] = val;
            });
            form.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
              if (cb.name) payload[cb.name] = cb.checked;
            });

            if (typeof payload.specialties_csv === 'string') {
              payload.specialties = payload.specialties_csv.split(',').map((s) => s.trim()).filter(Boolean);
              delete payload.specialties_csv;
            }
            if (typeof payload.responsibilities_text === 'string') {
              payload.responsibilities = payload.responsibilities_text.split('\n').map((s) => s.trim()).filter(Boolean);
              delete payload.responsibilities_text;
            }

            let targetUrl = endpoint;
            if (targetUrl.includes('{task_id}')) {
              const tidInput = form.querySelector('input[name="task_id"]');
              targetUrl = targetUrl.replace('{task_id}', encodeURIComponent(tidInput ? tidInput.value : ''));
            }

            await apiFetch(targetUrl, {
              method,
              body: JSON.stringify(payload)
            });
          }

          showToast('Saved to PostgreSQL database', 'success');
          window.setTimeout(() => window.location.reload(), 380);
        } catch (err) {
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
        showToast('Updated in PostgreSQL', 'success');
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
      { sel: '#workly-sidebar', title: 'Role-Scoped Workspace', text: 'Navigate modules tailored to your role with live PostgreSQL review & PTO alerts.' },
      { sel: '#workly-view-content', title: 'Full-Width Operations', text: 'Organize Kanban tasks by Client Swimlanes, drag Focus blocks onto the Calendar, press Ctrl+K for Quick Jump, and use right-side drawers.' },
      { sel: '#sidebar-profile-anchor', title: 'Profile & Performance', text: 'Click your profile anchor at any time to edit your bio/specialties, review compensation, badges, and upload photos.' }
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
