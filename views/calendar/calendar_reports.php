<?php
$calMonth = $data['cal_month'];
$firstDay = new DateTimeImmutable($calMonth . '-01');
$monthEndDay = $firstDay->modify('last day of this month');
$daysInMonth = (int) $firstDay->format('t');
$startDow = (int) $firstDay->format('N'); // 1 (Mon) to 7 (Sun)
$todayYmd = date('Y-m-d');

$eventsByDate = [];
foreach ($data['meetings'] as $m) {
    $dKey = substr((string) $m['start_time'], 0, 10);
    $mType = (string) ($m['meeting_type'] ?? 'team_sync');
    $clientSuffix = !empty($m['client_name']) ? ' · ' . $m['client_name'] : '';
    $canModify = in_array($role, ['admin', 'team_leader'], true)
        || (string) $m['va_id'] === (string) $user['id']
        || (string) ($m['created_by_tl_id'] ?? '') === (string) $user['id'];

    $eventsByDate[$dKey][] = [
        'id'        => (string) $m['id'],
        'type'      => $mType,
        'title'     => (string) $m['title'],
        'label'     => substr((string) $m['start_time'], 11, 5) . ' ' . $m['title'],
        'tooltip'   => substr((string) $m['start_time'], 11, 5) . '–' . substr((string) $m['end_time'], 11, 5) . ' ' . $m['title'] . $clientSuffix,
        'draggable' => $canModify,
    ];
}
foreach ($data['calendar_tasks'] as $ct) {
    $dKey = (string) $ct['deadline'];
    $eventsByDate[$dKey][] = [
        'id'        => '',
        'type'      => 'task',
        'label'     => 'Due: ' . $ct['title'],
        'tooltip'   => 'Deliverable Deadline: ' . $ct['title'],
        'draggable' => false,
    ];
}
foreach (($data['calendar_pto'] ?? []) as $pto) {
    try {
        $pStart = new DateTimeImmutable((string) $pto['start_date']);
        $pEnd = new DateTimeImmutable((string) $pto['end_date']);
        $cur = $pStart < $firstDay ? $firstDay : $pStart;
        $limit = $pEnd > $monthEndDay ? $monthEndDay : $pEnd;
        while ($cur <= $limit) {
            $eventsByDate[$cur->format('Y-m-d')][] = [
                'id'        => '',
                'type'      => 'pto',
                'label'     => 'PTO: ' . $pto['va_name'],
                'tooltip'   => 'Approved PTO Leave: ' . $pto['va_name'],
                'draggable' => false,
            ];
            $cur = $cur->modify('+1 day');
        }
    } catch (Throwable) {
        // Skip invalid date range
    }
}

$repTasks = $data['report_tasks'] ?? [];
$analytics = $data['report_analytics'] ?? [
    'total'           => 0,
    'completed'       => 0,
    'on_time'         => 0,
    'late'            => 0,
    'overdue'         => 0,
    'est_hours'       => 0.0,
    'extra_hours'     => 0.0,
    'total_hours'     => 0.0,
    'status_counts'   => ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'completed' => 0],
    'priority_counts' => ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
    'by_client'       => [],
    'by_va'           => [],
];

$repTotal = (int) ($analytics['total'] ?? 0);
$repDone = (int) ($analytics['completed'] ?? 0);
$repOnTime = (int) ($analytics['on_time'] ?? 0);
$repLate = (int) ($analytics['late'] ?? 0);
$repOverdue = (int) ($analytics['overdue'] ?? 0);
$repEstHours = (float) ($analytics['est_hours'] ?? 0.0);
$repExtraHours = (float) ($analytics['extra_hours'] ?? 0.0);
$repHours = (float) ($analytics['total_hours'] ?? 0.0);
$repCompletionRate = $repTotal > 0 ? round(($repDone / $repTotal) * 100, 1) : 0.0;
$repSla = $repDone > 0 ? round(($repOnTime / $repDone) * 100, 1) : ($repTotal === 0 ? 100.0 : 0.0);
$selectedRepVa = $data['report_va'] ?? 'all';

