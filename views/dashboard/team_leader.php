<?php
$podVas = $data['pod_vas'] ?? [];
$allVas = $data['all_vas'] ?? [];
$podClients = $data['pod_clients'] ?? [];
$trend = $data['completion_trend'] ?? [];
$statusCounts = $data['kanban_status_counts'] ?? [
    'backlog'     => 0,
    'todo'        => 0,
    'in_progress' => 0,
    'completed'   => 0,
];
$byVa = $data['kanban_by_va'] ?? [];
$vaRates = $data['va_completion_rates'] ?? [];
$vaSummaryTotals = $data['va_summary_totals'] ?? [
    'pod_completion_rate'      => 0.0,
    'pod_avg_turnaround_hours' => 0.0,
    'pod_avg_turnaround_days'  => 0.0,
    'pod_completed_tasks'      => (int) ($statusCounts['completed'] ?? 0),
    'pod_total_tasks'          => array_sum(array_map('intval', $statusCounts)),
    'assigned_va_count'        => count($vaRates),
];
$overdueTotal = (int) ($data['overdue_total'] ?? 0);
$activeDeliverables = $data['active_deliverables'] ?? [];

$totalKanbanTasks = array_sum(array_map('intval', $statusCounts));
$overallCompRate = $totalKanbanTasks > 0
    ? round(((int) ($statusCounts['completed'] ?? 0) / $totalKanbanTasks) * 100, 1)
    : 0.0;
$avgOnTimeSla = 100.0;
if (!empty($vaRates)) {
    $slaSum = 0.0;
    foreach ($vaRates as $vrItem) {
        $slaSum += (float) ($vrItem['on_time_rate'] ?? 100.0);
    }
    $avgOnTimeSla = round($slaSum / count($vaRates), 1);
}
$maxStatusCount = max(1, ...array_map('intval', array_values($statusCounts)));
$maxVaTotal = 1;
foreach ($byVa as $vr) {
    $maxVaTotal = max($maxVaTotal, (int) ($vr['total_tasks'] ?? 0));
}

$maxTrendTotal = 1;
foreach ($trend as $day) {
    $maxTrendTotal = max($maxTrendTotal, (int) $day['total_count']);
}

$stageMeta = [
    'backlog'     => ['label' => 'Backlog',     'color' => '#94A3B8'],
    'todo'        => ['label' => 'To Do',       'color' => '#A855F7'],
    'in_progress' => ['label' => 'In Progress', 'color' => '#2563EB'],
    'completed'   => ['label' => 'Completed',   'color' => '#059669'],
];
$urgentOpenCount = count(array_filter($activeDeliverables, static fn(array $d): bool => in_array($d['priority'] ?? '', ['urgent', 'high'], true)));
?>

<div class="page-header">
  <div>
    <h1>Team Leader Operations Hub</h1>
    <p class="muted">Real-time Kanban distribution by stage &amp; VA assignee, pod management, priority deliverables, and 30-day velocity.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <button type="button" class="btn btn-secondary" data-open-drawer="drawer-tl-pod">Manage Pod VAs</button>
    <a href="/tasks" class="btn btn-secondary">Open Kanban Board</a>
    <a href="/calendar-reports?tab=reports" class="btn btn-primary">Schedule Meeting &amp; SLA Report</a>
  </div>
</div>

<div class="grid-4" style="margin-bottom:22px;">
  <div class="card card-hover">
    <div class="kpi-label">Assigned Pod VAs</div>
    <div class="kpi-value"><?= count($podVas) ?></div>
    <div class="kpi-sub">Active roster</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Pod Deliverables Tracked</div>
    <div class="kpi-value"><?= $totalKanbanTasks ?></div>
    <div class="kpi-sub"><?= (int) $statusCounts['completed'] ?> completed</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Urgent &amp; High Priority</div>
    <div class="kpi-value" style="color:<?= $urgentOpenCount > 0 ? 'var(--danger)' : 'var(--ink)' ?>;"><?= $urgentOpenCount ?></div>
    <div class="kpi-sub">Active priority focus</div>
  </div>
  <div class="card card-hover">
    <div class="kpi-label">Managed Client Accounts</div>
    <div class="kpi-value"><?= count($podClients) ?></div>
    <div class="kpi-sub"><?= (int) ($alerts['pending_pto'] ?? 0) ?> pending PTO alert(s)</div>
  </div>
