<?php
$calMonth = $data['cal_month'];
$firstDay = new DateTimeImmutable($calMonth . '-01');
$monthEndDay = $firstDay->modify('last day of this month');
$daysInMonth = (int) $firstDay->format('t');
$startDow = (int) $firstDay->format('N'); // 1 (Mon) to 7 (Sun)

$eventsByDate = [];
foreach ($data['meetings'] as $m) {
    $dKey = substr((string) $m['start_time'], 0, 10);
    $eventsByDate[$dKey][] = [
        'type'  => 'meeting',
        'label' => substr((string) $m['start_time'], 11, 5) . ' ' . $m['title'],
    ];
}
foreach ($data['calendar_tasks'] as $ct) {
    $dKey = (string) $ct['deadline'];
    $eventsByDate[$dKey][] = [
        'type'  => 'task',
        'label' => 'Due: ' . $ct['title'],
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
                'type'  => 'pto',
                'label' => 'PTO: ' . $pto['va_name'],
            ];
            $cur = $cur->modify('+1 day');
        }
    } catch (Throwable) {
        // Skip invalid date range
    }
}

// Compute SLA summary metrics from report_tasks
$repTasks = $data['report_tasks'] ?? [];
$repTotal = count($repTasks);
$repDone = 0;
$repOnTime = 0;
$repHours = 0.0;
foreach ($repTasks as $rt) {
    $repHours += (float) $rt['estimated_hours'] + (float) $rt['extra_hours_logged'];
    if ($rt['status'] === 'completed') {
        $repDone++;
        $compDate = $rt['completed_at'] ? substr((string) $rt['completed_at'], 0, 10) : (string) $rt['deadline'];
        if ($compDate <= (string) $rt['deadline']) {
            $repOnTime++;
        }
    }
}
$repSla = $repDone > 0 ? round(($repOnTime / $repDone) * 100, 1) : ($repTotal === 0 ? 100.0 : 0.0);

$summaryPlainText = "Workly SLA Performance Report\n"
    . "Period: {$data['start_date']} to {$data['end_date']}\n"
    . "Submitted By: {$user['name']} ({$user['email']})\n"
    . "Total Tasks: {$repTotal} | Completed: {$repDone} | SLA On-Time: {$repSla}% | Hours: " . number_format($repHours, 1) . "h\n\n";
foreach ($repTasks as $rt) {
    $summaryPlainText .= "- [{$rt['status']}] {$rt['title']} ({$rt['client_name']}) | Due {$rt['deadline']}\n";
}

// Preserve active tab if custom report filter dates were submitted
$defaultTab = (isset($_GET['start_date']) || isset($_GET['end_date'])) && !isset($_GET['month']) ? 'reports' : 'calendar';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['calendar', 'reports'], true)) {
    $defaultTab = $_GET['tab'];
}
?>

<div class="page-header no-print">
  <div>
    <h1>Calendar &amp; SLA Performance Reports</h1>
    <p class="muted">Private user calendar events, approved PTO blocks, Team Leader meetings, and custom date-range PDF SLA reporting.</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (in_array($role, ['team_leader', 'admin'], true)): ?>
      <button type="button" class="btn btn-secondary" data-open-drawer="drawer-schedule-meeting">+ Schedule Meeting</button>
    <?php endif; ?>
    <button type="button" id="btn-print-sla" class="btn btn-primary">Export SLA Report PDF</button>
  </div>
</div>

<div class="tab-strip no-print">
  <button type="button" class="tab-btn <?= $defaultTab === 'calendar' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="calendar">Monthly Schedule &amp; PTO</button>
  <button type="button" class="tab-btn <?= $defaultTab === 'reports' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="reports">SLA Performance Reports</button>
</div>

