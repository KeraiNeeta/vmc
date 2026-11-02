@extends('layouts.app')

@section('title', ($record ? 'Edit ' : 'Add ').$title)

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div>
            <span class="eyebrow">MASTER DATA · {{ strtoupper($kind) }}</span>
            <h1>{{ $record ? 'Edit '.$title : 'Add '.$title }}</h1>
            <p class="muted">Changes are permission-checked, station-scoped, and recorded in the audit log.</p>
        </div>
    </div>
    <section class="panel form-panel">
        <form class="managed-user-form" method="POST" action="{{ $record ? route('app.'.$kind.'.update', $record->id) : route('app.'.$kind.'.store') }}">
            @csrf
            @if ($record) @method('PUT') @endif
            @if ($errors->any())
                <div class="alert" role="alert"><strong>Check the form:</strong> {{ $errors->first() }}</div>
            @endif

            <div class="form-grid">
                @if ($kind === 'stations')
                    <div class="field"><label for="code">Station code</label><input id="code" name="code" value="{{ old('code', $record?->code) }}" maxlength="30" required>@error('code')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="name">Station name</label><input id="name" name="name" value="{{ old('name', $record?->name) }}" maxlength="255" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="contact_number">Contact number</label><input id="contact_number" name="contact_number" value="{{ old('contact_number', $record?->contact_number) }}" maxlength="32" autocomplete="tel">@error('contact_number')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="address">Address</label><textarea id="address" name="address" rows="3" maxlength="2000" required>{{ old('address', $record?->address) }}</textarea>@error('address')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="latitude">Latitude</label><input id="latitude" name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude', $record?->latitude) }}">@error('latitude')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="longitude">Longitude</label><input id="longitude" name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude', $record?->longitude) }}">@error('longitude')<small class="field-error">{{ $message }}</small>@enderror</div>
                @elseif ($kind === 'wards')
                    <div class="field"><label for="code">Ward code</label><input id="code" name="code" value="{{ old('code', $record?->code) }}" maxlength="30" required>@error('code')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="name">Ward name</label><input id="name" name="name" value="{{ old('name', $record?->name) }}" maxlength="255" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field field-wide"><label for="description">Description</label><textarea id="description" name="description" rows="3" maxlength="2000">{{ old('description', $record?->description) }}</textarea>@error('description')<small class="field-error">{{ $message }}</small>@enderror</div>
                @elseif ($kind === 'vehicles')
                    <div class="field"><label for="vehicle_number">Vehicle number</label><input id="vehicle_number" name="vehicle_number" value="{{ old('vehicle_number', $record?->vehicle_number) }}" maxlength="40" required>@error('vehicle_number')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="vehicle_type">Vehicle type</label><select id="vehicle_type" name="vehicle_type" required><option value="">Select type</option>@foreach ($vehicleTypes as $type)<option value="{{ $type }}" @selected(old('vehicle_type', $record?->vehicle_type) === $type)>{{ str_replace('_', ' ', $type) }}</option>@endforeach</select>@error('vehicle_type')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="registration_number">Registration number</label><input id="registration_number" name="registration_number" value="{{ old('registration_number', $record?->registration_number) }}" maxlength="40">@error('registration_number')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="gps_device_id">GPS device ID</label><input id="gps_device_id" name="gps_device_id" value="{{ old('gps_device_id', $record?->gps_device_id) }}" maxlength="100">@error('gps_device_id')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="fire_station_id">Assigned station</label><select id="fire_station_id" name="fire_station_id" required><option value="">Select station</option>@foreach ($stations as $station)<option value="{{ $station->id }}" @selected((int) old('fire_station_id', $record?->fire_station_id) === $station->id)>{{ $station->name }} · {{ $station->code }}</option>@endforeach</select>@error('fire_station_id')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="status">Status</label><select id="status" name="status" required>@foreach ($vehicleStatuses as $status)<option value="{{ $status }}" @selected(old('status', $record?->status ?? 'AVAILABLE') === $status)>{{ str_replace('_', ' ', $status) }}</option>@endforeach</select>@error('status')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="latitude">Latitude</label><input id="latitude" name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude', $record?->latitude) }}">@error('latitude')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="longitude">Longitude</label><input id="longitude" name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude', $record?->longitude) }}">@error('longitude')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="last_service_at">Last service date</label><input id="last_service_at" name="last_service_at" type="date" value="{{ old('last_service_at', $record?->last_service_at?->format('Y-m-d')) }}">@error('last_service_at')<small class="field-error">{{ $message }}</small>@enderror</div>
                @else
                    <div class="field"><label for="name">Team name</label><input id="name" name="name" value="{{ old('name', $record?->name) }}" maxlength="255" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="fire_station_id">Assigned station</label><select id="fire_station_id" name="fire_station_id" required><option value="">Select station</option>@foreach ($stations as $station)<option value="{{ $station->id }}" @selected((int) old('fire_station_id', $record?->fire_station_id) === $station->id)>{{ $station->name }} · {{ $station->code }}</option>@endforeach</select>@error('fire_station_id')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="leader_user_id">Team leader</label><select id="leader_user_id" name="leader_user_id"><option value="">Not assigned</option>@foreach ($users as $user)<option value="{{ $user->id }}" @selected((int) old('leader_user_id', $record?->leader_user_id) === $user->id)>{{ $user->name }}</option>@endforeach</select>@error('leader_user_id')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="contact_number">Contact number</label><input id="contact_number" name="contact_number" value="{{ old('contact_number', $record?->contact_number) }}" maxlength="32">@error('contact_number')<small class="field-error">{{ $message }}</small>@enderror</div>
                    <div class="field"><label for="status">Status</label><select id="status" name="status" required>@foreach ($teamStatuses as $status)<option value="{{ $status }}" @selected(old('status', $record?->status ?? 'AVAILABLE') === $status)>{{ str_replace('_', ' ', $status) }}</option>@endforeach</select>@error('status')<small class="field-error">{{ $message }}</small>@enderror</div>
                @endif
            </div>

            @if ($kind === 'wards')
                @php($selectedStations = collect(old('station_ids', $record?->fireStations->modelKeys() ?? []))->map(fn ($id) => (int) $id)->all())
                <fieldset class="station-fieldset">
                    <legend>Assigned stations <span>Select at least one station in your scope.</span></legend>
                    @error('station_ids')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="station-choice-grid">
                        @foreach ($stations as $station)
                            <label class="station-choice"><input type="checkbox" name="station_ids[]" value="{{ $station->id }}" @checked(in_array($station->id, $selectedStations, true))><span><strong>{{ $station->name }}</strong><small>{{ $station->code }}</small></span></label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            @if ($kind === 'teams')
                @php($selectedMembers = collect(old('member_ids', $record?->members->modelKeys() ?? []))->map(fn ($id) => (int) $id)->all())
                <fieldset class="station-fieldset">
                    <legend>Team members <span>Only active users from the selected station can be assigned.</span></legend>
                    @error('member_ids')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="station-choice-grid">
                        @forelse ($users as $user)
                            <label class="station-choice"><input type="checkbox" name="member_ids[]" value="{{ $user->id }}" @checked(in_array($user->id, $selectedMembers, true))><span><strong>{{ $user->name }}</strong><small>Active station user</small></span></label>
                        @empty
                            <p class="muted">No active station users are available to assign.</p>
                        @endforelse
                    </div>
                </fieldset>
            @endif

            @if (in_array($kind, ['stations', 'wards'], true))
                <label class="active-choice">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $record?->is_active ?? true))>
                    <span><strong>{{ $kind === 'stations' ? 'Station active' : 'Ward active' }}</strong><small>Inactive records are hidden from operational assignment and station coverage.</small></span>
                </label>
            @endif

            <div class="form-actions">
                <a class="button button-light" href="{{ route('app.'.$kind) }}">Cancel</a>
                <button class="button button-primary" type="submit">{{ $record ? 'Save changes' : 'Create '.$title }}</button>
            </div>
        </form>

        @if ($record)
            <form method="POST" action="{{ route('app.'.$kind.'.destroy', $record->id) }}" class="archive-form">
                @csrf
                @method('DELETE')
                <p>Archive removes the record from active directories. Records linked to operational history cannot be archived.</p>
                <button class="button button-danger" type="submit">Archive {{ strtolower($title) }}</button>
            </form>
        @endif
    </section>
</section>
@endsection
