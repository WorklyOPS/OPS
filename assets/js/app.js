/* ==========================================================================
   Workly Platform — Production Client Orchestrator (Vanilla JS, Zero TS)
   Handles Right-Side Drawers, Kanban Drag & Drop + Right-Click Context Menu,
   Segmented Tabs, CSV Client Bulk Import, CAPTCHA Refresh, and CSRF Fetch.
   ========================================================================== */

(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';

  window.addEventListener('DOMContentLoaded', () => {
    const skel = document.getElementById('workly-skeleton');
    const content = document.getElementById('workly-view-content');
    if (skel && content) {
      window.requestAnimationFrame(() => {
        skel.classList.add('is-hidden');
        content.classList.remove('is-hidden');
      });
    }
    initAuthControls();
    initMobileDrawer();
    initDrawers();
    initTabs();
    initKanbanBoard();
    initClientDossiers();
    initAdminEditDrawers();
    initForms();
    initInteractiveButtons();
    initOnboardingTour();
  });

  // ------------------------------------------------------------------------
  // Auth Screen Helpers (CAPTCHA refresh + Password visibility)
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
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-toggle-password');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;
        const isPass = input.type === 'password';
        input.type = isPass ? 'text' : 'password';
        btn.textContent = isPass ? 'Hide' : 'Show';
      });
    });
  }

  // ------------------------------------------------------------------------
  // Toast & CSRF API Fetch Helper
  // ------------------------------------------------------------------------
  function showToast(message, type) {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'error' ? 'error' : 'success');
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), 3500);
  }

  async function apiFetch(url, options) {
    const opts = options || {};
    const headers = Object.assign({
      'X-CSRF-Token': CSRF_TOKEN
    }, opts.headers || {});

    if (!(opts.body instanceof FormData) && !headers['Content-Type']) {
      headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, Object.assign({}, opts, { headers }));
    const contentType = res.headers.get('content-type') || '';
    const data = contentType.includes('application/json') ? await res.json() : await res.text();
    if (!res.ok) {
      const msg = (data && data.error) ? data.error : 'Request failed (' + res.status + ')';
      throw new Error(msg);
    }
    return data;
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
  // Right-Side Slide-Over Drawer System
  // ------------------------------------------------------------------------
  function openDrawer(id) {
    closeAllDrawers();
    const el = document.getElementById(id);
    if (el) el.classList.remove('is-hidden');
  }

  function closeAllDrawers() {
    document.querySelectorAll('.drawer-backdrop').forEach((d) => d.classList.add('is-hidden'));
  }

  function initDrawers() {
    document.querySelectorAll('[data-open-drawer]').forEach((btn) => {
      btn.addEventListener('click', () => openDrawer(btn.getAttribute('data-open-drawer')));
    });
    document.querySelectorAll('[data-close-drawer]').forEach((btn) => {
      btn.addEventListener('click', closeAllDrawers);
    });
    document.querySelectorAll('.drawer-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeAllDrawers();
      });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closeAllDrawers();
        hideContextMenu();
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
          panel.classList.toggle('is-hidden', panel.getAttribute('data-tab-panel') !== target);
        });
      });
    });
  }

  function activateTab(group, tabKey) {
    const btn = document.querySelector('[data-tab-group="' + group + '"][data-tab-btn="' + tabKey + '"]');
    if (btn) btn.click();
  }

  // ------------------------------------------------------------------------
  // Kanban Drag-and-Drop + Right-Click Context Menu + Right Detail Drawer
  // ------------------------------------------------------------------------
  let contextTaskId = null;

  function hideContextMenu() {
    const cm = document.getElementById('kanban-context-menu');
    if (cm) cm.classList.add('is-hidden');
    contextTaskId = null;
  }

  function initKanbanBoard() {
    const board = document.getElementById('workly-kanban');
    if (!board) return;

    let draggedCard = null;
    const placeholder = document.createElement('div');
    placeholder.className = 'drop-placeholder';

    // Real-time search filter
    const searchInput = document.getElementById('kanban-search');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        const term = searchInput.value.trim().toLowerCase();
        board.querySelectorAll('.task-card').forEach((card) => {
          const text = (card.getAttribute('data-search') || '').toLowerCase();
          card.style.display = text.includes(term) ? '' : 'none';
        });
      });
    }

    // Drag & Drop handlers
    board.querySelectorAll('.task-card').forEach((card) => {
      card.addEventListener('dragstart', (e) => {
        hideContextMenu();
        draggedCard = card;
        card.classList.add('dragging');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
        }
      });

      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        board.querySelectorAll('.kanban-col').forEach((c) => c.classList.remove('drag-over'));
        draggedCard = null;
      });

      // Native Right-Click Context Menu
      card.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        const tid = card.getAttribute('data-task-id');
        if (!tid) return;
        showContextMenuAt(tid, e.clientX, e.clientY);
      });
    });

    board.querySelectorAll('.kanban-col').forEach((col) => {
      const container = col.querySelector('.kanban-col-cards');
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        col.classList.add('drag-over');
        if (container && draggedCard && !container.contains(placeholder)) {
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
        if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
        if (!draggedCard || !container) return;

        const newStatus = col.getAttribute('data-status');
        const oldCol = draggedCard.closest('.kanban-col');
        const oldStatus = oldCol ? oldCol.getAttribute('data-status') : '';
        const taskId = draggedCard.getAttribute('data-task-id');

        if (!newStatus || !taskId || newStatus === oldStatus) return;
        container.appendChild(draggedCard);
        updateColumnCounters();

        try {
          await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId) + '/status', {
            method: 'PATCH',
            body: JSON.stringify({ status: newStatus })
          });
          showToast('Task moved to ' + newStatus.replace('_', ' '), 'success');
        } catch (err) {
          showToast(err.message, 'error');
          window.location.reload();
        }
      });
    });

    // Click delegation on cards (trigger drawer tabs or 3-dot menu)
    board.addEventListener('click', (e) => {
      const menuBtn = e.target.closest('.task-menu-btn');
      if (menuBtn) {
        e.stopPropagation();
        const tid = menuBtn.getAttribute('data-task-id');
        const rect = menuBtn.getBoundingClientRect();
        if (tid) showContextMenuAt(tid, rect.left - 160, rect.bottom + 6);
        return;
      }

      const tabTrigger = e.target.closest('[data-open-task]');
      if (tabTrigger) {
        const tid = tabTrigger.getAttribute('data-open-task');
        const initialTab = tabTrigger.getAttribute('data-task-tab') || 'details';
        openTaskDetailDrawer(tid, initialTab);
      }
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
            showToast('Status updated', 'success');
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
            showToast('Task deleted', 'success');
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
    cm.classList.remove('is-hidden');
    const maxLeft = window.innerWidth - 230;
    const maxTop = window.innerHeight - 380;
    cm.style.left = Math.max(10, Math.min(x, maxLeft)) + 'px';
    cm.style.top = Math.max(10, Math.min(y, maxTop)) + 'px';
  }

  function updateColumnCounters() {
    document.querySelectorAll('.kanban-col').forEach((col) => {
      const cnt = col.querySelectorAll('.task-card').length;
      const badge = col.querySelector('.col-count');
      if (badge) badge.textContent = String(cnt);
    });
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
      task.client_name + ' • ' + task.va_name + ' • Due ' + task.deadline + ' • Est ' + task.estimated_hours + 'h';

    // Populate Inline Edit Form inside right drawer
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

    // Notes
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

    // Comments
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

    // Extra hours history
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
        drawer.querySelector('#dossier-contact').textContent = cl.name + ' • ' + cl.email;

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

    // Bulk CSV Import
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
          window.setTimeout(() => window.location.reload(), 450);
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
  // AJAX Form Submissions Across Modules
  // ------------------------------------------------------------------------
  function initForms() {
    document.querySelectorAll('[data-auto-submit]').forEach((el) => {
      el.addEventListener('change', () => {
        if (el.form) el.form.submit();
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
          window.setTimeout(() => window.location.reload(), 420);
        } catch (err) {
          showToast(err.message, 'error');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });

    // Dynamic PTO business-day counter (Mon-Fri max 5)
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
        window.setTimeout(() => window.location.reload(), 380);
      } catch (err) {
        showToast(err.message, 'error');
        btn.disabled = false;
      }
    });

    const printBtn = document.getElementById('btn-print-sla');
    if (printBtn) {
      printBtn.addEventListener('click', () => window.print());
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
      { sel: '#workly-view-content', title: 'Interactive Operations', text: 'Right-click Kanban cards for quick actions, open right-side drawers for details, and inspect clients in depth.' },
      { sel: '#sidebar-profile-anchor', title: 'Profile & Performance', text: 'Click your profile anchor at any time to review rates, compensation history, badges, and upload photos.' }
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
      tooltip.style.left = Math.min(window.innerWidth - 335, Math.max(16, rect.left + 20)) + 'px';

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
