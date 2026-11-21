<?php
$calView = (string) ($data['cal_view'] ?? 'week');
$calDate = (string) ($data['cal_date'] ?? date('Y-m-d'));
$calMonth = (string) ($data['cal_month'] ?? substr($calDate, 0, 7));
$rangeStart = (string) ($data['cal_range_start'] ?? ($calMonth . '-01'));
$rangeEnd = (string) ($data['cal_range_end'] ?? date('Y-m-t', strtotime($rangeStart)));
$userTzName = (string) ($data['user_timezone'] ?? (date_default_timezone_get() ?: 'UTC'));
try {
    $tzObj = new DateTimeZone($userTzName);
} catch (Throwable) {
    $tzObj = new DateTimeZone('UTC');
}
$todayYmd = (string) ($data['today_in_tz'] ?? (new DateTimeImmutable('now', $tzObj))->format('Y-m-d'));
$tzAbbr = (new DateTimeImmutable('now', $tzObj))->format('T');

$firstDay = new DateTimeImmutable($calMonth . '-01', $tzObj);
$monthEndDay = $firstDay->modify('last day of this month');
$daysInMonth = (int) $firstDay->format('t');
$startDow = (int) $firstDay->format('N'); // 1 (Mon) to 7 (Sun)

$gcalStatus = $data['google_calendar_status'] ?? ['connected' => false, 'needs_reconnect' => false, 'email' => null];
$syncedMeetingLinkMap = is_array($data['synced_meeting_map'] ?? null) ? $data['synced_meeting_map'] : [];
$syncedMeetingMap = array_flip($data['synced_meeting_ids'] ?? []);
$syncedTaskMap = array_flip($data['synced_task_ids'] ?? []);

$formatHourLabel = static function (int $h): string {
    if ($h === 0) {
        return '12 AM';
    }
    if ($h < 12) {
        return $h . ' AM';
    }
    if ($h === 12) {
        return '12 PM';
    }
    return ($h - 12) . ' PM';
};

$formatTimeAmPm = static function (DateTimeImmutable $dt): string {
    return $dt->format('i') === '00' ? $dt->format('g A') : $dt->format('g:i A');
};

// Build list of visible days for Week (7) or Day (1)
$visibleDays = [];
$curDayIt = new DateTimeImmutable($rangeStart, $tzObj);
$endDayLimit = new DateTimeImmutable($rangeEnd, $tzObj);
while ($curDayIt <= $endDayLimit) {
    $visibleDays[] = $curDayIt->format('Y-m-d');
    $curDayIt = $curDayIt->modify('+1 day');
}

$eventsByDate = [];
$allDayByDate = [];
$timedByDate = [];

