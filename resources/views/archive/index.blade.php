@extends('layouts.app')

@section('title', 'Incident archive')

@section('content')
<section class="page-stack operations-reports">
    <div class="page-heading">
        <div>
            <span class="eyebrow">RECORDS · CLOSED INCIDENTS</span>
            <h1>Incident archive</h1>
            <p class="muted">Archived incidents retain their call, dispatch, and audit history. Restore a record to return it to the active incident directory.</p>
        </div>
    </div>
    @if (session('status')) <div class="success-notice" role="status">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <form method="GET" action="{{ route('app.archive') }}" class="panel report-filter-form archive-search-form">
        <label>Search archived incidents
            <input type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Job ID, caller, phone, area">
        </label>
        <button class="button button-primary" type="submit">Search</button>
        @if ($search !== '') <a class="button button-light" href="{{ route('app.archive') }}">Clear</a> @endif
    </form>

    <section class="panel report-station-panel">
        <div class="report-panel-heading">
            <div><h2>Archived incidents</h2><p>{{ number_format($jobs->total()) }} records in your authorized station scope</p></div>
        </div>
        @if ($jobs->isNotEmpty())
            <div class="table-scroll"><table>
                <thead><tr><th>Job ID · reported</th><th>Caller · location</th><th>Type · priority</th><th>Status</th><th>Archived</th><th>Action</th></tr></thead>
                <tbody>
                    @foreach ($jobs as $job)
                        <tr>
                            <td><strong>{{ $job->job_id }}</strong><small>{{ $job->occurred_at?->format('M j, Y g:i A') }}</small></td>
                            <td><strong>{{ $job->caller_name }}</strong><small>{{ $job->caller_phone }} · {{ $job->society_name ?: $job->ward?->name ?: 'Location not recorded' }}</small></td>
                            <td>{{ str_replace('_', ' ', $job->incident_type) }}<small>{{ $job->priority }} priority</small></td>
                            <td><span class="status">{{ str_replace('_', ' ', $job->status) }}</span></td>
                            <td>{{ $job->deleted_at?->format('M j, Y g:i A') }}</td>
                            <td>
                                <form method="POST" action="{{ route('app.archive.restore', $job->id) }}" onsubmit="return confirm('Restore this incident to the active incident directory?');">
                                    @csrf
                                    <button class="button button-light" type="submit">Restore</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            {{ $jobs->links() }}
        @else
            <p class="report-empty">No archived incidents match this search.</p>
        @endif
    </section>
</section>
@endsection
