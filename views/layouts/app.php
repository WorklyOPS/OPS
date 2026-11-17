<?php
$roleLabel = match ($role) {
    'admin'       => 'System Admin',
    'team_leader' => 'Team Leader',
    'hrm'         => 'HR Manager',
    default       => 'Virtual Assistant',
};

$initials = '';
foreach (explode(' ', trim((string) $user['name'])) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials ?: 'W', 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="workly-csrf" content="<?= SecurityHelper::e($csrfToken) ?>">
  <title><?= SecurityHelper::e($pageTitle) ?> — Workly</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/workly.css">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="workly-sidebar">
      <div class="sidebar-head">
        <a href="<?= $role === 'va' ? '/tasks' : '/dashboard' ?>" class="sidebar-brand" style="text-decoration:none;">
          <span class="brand-mark" style="width:30px;height:30px;font-size:14px;">W</span>
          <span>Workly</span>
        </a>
        <span class="badge badge-brand"><?= SecurityHelper::e($roleLabel) ?></span>
      </div>

      <nav class="sidebar-nav" aria-label="Workspace Navigation">
        <div class="nav-section-label">Workspace</div>

        <?php if ($role !== 'va'): ?>
          <a href="/dashboard" class="nav-link <?= $activeSection === 'dashboard' ? 'active' : '' ?>">
            <span class="nav-link-inner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
              <span>Overview</span>
            </span>
          </a>
        <?php endif; ?>

        <a href="/tasks" class="nav-link <?= $activeSection === 'tasks' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/></svg>
            <span>Tasks</span>
          </span>
          <?php if (($alerts['review_tasks'] ?? 0) > 0 && $role === 'team_leader'): ?>
            <span class="nav-badge" title="Tasks waiting in review"><?= (int) $alerts['review_tasks'] ?></span>
          <?php endif; ?>
        </a>

        <?php if ($role !== 'va'): ?>
          <a href="/va-directory" class="nav-link <?= $activeSection === 'va_directory' ? 'active' : '' ?>">
            <span class="nav-link-inner">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              <span>VA Directory</span>
            </span>
          </a>
        <?php endif; ?>

        <a href="/clients" class="nav-link <?= $activeSection === 'clients' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            <span>Clients</span>
          </span>
        </a>

        <a href="/calendar-reports" class="nav-link <?= $activeSection === 'calendar_reports' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <span>Calendar &amp; Reports</span>
          </span>
        </a>

        <div class="nav-section-label">People &amp; Comms</div>

        <a href="/pto" class="nav-link <?= $activeSection === 'pto' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
            <span>PTO &amp; Leave</span>
          </span>
          <?php if (($alerts['pending_pto'] ?? 0) > 0 && in_array($role, ['team_leader', 'hrm', 'admin'], true)): ?>
            <span class="nav-badge" title="Pending PTO requests"><?= (int) $alerts['pending_pto'] ?></span>
          <?php endif; ?>
        </a>

        <a href="/email" class="nav-link <?= $activeSection === 'email' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
            <span>Email Hub</span>
          </span>
          <?php if (($alerts['unread_emails'] ?? 0) > 0): ?>
            <span class="nav-badge"><?= (int) $alerts['unread_emails'] ?></span>
          <?php endif; ?>
        </a>

        <a href="/knowledge" class="nav-link <?= $activeSection === 'knowledge' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <span>Knowledge Base</span>
          </span>
        </a>

        <a href="/perks" class="nav-link <?= $activeSection === 'perks' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 16.5 6.5 19.5l1-6.2L3 8.9 9 8z"/></svg>
            <span>Perks &amp; Badges</span>
          </span>
        </a>

        <a href="/profile" class="nav-link <?= $activeSection === 'profile' ? 'active' : '' ?>">
          <span class="nav-link-inner">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/></svg>
            <span>My Profile</span>
          </span>
        </a>
      </nav>

      <div class="sidebar-foot">
        <?php if ($role === 'team_leader' && (($alerts['review_tasks'] ?? 0) > 0 || ($alerts['pending_pto'] ?? 0) > 0 || ($alerts['unread_tl_notifications'] ?? 0) > 0)): ?>
          <div class="sidebar-alert-box">
            <strong style="display:block;margin-bottom:4px;color:var(--brand);">Team Leader Alerts</strong>
            <?php if (($alerts['review_tasks'] ?? 0) > 0): ?>
              <div><?= (int) $alerts['review_tasks'] ?> task(s) awaiting review</div>
            <?php endif; ?>
            <?php if (($alerts['pending_pto'] ?? 0) > 0): ?>
              <div><?= (int) $alerts['pending_pto'] ?> PTO request(s) pending</div>
            <?php endif; ?>
            <?php if (($alerts['unread_tl_notifications'] ?? 0) > 0): ?>
              <div><a href="/pto"><?= (int) $alerts['unread_tl_notifications'] ?> unread PTO alert(s)</a></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <a href="/profile" id="sidebar-profile-anchor" class="sidebar-profile-card">
          <?php if (!empty($user['avatar_url'])): ?>
            <img src="<?= SecurityHelper::e($user['avatar_url']) ?>" alt="Profile" class="avatar">
          <?php else: ?>
            <span class="avatar"><?= SecurityHelper::e($initials) ?></span>
          <?php endif; ?>
          <div style="min-width:0;flex:1;">
            <div style="font-size:12.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= SecurityHelper::e($user['name']) ?></div>
            <div class="muted" style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= SecurityHelper::e($roleLabel) ?></div>
          </div>
        </a>
      </div>
    </aside>

    <div class="main-wrap">
      <header class="topbar">
        <div class="topbar-left">
          <button type="button" id="mobile-menu-toggle" class="mobile-menu-btn" aria-label="Toggle Menu">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
          </button>
          <div class="topbar-breadcrumb">
            <span class="muted">Workly</span>
            <span class="muted">/</span>
            <strong style="font-size:15px;color:var(--ink);"><?= SecurityHelper::e($pageTitle) ?></strong>
          </div>
        </div>

        <div class="topbar-right">
          <button type="button" id="btn-open-cmdk" class="cmdk-trigger no-print" title="Quick workspace navigation (Ctrl+K)">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <span>Quick Jump</span>
            <span class="kbd-hint">Ctrl+K</span>
          </button>

          <button type="button" id="btn-start-tour" class="btn btn-secondary btn-sm no-print">Guide</button>

          <a href="/profile" id="topbar-user-pill" class="user-pill" style="text-decoration:none;">
            <?php if (!empty($user['avatar_url'])): ?>
              <img src="<?= SecurityHelper::e($user['avatar_url']) ?>" alt="Avatar" class="avatar">
            <?php else: ?>
              <span class="avatar"><?= SecurityHelper::e($initials) ?></span>
            <?php endif; ?>
            <div style="line-height:1.25;">
              <div style="font-size:12.5px;font-weight:600;color:var(--ink);"><?= SecurityHelper::e($user['name']) ?></div>
              <div class="muted" style="font-size:11px;"><?= SecurityHelper::e($user['email']) ?></div>
            </div>
          </a>

          <a href="/logout" class="btn btn-secondary btn-sm no-print">Sign Out</a>
        </div>
      </header>

      <main class="page-content">
        <div id="workly-skeleton" class="skeleton-screen" aria-busy="true" aria-label="Loading section">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div>
              <div class="skeleton-bar" style="width:280px;height:26px;margin-bottom:8px;"></div>
              <div class="skeleton-bar" style="width:420px;max-width:75vw;height:14px;"></div>
            </div>
            <div class="skeleton-bar" style="width:160px;height:38px;border-radius:10px;"></div>
          </div>

          <?php if ($activeSection === 'tasks'): ?>
            <div class="skeleton-bar" style="height:58px;border-radius:14px;"></div>
            <div class="kanban-board">
              <?php for ($col = 0; $col < 5; $col++): ?>
                <div class="kanban-col" style="min-height:420px;">
                  <div class="skeleton-bar" style="width:62%;height:18px;margin-bottom:8px;"></div>
                  <div class="skeleton-card" style="height:126px;"></div>
                  <div class="skeleton-card" style="height:112px;"></div>
                  <?php if ($col < 3): ?>
                    <div class="skeleton-card" style="height:118px;"></div>
                  <?php endif; ?>
                </div>
              <?php endfor; ?>
            </div>
          <?php elseif ($activeSection === 'dashboard'): ?>
            <div class="grid-4">
              <div class="skeleton-card" style="height:106px;"></div>
              <div class="skeleton-card" style="height:106px;"></div>
              <div class="skeleton-card" style="height:106px;"></div>
              <div class="skeleton-card" style="height:106px;"></div>
            </div>
            <div class="skeleton-card" style="height:290px;"></div>
            <div class="grid-2">
              <div class="skeleton-card" style="height:220px;"></div>
              <div class="skeleton-card" style="height:220px;"></div>
            </div>
          <?php elseif ($activeSection === 'calendar_reports'): ?>
            <div class="skeleton-bar" style="width:320px;height:40px;border-radius:12px;"></div>
            <div class="grid-4">
              <div class="skeleton-card" style="height:92px;"></div>
              <div class="skeleton-card" style="height:92px;"></div>
              <div class="skeleton-card" style="height:92px;"></div>
              <div class="skeleton-card" style="height:92px;"></div>
            </div>
            <div class="skeleton-card" style="height:360px;"></div>
          <?php elseif ($activeSection === 'email'): ?>
            <div class="email-layout">
              <div class="skeleton-card" style="height:520px;"></div>
              <div class="skeleton-card" style="height:520px;"></div>
            </div>
          <?php else: ?>
            <div class="grid-3">
              <div class="skeleton-card" style="height:118px;"></div>
              <div class="skeleton-card" style="height:118px;"></div>
              <div class="skeleton-card" style="height:118px;"></div>
            </div>
            <div class="skeleton-card" style="height:280px;"></div>
          <?php endif; ?>
        </div>

        <div id="workly-view-content" class="is-hidden view-animate">
          <?php
          $viewFile = match ($activeSection) {
              'tasks'            => __DIR__ . '/../tasks/kanban.php',
              'dashboard'        => match ($role) {
                  'admin'       => __DIR__ . '/../dashboard/admin.php',
                  'team_leader' => __DIR__ . '/../dashboard/team_leader.php',
                  'hrm'         => __DIR__ . '/../dashboard/hrm.php',
                  default       => __DIR__ . '/../dashboard/va_profile.php',
              },
              'va_directory'     => __DIR__ . '/../dashboard/va_directory.php',
              'profile'          => __DIR__ . '/../dashboard/va_profile.php',
              'clients'          => __DIR__ . '/../clients/crm.php',
              'calendar_reports' => __DIR__ . '/../calendar/calendar_reports.php',
              'pto'              => __DIR__ . '/../pto/pto_manager.php',
              'email'            => __DIR__ . '/../email/email_hub.php',
              'knowledge'        => __DIR__ . '/../knowledge/knowledge_base.php',
              'perks'            => __DIR__ . '/../gamification/perks.php',
              default            => __DIR__ . '/../tasks/kanban.php',
          };
          require $viewFile;
          ?>
        </div>
      </main>
    </div>
  </div>

  <!-- Spotlight Command Palette (Ctrl+K / Cmd+K) -->
  <div id="workly-cmdk" class="cmdk-backdrop is-hidden" role="dialog" aria-modal="true" aria-label="Workspace Command Palette">
    <div class="cmdk-panel">
      <div class="cmdk-input-wrap">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted);flex-shrink:0;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="cmdk-search-input" class="cmdk-input" type="text" placeholder="Jump to workspace section, reports, CRM, PTO, or profile...">
        <span class="kbd-hint">ESC</span>
      </div>
      <div id="cmdk-results" class="cmdk-results">
        <?php if ($role !== 'va'): ?>
          <button type="button" class="cmdk-item" data-cmdk-href="/dashboard">
            <span><strong>Overview Dashboard</strong> — Role KPIs &amp; Operations Hub</span>
            <span class="kbd-hint">Overview</span>
          </button>
        <?php endif; ?>
        <button type="button" class="cmdk-item" data-cmdk-href="/tasks">
          <span><strong>Weekly Task Kanban</strong> — Drag &amp; drop, right-click actions, VA notes</span>
          <span class="kbd-hint">Kanban</span>
        </button>
        <?php if ($role !== 'va'): ?>
          <button type="button" class="cmdk-item" data-cmdk-href="/va-directory">
            <span><strong>VA Directory</strong> — Roster performance &amp; pod workload</span>
            <span class="kbd-hint">Roster</span>
          </button>
        <?php endif; ?>
        <button type="button" class="cmdk-item" data-cmdk-href="/clients">
          <span><strong>Clients CRM</strong> — Accounts, hour utilization &amp; PDF contracts</span>
          <span class="kbd-hint">CRM</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/calendar-reports">
          <span><strong>Calendar &amp; Executive SLA Reports</strong> — Schedule, CSV &amp; PDF export</span>
          <span class="kbd-hint">Reports</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/calendar-reports?tab=reports">
          <span><strong>Open SLA Performance Report Builder</strong> — Filter by VA &amp; date range</span>
          <span class="kbd-hint">SLA PDF</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/pto">
          <span><strong>PTO &amp; Leave Console</strong> — Submit, approve &amp; check coverage overlaps</span>
          <span class="kbd-hint">Leave</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/email">
          <span><strong>Email Hub</strong> — Unified PostgreSQL inbox &amp; threads</span>
          <span class="kbd-hint">Inbox</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/knowledge">
          <span><strong>Knowledge Base</strong> — SOP playbooks &amp; client runbooks</span>
          <span class="kbd-hint">SOPs</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/perks">
          <span><strong>Perks &amp; Badges</strong> — Recognition tiers &amp; staff benefits</span>
          <span class="kbd-hint">Perks</span>
        </button>
        <button type="button" class="cmdk-item" data-cmdk-href="/profile">
          <span><strong>My Profile</strong> — Edit bio/specialties, earnings ledger &amp; photo</span>
          <span class="kbd-hint">Profile</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Spotlight Onboarding Tour Overlay -->
  <div id="tour-overlay" class="tour-overlay is-hidden"></div>
  <div id="tour-tooltip" class="tour-tooltip is-hidden">
    <div id="tour-progress" class="muted" style="font-size:11px;font-weight:600;margin-bottom:4px;"></div>
    <h4 id="tour-title" style="margin-bottom:6px;"></h4>
    <p id="tour-desc" class="muted" style="font-size:12.5px;margin-bottom:14px;"></p>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" id="tour-close" class="btn btn-secondary btn-sm">Close</button>
      <button type="button" id="tour-next" class="btn btn-primary btn-sm">Next</button>
    </div>
  </div>

  <script src="/assets/js/app.js"></script>
</body>
</html>
