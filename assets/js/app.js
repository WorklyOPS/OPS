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
    initClientCsvImport();
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

    // Native task context menu (right-click); touch users can use a long press.
    const hideTaskMenus = () => {
      board.querySelectorAll('.task-dropdown').forEach((menu) => {
        menu.classList.add('is-hidden');
        menu.style.removeProperty('left');
        menu.style.removeProperty('top');
      });
    };

    const openTaskMenu = (card, x, y) => {
      hideTaskMenus();
      const menu = card.querySelector('.task-dropdown');
      if (!menu) return;
      menu.classList.remove('is-hidden');
      menu.style.position = 'fixed';
      menu.style.left = Math.max(8, Math.min(x, window.innerWidth - 230)) + 'px';
      menu.style.top = Math.max(8, Math.min(y, window.innerHeight - 360)) + 'px';
    };

    board.addEventListener('contextmenu', (e) => {
      const card = e.target.closest('.task-card');
      if (!card) return;
      e.preventDefault();
      openTaskMenu(card, e.clientX, e.clientY);
    });

    board.addEventListener('keydown', (e) => {
      if (e.key !== 'ContextMenu' && !(e.shiftKey && e.key === 'F10')) return;
      const card = e.target.closest('.task-card');
      if (!card) return;
      e.preventDefault();
      const rect = card.getBoundingClientRect();
      openTaskMenu(card, rect.left + 12, rect.top + 12);
      const firstAction = card.querySelector('.task-dropdown button');
      if (firstAction) firstAction.focus();
    });

    let touchMenuTimer = null;
    board.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'touch') return;
      const card = e.target.closest('.task-card');
      if (!card) return;
      touchMenuTimer = window.setTimeout(() => openTaskMenu(card, e.clientX, e.clientY), 650);
    }, { passive: true });
    ['pointerup', 'pointercancel', 'pointermove'].forEach((eventName) => {
      board.addEventListener(eventName, () => {
        if (touchMenuTimer) window.clearTimeout(touchMenuTimer);
        touchMenuTimer = null;
      }, { passive: true });
    });

    board.addEventListener('click', async (e) => {
      const actionBtn = e.target.closest('[data-task-action]');
      if (actionBtn) {
        e.preventDefault();
        e.stopPropagation();
        const action = actionBtn.getAttribute('data-task-action');
        const taskId = actionBtn.getAttribute('data-task-id');

        try {
          if (action === 'open') {
            hideTaskMenus();
            openTaskDetailModal(taskId);
            return;
          }
          if (action === 'delete' && !window.confirm('Permanently delete this task? This cannot be undone.')) return;
          if (action === 'archive' && !window.confirm('Archive this task? It will be hidden from the active board.')) return;

          let endpoint = '/api/v1/tasks/' + encodeURIComponent(taskId);
          let method = 'PATCH';
          let body = {};

          if (action === 'status') {
            body.status = actionBtn.getAttribute('data-status');
            endpoint += '/status';
          } else if (action === 'priority') {
            body.priority = actionBtn.getAttribute('data-priority');
          } else if (action === 'urgent') {
            body.is_urgent = actionBtn.getAttribute('data-urgent') === '1';
          } else if (action === 'duplicate') {
            endpoint += '/duplicate';
            method = 'POST';
          } else if (action === 'archive') {
            endpoint += '/archive';
          } else if (action === 'delete') {
            method = 'DELETE';
          } else {
            return;
          }

          await apiFetch(endpoint, {
            method,
            body: method === 'DELETE' || (method === 'POST' && action === 'duplicate') ? undefined : JSON.stringify(body)
          });
          showToast('Task updated', 'success');
          window.location.reload();
        } catch (err) {
          showToast(err.message, 'error');
        }
        return;
      }

      const titleBtn = e.target.closest('[data-open-task]');
      if (titleBtn) {
        hideTaskMenus();
        openTaskDetailModal(titleBtn.getAttribute('data-open-task'));
        return;
      }

      if (!e.target.closest('.task-dropdown')) hideTaskMenus();
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.task-card')) hideTaskMenus();
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

    const activityList = modal.querySelector('#detail-activity-list');
    if (activityList) {
      activityList.replaceChildren();
      const notes = ((allData.notes || {})[taskId] || []).map((item) => ({
        type: 'note', content: item.content, author_name: item.author_name,
        author_role: item.author_role, created_at: item.created_at
      }));
      const comments = ((allData.comments || {})[taskId] || []).map((item) => ({
        type: 'comment', content: item.message, author_name: item.author_name,
        author_role: item.author_role, created_at: item.created_at
      }));
      const activity = notes.concat(comments).sort((a, b) =>
        String(a.created_at || '').localeCompare(String(b.created_at || ''))
      );

      if (!activity.length) {
        const empty = document.createElement('p');
        empty.className = 'muted';
        empty.textContent = 'No activity yet. Start the conversation.';
        activityList.appendChild(empty);
      } else {
        activity.forEach((item) => {
          const entry = document.createElement('article');
          entry.className = 'task-activity-item ' + (item.type === 'note' ? 'is-note' : 'is-comment');
          const meta = document.createElement('div');
          meta.className = 'task-activity-meta';
          const author = document.createElement('strong');
          author.textContent = item.author_name || 'Workspace member';
          const kind = document.createElement('span');
          kind.className = 'badge ' + (item.type === 'note' ? 'badge-warn' : 'badge-info');
          kind.textContent = item.type === 'note' ? 'Internal note' : 'Comment';
          const role = document.createElement('span');
          role.className = 'muted';
          role.textContent = item.author_role || '';
          const content = document.createElement('p');
          content.textContent = item.content || '';
          meta.append(author, kind, role);
          entry.append(meta, content);
          activityList.appendChild(entry);
        });
      }
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
  // Client CSV Import: header mapping, preview and validated bulk submission
  // ------------------------------------------------------------------------
  function initClientCsvImport() {
    const fileInput = document.getElementById('client-csv-file');
    const mappingBox = document.getElementById('client-csv-mapping');
    const previewBox = document.getElementById('client-csv-preview');
    const statusBox = document.getElementById('client-csv-status');
    const importBtn = document.getElementById('client-csv-import');
    if (!fileInput || !mappingBox || !previewBox || !statusBox || !importBtn) return;

    const fields = [
      { key: 'agency_name', label: 'Company / Agency', aliases: ['agency_name', 'agency', 'company', 'company_name', 'business'] },
      { key: 'name', label: 'Contact name', aliases: ['name', 'contact', 'contact_name', 'full_name', 'executive_contact'] },
      { key: 'email', label: 'Email', aliases: ['email', 'primary_email', 'contact_email'] },
      { key: 'phone', label: 'Phone', aliases: ['phone', 'telephone', 'mobile'] },
      { key: 'state', label: 'State', aliases: ['state', 'province', 'region'] },
      { key: 'country', label: 'Country', aliases: ['country'] },
      { key: 'website', label: 'Website', aliases: ['website', 'url', 'company_website'] },
      { key: 'linkedin', label: 'LinkedIn', aliases: ['linkedin', 'linkedin_url'] },
      { key: 'additional_email', label: 'Additional email', aliases: ['additional_email', 'secondary_email', 'email_2'] },
      { key: 'additional_phone', label: 'Additional phone', aliases: ['additional_phone', 'secondary_phone', 'phone_2'] }
    ];
    let headers = [];
    let rows = [];
    let mappings = {};

    const normalize = (value) => String(value || '').trim().toLowerCase().replace(/[\s\-./]+/g, '_');

    function parseCsv(text, delimiter) {
      const result = [];
      let row = [], value = '', quoted = false;
      for (let i = 0; i < text.length; i++) {
        const char = text[i];
        if (quoted) {
          if (char === '"' && text[i + 1] === '"') { value += '"'; i++; }
          else if (char === '"') quoted = false;
          else value += char;
        } else if (char === '"') quoted = true;
        else if (char === delimiter) { row.push(value); value = ''; }
        else if (char === '\n') { row.push(value.replace(/\r$/, '')); result.push(row); row = []; value = ''; }
        else value += char;
      }
      if (value.length || row.length) { row.push(value.replace(/\r$/, '')); result.push(row); }
      return result.filter((r) => r.some((cell) => String(cell).trim() !== ''));
    }

    function renderMapping() {
      mappingBox.replaceChildren();
      fields.forEach((field) => {
        const wrap = document.createElement('div');
        wrap.className = 'form-group';
        const label = document.createElement('label');
        label.className = 'form-label';
        label.textContent = field.label;
        const select = document.createElement('select');
        select.className = 'select';
        select.dataset.mapField = field.key;
        const none = document.createElement('option');
        none.value = '-1'; none.textContent = 'Not mapped'; select.appendChild(none);
        headers.forEach((header, index) => {
          const option = document.createElement('option');
          option.value = String(index); option.textContent = header || ('Column ' + (index + 1));
          select.appendChild(option);
        });
        const found = headers.findIndex((header) => field.aliases.includes(normalize(header)));
        select.value = found >= 0 ? String(found) : '-1';
        select.addEventListener('change', () => { mappings[field.key] = Number(select.value); renderPreview(); });
        wrap.append(label, select); mappingBox.appendChild(wrap);
        mappings[field.key] = Number(select.value);
      });
    }

    function mappedRow(row) {
      const item = {};
      fields.forEach((field) => {
        const index = mappings[field.key];
        item[field.key] = Number.isInteger(index) && index >= 0 ? String(row[index] || '').trim() : '';
      });
      return item;
    }

    function renderPreview() {
      const required = ['agency_name', 'name', 'email'];
      const missing = required.filter((key) => !Number.isInteger(mappings[key]) || mappings[key] < 0);
      const mapped = rows.slice(0, 5).map(mappedRow);
      previewBox.replaceChildren();
      if (!rows.length) return;
      const table = document.createElement('table'); table.className = 'table';
      const thead = document.createElement('thead'); const tr = document.createElement('tr');
      ['Company', 'Contact', 'Email', 'Phone'].forEach((label) => { const th = document.createElement('th'); th.textContent = label; tr.appendChild(th); });
      thead.appendChild(tr); table.appendChild(thead);
      const tbody = document.createElement('tbody');
      mapped.forEach((item) => {
        const row = document.createElement('tr');
        ['agency_name', 'name', 'email', 'phone'].forEach((key) => { const td = document.createElement('td'); td.textContent = item[key] || '—'; row.appendChild(td); });
        tbody.appendChild(row);
      });
      table.appendChild(tbody); previewBox.appendChild(table);
      statusBox.textContent = rows.length + ' data row(s) detected. ' + (missing.length ? 'Map required columns: ' + missing.join(', ') + '.' : 'Previewing first ' + mapped.length + ' row(s).');
      importBtn.disabled = missing.length > 0 || rows.length === 0;
    }

    fileInput.addEventListener('change', async () => {
      importBtn.disabled = true; previewBox.replaceChildren(); mappingBox.replaceChildren(); rows = []; headers = [];
      const file = fileInput.files && fileInput.files[0];
      if (!file) { statusBox.textContent = 'Choose a CSV file to begin.'; return; }
      if (!/\.csv$/i.test(file.name) && file.type !== 'text/csv') { statusBox.textContent = 'Please select a .csv file. XLSX is not supported here.'; return; }
      if (file.size > 5 * 1024 * 1024) { statusBox.textContent = 'CSV exceeds the 5 MB limit.'; return; }
      try {
        const content = (await file.text()).replace(/^\uFEFF/, '');
        const firstLine = content.split(/\r?\n/, 1)[0] || '';
        const commaCount = (firstLine.match(/,/g) || []).length;
        const semicolonCount = (firstLine.match(/;/g) || []).length;
        const parsed = parseCsv(content, semicolonCount > commaCount ? ';' : ',');
        if (parsed.length < 2) throw new Error('The CSV must include a header and at least one data row.');
        headers = parsed.shift().map((h) => h.trim());
        rows = parsed;
        renderMapping(); renderPreview();
      } catch (error) { statusBox.textContent = error.message; }
    });

    importBtn.addEventListener('click', async () => {
      const vaId = (document.getElementById('client-csv-va') || {}).value || '';
      const tlId = (document.getElementById('client-csv-tl') || {}).value || '';
      if (!vaId || !tlId) { showToast('Select a default VA and Team Leader.', 'error'); return; }
      const clients = rows.map((row) => Object.assign(mappedRow(row), { focus_va_id: vaId, team_leader_id: tlId }))
        .filter((item) => item.agency_name && item.name && item.email);
      if (!clients.length) { showToast('No valid client rows found.', 'error'); return; }
      importBtn.disabled = true;
      try {
        const result = await apiFetch('/api/v1/clients/bulk', { method: 'POST', body: JSON.stringify({ clients }) });
        showToast((result.imported || 0) + ' client(s) imported.', 'success');
        window.setTimeout(() => window.location.reload(), 500);
      } catch (error) {
        showToast(error.message, 'error'); importBtn.disabled = false;
      }
    });
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
