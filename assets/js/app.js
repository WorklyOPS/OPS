/* ==========================================================================
   Workly Virtual Assistant Management Platform — Production Frontend Scripts
   Zero dependencies, zero demo accounts, strict CSRF-protected REST calls.
   ========================================================================== */

(function () {
  'use strict';

  const csrfMeta = document.querySelector('meta[name="workly-csrf"]');
  const CSRF_TOKEN = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';

  // ------------------------------------------------------------------------
  // Smooth Skeleton Entrance Transition
  // ------------------------------------------------------------------------
  window.addEventListener('DOMContentLoaded', () => {
    const skel = document.getElementById('workly-skeleton');
    const content = document.getElementById('workly-view-content');
    if (skel && content) {
      window.requestAnimationFrame(() => {
        skel.classList.add('is-hidden');
        content.classList.remove('is-hidden');
      });
    }
    initMobileDrawer();
    initModals();
    initKanbanBoard();
    initForms();
    initInteractiveButtons();
    initOnboardingTour();
  });

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

  // ------------------------------------------------------------------------
  // Mobile Navigation Drawer
  // ------------------------------------------------------------------------
  function initMobileDrawer() {
    const btn = document.getElementById('mobile-menu-toggle');
    const sidebar = document.getElementById('workly-sidebar');
    if (!btn || !sidebar) return;
    btn.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 860 && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && !btn.contains(e.target)) {
          sidebar.classList.remove('open');
        }
      }
    });
  }

  // ------------------------------------------------------------------------
  // Accessible Modal System
  // ------------------------------------------------------------------------
  function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('is-hidden');
  }

  function closeAllModals() {
    document.querySelectorAll('.modal-backdrop').forEach((m) => m.classList.add('is-hidden'));
  }

  function initModals() {
    document.querySelectorAll('[data-open-modal]').forEach((btn) => {
      btn.addEventListener('click', () => openModal(btn.getAttribute('data-open-modal')));
    });
    document.querySelectorAll('[data-close-modal]').forEach((btn) => {
      btn.addEventListener('click', closeAllModals);
    });
    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) closeAllModals();
      });
    });
  }

  // ------------------------------------------------------------------------
  // Kanban Drag-and-Drop + Quick Context Menu + Detail Modal
  // ------------------------------------------------------------------------
  function initKanbanBoard() {
    const board = document.getElementById('workly-kanban');
    if (!board) return;

    let draggedCard = null;
    const placeholder = document.createElement('div');
    placeholder.className = 'drop-placeholder';

    // Real-time search bar filter
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

    board.querySelectorAll('.task-card').forEach((card) => {
      card.addEventListener('dragstart', (e) => {
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
    });

    board.querySelectorAll('.kanban-col').forEach((col) => {
      const container = col.querySelector('.kanban-col-cards');
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        col.classList.add('drag-over');
        if (container && draggedCard) {
          if (!container.contains(placeholder)) {
            container.appendChild(placeholder);
          }
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
          showToast('Task status updated', 'success');
        } catch (err) {
          showToast(err.message, 'error');
          window.location.reload();
        }
      });
    });

    // Context menu & task modal openers
    board.addEventListener('click', async (e) => {
      const menuBtn = e.target.closest('.task-menu-btn');
      if (menuBtn) {
        e.stopPropagation();
        const card = menuBtn.closest('.task-card');
        const menu = card ? card.querySelector('.task-dropdown') : null;
        document.querySelectorAll('.task-dropdown').forEach((d) => {
          if (d !== menu) d.classList.add('is-hidden');
        });
        if (menu) menu.classList.toggle('is-hidden');
        return;
      }

      const actionBtn = e.target.closest('[data-task-action]');
      if (actionBtn) {
        const action = actionBtn.getAttribute('data-task-action');
        const taskId = actionBtn.getAttribute('data-task-id');
        if (action === 'delete') {
          try {
            await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId), { method: 'DELETE' });
            showToast('Task removed', 'success');
            window.location.reload();
          } catch (err) {
            showToast(err.message, 'error');
          }
        } else if (action === 'priority') {
          const priority = actionBtn.getAttribute('data-priority');
          try {
            await apiFetch('/api/v1/tasks/' + encodeURIComponent(taskId), {
              method: 'PATCH',
              body: JSON.stringify({ priority })
            });
            showToast('Priority updated', 'success');
            window.location.reload();
          } catch (err) {
            showToast(err.message, 'error');
          }
        } else if (action === 'open') {
          openTaskDetailModal(taskId);
        }
        return;
      }

      const titleBtn = e.target.closest('[data-open-task]');
      if (titleBtn) {
        openTaskDetailModal(titleBtn.getAttribute('data-open-task'));
      }
    });

    document.addEventListener('click', () => {
      document.querySelectorAll('.task-dropdown').forEach((d) => d.classList.add('is-hidden'));
    });
  }

  function updateColumnCounters() {
    document.querySelectorAll('.kanban-col').forEach((col) => {
      const cnt = col.querySelectorAll('.task-card').length;
      const badge = col.querySelector('.col-count');
      if (badge) badge.textContent = String(cnt);
    });
  }

  function openTaskDetailModal(taskId) {
    const store = document.getElementById('task-details-json');
    if (!store) return;
    const allData = JSON.parse(store.textContent || '{}');
    const task = (allData.tasks || {})[taskId];
    if (!task) return;

    const modal = document.getElementById('modal-task-detail');
    if (!modal) return;

    modal.querySelector('#detail-task-title').textContent = task.title;
    modal.querySelector('#detail-task-meta').textContent =
      task.client_name + ' • Assigned to ' + task.va_name + ' • Due ' + task.deadline + ' • Est ' + task.estimated_hours + 'h';
    modal.querySelector('#detail-task-desc').textContent = task.description || 'No description provided.';

    const notesList = modal.querySelector('#detail-notes-list');
    const commentsList = modal.querySelector('#detail-comments-list');
    notesList.innerHTML = '';
    commentsList.innerHTML = '';

    const notes = (allData.notes || {})[taskId] || [];
    if (notes.length === 0) {
      notesList.innerHTML = '<p class="muted">No notes yet.</p>';
    } else {
      notes.forEach((n) => {
        const div = document.createElement('div');
        div.className = 'message-bubble';
        div.innerHTML = '<strong>' + escapeHtml(n.author_name) + '</strong> <span class="badge badge-neutral">' +
          escapeHtml(n.author_role) + '</span><p style="margin-top:4px">' + escapeHtml(n.content) + '</p>';
        notesList.appendChild(div);
      });
    }

    const comments = (allData.comments || {})[taskId] || [];
    if (comments.length === 0) {
      commentsList.innerHTML = '<p class="muted">No comments yet.</p>';
    } else {
      comments.forEach((c) => {
        const div = document.createElement('div');
        div.className = 'message-bubble';
        div.innerHTML = '<strong>' + escapeHtml(c.author_name) + '</strong><p style="margin-top:4px">' + escapeHtml(c.message) + '</p>';
        commentsList.appendChild(div);
      });
    }

    modal.querySelectorAll('input[name="task_id"]').forEach((inp) => { inp.value = taskId; });
    openModal('modal-task-detail');
  }

  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
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
              if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                payload[cleanKey] = payload[cleanKey] || [];
                payload[cleanKey].push(val);
              } else {
                payload[key] = val;
              }
            });

            // Handle checkboxes properly
            form.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
              if (cb.name) payload[cb.name] = cb.checked;
            });

            // Special CSV/array parsing for VA specialties
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
          window.setTimeout(() => window.location.reload(), 450);
        } catch (err) {
          showToast(err.message, 'error');
          if (submitBtn) submitBtn.disabled = false;
        }
      });
    });

    // Dynamic PTO business day counter (Monday-Friday max 5)
    const ptoStart = document.getElementById('pto-start');
    const ptoEnd = document.getElementById('pto-end');
    const ptoPreview = document.getElementById('pto-days-preview');
    if (ptoStart && ptoEnd && ptoPreview) {
      const recalc = () => {
        if (!ptoStart.value || !ptoEnd.value) {
          ptoPreview.textContent = 'Select start and end dates (Mon-Fri, max 5 business days).';
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
        ptoPreview.textContent = 'Calculated business days (Mon-Fri): ' + days + (days > 5 ? ' (Exceeds 5-day policy)' : '');
      };
      ptoStart.addEventListener('change', recalc);
      ptoEnd.addEventListener('change', recalc);
    }
  }

  // ------------------------------------------------------------------------
  // Action Buttons (Suspend, Reactivate, Delete, PTO Review, Report Print)
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
        await apiFetch(url, {
          method,
          body: bodyRaw || undefined
        });
        showToast('Action completed', 'success');
        window.setTimeout(() => window.location.reload(), 400);
      } catch (err) {
        showToast(err.message, 'error');
        btn.disabled = false;
      }
    });

    // Print / Export SLA PDF
    const printBtn = document.getElementById('btn-print-sla');
    if (printBtn) {
      printBtn.addEventListener('click', () => window.print());
    }

    // Admin Audit Log Month/User filter reload
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
  // Interactive Onboarding Spotlight Tour (Non-blocking UI overlay)
  // ------------------------------------------------------------------------
  function initOnboardingTour() {
    const startBtn = document.getElementById('btn-start-tour');
    if (!startBtn) return;

    const steps = [
      { sel: '#workly-sidebar', title: 'Role-Scoped Navigation', text: 'Your sidebar reflects only modules and alerts authorized for your role.' },
      { sel: '#topbar-user-pill', title: 'Authenticated Session', text: 'Signed-in PostgreSQL session identity with zero demo presets.' },
      { sel: '#workly-view-content', title: 'Live Workspace', text: 'All tasks, reports, CRM clients, and PTO records query PostgreSQL directly.' }
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
      const top = Math.min(window.innerHeight - 190, Math.max(16, rect.top + 16));
      const left = Math.min(window.innerWidth - 330, Math.max(16, rect.left + 20));
      tooltip.style.top = top + 'px';
      tooltip.style.left = left + 'px';

      tooltip.querySelector('#tour-title').textContent = step.title;
      tooltip.querySelector('#tour-desc').textContent = step.text;
      tooltip.querySelector('#tour-progress').textContent = 'Step ' + (i + 1) + ' of ' + steps.length;
    }

    startBtn.addEventListener('click', () => {
      idx = 0;
      showStep(idx);
    });

    const nextBtn = document.getElementById('tour-next');
    const closeBtn = document.getElementById('tour-close');
    if (nextBtn) nextBtn.addEventListener('click', () => { idx++; showStep(idx); });
    if (closeBtn) closeBtn.addEventListener('click', cleanup);
    if (overlay) overlay.addEventListener('click', cleanup);
  }
})();