foreach ($data['meetings'] as $m) {
    try {
        $dtStart = (new DateTimeImmutable((string) $m['start_time']))->setTimezone($tzObj);
        $dtEnd = (new DateTimeImmutable((string) $m['end_time']))->setTimezone($tzObj);
    } catch (Throwable) {
        continue;
    }

    $mType = (string) ($m['meeting_type'] ?? 'team_sync');
    $clientSuffix = !empty($m['client_name']) ? ' · ' . $m['client_name'] : '';
    $canModify = in_array($role, ['admin', 'team_leader'], true)
        || (string) $m['va_id'] === (string) $user['id']
        || (string) ($m['created_by_tl_id'] ?? '') === (string) $user['id'];
    $mid = (string) $m['id'];
    $isSynced = isset($syncedMeetingMap[$mid]) || filter_var($m['google_synced'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $isAllDay = filter_var($m['is_all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $extSource = (string) ($m['external_source'] ?? 'workly');
    $gHtmlLink = (string) ($syncedMeetingLinkMap[$mid] ?? '');

    $startAmPm = $formatTimeAmPm($dtStart);
    $endAmPm = $formatTimeAmPm($dtEnd);
    $timeRangeLabel = $isAllDay ? 'All Day' : ($startAmPm . ' – ' . $endAmPm);

    $baseMeta = [
        'id'         => $mid,
        'type'       => $mType,
        'title'      => (string) $m['title'],
        'va_name'    => (string) ($m['va_name'] ?? ''),
        'client'     => (string) ($m['client_name'] ?? ''),
        'link'       => (string) ($m['meeting_link'] ?? ''),
        'glink'      => $gHtmlLink,
        'notes'      => (string) ($m['notes'] ?? ''),
        'source'     => $extSource,
        'date_label' => $dtStart->format('D, M j, Y'),
        'time_range' => $timeRangeLabel,
        'draggable'  => $canModify,
        'synced'     => $isSynced,
        'is_all_day' => $isAllDay,
    ];

    $sDayStr = $dtStart->format('Y-m-d');
    $eDayStr = $dtEnd->format('Y-m-d');

    if ($isAllDay) {
        $dCur = max($rangeStart, $sDayStr);
        $dLim = min($rangeEnd, $eDayStr);
        $curObj = new DateTimeImmutable($dCur, $tzObj);
        $limObj = new DateTimeImmutable($dLim, $tzObj);
        while ($curObj <= $limObj) {
            $dKey = $curObj->format('Y-m-d');
            $item = $baseMeta + [
                'label'   => (string) $m['title'],
                'tooltip' => 'All Day · ' . $m['title'] . $clientSuffix . ($isSynced ? ' (Google Calendar)' : ''),
            ];
            $eventsByDate[$dKey][] = $item;
            $allDayByDate[$dKey][] = $item;
            $curObj = $curObj->modify('+1 day');
        }
    } else {
        // Place timed block across the day(s) it covers within range
        $dCur = max($rangeStart, $sDayStr);
        $dLim = min($rangeEnd, $eDayStr);
        $curObj = new DateTimeImmutable($dCur, $tzObj);
        $limObj = new DateTimeImmutable($dLim, $tzObj);
        while ($curObj <= $limObj) {
            $dKey = $curObj->format('Y-m-d');
            $dayStartMin = ($dKey === $sDayStr)
                ? (((int) $dtStart->format('G') * 60) + (int) $dtStart->format('i'))
                : 0;
            $dayEndMin = ($dKey === $eDayStr)
                ? (((int) $dtEnd->format('G') * 60) + (int) $dtEnd->format('i'))
                : 1440;
            if ($dayEndMin <= $dayStartMin) {
                $dayEndMin = min(1440, $dayStartMin + 30);
            }
            $durMin = max(28, $dayEndMin - $dayStartMin);

            $item = $baseMeta + [
                'label'     => $startAmPm . ' ' . $m['title'],
                'tooltip'   => $timeRangeLabel . ' · ' . $m['title'] . $clientSuffix . ($isSynced ? ' (Synced to Google Calendar)' : ''),
                'start_min' => $dayStartMin,
                'end_min'   => min(1440, $dayStartMin + $durMin),
                'dur_min'   => $durMin,
                'col_idx'   => 0,
                'col_total' => 1,
            ];
            $eventsByDate[$dKey][] = $item;
            $timedByDate[$dKey][] = $item;
            $curObj = $curObj->modify('+1 day');
        }
    }
}

// Assign non-overlapping column lanes for concurrent timed events per day
foreach ($timedByDate as $dKey => &$dayList) {
    usort($dayList, static fn (array $a, array $b): int => $a['start_min'] <=> $b['start_min']);
    $count = count($dayList);
    for ($i = 0; $i < $count; $i++) {
        $usedCols = [];
        for ($j = 0; $j < $i; $j++) {
            if ($dayList[$j]['end_min'] > $dayList[$i]['start_min']) {
                $usedCols[$dayList[$j]['col_idx']] = true;
            }
        }
        $col = 0;
        while (isset($usedCols[$col])) {
            $col++;
        }
        $dayList[$i]['col_idx'] = $col;
    }
    for ($i = 0; $i < $count; $i++) {
        $maxCol = $dayList[$i]['col_idx'];
        for ($j = 0; $j < $count; $j++) {
            if ($dayList[$j]['start_min'] < $dayList[$i]['end_min'] && $dayList[$j]['end_min'] > $dayList[$i]['start_min']) {
                $maxCol = max($maxCol, $dayList[$j]['col_idx']);
            }
        }
        $dayList[$i]['col_total'] = $maxCol + 1;
    }
}
unset($dayList);

foreach ($data['calendar_tasks'] as $ct) {
    $dKey = (string) $ct['deadline'];
    $isSynced = isset($syncedTaskMap[(string) $ct['id']]);
    $tItem = [
        'id'        => '',
        'type'      => 'task',
        'label'     => 'Due: ' . $ct['title'],
        'tooltip'   => 'Deliverable Deadline: ' . $ct['title'] . ($isSynced ? ' (Synced to Google Calendar)' : ''),
        'draggable' => false,
        'synced'    => $isSynced,
    ];
    $eventsByDate[$dKey][] = $tItem;
    $allDayByDate[$dKey][] = $tItem;
}

foreach (($data['calendar_pto'] ?? []) as $pto) {
    try {
        $pStart = new DateTimeImmutable((string) $pto['start_date'], $tzObj);
        $pEnd = new DateTimeImmutable((string) $pto['end_date'], $tzObj);
        $visStartObj = new DateTimeImmutable($rangeStart, $tzObj);
        $visEndObj = new DateTimeImmutable($rangeEnd, $tzObj);
        $cur = $pStart < $visStartObj ? $visStartObj : $pStart;
        $limit = $pEnd > $visEndObj ? $visEndObj : $pEnd;
        while ($cur <= $limit) {
            $dKey = $cur->format('Y-m-d');
            $ptoItem = [
                'id'        => '',
                'type'      => 'pto',
                'label'     => 'PTO: ' . $pto['va_name'],
                'tooltip'   => 'Approved PTO Leave: ' . $pto['va_name'],
                'draggable' => false,
            ];
            $eventsByDate[$dKey][] = $ptoItem;
            $allDayByDate[$dKey][] = $ptoItem;
            $cur = $cur->modify('+1 day');
        }
    } catch (Throwable) {
        // Skip invalid date range
    }
}

$selectedRepVa = $data['report_va'] ?? 'all';

$buildCalUrl = static function (array $overrides) use ($calView, $calDate, $calMonth, $data, $selectedRepVa): string {
    $q = array_merge([
        'tab'          => 'calendar',
        'cal_view'     => $calView,
        'cal_date'     => $calDate,
        'month'        => $calMonth,
        'start_date'   => $data['start_date'],
        'end_date'     => $data['end_date'],
        'rep_va'       => $selectedRepVa,
        'monitor_user' => $data['monitor_user'] ?? '',
    ], $overrides);
    $q = array_filter($q, static fn ($v): bool => $v !== '' && $v !== null);
    return '/calendar-reports?' . http_build_query($q);
};

$anchorCalObj = new DateTimeImmutable($calDate, $tzObj);
if ($calView === 'day') {
    $prevDate = $anchorCalObj->modify('-1 day')->format('Y-m-d');
    $nextDate = $anchorCalObj->modify('+1 day')->format('Y-m-d');
    $rangeHeading = $anchorCalObj->format('l, M j, Y');
} elseif ($calView === 'week') {
    $wStartObj = new DateTimeImmutable($rangeStart, $tzObj);
    $wEndObj = new DateTimeImmutable($rangeEnd, $tzObj);
    $prevDate = $anchorCalObj->modify('-7 days')->format('Y-m-d');
    $nextDate = $anchorCalObj->modify('+7 days')->format('Y-m-d');
    $rangeHeading = $wStartObj->format('M j') . ' – ' . $wEndObj->format('M j, Y');
} else {
    $prevMonthObj = $firstDay->modify('-1 month');
    $nextMonthObj = $firstDay->modify('+1 month');
    $prevDate = $prevMonthObj->format('Y-m-01');
    $nextDate = $nextMonthObj->format('Y-m-01');
    $rangeHeading = $firstDay->format('F Y');
}

$repTasks = $data['report_tasks'] ?? [];
$analytics = $data['report_analytics'] ?? [
    'total'               => 0,
    'completed'           => 0,
    'on_time'             => 0,
    'late'                => 0,
    'overdue'             => 0,
    'est_hours'           => 0.0,
    'extra_hours'         => 0.0,
    'total_hours'         => 0.0,
    'subtask_total'       => 0,
    'subtask_done'        => 0,
    'subtask_rate'        => 0.0,
    'tasks_with_subtasks' => 0,
    'status_counts'       => ['backlog' => 0, 'todo' => 0, 'in_progress' => 0, 'completed' => 0],
    'priority_counts'     => ['urgent' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
    'by_client'           => [],
    'by_va'               => [],
];

$repTotal = (int) ($analytics['total'] ?? 0);
$repDone = (int) ($analytics['completed'] ?? 0);
$repOnTime = (int) ($analytics['on_time'] ?? 0);
$repLate = (int) ($analytics['late'] ?? 0);
$repOverdue = (int) ($analytics['overdue'] ?? 0);
$repEstHours = (float) ($analytics['est_hours'] ?? 0.0);
$repExtraHours = (float) ($analytics['extra_hours'] ?? 0.0);
$repHours = (float) ($analytics['total_hours'] ?? 0.0);
$repSubTotal = (int) ($analytics['subtask_total'] ?? 0);
$repSubDone = (int) ($analytics['subtask_done'] ?? 0);
$repSubRate = (float) ($analytics['subtask_rate'] ?? 0.0);
$repTasksWithSub = (int) ($analytics['tasks_with_subtasks'] ?? 0);
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
    . "Checklist Steps: {$repSubDone}/{$repSubTotal} ({$repSubRate}%) across {$repTasksWithSub} deliverable(s)\n"
    . "Hours: " . number_format($repHours, 1) . "h (Est " . number_format($repEstHours, 1) . "h + Extra " . number_format($repExtraHours, 1) . "h)\n\n";
foreach ($repTasks as $rt) {
    $noteStr = !empty($rt['latest_note']) ? ' | Note: ' . $rt['latest_note'] : '';
    $subStr = ((int) ($rt['subtask_total'] ?? 0) > 0)
        ? " | Checklist: {$rt['subtask_done']}/{$rt['subtask_total']} ({$rt['subtask_status']})"
        : ' | Checklist: None';
    $summaryPlainText .= "- [{$rt['status']} | {$rt['sla_state']}] {$rt['title']} ({$rt['client_name']} / {$rt['va_name']}) | Due {$rt['deadline']}{$subStr}{$noteStr}\n";
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

<div class="gcal-sync-bar no-print">
  <div class="gcal-sync-info">
    <strong>Google Calendar Sync</strong>
    <span class="muted">Automatically adds assigned meetings &amp; task deadlines to each participant's Google Calendar.</span>
  </div>
  <div class="gcal-sync-actions">
    <?php if (!empty($gcalStatus['connected'])): ?>
      <div class="gmail-account-capsule">
        <span class="gmail-live-segment" title="Google Calendar synchronization active">
          <span class="gmail-live-dot"></span>
          <span>Live</span>
        </span>
        <span class="gmail-capsule-divider" aria-hidden="true"></span>
        <span class="gmail-account-email-chip" title="<?= SecurityHelper::e((string) ($gcalStatus['email'] ?? '')) ?>">
          <span><?= SecurityHelper::e((string) ($gcalStatus['email'] ?? 'Connected')) ?></span>
        </span>
        <div class="gmail-capsule-actions">
          <button type="button" id="btn-gcal-sync-now" class="gmail-capsule-btn" data-month="<?= SecurityHelper::e($calMonth) ?>" title="Sync this month's meetings and tasks to Google Calendar">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/>
            </svg>
            <span>Sync Now</span>
          </button>
        </div>
      </div>
    <?php elseif (!empty($gcalStatus['needs_reconnect'])): ?>
      <span class="badge badge-warn">Permission Needed (<?= SecurityHelper::e((string) ($gcalStatus['email'] ?? 'Google')) ?>)</span>
      <a href="/api/v1/email/google/connect?next=/calendar-reports" class="gsi-material-button">
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span>Authorize Google Calendar</span>
      </a>
    <?php else: ?>
      <a href="/api/v1/email/google/connect?next=/calendar-reports" class="gsi-material-button">
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
          <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
          <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>
        <span>Connect Google Calendar</span>
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="tab-strip no-print">
  <button type="button" class="tab-btn <?= $defaultTab === 'calendar' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="calendar">Calendar &amp; Meeting Scheduler</button>
  <button type="button" class="tab-btn <?= $defaultTab === 'reports' ? 'active' : '' ?>" data-tab-group="cal-rep" data-tab-btn="reports">Executive SLA Analytics</button>
</div>

<!-- Tab 1: Hourly & Monthly Calendar + Bidirectional Google Calendar Scheduler -->
<div class="card no-print <?= $defaultTab === 'calendar' ? '' : 'is-hidden' ?> cal-schedule-root"
     data-tab-group="cal-rep"
     data-tab-panel="calendar"
     data-gcal-connected="<?= !empty($gcalStatus['connected']) ? '1' : '0' ?>"
     data-cal-view="<?= SecurityHelper::e($calView) ?>"
     style="margin-bottom:24px;">
  <form method="GET" action="/calendar-reports" class="cal-toolbar-head">
    <input type="hidden" name="start_date" value="<?= SecurityHelper::e($data['start_date']) ?>">
    <input type="hidden" name="end_date" value="<?= SecurityHelper::e($data['end_date']) ?>">
    <input type="hidden" name="rep_va" value="<?= SecurityHelper::e($selectedRepVa) ?>">
    <input type="hidden" name="cal_view" value="<?= SecurityHelper::e($calView) ?>">
    <input type="hidden" name="tab" value="calendar">

    <div class="cal-toolbar-title-group">
      <h3><?= SecurityHelper::e($rangeHeading) ?></h3>
      <span class="muted" style="font-size:12px;">Click any hour slot to schedule a meeting or drag events between hours and days.</span>
    </div>

    <div class="cal-toolbar-controls">
      <div class="cal-nav-group" role="group" aria-label="Calendar Navigation">
        <a class="btn btn-secondary btn-sm"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_date' => $prevDate, 'month' => substr($prevDate, 0, 7)])) ?>"
           title="Previous">&lsaquo;</a>
        <a class="btn btn-secondary btn-sm"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_date' => $todayYmd, 'month' => substr($todayYmd, 0, 7)])) ?>">Today</a>
        <a class="btn btn-secondary btn-sm"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_date' => $nextDate, 'month' => substr($nextDate, 0, 7)])) ?>"
           title="Next">&rsaquo;</a>
      </div>

      <div class="cal-view-switch" role="group" aria-label="Calendar View">
        <a class="cal-view-btn <?= $calView === 'week' ? 'active' : '' ?>"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_view' => 'week'])) ?>">Week</a>
        <a class="cal-view-btn <?= $calView === 'day' ? 'active' : '' ?>"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_view' => 'day'])) ?>">Day</a>
        <a class="cal-view-btn <?= $calView === 'month' ? 'active' : '' ?>"
           href="<?= SecurityHelper::e($buildCalUrl(['cal_view' => 'month', 'month' => substr($calDate, 0, 7)])) ?>">Month</a>
      </div>

      <?php if ($calView === 'month'): ?>
        <input type="month" name="month" class="input input-sm" value="<?= SecurityHelper::e($calMonth) ?>" data-auto-submit style="width:auto;">
      <?php else: ?>
        <input type="date" name="cal_date" class="input input-sm" value="<?= SecurityHelper::e($calDate) ?>" data-auto-submit style="width:auto;">
      <?php endif; ?>

      <?php if ($role === 'admin' && !empty($data['staff_directory'])): ?>
        <select name="monitor_user" class="select input-sm" data-auto-submit style="width:auto;">
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

  <?php if ($calView === 'week' || $calView === 'day'): ?>
    <?php $colCount = count($visibleDays); ?>
    <div class="cal-hourly-shell <?= $calView === 'day' ? 'is-day-view' : 'is-week-view' ?>">
      <div class="cal-hourly-scroll-x">
        <!-- Sticky Day Header & All-Day Strip -->
        <div class="cal-hourly-head-row" style="--cal-cols: <?= $colCount ?>;">
          <div class="cal-ruler-corner" title="<?= SecurityHelper::e($userTzName) ?>">
            <span><?= SecurityHelper::e($tzAbbr) ?></span>
          </div>
          <?php foreach ($visibleDays as $dStr): ?>
            <?php
              $dObj = new DateTimeImmutable($dStr, $tzObj);
              $isTodayCol = ($dStr === $todayYmd);
            ?>
            <a class="cal-hourly-day-head <?= $isTodayCol ? 'is-today' : '' ?>"
               href="<?= SecurityHelper::e($buildCalUrl(['cal_view' => 'day', 'cal_date' => $dStr])) ?>"
               title="Open Day view for <?= SecurityHelper::e($dObj->format('M j, Y')) ?>">
              <span class="cal-head-dow"><?= SecurityHelper::e($dObj->format('D')) ?></span>
              <span class="cal-head-dom"><?= SecurityHelper::e($dObj->format('j')) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="cal-allday-row" style="--cal-cols: <?= $colCount ?>;">
          <div class="cal-allday-label">All Day</div>
          <?php foreach ($visibleDays as $dStr): ?>
            <div class="cal-allday-cell <?= $dStr === $todayYmd ? 'is-today' : '' ?>" data-cal-date="<?= SecurityHelper::e($dStr) ?>">
              <?php foreach (($allDayByDate[$dStr] ?? []) as $ev): ?>
                <?php
                  $evType = (string) ($ev['type'] ?? 'team_sync');
                  $chipClass = match ($evType) {
                      'task'                => 'task-chip',
                      'pto'                 => 'pto-chip',
                      'focus_block'         => 'focus-chip',
                      'availability_window' => 'avail-chip',
                      'google_event'        => 'google-chip',
                      default               => '',
                  };
                  $hasId = !empty($ev['id']);
                ?>
                <div class="cal-event-chip <?= $chipClass ?>"
                     <?= !empty($ev['draggable']) ? 'draggable="true"' : '' ?>
                     <?php if ($hasId): ?>
                       data-meeting-id="<?= SecurityHelper::e($ev['id']) ?>"
                       data-ev-title="<?= SecurityHelper::e($ev['title'] ?? '') ?>"
                       data-ev-date="<?= SecurityHelper::e($ev['date_label'] ?? '') ?>"
                       data-ev-time="<?= SecurityHelper::e($ev['time_range'] ?? 'All Day') ?>"
                       data-ev-type="<?= SecurityHelper::e($evType) ?>"
                       data-ev-client="<?= SecurityHelper::e($ev['client'] ?? '') ?>"
                       data-ev-va="<?= SecurityHelper::e($ev['va_name'] ?? '') ?>"
                       data-ev-link="<?= SecurityHelper::e($ev['link'] ?? '') ?>"
                       data-ev-glink="<?= SecurityHelper::e($ev['glink'] ?? '') ?>"
                       data-ev-notes="<?= SecurityHelper::e($ev['notes'] ?? '') ?>"
                       data-ev-source="<?= SecurityHelper::e($ev['source'] ?? 'workly') ?>"
                       data-ev-can-delete="<?= !empty($ev['draggable']) ? '1' : '0' ?>"
                     <?php endif; ?>
                     title="<?= SecurityHelper::e($ev['tooltip'] ?? $ev['label']) ?>">
                  <span class="cal-chip-label"><?= SecurityHelper::e($ev['label']) ?></span>
                  <?php if (!empty($ev['synced'])): ?>
                    <span class="cal-chip-gcal-dot" title="Synced to Google Calendar">G</span>
                  <?php endif; ?>
                  <?php if (!empty($ev['draggable']) && $hasId): ?>
                    <button type="button" class="cal-chip-del" data-delete-meeting="<?= SecurityHelper::e($ev['id']) ?>" title="Remove block" aria-label="Remove block">&times;</button>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Vertically Scrollable 24-Hour AM/PM Grid -->
        <div id="workly-cal-timeline-scroll" class="cal-timeline-scroll">
          <div class="cal-timeline-body" style="--cal-cols: <?= $colCount ?>;">
            <div class="cal-time-ruler" aria-hidden="true">
              <?php for ($h = 0; $h < 24; $h++): ?>
                <div class="cal-hour-label">
                  <span><?= SecurityHelper::e($formatHourLabel($h)) ?></span>
                </div>
              <?php endfor; ?>
            </div>

            <?php foreach ($visibleDays as $dStr): ?>
              <div class="cal-day-col <?= $dStr === $todayYmd ? 'is-today' : '' ?>" data-cal-date="<?= SecurityHelper::e($dStr) ?>">
                <?php for ($h = 0; $h < 24; $h++): ?>
                  <div class="cal-hour-slot"
                       data-cal-date="<?= SecurityHelper::e($dStr) ?>"
                       data-cal-hour="<?= $h ?>"
                       title="Schedule at <?= SecurityHelper::e($formatHourLabel($h)) ?>"></div>
                <?php endfor; ?>

                <div class="cal-timed-events-layer">
                  <?php foreach (($timedByDate[$dStr] ?? []) as $ev): ?>
                    <?php
                      $evType = (string) ($ev['type'] ?? 'team_sync');
                      $chipClass = match ($evType) {
                          'focus_block'         => 'focus-chip',
                          'availability_window' => 'avail-chip',
                          'google_event'        => 'google-chip',
                          'client_meeting'      => 'client-chip',
                          default               => 'team-chip',
                      };
                      $topPx = round(($ev['start_min'] / 60) * 56, 2);
                      $heightPx = max(24, round(($ev['dur_min'] / 60) * 56, 2));
                      $colTotal = max(1, (int) ($ev['col_total'] ?? 1));
                      $colIdx = max(0, (int) ($ev['col_idx'] ?? 0));
                      $widthPct = round(100 / $colTotal, 3);
                      $leftPct = round($colIdx * (100 / $colTotal), 3);
                    ?>
                    <div class="cal-timed-event <?= $chipClass ?>"
                         style="top:<?= $topPx ?>px;height:<?= $heightPx ?>px;left:calc(<?= $leftPct ?>% + 2px);width:calc(<?= $widthPct ?>% - 4px);"
                         <?= !empty($ev['draggable']) ? 'draggable="true"' : '' ?>
                         data-meeting-id="<?= SecurityHelper::e($ev['id']) ?>"
                         data-ev-title="<?= SecurityHelper::e($ev['title']) ?>"
                         data-ev-date="<?= SecurityHelper::e($ev['date_label']) ?>"
                         data-ev-time="<?= SecurityHelper::e($ev['time_range']) ?>"
                         data-ev-type="<?= SecurityHelper::e($evType) ?>"
                         data-ev-client="<?= SecurityHelper::e($ev['client'] ?? '') ?>"
                         data-ev-va="<?= SecurityHelper::e($ev['va_name'] ?? '') ?>"
                         data-ev-link="<?= SecurityHelper::e($ev['link'] ?? '') ?>"
                         data-ev-glink="<?= SecurityHelper::e($ev['glink'] ?? '') ?>"
                         data-ev-notes="<?= SecurityHelper::e($ev['notes'] ?? '') ?>"
                         data-ev-source="<?= SecurityHelper::e($ev['source'] ?? 'workly') ?>"
                         data-ev-can-delete="<?= !empty($ev['draggable']) ? '1' : '0' ?>"
                         title="<?= SecurityHelper::e($ev['tooltip']) ?>">
                      <div class="cal-timed-event-head">
                        <span class="cal-timed-time"><?= SecurityHelper::e($ev['time_range']) ?></span>
                        <?php if (!empty($ev['synced'])): ?>
                          <span class="cal-chip-gcal-dot" title="Synced to Google Calendar">G</span>
                        <?php endif; ?>
                      </div>
                      <div class="cal-timed-title"><?= SecurityHelper::e($ev['title']) ?></div>
                      <?php if (!empty($ev['draggable'])): ?>
                        <button type="button"
                                class="cal-chip-del cal-timed-del"
                                data-delete-meeting="<?= SecurityHelper::e($ev['id']) ?>"
                                title="Remove event"
                                aria-label="Remove event">&times;</button>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
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
                    'google_event'        => 'google-chip',
                    default               => '',
                };
                $hasId = !empty($ev['id']);
              ?>
              <div class="cal-event-chip <?= $chipClass ?><?= !empty($ev['synced']) ? ' is-gcal-synced' : '' ?>"
                   <?= !empty($ev['draggable']) ? 'draggable="true"' : '' ?>
                   <?php if ($hasId): ?>
                     data-meeting-id="<?= SecurityHelper::e($ev['id']) ?>"
                     data-ev-title="<?= SecurityHelper::e($ev['title'] ?? '') ?>"
                     data-ev-date="<?= SecurityHelper::e($ev['date_label'] ?? '') ?>"
                     data-ev-time="<?= SecurityHelper::e($ev['time_range'] ?? '') ?>"
                     data-ev-type="<?= SecurityHelper::e($evType) ?>"
                     data-ev-client="<?= SecurityHelper::e($ev['client'] ?? '') ?>"
                     data-ev-va="<?= SecurityHelper::e($ev['va_name'] ?? '') ?>"
                     data-ev-link="<?= SecurityHelper::e($ev['link'] ?? '') ?>"
                     data-ev-glink="<?= SecurityHelper::e($ev['glink'] ?? '') ?>"
                     data-ev-notes="<?= SecurityHelper::e($ev['notes'] ?? '') ?>"
                     data-ev-source="<?= SecurityHelper::e($ev['source'] ?? 'workly') ?>"
                     data-ev-can-delete="<?= !empty($ev['draggable']) ? '1' : '0' ?>"
                   <?php endif; ?>
                   data-meeting-type="<?= SecurityHelper::e($evType) ?>"
                   title="<?= SecurityHelper::e($ev['tooltip'] ?? $ev['label']) ?>">
                <span class="cal-chip-label"><?= SecurityHelper::e($ev['label']) ?></span>
                <?php if (!empty($ev['synced'])): ?>
                  <span class="cal-chip-gcal-dot" title="Synced to Google Calendar" aria-label="Synced to Google Calendar">G</span>
                <?php endif; ?>
                <?php if (!empty($ev['draggable']) && $hasId): ?>
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
  <?php endif; ?>
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
    <div class="card" style="background:var(--bg);padding:14px;">
      <div class="kpi-label">Checklist Completion</div>
      <div class="kpi-value tabular-nums" style="font-size:22px;color:var(--brand);"><?= $repSubRate ?>%</div>
      <div class="kpi-sub tabular-nums"><?= $repSubDone ?> / <?= $repSubTotal ?> steps across <?= $repTasksWithSub ?> task(s)</div>
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
  <h4 style="margin-bottom:10px;">Detailed Deliverables, Checklist &amp; Execution Notes Ledger</h4>
  <div class="table-wrap" style="margin-bottom:20px;">
    <table id="sla-tasks-table" class="table">
      <thead>
        <tr>
          <th>Deliverable &amp; Latest VA Note</th>
          <th>Checklist &amp; Status</th>
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
          <tr><td colspan="9" class="muted">No deliverables fall inside this custom date range.</td></tr>
        <?php else: ?>
          <?php foreach ($repTasks as $rt): ?>
            <?php
              $slaBadge = match ($rt['sla_state'] ?? 'Active') {
                  'On Time' => 'badge-success',
                  'Late'    => 'badge-warn',
                  'Overdue' => 'badge-danger',
                  default   => 'badge-info',
              };
              $subTotal = (int) ($rt['subtask_total'] ?? 0);
              $subDone = (int) ($rt['subtask_done'] ?? 0);
              $subPct = (int) ($rt['subtask_pct'] ?? 0);
              $subStatus = (string) ($rt['subtask_status'] ?? 'No Checklist');
              $subBadgeClass = match ($subStatus) {
                  'Complete'    => 'badge-success',
                  'In Progress' => 'badge-info',
                  'Not Started' => 'badge-warn',
                  default       => 'badge-neutral',
              };
              $subItems = $rt['subtasks'] ?? [];
            ?>
            <tr>
              <td>
                <strong><?= SecurityHelper::e($rt['title']) ?></strong>
                <?php if (!empty($rt['latest_note'])): ?>
                  <div class="muted" style="font-size:11.5px;margin-top:2px;">Note: <?= SecurityHelper::e($rt['latest_note']) ?></div>
                <?php endif; ?>
              </td>
              <td class="report-checklist-cell">
                <?php if ($subTotal === 0): ?>
                  <span class="badge badge-neutral">No Checklist</span>
                <?php else: ?>
                  <div class="report-checklist-head">
                    <span class="badge <?= $subBadgeClass ?>"><?= SecurityHelper::e($subStatus) ?></span>
                    <span class="mono tabular-nums" style="font-size:11.5px;font-weight:700;"><?= $subDone ?>/<?= $subTotal ?> (<?= $subPct ?>%)</span>
                  </div>
                  <ul class="report-checklist-items">
                    <?php foreach ($subItems as $sItem): ?>
                      <?php $isSubDone = !empty($sItem['is_completed']); ?>
                      <li class="<?= $isSubDone ? 'is-done' : '' ?>">
                        <span class="report-check-mark" aria-hidden="true"><?= $isSubDone ? '✓' : '○' ?></span>
                        <span><?= SecurityHelper::e((string) $sItem['title']) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
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
          <label class="form-label">Recipient Team Leader<?= $role === 'admin' ? ' / Admin' : '' ?></label>
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

<!-- Event Preview Modal (for Workly & Google Calendar synced events) -->
<div id="modal-cal-event-detail" class="modal-backdrop is-hidden">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="cal-detail-title" style="max-width:480px;">
    <div class="modal-head">
      <div>
        <span id="cal-detail-type-badge" class="badge badge-info" style="margin-bottom:6px;">Event</span>
        <h3 id="cal-detail-title">Calendar Event</h3>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-close-modal>Close</button>
    </div>
    <div class="modal-body" style="display:grid;gap:10px;">
      <div style="font-size:13.5px;font-weight:600;color:var(--ink);" id="cal-detail-datetime"></div>
      <div id="cal-detail-meta" class="muted" style="font-size:12.5px;"></div>
      <div id="cal-detail-notes" class="is-hidden" style="padding:10px 12px;border-radius:10px;background:var(--bg);border:1px solid var(--border);font-size:13px;white-space:pre-wrap;"></div>
    </div>
    <div class="modal-foot" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding-top:14px;border-top:1px solid var(--border);">
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a id="cal-detail-meet-link" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm is-hidden">Join Meeting</a>
        <a id="cal-detail-gcal-link" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm is-hidden">Open in Google Calendar</a>
      </div>
      <button type="button" id="cal-detail-delete-btn" class="btn btn-danger btn-sm is-hidden">Delete Event</button>
    </div>
  </div>
</div>
