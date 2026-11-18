/* ==========================================================================
   Workly Team Leader Performance Dashboard (Recharts Interactive Module)
   Visualizes Average Turnaround Time, Task Completion %, Productivity Trends,
   and Cross-Role VA Specialization Benchmarks via Recharts
   ========================================================================== */

(function () {
  'use strict';

  function formatTurnaroundLabel(hours) {
    var h = Number(hours || 0);
    if (!h || h <= 0) return '0.0h';
    if (h >= 48) {
      return h.toFixed(1) + 'h (' + (h / 24).toFixed(1) + 'd)';
    }
    return h.toFixed(1) + 'h';
  }

  function filterVasByRole(vaRows, selectedRole) {
    if (!selectedRole || selectedRole === 'all') return vaRows;
    return vaRows.filter(function (row) {
      if (Array.isArray(row.specialties) && row.specialties.indexOf(selectedRole) !== -1) {
        return true;
      }
      return row.primary_role === selectedRole;
    });
  }

  function mountTeamLeaderPerformanceChart() {
    var rootEl = document.getElementById('tl-recharts-performance-root');
    var dataEl = document.getElementById('tl-performance-json');
    if (!rootEl || !dataEl) return;

    var sdk = window.WorklyChartsSDK;
    var rc = (sdk && sdk.Recharts) ? sdk.Recharts : sdk;
    if (!sdk || !sdk.React || !sdk.createRoot || !rc || !rc.ResponsiveContainer || !rc.ComposedChart || !rc.BarChart) {
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
    var trend = Array.isArray(payload.trend) ? payload.trend : [];
    var vaRates = Array.isArray(payload.va_rates) ? payload.va_rates : [];
    var roleBenchmarks = Array.isArray(payload.role_benchmarks) ? payload.role_benchmarks : [];
    var availableRoles = Array.isArray(payload.available_roles) ? payload.available_roles : [];

    function CustomTooltip(props) {
      if (!props || !props.active || !props.payload || !props.payload.length) return null;
      var row = props.payload[0].payload || {};
      var title = row.role_name
        ? ('Role: ' + row.role_name)
        : (row.full_name ? row.full_name : (row.day_label || props.label || ''));

      return e('div', { className: 'tl-recharts-tooltip' },
        e('div', { className: 'tl-recharts-tt-title' }, title),
        e('div', { className: 'tl-recharts-tt-grid' },
          row.va_count != null ? [
            e('span', { key: 'vc-l', className: 'muted' }, 'Assigned VAs'),
            e('strong', { key: 'vc-v' }, String(row.va_count))
          ] : null,
          row.primary_role && !row.role_name ? [
            e('span', { key: 'pr-l', className: 'muted' }, 'VA Role'),
            e('strong', { key: 'pr-v' }, String((row.specialties && row.specialties.length) ? row.specialties.join(', ') : row.primary_role))
          ] : null,
          e('span', { className: 'muted' }, 'Completion Rate'),
          e('strong', { style: { color: '#059669' } }, Number(row.completion_rate || 0).toFixed(1) + '%'),
          row.productivity_score != null ? [
            e('span', { key: 'ps-l', className: 'muted' }, 'Productivity Score'),
            e('strong', { key: 'ps-v', style: { color: '#8822CE' } }, Number(row.productivity_score || 0).toFixed(1) + '%')
          ] : null,
          row.avg_turnaround_hours != null ? [
            e('span', { key: 'tat-l', className: 'muted' }, 'Avg Turnaround'),
            e('strong', { key: 'tat-v', style: { color: '#D97706' } }, formatTurnaroundLabel(row.avg_turnaround_hours))
          ] : null,
          e('span', { className: 'muted' }, 'Completed / Total'),
          e('strong', { style: { color: '#8822CE' } }, String(row.done_count != null ? row.done_count : (row.completed || 0)) + ' / ' + String(row.total_count != null ? row.total_count : (row.total_tasks || 0))),
          e('span', { className: 'muted' }, 'Overdue Tasks'),
          e('strong', { style: { color: Number(row.overdue_count || 0) > 0 ? '#DC2626' : '#059669' } }, String(row.overdue_count || 0))
        )
      );
    }

    function PerformanceWidget() {
      var defaultMode = roleBenchmarks.length > 0 ? 'roles' : 'trend';
      var modeState = React.useState(defaultMode);
      var mode = modeState[0];
      var setMode = modeState[1];

      var rangeState = React.useState(30);
      var daysWindow = rangeState[0];
      var setDaysWindow = rangeState[1];

      var trendFocusState = React.useState('productivity');
      var trendFocus = trendFocusState[0];
      var setTrendFocus = trendFocusState[1];

      var roleFilterState = React.useState('all');
      var selectedRole = roleFilterState[0];
      var setSelectedRole = roleFilterState[1];

      var filteredTrend = React.useMemo(function () {
        if (!trend.length) return [];
        if (daysWindow >= 30 || trend.length <= daysWindow) return trend;
        return trend.slice(trend.length - daysWindow);
      }, [daysWindow]);

      var filteredVas = React.useMemo(function () {
        return filterVasByRole(vaRates, selectedRole);
      }, [selectedRole]);

      var hasTrend = filteredTrend.length > 0;
      var hasVas = filteredVas.length > 0;
      var hasRoles = roleBenchmarks.length > 0;

      return e('div', null,
        e('div', { className: 'tl-perf-toolbar' },
          e('div', { className: 'tab-strip', style: { marginBottom: 0 } },
            e('button', {
              type: 'button',
              className: 'tab-btn' + (mode === 'roles' ? ' active' : ''),
              onClick: function () { setMode('roles'); }
            }, 'VA Role Comparison'),
            e('button', {
              type: 'button',
              className: 'tab-btn' + (mode === 'trend' ? ' active' : ''),
              onClick: function () { setMode('trend'); }
            }, 'Productivity Trends'),
            e('button', {
              type: 'button',
              className: 'tab-btn' + (mode === 'va' ? ' active' : ''),
              onClick: function () { setMode('va'); }
            }, 'Per-VA Benchmark')
          ),

          e('div', { className: 'tl-perf-controls' },
            mode === 'trend' ? e('div', { className: 'tl-filter-group' },
              [7, 14, 30].map(function (win) {
                return e('button', {
                  key: 'win-' + win,
                  type: 'button',
                  className: 'collab-filter-chip' + (daysWindow === win ? ' is-active' : ''),
                  onClick: function () { setDaysWindow(win); }
                }, win + 'D');
              }),
              e('button', {
                type: 'button',
                className: 'collab-filter-chip' + (trendFocus === 'productivity' ? ' is-active' : ''),
                onClick: function () { setTrendFocus('productivity'); }
              }, 'Velocity & Completion'),
              e('button', {
                type: 'button',
                className: 'collab-filter-chip' + (trendFocus === 'turnaround' ? ' is-active' : ''),
                onClick: function () { setTrendFocus('turnaround'); }
              }, 'Turnaround Trend')
            ) : null,

            mode === 'va' && availableRoles.length > 0 ? e('select', {
              className: 'select tl-role-filter-select',
              'aria-label': 'Filter VAs by role',
              value: selectedRole,
              onChange: function (ev) { setSelectedRole(ev.target.value); }
            },
              e('option', { value: 'all' }, 'All VA Roles (' + vaRates.length + ')'),
              availableRoles.map(function (rName) {
                return e('option', { key: rName, value: rName }, rName);
              })
            ) : null
          )
        ),

        e('div', { className: 'tl-perf-legend', style: { marginBottom: '12px' } },
          mode === 'roles'
            ? [
                e('span', { key: 'cr', className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
                  'Completion Rate (%)'
                ),
                e('span', { key: 'ps', className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#8822CE' } }),
                  'Productivity Score (%)'
                ),
                e('span', { key: 'tat', className: 'tl-legend-item' },
                  e('span', { className: 'tl-legend-swatch', style: { background: '#D97706' } }),
                  'Avg Turnaround (hrs)'
                )
              ]
            : (mode === 'trend'
                ? (trendFocus === 'turnaround'
                    ? [
                        e('span', { key: 'tat', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#D97706' } }),
                          'Avg Turnaround (hrs)'
                        ),
                        e('span', { key: 'cr', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
                          'Completion Rate (%)'
                        ),
                        e('span', { key: 'dn', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#8822CE' } }),
                          'Completed Count'
                        )
                      ]
                    : [
                        e('span', { key: 'cr', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
                          'Completion Rate (%)'
                        ),
                        e('span', { key: 'ps', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#2563EB' } }),
                          'Productivity Score (%)'
                        ),
                        e('span', { key: 'dn', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#8822CE' } }),
                          'Completed'
                        ),
                        e('span', { key: 'ov', className: 'tl-legend-item' },
                          e('span', { className: 'tl-legend-swatch', style: { background: '#DC2626' } }),
                          'Overdue'
                        )
                      ])
                : [
                    e('span', { key: 'cr', className: 'tl-legend-item' },
                      e('span', { className: 'tl-legend-swatch', style: { background: '#059669' } }),
                      'Completion Rate (%)'
                    ),
                    e('span', { key: 'sla', className: 'tl-legend-item' },
                      e('span', { className: 'tl-legend-swatch', style: { background: '#2563EB' } }),
                      'On-Time SLA (%)'
                    ),
                    e('span', { key: 'tat', className: 'tl-legend-item' },
                      e('span', { className: 'tl-legend-swatch', style: { background: '#D97706' } }),
                      'Avg Turnaround (hrs)'
                    )
                  ])
        ),

        mode === 'roles'
          ? (!hasRoles
              ? e('div', { className: 'notice notice-info', style: { marginTop: '8px', marginBottom: 0 } },
                  'Assign VA specialties and deliverables to compare performance benchmarks across VA roles.'
                )
              : e('div', null,
                  e('div', { className: 'tl-recharts-canvas' },
                    e(rc.ResponsiveContainer, { width: '100%', height: 300 },
                      e(rc.ComposedChart, { data: roleBenchmarks, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                        e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: 'rgba(148, 163, 184, 0.24)', vertical: false }),
                        e(rc.XAxis, {
                          dataKey: 'role_name',
                          tick: { fontSize: 11.5, fill: '#94A3B8', fontWeight: 700 },
                          axisLine: { stroke: 'rgba(148, 163, 184, 0.35)' },
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
                          yAxisId: 'tat',
                          orientation: 'right',
                          unit: 'h',
                          tick: { fontSize: 11, fill: '#D97706', fontWeight: 600 },
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
                          dataKey: 'productivity_score',
                          name: 'Productivity Score (%)',
                          fill: '#8822CE',
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
                    )
                  ),

                  e('div', { className: 'table-wrap tl-va-summary-table-wrap' },
                    e('table', { className: 'table' },
                      e('thead', null,
                        e('tr', null,
                          e('th', null, 'VA Role / Specialization'),
                          e('th', null, 'Pod VAs'),
                          e('th', null, 'Done / Total'),
                          e('th', null, 'Completion %'),
                          e('th', null, 'Avg Turnaround'),
                          e('th', null, 'On-Time SLA'),
                          e('th', null, 'Productivity')
                        )
                      ),
                      e('tbody', null,
                        roleBenchmarks.map(function (rb) {
                          return e('tr', { key: rb.role_name },
                            e('td', null, e('span', { className: 'badge badge-brand' }, rb.role_name)),
                            e('td', { className: 'tabular-nums' }, String(rb.va_count || 0)),
                            e('td', { className: 'tabular-nums' }, String(rb.completed || 0) + ' / ' + String(rb.total_tasks || 0)),
                            e('td', { className: 'tabular-nums', style: { fontWeight: 700, color: '#059669' } }, Number(rb.completion_rate || 0).toFixed(1) + '%'),
                            e('td', { className: 'tabular-nums' }, formatTurnaroundLabel(rb.avg_turnaround_hours)),
                            e('td', { className: 'tabular-nums' }, Number(rb.on_time_rate || 100).toFixed(1) + '%'),
                            e('td', { className: 'tabular-nums', style: { fontWeight: 700, color: '#8822CE' } }, Number(rb.productivity_score || 0).toFixed(1) + '%')
                          );
                        })
                      )
                    )
                  )
                )
            )
          : (mode === 'trend'
              ? (!hasTrend
                  ? e('div', { className: 'notice notice-info', style: { marginTop: '8px', marginBottom: 0 } },
                      'No pod deliverables with deadlines in the past 30 days yet. Assign deliverables on the Kanban board to populate productivity trends.'
                    )
                  : e('div', { className: 'tl-recharts-canvas' },
                      e(rc.ResponsiveContainer, { width: '100%', height: 300 },
                        e(rc.ComposedChart, { data: filteredTrend, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                          e('defs', null,
                            e('linearGradient', { id: 'worklyCompGrad', x1: '0', y1: '0', x2: '0', y2: '1' },
                              e('stop', { offset: '5%', stopColor: '#059669', stopOpacity: 0.28 }),
                              e('stop', { offset: '95%', stopColor: '#059669', stopOpacity: 0.02 })
                            )
                          ),
                          e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: 'rgba(148, 163, 184, 0.24)', vertical: false }),
                          e(rc.XAxis, {
                            dataKey: 'short_date',
                            tick: { fontSize: 11, fill: '#94A3B8', fontWeight: 600 },
                            axisLine: { stroke: 'rgba(148, 163, 184, 0.35)' },
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
                            yAxisId: 'rightAxis',
                            orientation: 'right',
                            allowDecimals: trendFocus === 'turnaround',
                            unit: trendFocus === 'turnaround' ? 'h' : '',
                            tick: { fontSize: 11, fill: trendFocus === 'turnaround' ? '#D97706' : '#94A3B8', fontWeight: 600 },
                            axisLine: false,
                            tickLine: false
                          }),
                          e(rc.Tooltip, { content: e(CustomTooltip) }),
                          e(rc.ReferenceLine, { yAxisId: 'rate', y: 85, stroke: 'rgba(148, 163, 184, 0.45)', strokeDasharray: '4 4' }),
                          trendFocus === 'turnaround'
                            ? [
                                e(rc.Bar, {
                                  key: 'done-bar',
                                  yAxisId: 'rightAxis',
                                  dataKey: 'done_count',
                                  name: 'Completed',
                                  fill: '#8822CE',
                                  radius: [6, 6, 0, 0],
                                  barSize: 16
                                }),
                                e(rc.Line, {
                                  key: 'tat-line',
                                  yAxisId: 'rightAxis',
                                  type: 'monotone',
                                  dataKey: 'avg_turnaround_hours',
                                  name: 'Avg Turnaround (hrs)',
                                  stroke: '#D97706',
                                  strokeWidth: 2.8,
                                  dot: { r: 4, fill: '#D97706', stroke: '#FFFFFF', strokeWidth: 1.5 }
                                }),
                                e(rc.Area, {
                                  key: 'comp-area',
                                  yAxisId: 'rate',
                                  type: 'monotone',
                                  dataKey: 'completion_rate',
                                  name: 'Completion Rate (%)',
                                  stroke: '#059669',
                                  strokeWidth: 2.2,
                                  fill: 'url(#worklyCompGrad)'
                                })
                              ]
                            : [
                                e(rc.Bar, {
                                  key: 'done-bar',
                                  yAxisId: 'rightAxis',
                                  dataKey: 'done_count',
                                  name: 'Completed',
                                  fill: '#8822CE',
                                  radius: [6, 6, 0, 0],
                                  barSize: 16
                                }),
                                e(rc.Bar, {
                                  key: 'ovd-bar',
                                  yAxisId: 'rightAxis',
                                  dataKey: 'overdue_count',
                                  name: 'Overdue',
                                  fill: '#DC2626',
                                  radius: [6, 6, 0, 0],
                                  barSize: 16
                                }),
                                e(rc.Area, {
                                  key: 'comp-area',
                                  yAxisId: 'rate',
                                  type: 'monotone',
                                  dataKey: 'completion_rate',
                                  name: 'Completion Rate (%)',
                                  stroke: '#059669',
                                  strokeWidth: 2.6,
                                  fill: 'url(#worklyCompGrad)',
                                  dot: { r: 3.5, fill: '#059669', stroke: '#FFFFFF', strokeWidth: 1.5 },
                                  activeDot: { r: 5.5, fill: '#059669' }
                                }),
                                e(rc.Line, {
                                  key: 'prod-line',
                                  yAxisId: 'rate',
                                  type: 'monotone',
                                  dataKey: 'productivity_score',
                                  name: 'Productivity Score (%)',
                                  stroke: '#2563EB',
                                  strokeWidth: 2.2,
                                  strokeDasharray: '4 3',
                                  dot: false
                                })
                              ]
                        )
                      )
                    )
                )
              : (!hasVas
                  ? e('div', { className: 'notice notice-info', style: { marginTop: '8px', marginBottom: 0 } },
                      'No VAs match the selected role filter yet.'
                    )
                  : e('div', { className: 'tl-recharts-canvas' },
                      e(rc.ResponsiveContainer, { width: '100%', height: 300 },
                        e(rc.ComposedChart, { data: filteredVas, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                          e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: 'rgba(148, 163, 184, 0.24)', vertical: false }),
                          e(rc.XAxis, {
                            dataKey: 'name',
                            tick: { fontSize: 11.5, fill: '#94A3B8', fontWeight: 700 },
                            axisLine: { stroke: 'rgba(148, 163, 184, 0.35)' },
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
                            yAxisId: 'tat',
                            orientation: 'right',
                            unit: 'h',
                            tick: { fontSize: 11, fill: '#D97706', fontWeight: 600 },
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
                            yAxisId: 'tat',
                            type: 'monotone',
                            dataKey: 'avg_turnaround_hours',
                            name: 'Avg Turnaround (hrs)',
                            stroke: '#D97706',
                            strokeWidth: 2.6,
                            dot: { r: 4, fill: '#D97706', stroke: '#FFFFFF', strokeWidth: 1.5 }
                          })
                        )
                      )
                    )
                )
            )
      );
    }

    var root = sdk.createRoot(rootEl);
    root.render(e(PerformanceWidget));
  }

  function mountTeamLeaderVaSummaryChart() {
    var rootEl = document.getElementById('tl-recharts-va-summary-root');
    var dataEl = document.getElementById('tl-va-summary-json');
    if (!rootEl || !dataEl) return;

    var sdk = window.WorklyChartsSDK;
    var rc = (sdk && sdk.Recharts) ? sdk.Recharts : sdk;
    if (!sdk || !sdk.React || !sdk.createRoot || !rc || !rc.ResponsiveContainer || !rc.ComposedChart || !rc.BarChart) {
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
    var vaRows = Array.isArray(payload.va_rates) ? payload.va_rates : [];
    var availableRoles = Array.isArray(payload.available_roles) ? payload.available_roles : [];

    function SummaryTooltip(props) {
      if (!props || !props.active || !props.payload || !props.payload.length) return null;
      var row = props.payload[0].payload || {};
      return e('div', { className: 'tl-recharts-tooltip' },
        e('div', { className: 'tl-recharts-tt-title' }, row.full_name || row.name || 'Virtual Assistant'),
        e('div', { className: 'tl-recharts-tt-grid' },
          row.primary_role ? [
            e('span', { key: 'r-l', className: 'muted' }, 'VA Role'),
            e('strong', { key: 'r-v' }, String((row.specialties && row.specialties.length) ? row.specialties.join(', ') : row.primary_role))
          ] : null,
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

      var roleState = React.useState('all');
      var selectedRole = roleState[0];
      var setSelectedRole = roleState[1];

      var visibleRows = React.useMemo(function () {
        return filterVasByRole(vaRows, selectedRole);
      }, [selectedRole]);

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

          e('div', { className: 'tl-perf-controls' },
            availableRoles.length > 0 ? e('select', {
              className: 'select tl-role-filter-select',
              'aria-label': 'Filter summary by VA role',
              value: selectedRole,
              onChange: function (ev) { setSelectedRole(ev.target.value); }
            },
              e('option', { value: 'all' }, 'All VA Roles (' + vaRows.length + ')'),
              availableRoles.map(function (rName) {
                return e('option', { key: rName, value: rName }, rName);
              })
            ) : null,

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
          )
        ),

        !visibleRows.length
          ? e('div', { className: 'notice notice-info', style: { marginTop: '8px', marginBottom: 0 } },
              'No VAs assigned to the selected specialization role yet.'
            )
          : e('div', null,
              e('div', { className: 'tl-recharts-canvas' },
                e(rc.ResponsiveContainer, { width: '100%', height: 290 },
                  viewMode === 'combined'
                    ? e(rc.ComposedChart, { data: visibleRows, margin: { top: 14, right: 18, left: -6, bottom: 4 } },
                        e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: 'rgba(148, 163, 184, 0.24)', vertical: false }),
                        e(rc.XAxis, {
                          dataKey: 'name',
                          tick: { fontSize: 11.5, fill: '#94A3B8', fontWeight: 700 },
                          axisLine: { stroke: 'rgba(148, 163, 184, 0.35)' },
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
                    : e(rc.BarChart, { data: visibleRows, margin: { top: 14, right: 18, left: -4, bottom: 4 } },
                        e(rc.CartesianGrid, { strokeDasharray: '3 3', stroke: 'rgba(148, 163, 184, 0.24)', vertical: false }),
                        e(rc.XAxis, {
                          dataKey: 'name',
                          tick: { fontSize: 11.5, fill: '#94A3B8', fontWeight: 700 },
                          axisLine: { stroke: 'rgba(148, 163, 184, 0.35)' },
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
                      e('th', null, 'VA Role'),
                      e('th', null, 'Completed'),
                      e('th', null, 'Completion Rate'),
                      e('th', null, 'Avg Turnaround'),
                      e('th', null, 'On-Time SLA')
                    )
                  ),
                  e('tbody', null,
                    visibleRows.map(function (row) {
                      var rate = Number(row.completion_rate || 0);
                      var sla = Number(row.on_time_rate || 100);
                      var roleLabel = (row.specialties && row.specialties.length) ? row.specialties.join(', ') : (row.primary_role || 'General VA');
                      return e('tr', { key: row.va_id || row.name },
                        e('td', null, e('strong', null, row.full_name || row.name)),
                        e('td', null, e('span', { className: 'badge badge-neutral' }, roleLabel)),
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
