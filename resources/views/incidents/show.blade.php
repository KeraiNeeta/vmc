@extends('layouts.app')

@section('title', $job->job_id)

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">EMERGENCY DESK · INCIDENT DETAILS</span><h1>{{ $job->job_id }}</h1><p class="muted">{{ $job->occurred_at->format('M j, Y g:i A') }} · {{ $job->ward?->name ?? 'Ward not assigned' }}</p></div>
        <div>
            <span class="status">{{ str_replace('_', ' ', $job->status) }}</span>
            @if ($canArchive)
                <form method="POST" action="{{ route('app.jobs.archive', $job->id) }}" class="inline-form" onsubmit="return confirm('Archive this closed incident? It can be restored later.');">
                    @csrf
                    <button class="button button-light" type="submit">Archive incident</button>
                </form>
            @endif
            <a class="button button-light" href="{{ route('app.jobs') }}">Back to incidents</a>
        </div>
    </div>
    @if (session('status')) <div class="success-notice" role="status">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <section class="panel">
        <h2>Caller and incident</h2>
        <div class="form-grid">
            <div><small>Caller</small><strong>{{ $job->caller_name }}</strong></div>
            <div><small>Phone</small><strong>{{ $job->caller_phone }}</strong></div>
            <div><small>Call source</small><strong>{{ str_replace('_', ' ', $job->call_source) }}</strong></div>
            <div><small>Type · priority</small><strong>{{ str_replace('_', ' ', $job->incident_type) }} · {{ $job->priority }}</strong></div>
            <div class="field-wide"><small>Address</small><strong>{{ $job->incident_address }}</strong></div>
            <div><small>Society / area</small><strong>{{ $job->society_name ?: 'Not recorded' }}</strong></div>
            <div><small>Coordinates</small><strong>{{ $job->latitude && $job->longitude ? $job->latitude.', '.$job->longitude : 'Not recorded' }}</strong></div>
            <div class="field-wide"><small>Intake remarks</small><strong>{{ $job->remarks ?: '—' }}</strong></div>
        </div>
    </section>

    @if ($job->assignments->isNotEmpty() || $canAssign)
        <section class="panel">
            <div class="page-heading">
                <div><h2>Response units</h2><p class="muted">Each vehicle is assigned and tracked independently.</p></div>
            </div>
            @if ($job->assignments->isNotEmpty())
                <div class="table-scroll"><table><thead><tr><th>Vehicle</th><th>Station</th><th>Team</th><th>Assigned by</th><th>Status</th><th>Next action</th></tr></thead><tbody>
                    @foreach ($job->assignments as $assignment)
                        @php
                            $nextAssignmentStatus = [
                                \App\Models\JobAssignment::STATUS_ASSIGNED => \App\Models\JobAssignment::STATUS_EN_ROUTE,
                                \App\Models\JobAssignment::STATUS_EN_ROUTE => \App\Models\JobAssignment::STATUS_ARRIVED,
                                \App\Models\JobAssignment::STATUS_ARRIVED => \App\Models\JobAssignment::STATUS_IN_PROGRESS,
                                \App\Models\JobAssignment::STATUS_IN_PROGRESS => \App\Models\JobAssignment::STATUS_COMPLETED,
                            ][$assignment->status] ?? null;
                        @endphp
                        <tr>
                            <td><strong>{{ $assignment->vehicle?->vehicle_number ?? 'Vehicle unavailable' }}</strong></td>
                            <td>{{ $assignment->fireStation?->name ?? '—' }}</td>
                            <td>{{ $assignment->team?->name ?? 'Not assigned' }}@if ($assignment->teamLeader) <small class="muted"> · {{ $assignment->teamLeader->name }}</small>@endif</td>
                            <td>{{ $assignment->assigner?->name ?? 'System' }}</td>
                            <td><span class="status">{{ str_replace('_', ' ', $assignment->status) }}</span></td>
                            <td>
                                @if ($nextAssignmentStatus && in_array($assignment->id, $updatableAssignmentIds, true) && ! $assignment->released_at)
                                    <form method="POST" action="{{ route('app.jobs.assignments.status', [$job->id, $assignment->id]) }}" class="managed-user-form incident-form">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="status" value="{{ $nextAssignmentStatus }}">
                                        <label>Update note<input name="remarks" maxlength="2000" placeholder="Optional"></label>
                                        <button class="button button-light" type="submit">{{ str_replace('_', ' ', $nextAssignmentStatus) }}</button>
                                    </form>
                                @elseif ($assignment->released_at)
                                    <span class="muted">Unit released {{ $assignment->released_at->format('M j, g:i A') }}</span>
                                @else
                                    <span class="muted">No action available</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
            @else
                <p class="muted">No response units have been assigned yet.</p>
            @endif

            @if ($canAssign)
                @if ($assignableStations->isNotEmpty())
                    <form method="POST" action="{{ route('app.jobs.assignments.store', $job->id) }}" class="managed-user-form incident-form assignment-form">
                        @csrf
                        <h3>Assign one or more units</h3>
                        <div id="assignment-rows">
                            <div class="assignment-row form-grid">
                                <label>Fire station
                                    <select name="assignments[0][fire_station_id]" data-field="fire_station_id" class="assignment-station" required>
                                        <option value="">Select station</option>
                                        @foreach ($assignableStations as $station)
                                            <option value="{{ $station->id }}">{{ $station->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Available vehicle
                                    <select name="assignments[0][vehicle_id]" data-field="vehicle_id" class="assignment-vehicle" required>
                                        <option value="">Select vehicle</option>
                                        @foreach ($assignableStations as $station)
                                            @foreach ($station->vehicles as $vehicle)
                                                <option value="{{ $vehicle->id }}" data-station="{{ $station->id }}">{{ $vehicle->vehicle_number }} · {{ str_replace('_', ' ', $vehicle->vehicle_type) }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                                <label>Response team (optional)
                                    <select name="assignments[0][team_id]" data-field="team_id" class="assignment-team">
                                        <option value="">No team selected</option>
                                        @foreach ($assignableStations as $station)
                                            @foreach ($station->teams as $team)
                                                <option value="{{ $team->id }}" data-station="{{ $station->id }}">{{ $team->name }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </div>
                        <button class="button button-light" type="button" id="add-assignment-row">Add another vehicle</button>
                        <label>Dispatch notes<textarea name="remarks" rows="2" maxlength="2000"></textarea></label>
                        <button class="button button-primary" type="submit">Assign response units</button>
                    </form>
                    <template id="assignment-row-template">
                        <div class="assignment-row form-grid">
                            <label>Fire station
                                <select data-field="fire_station_id" class="assignment-station" required>
                                    <option value="">Select station</option>
                                    @foreach ($assignableStations as $station)
                                        <option value="{{ $station->id }}">{{ $station->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Available vehicle
                                <select data-field="vehicle_id" class="assignment-vehicle" required>
                                    <option value="">Select vehicle</option>
                                    @foreach ($assignableStations as $station)
                                        @foreach ($station->vehicles as $vehicle)
                                            <option value="{{ $vehicle->id }}" data-station="{{ $station->id }}">{{ $vehicle->vehicle_number }} · {{ str_replace('_', ' ', $vehicle->vehicle_type) }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <label>Response team (optional)
                                <select data-field="team_id" class="assignment-team">
                                    <option value="">No team selected</option>
                                    @foreach ($assignableStations as $station)
                                        @foreach ($station->teams as $team)
                                            <option value="{{ $team->id }}" data-station="{{ $station->id }}">{{ $team->name }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <button class="button button-light remove-assignment-row" type="button">Remove vehicle</button>
                        </div>
                    </template>
                @else
                    <p class="muted">No active station with an available vehicle covers this incident ward.</p>
                @endif
            @endif
        </section>
    @endif

    @if ($job->status === \App\Models\EmergencyJob::STATUS_RECEIVED && ($canCloseDuplicate || $canTransferTo112))
        <section class="form-grid">
            @if ($canCloseDuplicate)
                <div class="panel">
                    <h2>Close as duplicate</h2>
                    @if ($possibleDuplicates->isNotEmpty())
                        <p class="muted">Possible matches use the same caller number from the last 48 hours. Confirm the original before closing.</p>
                        <form method="POST" action="{{ route('app.jobs.duplicate', $job->id) }}" class="managed-user-form incident-form">
                            @csrf
                            <label>Original incident
                                <select name="duplicate_of" required>
                                    <option value="">Select original incident</option>
                                    @foreach ($possibleDuplicates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->job_id }} · {{ $candidate->incident_type }} · {{ $candidate->occurred_at->format('M j, g:i A') }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Closure note<textarea name="remarks" rows="2" maxlength="2000"></textarea></label>
                            <button class="button button-light" type="submit">Confirm duplicate</button>
                        </form>
                    @else
                        <p class="muted">No matching recent caller incidents were found.</p>
                    @endif
                </div>
            @endif
            @if ($canTransferTo112)
                <div class="panel">
                    <h2>Transfer to 112</h2>
                    <p class="muted">Record the 112 reference number to keep the handoff auditable.</p>
                    <form method="POST" action="{{ route('app.jobs.transfer-112', $job->id) }}" class="managed-user-form incident-form">
                        @csrf
                        <label>112 reference number<input name="reference_number" required maxlength="100"></label>
                        <label>Handoff details<textarea name="details" rows="2" maxlength="5000"></textarea></label>
                        <button class="button button-primary" type="submit">Record transfer</button>
                    </form>
                </div>
            @endif
        </section>
    @endif

    @if ($job->externalReferences->isNotEmpty())
        <section class="panel">
            <h2>External references</h2>
            <div class="table-scroll"><table><thead><tr><th>Type</th><th>Reference</th><th>Details</th><th>Recorded</th></tr></thead><tbody>
                @foreach ($job->externalReferences as $reference)
                    <tr><td>{{ $reference->reference_type }}</td><td><strong>{{ $reference->reference_number }}</strong></td><td>{{ $reference->details ?: '—' }}</td><td>{{ ($reference->transferred_at ?? $reference->confirmed_at ?? $reference->created_at)->format('M j, Y g:i A') }}</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endif

    <section class="panel">
        <h2>Incident history</h2>
        <div class="table-scroll"><table><thead><tr><th>Time</th><th>Change</th><th>Unit</th><th>By</th><th>Notes</th></tr></thead><tbody>
            @forelse ($job->statusHistory as $history)
                <tr><td>{{ $history->changed_at->format('M j, Y g:i A') }}</td><td>{{ $history->previous_status ? str_replace('_', ' ', $history->previous_status).' → ' : '' }}<strong>{{ str_replace('_', ' ', $history->new_status) }}</strong></td><td>{{ $history->assignment?->vehicle?->vehicle_number ?? 'Incident' }}</td><td>{{ $history->actor?->name ?? 'System' }}</td><td>{{ $history->remarks ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="5" class="empty-cell">No incident history recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</section>
<script>
    (() => {
        const rows = document.getElementById('assignment-rows');
        const template = document.getElementById('assignment-row-template');
        const addButton = document.getElementById('add-assignment-row');
        if (!rows || !template || !addButton) return;

        const filterResources = (row) => {
            const stationId = row.querySelector('.assignment-station').value;
            row.querySelectorAll('.assignment-vehicle option[data-station], .assignment-team option[data-station]').forEach((option) => {
                const available = !stationId || option.dataset.station === stationId;
                option.hidden = !available;
                option.disabled = !available;
                if (!available && option.selected) option.selected = false;
            });
        };

        const renumberRows = () => {
            rows.querySelectorAll('.assignment-row').forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((field) => {
                    field.name = `assignments[${index}][${field.dataset.field}]`;
                });
            });
        };

        const addRow = () => {
            if (rows.children.length >= 10) return;
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('.assignment-station').addEventListener('change', () => filterResources(row));
            row.querySelector('.remove-assignment-row').addEventListener('click', () => {
                row.remove();
                renumberRows();
            });
            rows.appendChild(row);
            renumberRows();
        };

        rows.querySelector('.assignment-station').addEventListener('change', () => filterResources(rows.firstElementChild));
        addButton.addEventListener('click', addRow);
    })();
</script>
@endsection
