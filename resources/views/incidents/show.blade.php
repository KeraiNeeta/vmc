@extends('layouts.app')

@section('title', $job->job_id)

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">EMERGENCY DESK · INCIDENT DETAILS</span><h1>{{ $job->job_id }}</h1><p class="muted">{{ $job->occurred_at->format('M j, Y g:i A') }} · {{ $job->ward?->name ?? 'Ward not assigned' }}</p></div>
        <div><span class="status">{{ str_replace('_', ' ', $job->status) }}</span> <a class="button button-light" href="{{ route('app.jobs') }}">Back to incidents</a></div>
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
            <div><small>Coordinates</small><strong>{{ $job->latitude && $job->longitude ? $job->latitude.', '.$job->longitude : 'Not recorded' }}</strong></div>
            <div class="field-wide"><small>Intake remarks</small><strong>{{ $job->remarks ?: '—' }}</strong></div>
        </div>
    </section>

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
        <div class="table-scroll"><table><thead><tr><th>Time</th><th>Change</th><th>By</th><th>Notes</th></tr></thead><tbody>
            @forelse ($job->statusHistory as $history)
                <tr><td>{{ $history->changed_at->format('M j, Y g:i A') }}</td><td>{{ $history->previous_status ? str_replace('_', ' ', $history->previous_status).' → ' : '' }}<strong>{{ str_replace('_', ' ', $history->new_status) }}</strong></td><td>{{ $history->actor?->name ?? 'System' }}</td><td>{{ $history->remarks ?: '—' }}</td></tr>
            @empty
                <tr><td colspan="4" class="empty-cell">No incident history recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</section>
@endsection
