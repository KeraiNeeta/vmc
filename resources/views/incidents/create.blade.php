@extends('layouts.app')

@section('title', 'Register an emergency')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">EMERGENCY DESK · INCIDENT INTAKE</span><h1>Register an emergency</h1><p class="muted">Capture caller and incident details. Search recent calls first to help avoid duplicate dispatches.</p></div>
        <a class="button button-light" href="{{ route('app.jobs') }}">Back to incidents</a>
    </div>
    @if (session('status')) <div class="success-notice" role="status">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <section class="panel">
        <form class="directory-toolbar" method="GET" action="{{ route('app.jobs.new') }}">
            <div><h2>Caller lookup</h2><p>Search recent incidents by phone number or caller name.</p></div>
            <div class="search-control"><input name="caller_search" value="{{ $callerSearch }}" placeholder="Phone number or caller name" aria-label="Search callers"><button class="button button-light button-small" type="submit">Search</button></div>
        </form>
        @if ($callerSearch !== '')
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Job ID</th><th>Society / caller / contact</th><th>Emergency</th><th>Status</th><th>Received</th></tr></thead>
                    <tbody>
                    @forelse ($callerResults as $candidate)
                        <tr>
                            <td><a class="text-link" href="{{ route('app.jobs.show', $candidate->id) }}">{{ $candidate->job_id }}</a></td>
                            <td><strong>{{ $candidate->society_name ?: ($candidate->ward?->name ?? 'Area not recorded') }}</strong><small>{{ $candidate->caller_name }}</small><small>{{ $candidate->caller_phone }}</small></td>
                            <td>{{ str_replace('_', ' ', $candidate->incident_type) }}<small>{{ str_replace('_', ' ', $candidate->priority) }} severity</small></td>
                            <td><span class="status">{{ str_replace('_', ' ', $candidate->status) }}</span></td>
                            <td>{{ $candidate->occurred_at->format('M j, Y g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty-cell">No recent caller records found in your authorized station scope.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="muted">Review a matching record before creating a new incident. Only unassigned newly received incidents can be closed as duplicates.</p>
        @endif
    </section>

    <section class="panel">
        <form method="POST" action="{{ route('app.jobs.store') }}" class="managed-user-form incident-form">
            @csrf
            <div class="form-grid">
                <label>Caller name<input name="caller_name" value="{{ old('caller_name') }}" required maxlength="255"></label>
                <label>Caller phone<input name="caller_phone" value="{{ old('caller_phone', $callerSearch) }}" required maxlength="32" autocomplete="tel"></label>
                <label>Call source
                    <select name="call_source" required>
                        @foreach (['PHONE' => 'Phone', '112' => '112', 'WALK_IN' => 'Walk-in', 'OTHER' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('call_source', 'PHONE') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Incident type
                    <select name="incident_type" required>
                        <option value="">Select type</option>
                        @foreach (['STRUCTURE_FIRE' => 'Structure fire', 'VEHICLE_FIRE' => 'Vehicle fire', 'ROAD_ACCIDENT' => 'Road accident', 'MEDICAL_EMERGENCY' => 'Medical emergency', 'RESCUE' => 'Rescue', 'OTHER' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('incident_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Priority
                    <select name="priority" required>
                        @foreach (['LOW' => 'Low', 'NORMAL' => 'Normal', 'HIGH' => 'High', 'CRITICAL' => 'Critical'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', 'NORMAL') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Incident time<input type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\\TH:i')) }}" required></label>
                <label>Ward / zone
                    <select name="ward_id" required>
                        <option value="">Select ward</option>
                        @foreach ($wards as $ward)
                            <option value="{{ $ward->id }}" @selected((string) old('ward_id') === (string) $ward->id)>{{ $ward->name }} · {{ $ward->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Society / area<input name="society_name" value="{{ old('society_name') }}" required maxlength="255" placeholder="Society, neighborhood or landmark"></label>
                <label class="field-wide">Incident address<textarea name="incident_address" required maxlength="2000" rows="3">{{ old('incident_address') }}</textarea></label>
                <label>Latitude<input type="number" name="latitude" value="{{ old('latitude') }}" min="-90" max="90" step="any"></label>
                <label>Longitude<input type="number" name="longitude" value="{{ old('longitude') }}" min="-180" max="180" step="any"></label>
                <label class="field-wide">Intake remarks<textarea name="remarks" maxlength="5000" rows="3">{{ old('remarks') }}</textarea></label>
            </div>
            <div class="form-actions"><a class="button button-light" href="{{ route('app.jobs') }}">Cancel</a><button class="button button-primary" type="submit">Create incident</button></div>
        </form>
    </section>
</section>
@endsection