$scopeName = 'All Assigned Scope';
if ($role === 'va') {
    $scopeName = $user['name'];
} elseif ($selectedRepVa !== 'all') {
    foreach (($data['vas'] ?? []) as $v) {
        if ($v['id'] === $selectedRepVa) {
            $scopeName = $v['name'];
            break;
        }
    }
}

$summaryPlainText = "Workly Executive SLA Performance Report\n"
    . "Period: {$data['start_date']} to {$data['end_date']} | Scope: {$scopeName}\n"
    . "Prepared By: {$user['name']} ({$user['email']})\n"
    . "Total Tasks: {$repTotal} | Completed: {$repDone} ({$repCompletionRate}%) | SLA On-Time: {$repSla}% | Late: {$repLate} | Overdue: {$repOverdue}\n"
    . "Hours: " . number_format($repHours, 1) . "h (Est " . number_format($repEstHours, 1) . "h + Extra " . number_format($repExtraHours, 1) . "h)\n\n";
foreach ($repTasks as $rt) {
    $noteStr = !empty($rt['latest_note']) ? ' | Note: ' . $rt['latest_note'] : '';
    $summaryPlainText .= "- [{$rt['status']} | {$rt['sla_state']}] {$rt['title']} ({$rt['client_name']} / {$rt['va_name']}) | Due {$rt['deadline']}{$noteStr}\n";
}

$defaultTab = (isset($_GET['start_date']) || isset($_GET['end_date']) || isset($_GET['rep_va'])) && !isset($_GET['month']) ? 'reports' : 'calendar';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['calendar', 'reports'], true)) {
    $defaultTab = $_GET['tab'];
}
?>

<div class="page-header no-print">
  <div>
    <h1>Calendar &amp; Executive SLA Reports</h1>
    <p class="muted">Schedule meetings &amp; focus blocks synced with CRM client availability hours, and export executive SLA PDF reports.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-schedule-meeting">+ Schedule Meeting</button>
    <?php endif; ?>
    <button type="button"
            class="btn btn-secondary"
            data-export-csv="sla-tasks-table"
            data-csv-filename="workly_sla_report_<?= SecurityHelper::e($data['start_date']) ?>_to_<?= SecurityHelper::e($data['end_date']) ?>.csv">Export CSV</button>
    <button type="button" id="btn-print-sla" class="btn btn-primary">Export SLA Report PDF</button>
  </div>
</div>

<div class="tab-strip no-print">
  <button type="button" class="tab-btn <?= $defaultTab === 'calendar' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="calendar">Calendar &amp; Meeting Scheduler</button>
  <button type="button" class="tab-btn <?= $defaultTab === 'reports' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="reports">Executive SLA Analytics</button>
</div>

