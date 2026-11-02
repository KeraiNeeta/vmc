@extends('layouts.app')

@section('title', $managedUser->name)

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">USER ACCOUNT · #{{ $managedUser->id }}</span><h1>{{ $managedUser->name }}</h1><p class="muted">{{ $managedUser->email }}</p></div>
        <div class="heading-actions"><a class="button button-light" href="{{ route('users.index') }}">← User list</a>@if ($canEdit)<a class="button button-primary" href="{{ route('users.edit', $managedUser) }}">Edit account</a>@endif</div>
    </div>
    @if (session('status'))<div class="success-notice" role="status">{{ session('status') }}</div>@endif
    <div class="user-detail-grid">
        <section class="panel detail-panel">
            <div class="panel-heading"><div><h2>Account overview</h2><p>Current access and account status</p></div><span class="status {{ $managedUser->is_active ? 'status-active' : 'status-inactive' }}">{{ $managedUser->is_active ? 'Active' : 'Inactive' }}</span></div>
            <dl class="detail-list">
                <div><dt>Account ID</dt><dd>#{{ $managedUser->id }}</dd></div>
                <div><dt>Role hierarchy</dt><dd>{{ $managedUser->roles->pluck('name')->join(', ') ?: 'No role assigned' }}</dd></div>
                <div><dt>Assigned stations</dt><dd>{{ $stations->pluck('name')->join(', ') ?: 'No station assigned' }}</dd></div>
                <div><dt>Member since</dt><dd>{{ $managedUser->created_at?->format('M j, Y') ?? '—' }}</dd></div>
                <div><dt>Last sign in</dt><dd>{{ $managedUser->last_login_at?->format('M j, Y g:i A') ?? 'Never signed in' }}</dd></div>
                <div><dt>Effective permissions</dt><dd><div class="permission-list">{{ $managedUser->roles->flatMap(fn ($role) => $role->permissions->pluck('code'))->unique()->sort()->implode(', ') ?: 'None' }}</div></dd></div>
            </dl>
        </section>
        <section class="panel detail-panel">
            <div class="panel-heading"><div><h2>Account activity</h2><p>Recent recorded actions for this user</p></div></div>
            @if ($logs->isEmpty())
                <div class="empty-state compact-empty"><strong>No activity recorded yet</strong><p>Authentication and account-management events appear here.</p></div>
            @else
                <div class="activity-list">
                    @foreach ($logs as $log)
                        <div><span class="activity-dot"></span><span><strong>{{ str($log->event)->replace('.', ' ')->headline() }}</strong><small>{{ $log->created_at?->format('M j, Y g:i A') ?? 'Time unavailable' }}</small></span></div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
    <div class="hierarchy-note"><strong>Data visibility</strong><span>This page shows the account&apos;s roles, assigned stations, effective permissions, and recorded sign-in/activity history.</span></div>
</section>
@endsection
