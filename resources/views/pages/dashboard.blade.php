@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $jobStats = [
        ['label' => 'Total calls', 'value' => $summary['total_jobs'], 'icon' => '☎', 'tone' => 'blue'],
        ['label' => 'Pending', 'value' => $summary['pending_jobs'], 'icon' => '◷', 'tone' => 'orange'],
        ['label' => 'Active', 'value' => $summary['active_jobs'], 'icon' => '➤', 'tone' => 'green'],
        ['label' => 'Completed', 'value' => $summary['completed_jobs'], 'icon' => '✓', 'tone' => 'purple'],
        ['label' => 'Transfer to 112', 'value' => $summary['transferred_jobs'], 'icon' => '⇄', 'tone' => 'cyan'],
    ];
@endphp
<section class="ops-dashboard">
    <h1 class="ops-visually-hidden">Operations dashboard</h1>

    @if ($preview)
        <div class="notice"><span class="notice-icon">i</span><div><strong>Sign in to view operations</strong><p>Live dashboard data is restricted to authorized accounts.</p></div><a class="button button-primary button-small" href="{{ route('login') }}">Sign in</a></div>
    @endif

    <div class="ops-stat-grid">
        @foreach ($jobStats as $stat)
            <article class="ops-stat-card ops-stat-{{ $stat['tone'] }}">
                <span class="ops-stat-icon">{{ $stat['icon'] }}</span>
                <div><span>{{ $stat['label'] }}</span><strong>{{ $preview || $stat['value'] === null ? '—' : number_format($stat['value']) }}</strong></div>
            </article>
        @endforeach
    </div>

    <div class="ops-primary-grid">
        <section class="ops-panel ops-map-panel">
            <div class="ops-panel-heading">
                <div><h2><span class="ops-pin">⌖</span> Incident & station map</h2><p>Locations from saved records · Not live GPS</p></div>
                <div class="ops-map-legend"><span><i class="legend-fire"></i> Incident</span><span><i class="legend-station"></i> Station</span><span><i class="legend-unit"></i> Vehicle</span></div>
            </div>
            <div class="ops-map" role="img" aria-label="Illustrative map of saved station and incident coordinates">
                <div class="map-park map-park-one"></div><div class="map-park map-park-two"></div>
                <div class="map-river"></div>
                <span class="map-road road-one"></span><span class="map-road road-two"></span><span class="map-road road-three"></span>
                <span class="map-road road-four"></span><span class="map-road road-five"></span><span class="map-road road-six"></span>
                <span class="map-road road-seven"></span><span class="map-road road-eight"></span><span class="map-road road-nine"></span>
                <div class="map-city-label">Vadodara</div>
                @forelse ($mapMarkers as $marker)
                    <span class="map-marker map-marker-{{ $marker['kind'] }}" style="left: {{ $marker['x'] }}%; top: {{ $marker['y'] }}%" title="{{ $marker['label'] }}" aria-label="{{ ucfirst($marker['kind']) }}: {{ $marker['label'] }}">
                        {{ $marker['kind'] === 'incident' ? '!' : ($marker['kind'] === 'station' ? '⌂' : '▰') }}
                    </span>
                @empty
                    <div class="map-empty">{{ $preview ? 'Sign in to view authorized locations' : 'No coordinates are available in your access scope' }}</div>
                @endforelse
                <span class="map-scale">Illustrative demo map · Not live GPS</span>
            </div>
        </section>

        <div class="ops-side-stack">
            <section class="ops-panel ops-station-panel">
                <div class="ops-panel-heading"><div><h2>Fire station status</h2><p>Stations in your authorized scope</p></div><a href="{{ route('app.stations') }}">View all →</a></div>
                @if ($stations->isEmpty())
                    <p class="ops-empty">{{ $preview ? 'Sign in to view station status.' : 'No stations are assigned to your account.' }}</p>
                @else
                    <div class="ops-station-list">
                        @foreach ($stations->take(5) as $station)
                            <div class="ops-station-row">
                                <span class="station-state {{ $station->is_active ? 'is-online' : 'is-offline' }}"></span>
                                <span class="ops-station-name"><strong>{{ $station->name }}</strong><small>{{ $station->code }}</small></span>
                                <span class="ops-station-metric"><small>Available</small><strong>{{ isset($station->available_vehicles_count) ? $station->available_vehicles_count : '—' }}</strong></span>
                                <span class="ops-station-metric"><small>Teams</small><strong>{{ isset($station->active_teams_count) ? $station->active_teams_count : '—' }}</strong></span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ops-panel ops-recent-panel">
                <div class="ops-panel-heading"><div><h2>Recent incidents</h2><p>Latest calls visible to your account</p></div><a href="{{ route('app.jobs') }}">View all →</a></div>
                @if ($recentJobs->isEmpty())
                    <p class="ops-empty">{{ $preview ? 'Sign in to view incidents.' : 'No incidents in your access scope.' }}</p>
                @else
                    <div class="ops-recent-list">
                        @foreach ($recentJobs->take(5) as $job)
                            <a class="ops-recent-row" href="{{ route('app.jobs') }}">
                                <span class="incident-type-icon {{ $job->incident_type === 'STRUCTURE_FIRE' ? 'incident-fire' : 'incident-other' }}">{{ $job->incident_type === 'STRUCTURE_FIRE' ? '♨' : '✚' }}</span>
                                <span class="ops-recent-copy"><strong>{{ $job->job_id }}</strong><small>{{ $job->ward?->name ?? $job->incident_type }}</small></span>
                                <time>{{ $job->occurred_at->format('H:i') }}</time>
                                <span class="ops-status status-{{ strtolower($job->status) }}">{{ str_replace('_', ' ', $job->status) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>

    <div class="ops-secondary-grid">
        <section class="ops-panel">
            <div class="ops-panel-heading"><div><h2>Ward-wise pending jobs</h2><p>Incident counts by covered ward</p></div><a href="{{ route('app.jobs') }}">View incidents →</a></div>
            @if ($wardStats->isEmpty())
                <p class="ops-empty">{{ $preview ? 'Sign in to view ward activity.' : 'No ward activity is available.' }}</p>
            @else
                <div class="ops-table-wrap">
                    <table class="ops-table">
                        <thead><tr><th>Ward</th><th>Pending</th><th>Active</th><th>Total</th></tr></thead>
                        <tbody>
                        @foreach ($wardStats->take(6) as $item)
                            <tr><td><strong>{{ $item['ward']->name }}</strong><small>{{ $item['ward']->code }}</small></td><td>{{ $item['pending'] }}</td><td>{{ $item['active'] }}</td><td>{{ $item['pending'] + $item['active'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="ops-total-strip"><span>Pending calls</span><strong>{{ $preview || $summary['pending_jobs'] === null ? '—' : number_format($summary['pending_jobs']) }}</strong></div>
        </section>

        <section class="ops-panel ops-job-detail">
            <div class="ops-panel-heading"><div><h2>Incident details</h2><p>{{ $selectedJob?->job_id ?? 'Selected incident' }}</p></div>
                @if ($selectedJob)<span class="ops-priority">{{ str_replace('_', ' ', $selectedJob->priority) }} priority</span>@endif
            </div>
            @if (!$selectedJob)
                <p class="ops-empty">{{ $preview ? 'Sign in to view incident details.' : 'No incident is available in your scope.' }}</p>
            @else
                <div class="ops-job-facts">
                    <div><span>Caller</span><strong>{{ $selectedJob->caller_name }}</strong></div>
                    <div><span>Phone</span><strong>{{ $selectedJob->caller_phone }}</strong></div>
                    <div><span>Location</span><strong>{{ $selectedJob->incident_address }}</strong></div>
                    <div><span>Ward</span><strong>{{ $selectedJob->ward?->name ?? 'Not assigned' }}</strong></div>
                    <div><span>Incident</span><strong>{{ str_replace('_', ' ', $selectedJob->incident_type) }}</strong></div>
                    <div><span>Status</span><strong>{{ str_replace('_', ' ', $selectedJob->status) }}</strong></div>
                </div>
                <div class="ops-assignment-list">
                    @forelse ($selectedJob->assignments as $assignment)
                        <div><span class="assignment-check">✓</span><strong>{{ $assignment->fireStation?->name ?? 'Station' }}</strong><span>{{ $assignment->vehicle?->vehicle_number ?? 'No vehicle' }}{{ $assignment->team ? ' · '.$assignment->team->name : '' }}</span></div>
                    @empty
                        <div class="assignment-unassigned">No response unit assigned</div>
                    @endforelse
                </div>
                <div class="ops-timeline">
                    @foreach ($selectedJob->statusHistory->take(4) as $event)
                        <div><span class="timeline-dot"></span><strong>{{ str_replace('_', ' ', $event->new_status) }}</strong><time>{{ $event->changed_at->format('H:i') }}</time></div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="ops-panel ops-quick-panel">
            <div class="ops-panel-heading"><div><h2>Quick access</h2><p>Open operational master data</p></div></div>
            <div class="ops-quick-grid">
                @if (auth()->user()?->hasPermission('jobs.view'))
                    <a href="{{ route('app.jobs') }}"><span>☷</span>Incidents</a>
                @endif
                @if (auth()->user()?->hasPermission('vehicles.view'))
                    <a href="{{ route('app.vehicles') }}"><span>▰</span>Vehicles</a>
                @endif
                @if (auth()->user()?->hasPermission('teams.view'))
                    <a href="{{ route('app.teams') }}"><span>♧</span>Teams</a>
                @endif
                @if (auth()->user()?->hasPermission('stations.view'))
                    <a href="{{ route('app.stations') }}"><span>⌂</span>Stations</a>
                @endif
                @if (auth()->user()?->hasPermission('wards.view'))
                    <a href="{{ route('app.wards') }}"><span>◎</span>Wards</a>
                @endif
                @if ($preview)
                    <a href="{{ route('login') }}"><span>↪</span>Sign in</a>
                @endif
            </div>
        </section>
    </div>
    <footer class="ops-footer"><span><i></i> VMC Emergency Response</span><span>Dashboard data is permission- and station-scoped · Demo records are illustrative</span>
        @if (auth()->user()?->hasPermission('vehicles.view'))<a href="{{ route('app.vehicles') }}">Vehicle directory →</a>@endif
    </footer>
</section>
@endsection