<!-- Tab 1: Monthly Calendar & Unified Client-Synced Scheduler -->
<div class="card no-print <?= $defaultTab === 'calendar' ? '' : 'is-hidden' ?>" data-tab-group="cal-rep" data-tab-panel="calendar" style="margin-bottom:24px;">
  <form method="GET" action="/calendar-reports" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
    <input type="hidden" name="start_date" value="<?= SecurityHelper::e($data['start_date']) ?>">
    <input type="hidden" name="end_date" value="<?= SecurityHelper::e($data['end_date']) ?>">
    <input type="hidden" name="rep_va" value="<?= SecurityHelper::e($selectedRepVa) ?>">
    <input type="hidden" name="tab" value="calendar">
    <div>
      <h3><?= SecurityHelper::e($firstDay->format('F Y')) ?> Operations Calendar</h3>
      <span class="muted" style="font-size:12px;">Click any calendar day to open + Schedule Meeting and scan client-synced open time slots.</span>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input type="month" name="month" class="input" value="<?= SecurityHelper::e($calMonth) ?>" data-auto-submit style="width:auto;">
      <?php if ($role === 'admin' && !empty($data['staff_directory'])): ?>
        <select name="monitor_user" class="select" data-auto-submit style="width:auto;">
          <option value="">My Calendar</option>
          <?php foreach ($data['staff_directory'] as $sd): ?>
            <option value="<?= SecurityHelper::e($sd['id']) ?>" <?= $data['monitor_user'] === $sd['id'] ? 'selected' : '' ?>>
              Monitor: <?= SecurityHelper::e($sd['name']) ?> (<?= SecurityHelper::e($sd['role']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
  </form>

  <div class="calendar-scroll-wrap">
    <div class="calendar-grid">
      <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
        <div class="cal-dow"><?= $dow ?></div>
      <?php endforeach; ?>

      <?php for ($pad = 1; $pad < $startDow; $pad++): ?>
        <div class="cal-cell outside"></div>
      <?php endfor; ?>

      <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
        <?php
          $dateStr = $calMonth . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
          $isToday = $dateStr === $todayYmd;
        ?>
        <div class="cal-cell<?= $isToday ? ' is-today' : '' ?>" data-cal-date="<?= SecurityHelper::e($dateStr) ?>">
          <div class="cal-day-num"><?= $day ?></div>
          <?php foreach (($eventsByDate[$dateStr] ?? []) as $ev): ?>
            <?php
              $evType = (string) ($ev['type'] ?? 'team_sync');
              $chipClass = match ($evType) {
                  'task'                => 'task-chip',
                  'pto'                 => 'pto-chip',
                  'focus_block'         => 'focus-chip',
                  'availability_window' => 'avail-chip',
                  default               => '',
              };
            ?>
            <div class="cal-event-chip <?= $chipClass ?>"
                 <?= !empty($ev['draggable']) ? 'draggable="true"' : '' ?>
                 <?= !empty($ev['id']) ? 'data-meeting-id="' . SecurityHelper::e($ev['id']) . '"' : '' ?>
                 data-meeting-type="<?= SecurityHelper::e($evType) ?>"
                 title="<?= SecurityHelper::e($ev['tooltip'] ?? $ev['label']) ?>">
              <span class="cal-chip-label"><?= SecurityHelper::e($ev['label']) ?></span>
              <?php if (!empty($ev['draggable']) && !empty($ev['id'])): ?>
                <button type="button"
                        class="cal-chip-del"
                        data-delete-meeting="<?= SecurityHelper::e($ev['id']) ?>"
                        title="Remove scheduled block"
                        aria-label="Remove scheduled block">&times;</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Tab 2: Detailed Executive SLA Analytics & Compact PDF Print Report -->
<div class="card print-report-panel <?= $defaultTab === 'reports' ? '' : 'is-hidden' ?>" data-tab-group="cal-rep" data-tab-panel="reports">
  <!-- Print-only Executive PDF Masthead -->
  <div class="print-only-header">
    <div>
      <div style="font-size:10.5px;font-weight:700;color:#8822CE;letter-spacing:0.06em;">WORKLY OPERATIONS SUITE</div>
      <h1 style="font-size:19px;margin-top:2px;">Executive SLA &amp; Deliverables Report</h1>
      <div style="font-size:11px;color:#475569;margin-top:3px;">
        Period: <strong><?= SecurityHelper::e($data['start_date']) ?></strong> to <strong><?= SecurityHelper::e($data['end_date']) ?></strong>
        · Scope: <strong><?= SecurityHelper::e($scopeName) ?></strong>
      </div>
    </div>
    <div style="text-align:right;font-size:10.5px;color:#475569;">
      <div>Prepared by: <strong><?= SecurityHelper::e($user['name']) ?></strong></div>
      <div>Generated: <?= SecurityHelper::e(gmdate('Y-m-d H:i')) ?> UTC</div>
    </div>
  </div>

  <div class="no-print" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:18px;">
    <div>
      <h2>Executive SLA Analytics Builder</h2>
      <p class="muted" style="font-size:12.5px;">
        Period: <strong><?= SecurityHelper::e($data['start_date']) ?></strong> to <strong><?= SecurityHelper::e($data['end_date']) ?></strong> · Scope: <strong><?= SecurityHelper::e($scopeName) ?></strong>
      </p>
    </div>

    <form method="GET" action="/calendar-reports" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
      <input type="hidden" name="month" value="<?= SecurityHelper::e($calMonth) ?>">
      <input type="hidden" name="tab" value="reports">
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Start Date</label>
        <input class="input" type="date" name="start_date" value="<?= SecurityHelper::e($data['start_date']) ?>" required>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">End Date</label>
        <input class="input" type="date" name="end_date" value="<?= SecurityHelper::e($data['end_date']) ?>" required>
      </div>
      <?php if ($role !== 'va' && !empty($data['vas'])): ?>
        <div class="form-group" style="margin-bottom:0;">
          <label class="form-label">Filter by VA</label>
          <select class="select" name="rep_va">
            <option value="all" <?= $selectedRepVa === 'all' ? 'selected' : '' ?>>All Pod VAs</option>
            <?php foreach ($data['vas'] as $v): ?>
              <option value="<?= SecurityHelper::e($v['id']) ?>" <?= $selectedRepVa === $v['id'] ? 'selected' : '' ?>>
                <?= SecurityHelper::e($v['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Run Analytics</button>
    </form>
  </div>

  <!-- 5-KPI Executive Grid (Compact 3x2 grid in @media print) -->
  <div class="sla-kpi-grid" style="margin-bottom:20px;">
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Total Deliverables</div>
      <div class="kpi-value" style="font-size:22px;"><?= $repTotal ?></div>
      <div class="kpi-sub">In date range</div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Completion Rate</div>
      <div class="kpi-value" style="font-size:22px;"><?= $repCompletionRate ?>%</div>
      <div class="kpi-sub"><?= $repDone ?> of <?= $repTotal ?> finished</div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">SLA On-Time Rate</div>
      <div class="kpi-value" style="font-size:22px;color:var(--success);"><?= $repSla ?>%</div>
      <div class="kpi-sub"><?= $repOnTime ?> on-time / <?= $repLate ?> late</div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Logged Hours</div>
      <div class="kpi-value" style="font-size:22px;"><?= number_format($repHours, 1) ?>h</div>
      <div class="kpi-sub"><?= number_format($repEstHours, 1) ?>h est + <?= number_format($repExtraHours, 1) ?>h extra</div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Overdue Alerts</div>
      <div class="kpi-value" style="font-size:22px;color:<?= $repOverdue > 0 ? 'var(--danger)' : 'var(--ink)' ?>;"><?= $repOverdue ?></div>
      <div class="kpi-sub">Past deadline &amp; open</div>
    </div>
  </div>

  <!-- Stage & Priority Analytics Row -->
  <?php
    $repStageMeta = [
        'backlog'     => ['label' => 'Backlog',     'color' => '#94A3B8'],
        'todo'        => ['label' => 'To Do',       'color' => '#A855F7'],
        'in_progress' => ['label' => 'In Progress', 'color' => '#2563EB'],
        'completed'   => ['label' => 'Completed',   'color' => '#059669'],
    ];
    $stCounts = $analytics['status_counts'] ?? [];
    $prCounts = $analytics['priority_counts'] ?? [];
    $maxRepStage = max(1, ...array_map('intval', array_values($stCounts ?: [1])));
  ?>
  <div class="grid-2" style="margin-bottom:20px;">
    <div class="card" style="background:var(--bg);">
      <h4 style="margin-bottom:10px;">Stage Distribution in Period</h4>
      <div class="svg-chart-wrap">
        <svg width="100%" height="170" viewBox="0 0 500 170" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Report Stage Breakdown">
          <line x1="16" y1="130" x2="484" y2="130" stroke="#D4C8EA" stroke-width="1"/>
          <?php $idx = 0; foreach ($repStageMeta as $k => $m): ?>
            <?php
              $cnt = (int) ($stCounts[$k] ?? 0);
              $bh = $cnt > 0 ? max(8, (int) round(($cnt / $maxRepStage) * 98)) : 4;
              $bx = 48 + ($idx * 114);
              $by = 130 - $bh;
              $idx++;
            ?>
            <g>
              <rect class="chart-bar-rect" x="<?= $bx ?>" y="<?= $by ?>" width="48" height="<?= $bh ?>" rx="6" fill="<?= SecurityHelper::e($m['color']) ?>" data-chart-tip="<?= SecurityHelper::e($m['label'] . ': ' . $cnt . ' task(s)') ?>"/>
              <text x="<?= $bx + 24 ?>" y="<?= $by - 5 ?>" text-anchor="middle" font-size="11" font-weight="800" fill="#0F172A"><?= $cnt ?></text>
              <text x="<?= $bx + 24 ?>" y="150" text-anchor="middle" font-size="10.5" font-weight="600" fill="#475569"><?= SecurityHelper::e($m['label']) ?></text>
            </g>
          <?php endforeach; ?>
        </svg>
      </div>
    </div>

    <div class="card" style="background:var(--bg);display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <h4 style="margin-bottom:12px;">Priority &amp; Punctuality Breakdown</h4>
        <div style="display:grid;gap:10px;margin-bottom:14px;">
          <?php foreach (['urgent' => ['Urgent Priority', '#B91C1C'], 'high' => ['High Priority', '#D97706'], 'medium' => ['Medium Priority', '#2563EB'], 'low' => ['Low Priority', '#64748B']] as $pk => [$plbl, $pcol]): ?>
            <?php
              $pcnt = (int) ($prCounts[$pk] ?? 0);
              $ppct = $repTotal > 0 ? round(($pcnt / $repTotal) * 100, 1) : 0;
            ?>
            <div>
              <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
                <strong><?= SecurityHelper::e($plbl) ?></strong>
                <span class="tabular-nums"><?= $pcnt ?> (<?= $ppct ?>%)</span>
              </div>
              <div class="progress-track">
                <div style="width:<?= $ppct ?>%;height:100%;background:<?= $pcol ?>;border-radius:999px;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--border);font-size:12px;">
        <span class="badge badge-success">On Time: <?= $repOnTime ?></span>
        <span class="badge badge-warn">Late Completed: <?= $repLate ?></span>
        <span class="badge badge-danger">Overdue Open: <?= $repOverdue ?></span>
      </div>
    </div>
  </div>

  <!-- Client Account & VA Assignee Breakdown Matrices -->
  <div class="grid-2" style="margin-bottom:20px;">
    <div class="card" style="background:var(--bg);">
      <h4 style="margin-bottom:10px;">SLA Breakdown by Client Account</h4>
      <?php if (empty($analytics['by_client'])): ?>
        <p class="muted" style="font-size:12.5px;">No client activity in range.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Client</th>
                <th>Done / Total</th>
                <th>On-Time</th>
                <th>Hours (Est+Extra)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($analytics['by_client'] as $cb): ?>
                <?php $cSla = $cb['completed'] > 0 ? round(($cb['on_time'] / $cb['completed']) * 100, 0) : 100; ?>
                <tr>
                  <td><strong><?= SecurityHelper::e($cb['name']) ?></strong></td>
                  <td class="tabular-nums"><?= (int) $cb['completed'] ?> / <?= (int) $cb['total'] ?></td>
                  <td class="tabular-nums"><?= $cSla ?>%</td>
                  <td class="tabular-nums"><?= number_format((float) $cb['est_hours'] + (float) $cb['extra_hours'], 1) ?>h</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="background:var(--bg);">
      <h4 style="margin-bottom:10px;">SLA Breakdown by VA Assignee</h4>
      <?php if (empty($analytics['by_va'])): ?>
        <p class="muted" style="font-size:12.5px;">No VA deliverables in range.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Virtual Assistant</th>
                <th>Done / Total</th>
                <th>On-Time</th>
                <th>Hours (Est+Extra)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($analytics['by_va'] as $vb): ?>
                <?php $vSla = $vb['completed'] > 0 ? round(($vb['on_time'] / $vb['completed']) * 100, 0) : 100; ?>
                <tr>
                  <td><strong><?= SecurityHelper::e($vb['name']) ?></strong></td>
                  <td class="tabular-nums"><?= (int) $vb['completed'] ?> / <?= (int) $vb['total'] ?></td>
                  <td class="tabular-nums"><?= $vSla ?>%</td>
                  <td class="tabular-nums"><?= number_format((float) $vb['est_hours'] + (float) $vb['extra_hours'], 1) ?>h</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Granular Deliverables Ledger -->
  <h4 style="margin-bottom:10px;">Detailed Deliverables &amp; Execution Notes Ledger</h4>
  <div class="table-wrap" style="margin-bottom:20px;">
    <table id="sla-tasks-table" class="table">
      <thead>
        <tr>
          <th>Deliverable &amp; Latest VA Note</th>
          <th>Priority</th>
          <th>Client</th>
          <th>Assigned VA</th>
          <th>Deadline</th>
          <th>Est / Extra Hrs</th>
          <th>Stage</th>
          <th>SLA State</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($repTasks)): ?>
          <tr><td colspan="8" class="muted">No deliverables fall inside this custom date range.</td></tr>
        <?php else: ?>
          <?php foreach ($repTasks as $rt): ?>
            <?php
              $slaBadge = match ($rt['sla_state'] ?? 'Active') {
                  'On Time' => 'badge-success',
                  'Late'    => 'badge-warn',
                  'Overdue' => 'badge-danger',
                  default   => 'badge-info',
              };
            ?>
            <tr>
              <td>
                <strong><?= SecurityHelper::e($rt['title']) ?></strong>
                <?php if (!empty($rt['latest_note'])): ?>
                  <div class="muted" style="font-size:11.5px;margin-top:2px;">Note: <?= SecurityHelper::e($rt['latest_note']) ?></div>
                <?php endif; ?>
              </td>
              <?php
                $rtPrio = strtolower((string) ($rt['priority'] ?? 'medium'));
                $rtPrioClass = match ($rtPrio) {
                    'urgent' => 'priority-urgent',
                    'high'   => 'priority-high',
                    'low'    => 'priority-low',
                    default  => 'priority-medium',
                };
              ?>
              <td><span class="badge priority-badge <?= $rtPrioClass ?>"><?= SecurityHelper::e(ucfirst($rtPrio)) ?></span></td>
              <td><?= SecurityHelper::e($rt['client_name']) ?></td>
              <td><?= SecurityHelper::e($rt['va_name']) ?></td>
              <td class="mono" style="font-size:12px;"><?= SecurityHelper::e($rt['deadline']) ?></td>
              <td class="tabular-nums">
                <?= number_format((float) $rt['estimated_hours'], 1) ?>h
                <?php if ((float) $rt['extra_hours_logged'] > 0): ?>
                  <span class="muted">(+<?= number_format((float) $rt['extra_hours_logged'], 1) ?>h)</span>
                <?php endif; ?>
              </td>
              <td><span class="badge badge-brand"><?= SecurityHelper::e(strtoupper(str_replace('_', ' ', (string) $rt['status']))) ?></span></td>
              <td><span class="badge <?= $slaBadge ?>"><?= SecurityHelper::e($rt['sla_state'] ?? 'Active') ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($data['team_leaders'])): ?>
    <div class="no-print" style="padding-top:16px;border-top:1px solid var(--border);">
      <h4 style="margin-bottom:8px;">Submit Report to Team Leader</h4>
      <form data-api-form="/api/v1/reports/dispatch" data-api-method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
        <input type="hidden" name="start_date" value="<?= SecurityHelper::e($data['start_date']) ?>">
        <input type="hidden" name="end_date" value="<?= SecurityHelper::e($data['end_date']) ?>">
        <input type="hidden" name="summary_text" value="<?= SecurityHelper::e($summaryPlainText) ?>">
        <div class="form-group" style="margin-bottom:0;min-width:0;flex:1 1 240px;">
          <label class="form-label">Recipient Team Leader / Admin</label>
          <select class="select" name="recipient_email" required>
            <?php foreach ($data['team_leaders'] as $tl): ?>
              <option value="<?= SecurityHelper::e($tl['email']) ?>"><?= SecurityHelper::e($tl['name']) ?> (<?= SecurityHelper::e($tl['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Send Report to Thread</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if (in_array($role, ['va', 'team_leader', 'admin'], true)): ?>
  <!-- Unified Right-Side Drawer: + Schedule Meeting (with Focus Block & CRM Client Slot Scanner) -->
  <div id="drawer-schedule-meeting" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Schedule Meeting or Focus Block">
      <div class="drawer-head">
        <div>
          <h3>Schedule Meeting &amp; Open Slot Scanner</h3>
          <div class="muted" style="font-size:12px;">Schedule team syncs, client calls, focus blocks, and scan open windows synced with CRM client hours.</div>
        </div>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form id="form-schedule-meeting" data-api-form="/api/v1/meetings" data-api-method="POST">
          <div class="form-group">
            <label class="form-label">Title</label>
            <input class="input" id="sched-title" name="title" required placeholder="Weekly Client Sync or Deep Work Block">
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Event Type</label>
              <select class="select" id="sched-meeting-type" name="meeting_type" required>
                <option value="team_sync">Team Meeting / Alignment</option>
                <option value="client_meeting">Client Sync Call</option>
                <option value="focus_block">Deep Work Focus Block</option>
                <option value="availability_window">Client Availability Window</option>
              </select>
            </div>

            <?php if ($role !== 'va' && !empty($data['vas'])): ?>
              <div class="form-group">
                <label class="form-label">Staff / VA Participant</label>
                <select class="select" id="sched-va-id" name="va_id" required>
                  <option value="<?= SecurityHelper::e($user['id']) ?>">Myself (<?= SecurityHelper::e($user['name']) ?>)</option>
                  <?php foreach ($data['vas'] as $v): ?>
                    <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" id="sched-va-id" name="va_id" value="<?= SecurityHelper::e($user['id']) ?>">
              <div class="form-group">
                <label class="form-label">Staff Participant</label>
                <input class="input" value="<?= SecurityHelper::e($user['name']) ?>" readonly>
              </div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label">CRM Client (Syncs Available Hours Scanner)</label>
            <select class="select" id="sched-client-id" name="client_id">
              <option value="">Internal / Personal Focus (No Client)</option>
              <?php foreach ($data['clients'] as $c): ?>
                <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;">
            <input type="checkbox" id="cb-inc-client" name="include_client" value="1">
            <label for="cb-inc-client" style="font-size:13px;">Include client participant on calendar invite</label>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Start Time</label>
              <input class="input" id="sched-start-time" type="datetime-local" name="start_time" value="<?= date('Y-m-d') ?>T09:00" required>
            </div>
            <div class="form-group">
              <label class="form-label">End Time</label>
              <input class="input" id="sched-end-time" type="datetime-local" name="end_time" value="<?= date('Y-m-d') ?>T10:00" required>
            </div>
          </div>

          <!-- Client-Synced Live Open Slot Scanner -->
          <div style="padding:12px;border-radius:10px;background:var(--bg);border:1px solid var(--border);margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
              <strong style="font-size:12.5px;">Live Open Slot Scanner</strong>
              <button type="button" id="btn-check-slots" class="btn btn-secondary btn-sm">Scan Open Slots</button>
            </div>
            <div id="focus-slots-preview" class="muted" style="font-size:12px;">
              Select a date and optionally a CRM Client, then click Scan Open Slots to see synchronized open windows.
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Video Conference URL (optional auto-generated)</label>
            <input class="input" name="meeting_link" placeholder="https://meet.google.com/...">
          </div>

          <div class="form-group">
            <label class="form-label">Agenda / Objective (Optional)</label>
            <textarea class="textarea" name="notes" rows="2" placeholder="Meeting topics or focus milestones..."></textarea>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Schedule &amp; Save</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
