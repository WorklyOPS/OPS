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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountTeamLeaderPerformanceChart);
  } else {
    mountTeamLeaderPerformanceChart();
  }
})();
