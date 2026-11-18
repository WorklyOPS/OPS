/* ==========================================================================
   Workly Team Leader Performance Dashboard (Recharts Interactive Module)
   Visualizes Team Task Completion Rates (%) & Overdue Trends via Recharts
   ========================================================================== */

(function () {
  'use strict';

  function mountTeamLeaderPerformanceChart() {
    var rootEl = document.getElementById('tl-recharts-performance-root');
    var dataEl = document.getElementById('tl-performance-json');
    if (!rootEl || !dataEl) return;

    var sdk = window.WorklyChartsSDK;
    if (!sdk || !sdk.React || !sdk.createRoot || !sdk.Recharts) {
      rootEl.innerHTML = '<div class="notice notice-warn" style="margin:0;">Performance visualization library is unavailable. Refresh the page to retry.</div>';
      return;
    }

    var payload = {};
    try {
      payload = JSON.parse(dataEl.textContent || '{}');
    } catch (_err) {
      payload = {};
    }

    var React = sdk.React;
    var e = React.createElement;
    var rc = sdk.Recharts;
    var trend = Array.isArray(payload.trend) ? payload.trend : [];
    var vaRates = Array.isArray(payload.va_rates) ? payload.va_rates : [];

    function CustomTooltip(props) {
      if (!props || !props.active || !props.payload || !props.payload.length) return null;
      var row = props.payload[0].payload || {};
      var title = row.full_name ? row.full_name : (row.day_label || props.label || '');

      return e('div', { className: 'tl-recharts-tooltip' },
        e('div', { className: 'tl-recharts-tt-title' }, title),
        e('div', { className: 'tl-recharts-tt-grid' },
          e('span', { className: 'muted' }, 'Completion Rate'),
          e('strong', { style: { color: '#059669' } }, Number(row.completion_rate || 0).toFixed(1) + '%'),
          e('span', { className: 'muted' }, 'Completed'),
          e('strong', { style: { color: '#8822CE' } }, String(row.done_count != null ? row.done_count : (row.completed || 0)) + ' / ' + String(row.total_count != null ? row.total_count : (row.total_tasks || 0))),
          e('span', { className: 'muted' }, 'Overdue Tasks'),
          e('strong', { style: { color: Number(row.overdue_count || 0) > 0 ? '#DC2626' : '#059669' } }, String(row.overdue_count || 0))
        )
      );
    }

    function PerformanceWidget() {
      var modeState = React.useState('trend');
      var mode = modeState[0];
      var setMode = modeState[1];

      var hasTrend = trend.length > 0;
      var hasVas = vaRates.length > 0;

      return e('div', null,
        e('div', { className: 'tl-perf-toolbar' },
          e('div', { className: 'tab-strip', style: { marginBottom: 0 } },
            e('button', {
              type: 'button',
              className: 'tab-btn' + (mode === 'trend' ? ' active' : ''),
              onClick: function () { setMode('trend'); }
            }, '30-Day Completion & Overdue Trend'),
            e('button', {
              type: 'button',
              className: 'tab-btn' + (mode === 'va' ? ' active' : ''),
              onClick: function () { setMode('va'); }
            }, 'Per-VA Completion & Overdue')
          ),
          e('div', { className: 'tl-perf-legend' },
            e('span', { className: 'tl-legend-item' },
              e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
              'Completion Rate (%)'
            ),
            e('span', { className: 'tl-legend-item' },
              e('span', { className: 'tl-legend-swatch', style: { background: '#DC2626' } }),
              'Overdue Tasks'
            ),
            mode === 'trend'
              ? e('span', { className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#8822CE' } }),
                  'Completed Count'
                )
              : e('span', { className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#2563EB' } }),
                  'On-Time SLA (%)'
                )
          )
        ),

        mode === 'trend'
          ? (!hasTrend
              ? e('div', { className: 'notice notice-info', style: { marginTop: '14px', marginBottom: 0 } },
                  'No pod deliverables with deadlines in the past 30 days yet. Assign deliverables on the Kanban board to populate trends.'
                )
              : e('div', { className: 'tl-recharts-canvas' },
                  e(rc.ResponsiveContainer, { width: '100%', height: 300 },
                    e(rc.ComposedChart, { data: trend, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                      e('defs', null,
                        e('linearGradient', { id: 'worklyCompGrad', x1: '0', y1: '0', x2: '0', y2: '1' },
                          e('stop', { offset: '5%', stopColor: '#059669', stopOpacity: 0.28 }),
                          e('stop', { offset: '95%', stopColor: '#059669', stopOpacity: 0.02 })
                        )
                      ),
                      e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: '#EFEAF8', vertical: false }),
                      e(rc.XAxis, {
                        dataKey: 'short_date',
                        tick: { fontSize: 11, fill: '#64748B', fontWeight: 600 },
                        axisLine: { stroke: '#E8E1F2' },
                        tickLine: false
                      }),
                      e(rc.YAxis, {
                        yAxisId: 'rate',
                        domain: [0, 100],
                        unit: '%',
                        tick: { fontSize: 11, fill: '#059669', fontWeight: 600 },
                        axisLine: false,
                        tickLine: false
                      }),
                      e(rc.YAxis, {
                        yAxisId: 'count',
                        orientation: 'right',
                        allowDecimals: false,
                        tick: { fontSize: 11, fill: '#64748B', fontWeight: 600 },
                        axisLine: false,
                        tickLine: false
                      }),
                      e(rc.Tooltip, { content: e(CustomTooltip) }),
                      e(rc.ReferenceLine, { yAxisId: 'rate', y: 85, stroke: '#CBD5E1', strokeDasharray: '4 4' }),
                      e(rc.Bar, {
                        yAxisId: 'count',
                        dataKey: 'done_count',
                        name: 'Completed',
                        fill: '#8822CE',
                        radius: [6, 6, 0, 0],
                        barSize: 18
                      }),
                      e(rc.Bar, {
                        yAxisId: 'count',
                        dataKey: 'overdue_count',
                        name: 'Overdue',
                        fill: '#DC2626',
                        radius: [6, 6, 0, 0],
                        barSize: 18
                      }),
                      e(rc.Area, {
                        yAxisId: 'rate',
                        type: 'monotone',
                        dataKey: 'completion_rate',
                        name: 'Completion Rate (%)',
                        stroke: '#059669',
                        strokeWidth: 2.6,
                        fill: 'url(#worklyCompGrad)',
                        dot: { r: 3.5, fill: '#059669', stroke: '#FFFFFF', strokeWidth: 1.5 },
                        activeDot: { r: 5.5, fill: '#059669' }
                      })
                    )
                  )
                )
            )
          : (!hasVas
              ? e('div', { className: 'notice notice-info', style: { marginTop: '14px', marginBottom: 0 } },
                  'No active VAs with pod deliverables yet. Use Manage Pod VAs to add team members.'
                )
              : e('div', { className: 'tl-recharts-canvas' },
                  e(rc.ResponsiveContainer, { width: '100%', height: 300 },
                    e(rc.ComposedChart, { data: vaRates, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                      e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: '#EFEAF8', vertical: false }),
                      e(rc.XAxis, {
                        dataKey: 'name',
                        tick: { fontSize: 11.5, fill: '#334155', fontWeight: 700 },
                        axisLine: { stroke: '#E8E1F2' },
                        tickLine: false
                      }),
                      e(rc.YAxis, {
                        yAxisId: 'rate',
                        domain: [0, 100],
                        unit: '%',
                        tick: { fontSize: 11, fill: '#059669', fontWeight: 600 },
                        axisLine: false,
                        tickLine: false
                      }),
                      e(rc.YAxis, {
                        yAxisId: 'count',
                        orientation: 'right',
                        allowDecimals: false,
                        tick: { fontSize: 11, fill: '#DC2626', fontWeight: 600 },
                        axisLine: false,
                        tickLine: false
                      }),
                      e(rc.Tooltip, { content: e(CustomTooltip) }),
                      e(rc.Bar, {
                        yAxisId: 'rate',
                        dataKey: 'completion_rate',
                        name: 'Completion Rate (%)',
                        fill: '#059669',
                        radius: [7, 7, 0, 0],
                        barSize: 22
                      }),
                      e(rc.Bar, {
                        yAxisId: 'rate',
                        dataKey: 'on_time_rate',
                        name: 'On-Time SLA (%)',
                        fill: '#2563EB',
                        radius: [7, 7, 0, 0],
                        barSize: 22
                      }),
                      e(rc.Line, {
                        yAxisId: 'count',
                        type: 'monotone',
                        dataKey: 'overdue_count',
                        name: 'Overdue Tasks',
                        stroke: '#DC2626',
                        strokeWidth: 2.6,
                        dot: { r: 4, fill: '#DC2626', stroke: '#FFFFFF', strokeWidth: 1.5 }
                      })
                    )
                  )
                )
            )
      );
    }

    var root = sdk.createRoot(rootEl);
    root.render(e(PerformanceWidget));
  }

  function formatTurnaroundLabel(hours) {
    var h = Number(hours || 0);
    if (!h || h <= 0) return '0.0h';
    if (h >= 48) {
      return h.toFixed(1) + 'h (' + (h / 24).toFixed(1) + 'd)';
    }
    return h.toFixed(1) + 'h';
  }

  function mountTeamLeaderVaSummaryChart() {
    var rootEl = document.getElementById('tl-recharts-va-summary-root');
    var dataEl = document.getElementById('tl-va-summary-json');
    if (!rootEl || !dataEl) return;

    var sdk = window.WorklyChartsSDK;
    if (!sdk || !sdk.React || !sdk.createRoot || !sdk.Recharts) {
      rootEl.innerHTML = '<div class="notice notice-warn" style="margin:0;">Summary chart library is unavailable. Refresh the page to retry.</div>';
      return;
    }

    var payload = {};
    try {
      payload = JSON.parse(dataEl.textContent || '{}');
    } catch (_err) {
      payload = {};
    }

    var React = sdk.React;
    var e = React.createElement;
    var rc = sdk.Recharts;
    var vaRows = Array.isArray(payload.va_rates) ? payload.va_rates : [];

    function SummaryTooltip(props) {
      if (!props || !props.active || !props.payload || !props.payload.length) return null;
      var row = props.payload[0].payload || {};
      return e('div', { className: 'tl-recharts-tooltip' },
        e('div', { className: 'tl-recharts-tt-title' }, row.full_name || row.name || 'Virtual Assistant'),
        e('div', { className: 'tl-recharts-tt-grid' },
          e('span', { className: 'muted' }, 'Completion Rate'),
          e('strong', { style: { color: '#8822CE' } }, Number(row.completion_rate || 0).toFixed(1) + '%'),
          e('span', { className: 'muted' }, 'Avg Turnaround'),
          e('strong', { style: { color: '#D97706' } }, formatTurnaroundLabel(row.avg_turnaround_hours)),
          e('span', { className: 'muted' }, 'Completed / Total'),
          e('strong', null, String(row.completed || 0) + ' / ' + String(row.total_tasks || 0)),
          e('span', { className: 'muted' }, 'On-Time SLA'),
          e('strong', { style: { color: '#059669' } }, Number(row.on_time_rate || 100).toFixed(1) + '%')
        )
      );
    }

    function VaSummaryWidget() {
      var viewState = React.useState('combined');
      var viewMode = viewState[0];
      var setViewMode = viewState[1];

      if (!vaRows.length) {
        return e('div', { className: 'notice notice-info', style: { marginTop: '8px', marginBottom: 0 } },
          'No Virtual Assistants are assigned to your pod yet. Click Manage Pod VAs to build your team summary.'
        );
      }

      return e('div', null,
        e('div', { className: 'tl-perf-toolbar' },
          e('div', { className: 'tab-strip', style: { marginBottom: 0 } },
            e('button', {
              type: 'button',
              className: 'tab-btn' + (viewMode === 'combined' ? ' active' : ''),
              onClick: function () { setViewMode('combined'); }
            }, 'Completion & Turnaround'),
            e('button', {
              type: 'button',
              className: 'tab-btn' + (viewMode === 'turnaround' ? ' active' : ''),
              onClick: function () { setViewMode('turnaround'); }
            }, 'Turnaround Hours Comparison')
          ),
          e('div', { className: 'tl-perf-legend' },
            viewMode === 'combined'
              ? [
                  e('span', { key: 'cr', className: 'tl-legend-item' },
                    e('span', { className: 'tl-legend-swatch', style: { background: '#8822CE' } }),
                    'Completion Rate (%)'
                  ),
                  e('span', { key: 'sla', className: 'tl-legend-item' },
                    e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
                    'On-Time SLA (%)'
                  ),
                  e('span', { key: 'tat', className: 'tl-legend-item' },
                    e('span', { className: 'tl-legend-swatch', style: { background: '#D97706' } }),
                    'Avg Turnaround (hrs)'
                  )
                ]
              : e('span', { className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#D97706' } }),
                  'Average Turnaround Time (Hours)'
                )
          )
        ),

        e('div', { className: 'tl-recharts-canvas' },
          e(rc.ResponsiveContainer, { width: '100%', height: 290 },
            viewMode === 'combined'
              ? e(rc.ComposedChart, { data: vaRows, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                  e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: '#EFEAF8', vertical: false }),
                  e(rc.XAxis, {
                    dataKey: 'name',
                    tick: { fontSize: 11.5, fill: '#334155', fontWeight: 700 },
                    axisLine: { stroke: '#E8E1F2' },
                    tickLine: false
                  }),
                  e(rc.YAxis, {
                    yAxisId: 'rate',
                    domain: [0, 100],
                    unit: '%',
                    tick: { fontSize: 11, fill: '#8822CE', fontWeight: 600 },
                    axisLine: false,
                    tickLine: false
                  }),
                  e(rc.YAxis, {
                    yAxisId: 'tat',
                    orientation: 'right',
                    unit: 'h',
                    tick: { fontSize: 11, fill: '#D97706', fontWeight: 600 },
                    axisLine: false,
                    tickLine: false
                  }),
                  e(rc.Tooltip, { content: e(SummaryTooltip) }),
                  e(rc.Bar, {
                    yAxisId: 'rate',
                    dataKey: 'completion_rate',
                    name: 'Completion Rate (%)',
                    fill: '#8822CE',
                    radius: [7, 7, 0, 0],
                    barSize: 22
                  }),
                  e(rc.Bar, {
                    yAxisId: 'rate',
                    dataKey: 'on_time_rate',
                    name: 'On-Time SLA (%)',
                    fill: '#059669',
                    radius: [7, 7, 0, 0],
                    barSize: 22
                  }),
                  e(rc.Line, {
                    yAxisId: 'tat',
                    type: 'monotone',
                    dataKey: 'avg_turnaround_hours',
                    name: 'Avg Turnaround (hrs)',
                    stroke: '#D97706',
                    strokeWidth: 2.8,
                    dot: { r: 4.5, fill: '#D97706', stroke: '#FFFFFF', strokeWidth: 1.5 },
                    activeDot: { r: 6, fill: '#D97706' }
                  })
                )
              : e(rc.BarChart, { data: vaRows, margin: { top: 14, right: 18, left: -4, bottom: 4 } },
                  e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: '#EFEAF8', vertical: false }),
                  e(rc.XAxis, {
                    dataKey: 'name',
                    tick: { fontSize: 11.5, fill: '#334155', fontWeight: 700 },
                    axisLine: { stroke: '#E8E1F2' },
                    tickLine: false
                  }),
                  e(rc.YAxis, {
                    unit: 'h',
                    tick: { fontSize: 11, fill: '#D97706', fontWeight: 600 },
                    axisLine: false,
                    tickLine: false
                  }),
                  e(rc.Tooltip, { content: e(SummaryTooltip) }),
                  e(rc.Bar, {
                    dataKey: 'avg_turnaround_hours',
                    name: 'Avg Turnaround (hrs)',
                    fill: '#D97706',
                    radius: [8, 8, 0, 0],
                    barSize: 34
                  })
                )
          )
        ),

        e('div', { className: 'table-wrap tl-va-summary-table-wrap' },
          e('table', { className: 'table' },
            e('thead', null,
              e('tr', null,
                e('th', null, 'Virtual Assistant'),
                e('th', null, 'Completed'),
                e('th', null, 'Completion Rate'),
                e('th', null, 'Avg Turnaround'),
                e('th', null, 'On-Time SLA')
              )
            ),
            e('tbody', null,
              vaRows.map(function (row) {
                var rate = Number(row.completion_rate || 0);
                var sla = Number(row.on_time_rate || 100);
                return e('tr', { key: row.va_id || row.name },
                  e('td', null, e('strong', null, row.full_name || row.name)),
                  e('td', { className: 'tabular-nums' }, String(row.completed || 0) + ' / ' + String(row.total_tasks || 0)),
                  e('td', null,
                    e('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', minWidth: '120px' } },
                      e('div', { className: 'progress-track', style: { flex: 1, height: '6px' } },
                        e('div', { className: 'progress-fill', style: { width: Math.min(100, Math.max(0, rate)) + '%' } })
                      ),
                      e('span', { className: 'tabular-nums', style: { fontWeight: 700, fontSize: '12px' } }, rate.toFixed(1) + '%')
                    )
                  ),
                  e('td', { className: 'tabular-nums' }, formatTurnaroundLabel(row.avg_turnaround_hours)),
                  e('td', { className: 'tabular-nums', style: { color: sla >= 90 ? '#059669' : '#D97706', fontWeight: 700 } }, sla.toFixed(1) + '%')
                );
              })
            )
          )
        )
      );
    }

    var root = sdk.createRoot(rootEl);
    root.render(e(VaSummaryWidget));
  }

  function bootTeamLeaderCharts() {
    mountTeamLeaderVaSummaryChart();
    mountTeamLeaderPerformanceChart();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootTeamLeaderCharts);
  } else {
    bootTeamLeaderCharts();
  }
})();