</div>

<!-- Recharts Visual Summary Component: Assigned VA Completion Rates & Average Turnaround Time -->
<section class="card" style="margin-bottom:22px;" aria-label="Assigned VA Completion Rate and Average Turnaround Summary">
  <div class="tl-perf-head">
    <div>
      <h3>Assigned VA Completion &amp; Turnaround Summary</h3>
      <p class="muted" style="font-size:12.5px;">Aggregated task completion rate (%) and average turnaround time across your assigned Virtual Assistants.</p>
    </div>

    <div class="tl-perf-summary">
      <div class="tl-perf-stat">
        <span class="muted">Pod Completion Rate</span>
        <strong class="tabular-nums" style="color:var(--brand-secondary);"><?= number_format((float) ($vaSummaryTotals['pod_completion_rate'] ?? $overallCompRate), 1) ?>%</strong>
      </div>
      <div class="tl-perf-stat">
        <span class="muted">Avg Turnaround Time</span>
        <strong class="tabular-nums" style="color:var(--warn);">
          <?= number_format((float) ($vaSummaryTotals['pod_avg_turnaround_hours'] ?? 0), 1) ?>h
          <span class="muted" style="font-size:12px;font-weight:600;">(<?= number_format((float) ($vaSummaryTotals['pod_avg_turnaround_days'] ?? 0), 1) ?>d)</span>
        </strong>
      </div>
      <div class="tl-perf-stat">
        <span class="muted">Completed / Total</span>
        <strong class="tabular-nums"><?= (int) ($vaSummaryTotals['pod_completed_tasks'] ?? 0) ?> / <?= (int) ($vaSummaryTotals['pod_total_tasks'] ?? 0) ?></strong>
      </div>
    </div>
  </div>

  <script type="application/json" id="tl-va-summary-json"><?= json_encode([
      'va_rates' => $vaRates,
      'totals'   => $vaSummaryTotals,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
  <div id="tl-recharts-va-summary-root" class="tl-recharts-host">
    <div class="skeleton-bar" style="height:290px;border-radius:12px;"></div>
  </div>
</section>

<!-- Recharts Performance Dashboard Component: Team Completion Rates & Overdue Trends -->
<section class="card" style="margin-bottom:22px;" aria-label="Team Task Completion & Overdue Trends">
  <div class="tl-perf-head">
    <div>
      <h3>Team Task Completion &amp; Overdue Performance</h3>
      <p class="muted" style="font-size:12.5px;">Interactive completion rate trajectory, overdue deliverables tracking, and per-VA SLA benchmarks.</p>
    </div>

    <div class="tl-perf-summary">
      <div class="tl-perf-stat">
        <span class="muted">Completion Rate</span>
        <strong class="tabular-nums" style="color:var(--success);"><?= number_format($overallCompRate, 1) ?>%</strong>
      </div>
      <div class="tl-perf-stat">
        <span class="muted">Overdue Tasks</span>
        <strong class="tabular-nums" style="color:<?= $overdueTotal > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $overdueTotal ?></strong>
      </div>
      <div class="tl-perf-stat">
        <span class="muted">Pod On-Time SLA</span>
        <strong class="tabular-nums" style="color:var(--brand-secondary);"><?= number_format($avgOnTimeSla, 1) ?>%</strong>
      </div>
    </div>
  </div>

  <script type="application/json" id="tl-performance-json"><?= json_encode([
      'trend'    => $trend,
      'va_rates' => $vaRates,
  ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
  <div id="tl-recharts-performance-root" class="tl-recharts-host">
    <div class="skeleton-bar" style="height:300px;border-radius:12px;"></div>
  </div>
</section>

<!-- Kanban Task Distribution Dashboard Component (Status & VA Assignee Bar Charts) -->
<section class="card" style="margin-bottom:22px;" aria-label="Kanban Task Distribution Analytics">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
    <div>
      <h3>Kanban Board Task Distribution</h3>
      <p class="muted" style="font-size:12px;">Interactive breakdown by Kanban stage and per Virtual Assistant assignee. Click any bar to jump to filtered tasks.</p>
    </div>

    <div class="tab-strip" style="margin-bottom:0;">
      <button type="button" class="tab-btn active" data-tab-group="tl-kanban-dist" data-tab-btn="by-status">By Kanban Status</button>
      <button type="button" class="tab-btn" data-tab-group="tl-kanban-dist" data-tab-btn="by-va">By VA Assignee</button>
    </div>
  </div>

  <!-- Tab 1: Bar Chart by Kanban Status -->
  <div data-tab-group="tl-kanban-dist" data-tab-panel="by-status">
    <?php
      $stKeys = array_keys($stageMeta);
      $slotW = 148;
      $svgW = max(720, count($stKeys) * $slotW + 40);
      $svgH = 240;
      $plotH = 165;
      $baseY = 185;
    ?>
    <div class="svg-chart-wrap" style="margin-bottom:14px;">
      <svg width="100%" height="<?= $svgH ?>" viewBox="0 0 <?= $svgW ?> <?= $svgH ?>" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Kanban Task Count Per Status Bar Chart">
        <?php for ($g = 1; $g <= 4; $g++): ?>
          <?php $gy = $baseY - (int) round(($g / 4) * $plotH); ?>
          <line x1="20" y1="<?= $gy ?>" x2="<?= $svgW - 20 ?>" y2="<?= $gy ?>" stroke="#EFEAF8" stroke-width="1" stroke-dasharray="3 3"/>
        <?php endfor; ?>
        <line x1="20" y1="<?= $baseY ?>" x2="<?= $svgW - 20 ?>" y2="<?= $baseY ?>" stroke="#D4C8EA" stroke-width="1.5"/>

        <?php foreach ($stKeys as $idx => $stKey): ?>
          <?php
            $cnt = (int) ($statusCounts[$stKey] ?? 0);
            $pct = $totalKanbanTasks > 0 ? round(($cnt / $totalKanbanTasks) * 100, 1) : 0.0;
            $meta = $stageMeta[$stKey];
            $barH = $cnt > 0 ? max(10, (int) round(($cnt / $maxStatusCount) * $plotH)) : 4;
            $barW = 68;
            $x = 46 + ($idx * $slotW);
            $y = $baseY - $barH;
            $tip = $meta['label'] . ': ' . $cnt . ' task(s) (' . $pct . '% of board)';
          ?>
          <g>
            <rect class="chart-bar-rect"
                  x="<?= $x ?>"
                  y="<?= $y ?>"
                  width="<?= $barW ?>"
                  height="<?= $barH ?>"
                  rx="8"
                  fill="<?= SecurityHelper::e($meta['color']) ?>"
                  data-chart-tip="<?= SecurityHelper::e($tip) ?>"
                  data-chart-href="/tasks"/>
            <text x="<?= $x + ($barW / 2) ?>" y="<?= $y - 7 ?>" text-anchor="middle" font-size="13" font-weight="800" fill="#0F172A"><?= $cnt ?></text>
            <text x="<?= $x + ($barW / 2) ?>" y="<?= $baseY + 20 ?>" text-anchor="middle" font-size="12" font-weight="700" fill="#334155"><?= SecurityHelper::e($meta['label']) ?></text>
            <text x="<?= $x + ($barW / 2) ?>" y="<?= $baseY + 37 ?>" text-anchor="middle" font-size="11" fill="#64748B"><?= $pct ?>%</text>
          </g>
        <?php endforeach; ?>
      </svg>
    </div>

    <div class="grid-4">
      <?php foreach ($stKeys as $stKey): ?>
        <?php
          $cnt = (int) ($statusCounts[$stKey] ?? 0);
          $pct = $totalKanbanTasks > 0 ? round(($cnt / $totalKanbanTasks) * 100, 1) : 0.0;
          $meta = $stageMeta[$stKey];
        ?>
        <div style="padding:10px 12px;border-radius:10px;background:var(--bg);border:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
          <span style="display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--ink-soft);">
            <span style="width:9px;height:9px;border-radius:3px;background:<?= SecurityHelper::e($meta['color']) ?>;"></span>
            <?= SecurityHelper::e($meta['label']) ?>
          </span>
          <strong class="tabular-nums" style="font-size:13.5px;"><?= $cnt ?> <span class="muted" style="font-weight:500;font-size:11px;">(<?= $pct ?>%)</span></strong>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Tab 2: Stacked Bar Chart by VA Assignee -->
  <div class="is-hidden" data-tab-group="tl-kanban-dist" data-tab-panel="by-va">
    <?php if (empty($byVa)): ?>
      <p class="muted" style="padding:18px 0;">No Virtual Assistants with pod deliverables recorded yet.</p>
    <?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:14px;font-size:12px;">
        <?php foreach ($stageMeta as $meta): ?>
          <span style="display:inline-flex;align-items:center;gap:6px;color:var(--ink-soft);font-weight:600;">
            <span style="width:10px;height:10px;border-radius:3px;background:<?= SecurityHelper::e($meta['color']) ?>;"></span>
            <?= SecurityHelper::e($meta['label']) ?>
          </span>
        <?php endforeach; ?>
      </div>

      <?php
        $vaSlot = 128;
        $vaSvgW = max(700, count($byVa) * $vaSlot + 44);
        $vaSvgH = 250;
        $vaPlotH = 165;
        $vaBaseY = 188;
      ?>
      <div class="svg-chart-wrap" style="margin-bottom:16px;">
        <svg width="100%" height="<?= $vaSvgH ?>" viewBox="0 0 <?= $vaSvgW ?> <?= $vaSvgH ?>" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Kanban Task Count Per VA Assignee Stacked Bar Chart">
          <?php for ($g = 1; $g <= 4; $g++): ?>
            <?php $gy = $vaBaseY - (int) round(($g / 4) * $vaPlotH); ?>
            <line x1="20" y1="<?= $gy ?>" x2="<?= $vaSvgW - 20 ?>" y2="<?= $gy ?>" stroke="#EFEAF8" stroke-width="1" stroke-dasharray="3 3"/>
          <?php endfor; ?>
          <line x1="20" y1="<?= $vaBaseY ?>" x2="<?= $vaSvgW - 20 ?>" y2="<?= $vaBaseY ?>" stroke="#D4C8EA" stroke-width="1.5"/>

          <?php foreach ($byVa as $idx => $vr): ?>
            <?php
              $tot = (int) ($vr['total_tasks'] ?? 0);
              $x = 44 + ($idx * $vaSlot);
              $barW = 54;
              $curY = $vaBaseY;
              $firstName = explode(' ', trim((string) $vr['va_name']))[0] ?? 'VA';
              $shortName = strlen($firstName) > 12 ? substr($firstName, 0, 11) . '…' : $firstName;
              $vaHref = '/tasks?va=' . urlencode((string) $vr['va_id']);
              $vaTip = $vr['va_name'] . ' — Total: ' . $tot . ' | Done: ' . (int) $vr['completed'] . ' | In Progress: ' . (int) $vr['in_progress'] . ' | To Do: ' . (int) $vr['todo'] . ' | Hrs: ' . number_format((float) $vr['total_hours'], 1) . 'h';
            ?>
            <g>
              <?php if ($tot === 0): ?>
                <rect x="<?= $x ?>" y="<?= $vaBaseY - 4 ?>" width="<?= $barW ?>" height="4" rx="2" fill="#E2E8F0" data-chart-tip="<?= SecurityHelper::e($vaTip) ?>" data-chart-href="<?= SecurityHelper::e($vaHref) ?>"/>
              <?php else: ?>
                <?php foreach (['completed', 'in_progress', 'todo', 'backlog'] as $stKey): ?>
                  <?php
                    $segCount = (int) ($vr[$stKey] ?? 0);
                    if ($segCount <= 0) continue;
                    $segH = max(5, (int) round(($segCount / $maxVaTotal) * $vaPlotH));
                    $curY -= $segH;
                    $segTip = $vr['va_name'] . ' · ' . $stageMeta[$stKey]['label'] . ': ' . $segCount . ' task(s)';
                  ?>
                  <rect class="chart-bar-rect"
                        x="<?= $x ?>"
                        y="<?= $curY ?>"
                        width="<?= $barW ?>"
                        height="<?= $segH ?>"
                        rx="3"
                        fill="<?= SecurityHelper::e($stageMeta[$stKey]['color']) ?>"
                        data-chart-tip="<?= SecurityHelper::e($segTip) ?>"
                        data-chart-href="<?= SecurityHelper::e($vaHref) ?>"/>
                <?php endforeach; ?>
              <?php endif; ?>
              <text x="<?= $x + ($barW / 2) ?>" y="<?= max(16, ($tot > 0 ? $curY : $vaBaseY - 4) - 7) ?>" text-anchor="middle" font-size="12" font-weight="800" fill="#0F172A"><?= $tot ?></text>
              <text x="<?= $x + ($barW / 2) ?>" y="<?= $vaBaseY + 20 ?>" text-anchor="middle" font-size="12" font-weight="700" fill="#334155"><?= SecurityHelper::e($shortName) ?></text>
              <text x="<?= $x + ($barW / 2) ?>" y="<?= $vaBaseY + 37 ?>" text-anchor="middle" font-size="10.5" fill="#64748B"><?= number_format((float) $vr['on_time_rate'], 0) ?>% SLA</text>
            </g>
          <?php endforeach; ?>
        </svg>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>VA Assignee</th>
              <th>Backlog</th>
              <th>To Do</th>
              <th>In Progress</th>
              <th>Completed</th>
              <th>Total Tasks</th>
              <th>Logged Hours</th>
              <th>SLA Rate</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($byVa as $vr): ?>
              <tr>
                <td>
                  <a href="/tasks?va=<?= urlencode((string) $vr['va_id']) ?>" style="font-weight:700;">
                    <?= SecurityHelper::e($vr['va_name']) ?>
                  </a>
                </td>
                <td class="tabular-nums"><?= (int) $vr['backlog'] ?></td>
                <td class="tabular-nums"><?= (int) $vr['todo'] ?></td>
                <td class="tabular-nums"><?= (int) $vr['in_progress'] ?></td>
                <td class="tabular-nums"><strong style="color:var(--success);"><?= (int) $vr['completed'] ?></strong></td>
                <td class="tabular-nums"><strong><?= (int) $vr['total_tasks'] ?></strong></td>
                <td class="tabular-nums"><?= number_format((float) $vr['total_hours'], 1) ?>h</td>
                <td><span class="badge badge-success"><?= number_format((float) $vr['on_time_rate'], 1) ?>%</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Priority & Active Pod Deliverables + 30-Day Velocity Trend -->
<div class="grid-2" style="margin-bottom:22px;">
  <section class="card" aria-label="Priority and Active Pod Deliverables">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div>
        <h3>Priority &amp; Active Pod Deliverables</h3>
        <p class="muted" style="font-size:12px;">Highest priority open deliverables across your pod sorted by urgency and deadline.</p>
      </div>
      <span class="badge <?= count($activeDeliverables) > 0 ? 'badge-brand' : 'badge-success' ?>"><?= count($activeDeliverables) ?> Active</span>
    </div>

    <?php if (empty($activeDeliverables)): ?>
      <div class="notice notice-info" style="margin-bottom:0;">All pod deliverables are completed. Great operational momentum.</div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px;max-height:310px;overflow-y:auto;">
        <?php foreach ($activeDeliverables as $ad): ?>
          <?php
            $adPrio = strtolower((string) ($ad['priority'] ?? 'medium'));
            $adMeta = match ($adPrio) {
                'urgent' => ['Urgent', 'priority-urgent'],
                'high'   => ['High', 'priority-high'],
                'low'    => ['Low', 'priority-low'],
                default  => ['Medium', 'priority-medium'],
            };
          ?>
          <div style="padding:12px 14px;border:1px solid var(--border);border-radius:11px;background:var(--bg);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="min-width:0;flex:1;">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <span class="badge priority-badge <?= $adMeta[1] ?>"><?= $adMeta[0] ?></span>
                <strong style="font-size:13.5px;"><?= SecurityHelper::e($ad['title']) ?></strong>
              </div>
              <div class="meta-inline" style="margin-top:4px;">
                <span><?= SecurityHelper::e($ad['va_name']) ?></span>
                <span>·</span>
                <span><?= SecurityHelper::e($ad['client_name']) ?></span>
                <span>·</span>
                <span class="mono">Due <?= SecurityHelper::e($ad['deadline']) ?></span>
              </div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;">
              <button type="button"
                      class="btn btn-primary btn-sm"
                      data-action-url="/api/v1/tasks/<?= SecurityHelper::e($ad['id']) ?>/status"
                      data-action-method="PATCH"
                      data-action-body='{"status":"completed"}'>Mark Completed</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" aria-label="30-Day Completion Velocity">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
      <div>
        <h3>30-Day Deliverable Velocity</h3>
        <p class="muted" style="font-size:12px;">Aggregated deliverables due vs. completed.</p>
      </div>
      <div style="display:flex;gap:12px;font-size:12px;">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#E9D5FF;margin-right:4px;"></span>Total Due</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#8822CE;margin-right:4px;"></span>Completed</span>
      </div>
    </div>

    <?php if (empty($trend)): ?>
      <p class="muted">No deliverables recorded in the past 30 days yet.</p>
    <?php else: ?>
      <?php
        $barSlot = 44;
        $chartW = max(520, count($trend) * $barSlot + 24);
        $chartH = 200;
        $plotH = 140;
      ?>
      <div class="svg-chart-wrap">
        <svg width="<?= $chartW ?>" height="<?= $chartH ?>" viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" role="img" aria-label="30-Day Completion Velocity">
          <line x1="12" y1="<?= $plotH + 16 ?>" x2="<?= $chartW - 12 ?>" y2="<?= $plotH + 16 ?>" stroke="#E8E1F2" stroke-width="1"/>
          <?php foreach ($trend as $idx => $day): ?>
            <?php
              $tot = (int) $day['total_count'];
              $done = (int) $day['done_count'];
              $x = 18 + ($idx * $barSlot);
              $totH = $tot > 0 ? max(8, (int) round(($tot / $maxTrendTotal) * $plotH)) : 4;
              $doneH = $done > 0 ? max(6, (int) round(($done / $maxTrendTotal) * $plotH)) : 0;
              $yTot = ($plotH + 16) - $totH;
              $yDone = ($plotH + 16) - $doneH;
              $shortDate = substr((string) $day['day_label'], 5);
              $dayTip = $day['day_label'] . ': ' . $done . ' completed out of ' . $tot . ' due';
            ?>
            <g>
              <rect class="chart-bar-rect" x="<?= $x ?>" y="<?= $yTot ?>" width="24" height="<?= $totH ?>" rx="5" fill="#F3E8FF" data-chart-tip="<?= SecurityHelper::e($dayTip) ?>"/>
              <?php if ($doneH > 0): ?>
                <rect class="chart-bar-rect" x="<?= $x ?>" y="<?= $yDone ?>" width="24" height="<?= $doneH ?>" rx="5" fill="#8822CE" data-chart-tip="<?= SecurityHelper::e($dayTip) ?>"/>
              <?php endif; ?>
              <text x="<?= $x + 12 ?>" y="<?= $yTot - 5 ?>" text-anchor="middle" font-size="10" font-weight="700" fill="#475569"><?= $done ?>/<?= $tot ?></text>
              <text x="<?= $x + 12 ?>" y="<?= $chartH - 12 ?>" text-anchor="middle" font-size="10" fill="#64748B"><?= SecurityHelper::e($shortDate) ?></text>
            </g>
          <?php endforeach; ?>
        </svg>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- Visual PTO Approval Calendar (Fetches upcoming pod leave from DB) -->
<?php require __DIR__ . '/../partials/pto_calendar_card.php'; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
  <h3>Assigned Virtual Assistants</h3>
  <button type="button" class="btn btn-secondary btn-sm" data-open-drawer="drawer-tl-pod">Edit Pod Assignment</button>
</div>
<?php if (empty($podVas)): ?>
  <div class="card" style="margin-bottom:22px;">
    <p class="muted">No Virtual Assistants are assigned to your pod yet. Click <strong>Manage Pod VAs</strong> to select VAs for your pod.</p>
  </div>
<?php else: ?>
  <div class="grid-3" style="margin-bottom:22px;">
    <?php foreach ($podVas as $va): ?>
      <div class="card card-hover">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
          <?php if (!empty($va['avatar_url'])): ?>
            <img src="<?= SecurityHelper::e($va['avatar_url']) ?>" alt="Avatar" class="avatar avatar-lg">
          <?php else: ?>
            <span class="avatar avatar-lg"><?= SecurityHelper::e(strtoupper(substr((string) $va['va_name'], 0, 2))) ?></span>
          <?php endif; ?>
          <div>
            <strong><?= SecurityHelper::e($va['va_name']) ?></strong>
            <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($va['va_email']) ?></div>
            <span class="badge badge-success" style="margin-top:4px;"><?= number_format((float) $va['on_time_rate'], 1) ?>% SLA</span>
          </div>
        </div>

        <div class="tl-va-stats-grid">
          <div>Completed: <strong class="tabular-nums"><?= (int) $va['completed_tasks'] ?></strong></div>
          <div>In Progress: <strong class="tabular-nums"><?= (int) $va['in_progress_tasks'] ?></strong></div>
          <div>PTO Balance: <strong class="tabular-nums"><?= (int) $va['pto_balance_days'] ?>d</strong></div>
          <div>Rate: <strong class="tabular-nums">$<?= number_format((float) $va['hourly_rate'], 2) ?>/h</strong></div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="/tasks?va=<?= urlencode((string) $va['va_id']) ?>" class="btn btn-secondary btn-sm" style="flex:1 1 110px;">Tasks</a>
          <a href="/profile?va_id=<?= urlencode((string) $va['va_id']) ?>" class="btn btn-secondary btn-sm" style="flex:1 1 110px;">Inspect Profile</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3 style="margin-bottom:12px;">Pod Client Capacity</h3>
<?php if (empty($podClients)): ?>
  <div class="card"><p class="muted">No client accounts assigned to your pod yet.</p></div>
<?php else: ?>
  <div class="grid-3">
    <?php foreach ($podClients as $cl): ?>
      <?php
        $capPct = (float) $cl['contracted_hours_per_month'] > 0
            ? min(100, round(((float) $cl['consumed_hours_month'] / (float) $cl['contracted_hours_per_month']) * 100, 1))
            : 0;
      ?>
      <div class="card card-hover">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <div>
            <strong><?= SecurityHelper::e($cl['agency_name']) ?></strong>
            <div class="muted" style="font-size:12px;"><?= SecurityHelper::e($cl['name']) ?></div>
          </div>
          <span class="badge badge-brand"><?= SecurityHelper::e(ucfirst($cl['contract_status'])) ?></span>
        </div>
        <div class="muted" style="font-size:12px;margin-bottom:8px;">Assigned VA: <?= SecurityHelper::e($cl['focus_va_name'] ?: 'Unassigned') ?></div>
        <div class="progress-track" style="margin-bottom:6px;">
          <div class="progress-fill" style="width:<?= $capPct ?>%;"></div>
        </div>
        <div class="muted tabular-nums" style="font-size:11.5px;"><?= number_format((float) $cl['consumed_hours_month'], 1) ?> / <?= number_format((float) $cl['contracted_hours_per_month'], 1) ?> hrs (<?= $capPct ?>%)</div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- Right-Side Drawer: Manage Pod VAs -->
<div id="drawer-tl-pod" class="drawer-backdrop is-hidden">
  <aside class="drawer-panel" role="dialog" aria-label="Manage Pod Virtual Assistants">
    <div class="drawer-head">
      <div>
        <h3>Manage Pod Virtual Assistants</h3>
        <div class="muted" style="font-size:12px;">Select or unassign VAs directly supervised in your operational pod.</div>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
    </div>
    <div class="drawer-body">
      <form id="form-tl-pod" data-api-form="/api/v1/users/pod-vas" data-api-method="PATCH">
        <input type="hidden" name="team_leader_id" value="<?= SecurityHelper::e($user['id']) ?>">
        <div class="form-group">
          <label class="form-label">Active Virtual Assistants</label>
          <div class="choice-grid">
            <?php foreach ($allVas as $av): ?>
              <?php $inPod = ($av['team_leader_id'] === $user['id']); ?>
              <label class="choice-pill">
                <input type="checkbox" name="va_ids[]" value="<?= SecurityHelper::e($av['id']) ?>"<?= $inPod ? ' checked' : '' ?>>
                <span><?= SecurityHelper::e($av['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
          <button type="submit" class="btn btn-primary">Save Pod Roster</button>
        </div>
      </form>
    </div>
  </aside>
</div>