<!-- Tab 1: Private Role-Scoped Calendar -->
<div class="card no-print <?= $defaultTab === 'calendar' ? '' : 'is-hidden' ?>" data-tab-group="cal-rep" data-tab-panel="calendar" style="margin-bottom:24px;">
  <form method="GET" action="/calendar-reports" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
    <input type="hidden" name="start_date" value="<?= SecurityHelper::e($data['start_date']) ?>">
    <input type="hidden" name="end_date" value="<?= SecurityHelper::e($data['end_date']) ?>">
    <input type="hidden" name="tab" value="calendar">
    <div>
      <h3><?= SecurityHelper::e($firstDay->format('F Y')) ?> Schedule</h3>
      <span class="muted" style="font-size:12px;">Showing meetings, deliverable milestones &amp; approved PTO leave blocks.</span>
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

  <div class="calendar-grid">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow): ?>
      <div class="cal-dow"><?= $dow ?></div>
    <?php endforeach; ?>

    <?php for ($pad = 1; $pad < $startDow; $pad++): ?>
      <div class="cal-cell outside"></div>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
      <?php $dateStr = $calMonth . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT); ?>
      <div class="cal-cell">
        <div class="cal-day-num"><?= $day ?></div>
        <?php foreach (($eventsByDate[$dateStr] ?? []) as $ev): ?>
          <?php
            $chipClass = $ev['type'] === 'task'
                ? 'task-chip'
                : ($ev['type'] === 'pto' ? 'pto-chip' : '');
          ?>
          <div class="cal-event-chip <?= $chipClass ?>" title="<?= SecurityHelper::e($ev['label']) ?>">
            <?= SecurityHelper::e($ev['label']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<!-- Tab 2: Custom Date-Range SLA Performance Report Builder -->
<div class="card <?= $defaultTab === 'reports' ? '' : 'is-hidden' ?>" data-tab-group="cal-rep" data-tab-panel="reports">
  <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:18px;">
    <div>
      <h2>Custom SLA Performance Report</h2>
      <p class="muted" style="font-size:12.5px;">Period: <strong><?= SecurityHelper::e($data['start_date']) ?></strong> to <strong><?= SecurityHelper::e($data['end_date']) ?></strong></p>
    </div>

    <form method="GET" action="/calendar-reports" class="no-print" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
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
      <button type="submit" class="btn btn-secondary">Generate SLA Report</button>
    </form>
  </div>

  <div class="grid-4" style="margin-bottom:20px;">
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Total Deliverables</div>
      <div class="kpi-value" style="font-size:22px;"><?= $repTotal ?></div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Completed Deliverables</div>
      <div class="kpi-value" style="font-size:22px;"><?= $repDone ?></div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">SLA On-Time Compliance</div>
      <div class="kpi-value" style="font-size:22px;color:var(--success);"><?= $repSla ?>%</div>
    </div>
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Total Logged Hours</div>
      <div class="kpi-value" style="font-size:22px;"><?= number_format($repHours, 1) ?>h</div>
    </div>
  </div>

  <div class="table-wrap" style="margin-bottom:20px;">
    <table class="table">
      <thead>
        <tr>
          <th>Deliverable</th>
          <th>Client</th>
          <th>Assigned VA</th>
          <th>Deadline</th>
          <th>Hours</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($repTasks)): ?>
          <tr><td colspan="6" class="muted">No deliverables fall inside this custom date range.</td></tr>
        <?php else: ?>
          <?php foreach ($repTasks as $rt): ?>
            <tr>
              <td><strong><?= SecurityHelper::e($rt['title']) ?></strong></td>
              <td><?= SecurityHelper::e($rt['client_name']) ?></td>
              <td><?= SecurityHelper::e($rt['va_name']) ?></td>
              <td><?= SecurityHelper::e($rt['deadline']) ?></td>
              <td><?= number_format((float) $rt['estimated_hours'] + (float) $rt['extra_hours_logged'], 1) ?>h</td>
              <td><span class="badge badge-brand"><?= SecurityHelper::e(strtoupper((string) $rt['status'])) ?></span></td>
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
        <div class="form-group" style="margin-bottom:0;min-width:260px;flex:1;">
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

<?php if (in_array($role, ['team_leader', 'admin'], true)): ?>
  <div id="drawer-schedule-meeting" class="drawer-backdrop is-hidden">
    <aside class="drawer-panel" role="dialog" aria-label="Schedule Team Meeting">
      <div class="drawer-head">
        <h3>Schedule Team Meeting</h3>
        <button type="button" class="btn btn-secondary btn-sm" data-close-drawer>Close</button>
      </div>
      <div class="drawer-body">
        <form data-api-form="/api/v1/meetings" data-api-method="POST">
          <div class="form-group">
            <label class="form-label">Meeting Title</label>
            <input class="input" name="title" required placeholder="Weekly Alignment & Deliverable Review">
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Virtual Assistant Participant</label>
              <select class="select" name="va_id" required>
                <?php foreach ($data['vas'] as $v): ?>
                  <option value="<?= SecurityHelper::e($v['id']) ?>"><?= SecurityHelper::e($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Client (Optional)</label>
              <select class="select" name="client_id">
                <option value="">Internal Sync (No Client)</option>
                <?php foreach ($data['clients'] as $c): ?>
                  <option value="<?= SecurityHelper::e($c['id']) ?>"><?= SecurityHelper::e($c['agency_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group" style="flex-direction:row;align-items:center;gap:8px;">
            <input type="checkbox" id="cb-inc-client" name="include_client" value="1">
            <label for="cb-inc-client" style="font-size:13px;">Include client participant on calendar invite</label>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Start Time</label>
              <input class="input" type="datetime-local" name="start_time" required>
            </div>
            <div class="form-group">
              <label class="form-label">End Time</label>
              <input class="input" type="datetime-local" name="end_time" required>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Video Conference URL (optional auto-generated)</label>
            <input class="input" name="meeting_link" placeholder="https://meet.google.com/...">
          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button type="button" class="btn btn-secondary" data-close-drawer>Cancel</button>
            <button type="submit" class="btn btn-primary">Schedule &amp; Notify</button>
          </div>
        </form>
      </div>
    </aside>
  </div>
<?php endif; ?>
