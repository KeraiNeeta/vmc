@extends('layouts.app')

@section('title', 'Reports & analytics')

@section('content')
@php
    $summary = $report['summary'];
    $maxDaily = max(1, collect($report['daily'])->max('count'));
    $chartSets = [
        ['title' => 'Incident status', 'items' => $report['statuses'], 'tone' => 'blue'],
        ['title' => 'Severity', 'items' => $report['priorities'], 'tone' => 'severity'],
        ['title' => 'Incident type', 'items' => $report['incident_types'], 'tone' => 'green'],
    ];
@endphp
<section class="page-stack operations-reports">
    <div class="page-heading">
        <div>
            <span class="eyebrow">INSIGHTS · OPERATIONAL PERFORMANCE</span>
            <h1>Reports &amp; analytics</h1>
            <p class="muted">Operational volumes, severity, deployment activity, and response-time indicators for the selected period.</p>
        </div>
        <div class="report-heading-actions">
            @if (auth()->user()->hasPermission('reports.export'))
                <div class="report-export-actions" aria-label="Export report">
                    @foreach (['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'] as $format => $label)
                        <a class="button button-light" href="{{ route('app.reports.export', ['format' => $format] + request()->query()) }}">{{ $label }}</a>
                    @endforeach
                </div>
            @endif
            <span class="report-period">{{ \Illuminate\Support\Carbon::parse($report['filters']['from'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($report['filters']['to'])->format('M j, Y') }}</span>
        </div>
    </div>
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <form method="GET" action="{{ route('app.reports') }}" class="panel report-filter-form">
        <label>From date<input type="date" name="from" value="{{ $report['filters']['from'] }}" required></label>
        <label>To date<input type="date" name="to" value="{{ $report['filters']['to'] }}" required></label>
        <label>Station
            <select name="station_id">
                <option value="">All accessible stations</option>
                @foreach ($report['stations_available'] as $station)
                    <option value="{{ $station['id'] }}" @selected($report['filters']['station_id'] === $station['id'])>{{ $station['name'] }} · {{ $station['code'] }}</option>
                @endforeach
            </select>
        </label>
        <button class="button button-primary" type="submit">Apply filters</button>
    </form>

    <div class="report-stat-grid">
        <article class="report-stat-card"><span class="report-stat-icon tone-blue">☎</span><div><small>Total incidents</small><strong>{{ number_format($summary['total_jobs']) }}</strong></div></article>
        <article class="report-stat-card"><span class="report-stat-icon tone-orange">◷</span><div><small>Pending</small><strong>{{ number_format($summary['pending_jobs']) }}</strong></div></article>
        <article class="report-stat-card"><span class="report-stat-icon tone-green">➤</span><div><small>Active</small><strong>{{ number_format($summary['active_jobs']) }}</strong></div></article>
        <article class="report-stat-card"><span class="report-stat-icon tone-purple">✓</span><div><small>Completed · {{ $summary['completion_rate'] }}%</small><strong>{{ number_format($summary['completed_jobs']) }}</strong></div></article>
        <article class="report-stat-card"><span class="report-stat-icon tone-red">!</span><div><small>Critical priority</small><strong>{{ number_format($summary['critical_jobs']) }}</strong></div></article>
        <article class="report-stat-card"><span class="report-stat-icon tone-cyan">⇄</span><div><small>112 transfers</small><strong>{{ number_format($summary['transferred_jobs']) }}</strong></div></article>
    </div>

    <section class="panel report-trend-panel">
        <div class="report-panel-heading"><div><h2>Daily incident volume</h2><p>{{ count($report['daily']) }} days · based on incident-reported time</p></div><strong>{{ number_format($summary['total_jobs']) }} incidents</strong></div>
        @if ($summary['total_jobs'] > 0)
            <div class="report-trend-chart" role="img" aria-label="Daily incident count from {{ $report['filters']['from'] }} to {{ $report['filters']['to'] }}">
                @foreach ($report['daily'] as $day)
                    <div class="report-trend-day" title="{{ $day['label'] }}: {{ $day['count'] }} incidents">
                        <span class="report-trend-value">{{ $day['count'] ?: '' }}</span>
                        <span class="report-trend-bar" style="height: {{ max(3, ($day['count'] / $maxDaily) * 100) }}%"></span>
                        <small>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('j') }}</small>
                    </div>
                @endforeach
            </div>
            <div class="report-trend-axis"><span>{{ \Illuminate\Support\Carbon::parse($report['filters']['from'])->format('M j') }}</span><span>{{ \Illuminate\Support\Carbon::parse($report['filters']['to'])->format('M j') }}</span></div>
        @else
            <p class="report-empty">No incidents were recorded for this reporting period.</p>
        @endif
    </section>

    <section class="report-breakdown-grid">
        @foreach ($chartSets as $set)
            @php $maxCount = max(1, collect($set['items'])->max('count')); @endphp
            <article class="panel report-breakdown">
                <div class="report-panel-heading"><div><h2>{{ $set['title'] }}</h2><p>Share of reported incidents</p></div></div>
                @if (count($set['items']))
                    <div class="report-bars">
                        @foreach ($set['items'] as $item)
                            <div class="report-bar-row">
                                <div class="report-bar-label"><span>{{ str_replace('_', ' ', $item['label']) }}</span><strong>{{ number_format($item['count']) }} <small>{{ $item['percentage'] }}%</small></strong></div>
                                <div class="report-bar-track"><span class="report-bar-fill tone-{{ $set['tone'] }}" style="width: {{ ($item['count'] / $maxCount) * 100 }}%"></span></div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="report-empty">No records in this period.</p>
                @endif
            </article>
        @endforeach
    </section>

    <section class="report-performance-grid">
        <article class="panel report-performance-card">
            <span class="eyebrow">DISPATCH RESPONSE</span>
            <strong>{{ $summary['average_response_minutes'] !== null ? number_format($summary['average_response_minutes'], 1).' min' : '—' }}</strong>
            <p>Average time from incident report to first unit en route</p>
            <small>Based on {{ number_format($summary['jobs_with_response_time']) }} incidents with recorded response transitions.</small>
        </article>
        <article class="panel report-performance-card">
            <span class="eyebrow">INCIDENT RESOLUTION</span>
            <strong>{{ $summary['average_resolution_minutes'] !== null ? number_format($summary['average_resolution_minutes'], 1).' min' : '—' }}</strong>
            <p>Average time from incident report to all units completed</p>
            <small>Based on {{ number_format($summary['jobs_with_resolution_time']) }} incidents with recorded completion transitions.</small>
        </article>
        <article class="panel report-performance-card">
            <span class="eyebrow">DUPLICATE REPORTS</span>
            <strong>{{ number_format($summary['duplicate_jobs']) }}</strong>
            <p>Incidents closed after confirmation of an existing report</p>
            <small>Duplicates remain separate from transferred-to-112 incidents.</small>
        </article>
    </section>

    <section class="panel report-station-panel">
        <div class="report-panel-heading"><div><h2>Station deployment workload</h2><p>Unit assignments created for incidents in the selected period</p></div></div>
        @if (count($report['stations']))
            <div class="table-scroll"><table><thead><tr><th>Fire station</th><th>Deployments</th><th>Completed deployments</th><th>Completion rate</th></tr></thead><tbody>
                @foreach ($report['stations'] as $station)
                    <tr>
                        <td><strong>{{ $station['name'] }}</strong><small>{{ $station['code'] }}</small></td>
                        <td>{{ number_format($station['deployments']) }}</td>
                        <td>{{ number_format($station['completed_deployments']) }}</td>
                        <td>{{ $station['deployments'] ? number_format($station['completed_deployments'] / $station['deployments'] * 100, 1).'%' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @else
            <p class="report-empty">No stations are available in your access scope.</p>
        @endif
    </section>
    <p class="report-footnote">All figures use incident-reported date and your authorized station scope. Response and resolution averages are descriptive operational metrics, not SLA guarantees.</p>
</section>
@endsection
