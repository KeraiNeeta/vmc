@extends('layouts.app')

@section('title', $title)

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">{{ strtoupper($kind === 'roles' ? 'ACCESS CONTROL' : ($kind === 'users' ? 'ADMINISTRATION' : 'FIELD OPERATIONS')) }}</span><h1>{{ $title }}</h1><p class="muted">Permission-scoped directory of live database records.</p></div>
        <div class="page-heading-actions">
            @if ($records) <span class="record-count"><strong>{{ number_format($records->total()) }}</strong> records</span> @endif
            @if ($canManage && in_array($kind, ['stations', 'wards', 'vehicles', 'teams'], true))
                <a class="button button-primary" href="{{ route('app.'.$kind.'.create') }}">+ Add {{ strtolower(match ($kind) { 'stations' => 'station', 'wards' => 'ward', 'vehicles' => 'vehicle', default => 'team' }) }}</a>
            @endif
            @if ($kind === 'jobs' && $canCreate)
                <a class="button button-primary" href="{{ route('app.jobs.new') }}">+ Register incident</a>
            @endif
        </div>
    </div>
    @unless (auth()->user())
        <div class="notice"><span class="notice-icon">i</span><div><strong>Sign in to load live records.</strong><p>Directory access is restricted to your authorized operational scope.</p></div><a class="button button-light button-small" href="{{ route('login') }}">Sign in →</a></div>
    @endunless
    @if (session('status'))
        <div class="success-notice" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert" role="alert">{{ $errors->first() }}</div>
    @endif
    <section class="panel directory-panel">
        <form class="directory-toolbar" method="GET" action="{{ url()->current() }}">
            <div><h2>{{ $title }}</h2><p>Search authorized records</p></div>
            <div class="search-control"><input name="search" value="{{ $search }}" placeholder="Search {{ strtolower($title) }}…" aria-label="Search {{ $title }}"><button class="button button-light button-small" type="submit">Search</button></div>
        </form>
        @if ($records)
            <div class="table-scroll">
                <table>
                    @if ($kind === 'stations')
                        <thead><tr><th>Station</th><th>Code</th><th>Coverage</th><th>Team</th><th>Status</th></tr></thead>
                        <tbody>@forelse ($records as $station)
                            <tr><td><strong>{{ $station->name }}</strong><small>{{ $station->address }}</small>@if ($canManage)<small><a class="text-link" href="{{ route('app.stations.edit', $station->id) }}">Edit</a></small>@endif</td><td><code>{{ $station->code }}</code></td><td>{{ $station->wards_count }} wards</td><td>{{ $station->active_staff_count }} staff</td><td><span class="status {{ $station->is_active ? 'status-active' : 'status-inactive' }}">{{ $station->is_active ? 'Active' : 'Inactive' }}</span></td></tr>
                        @empty <tr><td colspan="5" class="empty-cell">No station records match your search.</td></tr>@endforelse</tbody>
                    @elseif ($kind === 'wards')
                        <thead><tr><th>Ward</th><th>Code</th><th>Assigned stations</th><th>Status</th></tr></thead>
                        <tbody>@forelse ($records as $ward)
                            <tr><td><strong>{{ $ward->name }}</strong><small>{{ $ward->description ?: 'Coverage area' }}</small>@if ($canManage)<small><a class="text-link" href="{{ route('app.wards.edit', $ward->id) }}">Edit</a></small>@endif</td><td><code>{{ $ward->code }}</code></td><td>{{ $ward->fireStations->pluck('name')->join(', ') ?: 'No station assigned' }}</td><td><span class="status {{ $ward->is_active ? 'status-active' : 'status-inactive' }}">{{ $ward->is_active ? 'Active' : 'Inactive' }}</span></td></tr>
                        @empty <tr><td colspan="4" class="empty-cell">No ward records match your search.</td></tr>@endforelse</tbody>
                    @elseif ($kind === 'users')
                        <thead><tr><th>Team member</th><th>Work email</th><th>Access roles</th><th>Status</th><th>Last sign in</th></tr></thead>
                        <tbody>@forelse ($records as $directoryUser)
                            <tr><td><strong>{{ $directoryUser->name }}</strong></td><td>{{ $directoryUser->email }}</td><td>{{ $directoryUser->roles->pluck('name')->join(', ') ?: 'No roles assigned' }}</td><td><span class="status {{ $directoryUser->is_active ? 'status-active' : 'status-inactive' }}">{{ $directoryUser->is_active ? 'Active' : 'Inactive' }}</span></td><td>{{ $directoryUser->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</td></tr>
                        @empty <tr><td colspan="5" class="empty-cell">No user records match your search.</td></tr>@endforelse</tbody>
                    @elseif ($kind === 'vehicles')
                        <thead><tr><th>Vehicle</th><th>Type</th><th>Registration</th><th>Station</th><th>Status</th><th>Last service</th></tr></thead>
                        <tbody>@forelse ($records as $vehicle)
                            <tr><td><strong>{{ $vehicle->vehicle_number }}</strong><small>{{ $vehicle->gps_device_id ?: 'No GPS device registered' }}</small>@if ($canManage)<small><a class="text-link" href="{{ route('app.vehicles.edit', $vehicle->id) }}">Edit</a></small>@endif</td><td>{{ str_replace('_', ' ', $vehicle->vehicle_type) }}</td><td>{{ $vehicle->registration_number ?: '—' }}</td><td>{{ $vehicle->fireStation?->name ?? 'Unassigned' }}</td><td><span class="status">{{ str_replace('_', ' ', $vehicle->status) }}</span></td><td>{{ $vehicle->last_service_at?->format('M j, Y') ?? 'Not recorded' }}</td></tr>
                        @empty <tr><td colspan="6" class="empty-cell">No vehicle records match your search.</td></tr>@endforelse</tbody>
                    @elseif ($kind === 'teams')
                        <thead><tr><th>Team</th><th>Station</th><th>Team leader</th><th>Members</th><th>Contact</th><th>Status</th></tr></thead>
                        <tbody>@forelse ($records as $team)
                            <tr><td><strong>{{ $team->name }}</strong>@if ($canManage)<small><a class="text-link" href="{{ route('app.teams.edit', $team->id) }}">Edit</a></small>@endif</td><td>{{ $team->fireStation?->name ?? 'Unassigned' }}</td><td>{{ $team->leader?->name ?? 'Not assigned' }}</td><td>{{ $team->active_members_count }}</td><td>{{ $team->contact_number ?: '—' }}</td><td><span class="status">{{ str_replace('_', ' ', $team->status) }}</span></td></tr>
                        @empty <tr><td colspan="6" class="empty-cell">No team records match your search.</td></tr>@endforelse</tbody>
                    @elseif ($kind === 'jobs')
                        <thead><tr><th>Job ID</th><th>Society / caller / contact</th><th>Emergency</th><th>Severity</th><th>Status</th><th>Received</th></tr></thead>
                        <tbody>@forelse ($records as $job)
                            <tr><td><strong><a class="text-link" href="{{ route('app.jobs.show', $job->id) }}">{{ $job->job_id }}</a></strong><small>{{ $job->ward?->code ?? 'Location' }}</small></td><td><strong>{{ $job->society_name ?: ($job->ward?->name ?? 'Area not recorded') }}</strong><small>{{ $job->caller_name }}</small><small>{{ $job->caller_phone }}</small></td><td>{{ str_replace('_', ' ', $job->incident_type) }}<small>{{ $job->incident_address }}</small></td><td><span class="status">{{ str_replace('_', ' ', $job->priority) }}</span></td><td><span class="status">{{ str_replace('_', ' ', $job->status) }}</span></td><td>{{ $job->occurred_at->format('M j, Y g:i A') }}</td></tr>
                        @empty <tr><td colspan="6" class="empty-cell">No incident records match your search.</td></tr>@endforelse</tbody>
                    @else
                        <thead><tr><th>Role</th><th>Code</th><th>Description</th><th>Permissions</th><th>Type</th></tr></thead>
                        <tbody>@forelse ($records as $role)
                            <tr><td><strong>{{ $role->name }}</strong></td><td><code>{{ $role->code }}</code></td><td>{{ $role->description }}</td><td>{{ $role->permissions->pluck('code')->join(', ') ?: 'No permissions assigned' }}</td><td>{{ $role->is_system ? 'System role' : 'Custom role' }}</td></tr>
                        @empty <tr><td colspan="5" class="empty-cell">No role records match your search.</td></tr>@endforelse</tbody>
                    @endif
                </table>
            </div>
            <div class="pagination">{{ $records->links() }}</div>
        @else
            <div class="empty-state directory-empty"><span class="empty-icon">⌕</span><strong>Live records are protected</strong><p>Sign in to search this directory within your access scope.</p></div>
        @endif
    </section>
    <p class="privacy-note">✓ &nbsp;This directory enforces server-side permissions and station-level access restrictions.</p>
</section>
@endsection
