<?php
/**
 * Shared Visual PTO Approval Calendar Partial (Managers: Team Leader, HRM, Admin)
 * Hydrated dynamically via GET /api/v1/pto/calendar?month=YYYY-MM
 */
$calInitialMonth = SecurityHelper::sanitizePlainText($selectedMonth ?? date('Y-m'), 7);
if (!preg_match('/^\d{4}-\d{2}$/', $calInitialMonth)) {
    $calInitialMonth = date('Y-m');
}
?>
<section id="workly-pto-calendar"
         class="card pto-cal-section"
         data-pto-calendar
         data-initial-month="<?= SecurityHelper::e($calInitialMonth) ?>"
         aria-label="Visual PTO Approval Calendar"
         style="margin-bottom:22px;">
  <div class="pto-cal-header">
    <div>
      <h3>Team PTO Approval Calendar</h3>
      <p class="muted" style="font-size:12.5px;">Visual leave timeline, coverage overlap detection, and upcoming time-off approvals.</p>
    </div>

    <div class="pto-cal-controls">
      <div class="pto-cal-filter-group" role="group" aria-label="Filter PTO statuses">
        <button type="button" class="pto-filter-btn is-active" data-pto-cal-filter="all">All</button>
        <button type="button" class="pto-filter-btn" data-pto-cal-filter="pending">Pending</button>
        <button type="button" class="pto-filter-btn" data-pto-cal-filter="approved">Approved</button>
      </div>

      <div class="pto-cal-nav-group">
        <button type="button" class="btn btn-secondary btn-sm" data-pto-cal-nav="prev" aria-label="Previous Month">&lsaquo;</button>
        <strong class="pto-cal-month-title" data-pto-cal-title>Loading...</strong>
        <button type="button" class="btn btn-secondary btn-sm" data-pto-cal-nav="next" aria-label="Next Month">&rsaquo;</button>
        <button type="button" class="btn btn-secondary btn-sm" data-pto-cal-nav="today">Today</button>
      </div>
    </div>
  </div>

  <div class="pto-cal-legend">
    <span class="pto-leg-item"><span class="pto-leg-swatch swatch-approved"></span>Approved Leave</span>
    <span class="pto-leg-item"><span class="pto-leg-swatch swatch-pending"></span>Pending Approval</span>
    <span class="pto-leg-item"><span class="pto-leg-swatch swatch-rejected"></span>Declined</span>
    <span class="pto-leg-item muted" data-pto-cal-summary>Fetching database schedule...</span>
  </div>

  <div class="pto-cal-layout">
    <div class="pto-cal-grid-wrap" data-pto-cal-grid>
      <div class="skeleton-bar" style="height:280px;border-radius:12px;"></div>
    </div>

    <aside class="pto-cal-agenda" aria-label="Upcoming Time-Off Queue">
      <div class="pto-cal-agenda-head">
        <strong>Upcoming Time-Off</strong>
        <span class="muted tabular-nums" style="font-size:12px;" data-pto-upcoming-count>0</span>
      </div>
      <div class="pto-cal-agenda-list" data-pto-cal-upcoming>
        <div class="skeleton-row"></div>
        <div class="skeleton-row"></div>
      </div>
    </aside>
  </div>
</section>
