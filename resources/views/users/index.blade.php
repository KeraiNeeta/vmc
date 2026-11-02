@extends('layouts.app')

@section('title', 'User management')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div>
            <span class="eyebrow">ROLE-BASED USER HIERARCHY</span>
            <h1>User management</h1>
            <p class="muted">Review each account&apos;s role, station assignments, and recent activity.</p>
        </div>
        @if (auth()->user()->hasPermission('users.manage'))
            <a class="button button-primary" href="{{ route('users.create') }}">+ Add user</a>
        @endif
    </div>
    @if (session('status'))
        <div class="success-notice" role="status">{{ session('status') }}</div>
    @endif
    <section class="panel directory-panel">
        <form class="directory-toolbar" method="GET" action="{{ route('users.index') }}">
            <div><h2>Authorized accounts</h2><p>{{ number_format($users->total()) }} users in your visibility scope</p></div>
            <div class="search-control"><input name="search" value="{{ $search }}" placeholder="Search name, email, or role…" aria-label="Search users"><button class="button button-light button-small" type="submit">Search</button></div>
        </form>
        <div class="table-scroll">
            <table class="user-table">
                <thead><tr><th>User</th><th>Hierarchy role</th><th>Assigned stations</th><th>Status</th><th>Last sign in</th><th></th></tr></thead>
                <tbody>
                @forelse ($users as $directoryUser)
                    @php
                        $assignedStations = $directoryUser->fireStations->merge($directoryUser->managedFireStations)->unique('id');
                    @endphp
                    <tr>
                        <td><a class="user-name-link" href="{{ route('users.show', $directoryUser) }}"><strong>{{ $directoryUser->name }}</strong><small>{{ $directoryUser->email }}</small></a></td>
                        <td><div class="role-badges">@forelse ($directoryUser->roles as $role)<span class="role-badge">{{ str_replace('_', ' ', $role->name) }}</span>@empty<span class="muted">No role assigned</span>@endforelse</div></td>
                        <td>
                            <div class="station-badges">
                                @forelse ($assignedStations as $station)<span class="station-badge">{{ $station->code }}</span>@empty<span class="muted">No station assigned</span>@endforelse
                            </div>
                        </td>
                        <td><span class="status {{ $directoryUser->is_active ? 'status-active' : 'status-inactive' }}">{{ $directoryUser->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>{{ $directoryUser->last_login_at?->format('M j, Y g:i A') ?? 'Never' }}</td>
                        <td class="user-actions">
                            <a class="text-link" href="{{ route('users.show', $directoryUser) }}">Details</a>
                            @if (in_array($directoryUser->id, $manageableUserIds, true))
                                <a class="text-link" href="{{ route('users.edit', $directoryUser) }}">Edit</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty-cell">No users match your search in the permitted station scope.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $users->links() }}</div>
    </section>
    <div class="hierarchy-note"><strong>Account hierarchy</strong><span>Super Admin → Control Room Admin → Station Admin → Dispatcher / Report Viewer → Driver → Team Member</span><small>Administrators can only assign roles below their own level. Station administrators are limited to users and stations in their scope.</small></div>
</section>
@endsection
