@extends('layouts.app')

@section('title', $user ? 'Edit user' : 'Add user')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">USER HIERARCHY</span><h1>{{ $user ? 'Edit account' : 'Create account' }}</h1><p class="muted">Set the account&apos;s role and the stations it can access.</p></div>
    </div>
    <section class="form-panel panel">
        <form method="POST" action="{{ $user ? route('users.update', $user) : route('users.store') }}" class="managed-user-form">
            @csrf
            @if ($user) @method('PUT') @endif
            @if ($errors->any())
                <div class="alert" role="alert"><strong>Check the form:</strong> {{ $errors->first() }}</div>
            @endif
            <div class="form-grid">
                <div class="field"><label for="name">Full name</label><input id="name" name="name" value="{{ old('name', $user?->name) }}" maxlength="255" autocomplete="name" required></div>
                <div class="field"><label for="email">Work email</label><input id="email" name="email" type="email" value="{{ old('email', $user?->email) }}" maxlength="255" autocomplete="email" required></div>
                <div class="field"><label for="role_code">Account role</label>
                    @php($selectedRole = old('role_code', $user?->roles->first()?->code))
                    <select id="role_code" name="role_code" required>
                        <option value="">Select an allowed role</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->code }}" @selected($selectedRole === $role->code)>{{ $role->name }} · {{ $role->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label for="password">{{ $user ? 'New password (optional)' : 'Temporary password' }}</label><input id="password" name="password" type="password" minlength="14" maxlength="255" autocomplete="new-password" {{ $user ? '' : 'required' }}><small>{{ $user ? 'Leave blank to keep the current password.' : 'At least 14 characters. Share it through a secure channel.' }}</small></div>
            </div>

            <fieldset class="station-fieldset">
                <legend>Station access <span>Choose only the stations this account needs.</span></legend>
                @error('station_ids')<p class="field-error">{{ $message }}</p>@enderror
                <div class="station-choice-grid">
                    @php($selectedStations = collect(old('station_ids', $user?->fireStations->modelKeys() ?? []))->map(fn ($id) => (int) $id)->all())
                    @forelse ($stations as $station)
                        <label class="station-choice">
                            <input type="checkbox" name="station_ids[]" value="{{ $station->id }}" @checked(in_array($station->id, $selectedStations, true))>
                            <span><strong>{{ $station->name }}</strong><small>{{ $station->code }}</small></span>
                        </label>
                    @empty
                        <p class="muted">No stations are available in your scope. Ask a higher-level administrator to assign your station.</p>
                    @endforelse
                </div>
            </fieldset>

            <label class="active-choice">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user?->is_active ?? true))>
                <span><strong>Account active</strong><small>Inactive accounts cannot sign in; active API tokens are revoked when disabled.</small></span>
            </label>

            <div class="form-actions"><a class="button button-light" href="{{ $user ? route('users.show', $user) : route('users.index') }}">Cancel</a><button class="button button-primary" type="submit">{{ $user ? 'Save changes' : 'Create user' }}</button></div>
        </form>
    </section>
</section>
@endsection
