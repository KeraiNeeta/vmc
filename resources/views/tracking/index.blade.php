@extends('layouts.app')

@section('title', 'Live GPS tracking')

@section('content')
<section class="page-stack tracking-page">
    <div class="page-heading">
        <div>
            <span class="eyebrow">FIELD OPERATIONS · LIVE FLEET</span>
            <h1>Live GPS tracking</h1>
            <p class="muted">Authenticated vehicle locations and assignment route history. Positions stay within your station access.</p>
        </div>
        <span class="tracking-live"><i></i> GPS feed</span>
    </div>

    <section class="tracking-layout">
        <div class="panel tracking-map-panel">
            <div class="tracking-panel-heading">
                <div><h2>Fleet location map</h2><p>Local coordinate plot · no third-party map service · refreshed at {{ now()->format('g:i:s A') }}</p></div>
                <span class="tracking-count">{{ $vehicles->count() }} tracked {{ \Illuminate\Support\Str::plural('vehicle', $vehicles->count()) }}</span>
            </div>
            @if ($vehicles->isNotEmpty())
                <div class="tracking-map">
                    <svg viewBox="0 0 800 480" role="img" aria-label="Map plot of latest vehicle GPS points and selected route">
                        <defs>
                            <pattern id="tracking-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                                <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(154,184,218,.12)" stroke-width="1"/>
                            </pattern>
                        </defs>
                        <rect width="800" height="480" fill="url(#tracking-grid)"/>
                        <path d="M0 400 C150 330 190 420 340 350 S600 315 800 205" class="tracking-road"/>
                        <path d="M110 0 C155 115 205 240 350 480" class="tracking-road tracking-road-secondary"/>
                        @if ($routePoints->count() > 1)
                            <polyline points="{{ $routePath }}" class="tracking-route-line"/>
                        @endif
                        @foreach ($markers as $marker)
                            <g class="{{ $marker['is_selected'] ? 'tracking-marker is-selected' : 'tracking-marker' }}">
                                <circle cx="{{ $marker['x'] }}" cy="{{ $marker['y'] }}" r="13" class="tracking-marker-halo"/>
                                <circle cx="{{ $marker['x'] }}" cy="{{ $marker['y'] }}" r="6" class="tracking-marker-dot"/>
                                <text x="{{ $marker['x'] + 12 }}" y="{{ $marker['y'] - 9 }}">{{ $marker['vehicle']->vehicle_number }}</text>
                                <title>{{ $marker['vehicle']->vehicle_number }} · {{ $marker['vehicle']->latestGpsLog->latitude }}, {{ $marker['vehicle']->latestGpsLog->longitude }}</title>
                            </g>
                        @endforeach
                        @foreach ($routePoints as $index => $point)
                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $loop->first || $loop->last ? 6 : 3.5 }}" class="{{ $loop->first ? 'tracking-route-point route-start' : ($loop->last ? 'tracking-route-point route-end' : 'tracking-route-point') }}">
                                <title>{{ $point['log']->recorded_at->format('M j, g:i:s A') }} · {{ $point['log']->latitude }}, {{ $point['log']->longitude }}</title>
                            </circle>
                        @endforeach
                    </svg>
                    <div class="tracking-map-legend"><span><i class="legend-live"></i> Latest location</span><span><i class="legend-route"></i> Selected route</span></div>
                </div>
            @else
                <div class="tracking-empty"><span>⌖</span><strong>No GPS points received</strong><p>Vehicle positions will appear here after the first authenticated mobile GPS update.</p></div>
            @endif
        </div>

        <aside class="panel tracking-units-panel">
            <div class="tracking-panel-heading"><div><h2>Tracked vehicles</h2><p>Most recent received location</p></div></div>
            @if ($vehicles->isNotEmpty())
                <div class="tracking-unit-list">
                    @foreach ($vehicles as $vehicle)
                        @php
                            $isStale = $vehicle->latestGpsLog->recorded_at->lt(now()->subMinutes(5));
                            $activeAssignment = $vehicle->assignments->first();
                        @endphp
                        <a class="tracking-unit {{ $selectedVehicle?->is($vehicle) ? 'is-selected' : '' }}" href="{{ route('app.tracking', ['vehicle_id' => $vehicle->id]) }}">
                            <span class="tracking-unit-icon">⌖</span>
                            <span class="tracking-unit-details">
                                <strong>{{ $vehicle->vehicle_number }}</strong>
                                <small>{{ $vehicle->fireStation?->name ?? 'Station unavailable' }} · {{ $vehicle->status }}</small>
                                @if ($activeAssignment?->emergencyJob)
                                    <small>{{ $activeAssignment->emergencyJob->job_id }} · {{ str_replace('_', ' ', $activeAssignment->status) }}</small>
                                @endif
                            </span>
                            <span class="tracking-unit-time {{ $isStale ? 'is-stale' : '' }}">{{ $isStale ? 'STALE' : 'LIVE' }}<small>{{ $vehicle->latestGpsLog->recorded_at->diffForHumans() }}</small></span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="tracking-no-units">No vehicles in your authorized tracking scope.</p>
            @endif
        </aside>
    </section>

    @if ($selectedVehicle)
        <section class="panel tracking-history-panel">
            <div class="tracking-panel-heading">
                <div><h2>Route history · {{ $selectedVehicle->vehicle_number }}</h2><p>{{ $selectedVehicle->fireStation?->name }} · {{ $routePoints->count() }} GPS points in this range</p></div>
                <form method="GET" action="{{ route('app.tracking') }}" class="tracking-filters">
                    <input type="hidden" name="vehicle_id" value="{{ $selectedVehicle->id }}">
                    <label>Assignment
                        <select name="assignment_id">
                            <option value="">All my vehicle routes</option>
                            @foreach ($routeAssignments as $assignment)
                                <option value="{{ $assignment->id }}" @selected((string) ($filters['assignment_id'] ?? '') === (string) $assignment->id)>{{ $assignment->emergencyJob?->job_id ?? 'Job '.$assignment->emergency_job_id }} · {{ str_replace('_', ' ', $assignment->status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>From<input type="datetime-local" name="from" value="{{ isset($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->format('Y-m-d\\TH:i') : now()->subDay()->format('Y-m-d\\TH:i') }}"></label>
                    <label>To<input type="datetime-local" name="to" value="{{ isset($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->format('Y-m-d\\TH:i') : now()->format('Y-m-d\\TH:i') }}"></label>
                    <button class="button button-light button-small" type="submit">Show route</button>
                </form>
            </div>
            <div class="table-scroll"><table><thead><tr><th>Recorded</th><th>Coordinates</th><th>Speed</th><th>Job</th></tr></thead><tbody>
                @forelse ($routePoints->reverse() as $point)
                    <tr>
                        <td>{{ $point['log']->recorded_at->format('M j, Y g:i:s A') }}</td>
                        <td>{{ $point['log']->latitude }}, {{ $point['log']->longitude }}</td>
                        <td>{{ $point['log']->speed_kph !== null ? $point['log']->speed_kph.' km/h' : '—' }}</td>
                        <td>{{ $point['log']->assignment?->emergencyJob?->job_id ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-cell">No route points match this vehicle and time range.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @endif
</section>
<script>
    window.setTimeout(() => window.location.reload(), 30000);
</script>
@endsection
