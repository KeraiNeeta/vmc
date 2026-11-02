<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterDataManager
{
    public const VEHICLE_TYPES = [
        'FIRE_ENGINE',
        'WATER_TENDER',
        'RESCUE',
        'AMBULANCE',
        'UTILITY',
    ];

    public const VEHICLE_STATUSES = [
        'AVAILABLE',
        'DEPLOYED',
        'MAINTENANCE',
        'OUT_OF_SERVICE',
    ];

    public const TEAM_STATUSES = [
        'AVAILABLE',
        'DEPLOYED',
        'OFF_DUTY',
    ];

    public function stationsFor(User $user): Collection
    {
        $query = FireStation::query()->where('is_active', true)->orderBy('name');

        if (! $this->canManageAllStations($user)) {
            $query->whereKey($this->stationIdsFor($user));
        }

        return $query->get(['id', 'code', 'name']);
    }

    public function usersFor(User $user): Collection
    {
        $stations = $this->stationsFor($user);

        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereHas('fireStations', fn ($stationsQuery) => $stationsQuery->whereIn('fire_stations.id', $stations->modelKeys()))
                ->orWhereHas('managedFireStations', fn ($stationsQuery) => $stationsQuery->whereIn('id', $stations->modelKeys())))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function find(string $kind, int|string $id): Model
    {
        $model = $this->modelClass($kind);

        return $model::query()->findOrFail($id);
    }

    public function authorizeWebRecord(string $kind, Model $record, User $actor): void
    {
        $this->authorizeRecord($kind, $record, $actor);
    }

    public function create(Request $request, string $kind, User $actor, AuditLogger $auditLogger): Model
    {
        if ($kind === 'stations' && ! $this->canManageAllStations($actor)) {
            abort(403);
        }

        $data = $this->validated($request, $kind, $actor);

        return DB::transaction(function () use ($data, $kind, $actor, $auditLogger): Model {
            $record = $this->modelClass($kind)::query()->create($this->attributes($kind, $data));
            $this->syncRelations($record, $kind, $data);
            $auditLogger->record($actor, $kind.'.created', $record);

            return $record->refresh();
        });
    }

    public function update(Request $request, string $kind, Model $record, User $actor, AuditLogger $auditLogger): Model
    {
        $this->authorizeRecord($kind, $record, $actor);
        $data = $this->validated($request, $kind, $actor, $record);

        return DB::transaction(function () use ($data, $kind, $record, $actor, $auditLogger): Model {
            $record->update($this->attributes($kind, $data));
            $this->syncRelations($record, $kind, $data);
            $auditLogger->record($actor, $kind.'.updated', $record);

            return $record->refresh();
        });
    }

    public function delete(string $kind, Model $record, User $actor, AuditLogger $auditLogger): void
    {
        $this->authorizeRecord($kind, $record, $actor);

        $hasDependencies = match ($kind) {
            'stations' => $record->wards()->exists()
                || $record->users()->exists()
                || $record->admin()->exists()
                || $record->vehicles()->exists()
                || $record->teams()->exists()
                || EmergencyJob::query()->whereHas('ward.fireStations', fn ($query) => $query->whereKey($record->getKey()))
                    ->orWhereHas('assignments', fn ($query) => $query->where('fire_station_id', $record->getKey()))
                    ->exists(),
            'wards' => $record->emergencyJobs()->exists(),
            'vehicles' => $record->assignments()->exists(),
            'teams' => $record->assignments()->exists(),
            default => true,
        };

        if ($hasDependencies) {
            throw ValidationException::withMessages([
                'record' => 'This record is linked to operational data and cannot be archived. Update its status instead.',
            ]);
        }

        DB::transaction(function () use ($kind, $record, $actor, $auditLogger): void {
            $record->delete();
            $auditLogger->record($actor, $kind.'.archived', $record);
        });
    }

    private function validated(Request $request, string $kind, User $actor, ?Model $record = null): array
    {
        $stationIds = $this->stationsFor($actor)->modelKeys();
        $stationRule = Rule::exists('fire_stations', 'id')->whereNull('deleted_at');
        if (! $this->canManageAllStations($actor)) {
            $stationRule->whereIn('id', $stationIds);
        }

        $rules = match ($kind) {
            'stations' => [
                'code' => ['required', 'string', 'max:30', Rule::unique('fire_stations', 'code')->ignore($record?->getKey())],
                'name' => ['required', 'string', 'max:255'],
                'address' => ['required', 'string', 'max:2000'],
                'contact_number' => ['nullable', 'string', 'max:32'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'is_active' => ['required', 'boolean'],
            ],
            'wards' => [
                'code' => ['required', 'string', 'max:30', Rule::unique('wards', 'code')->ignore($record?->getKey())],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'station_ids' => ['required', 'array', 'min:1'],
                'station_ids.*' => ['required', 'integer', 'distinct', $stationRule],
                'is_active' => ['required', 'boolean'],
            ],
            'vehicles' => [
                'fire_station_id' => ['required', 'integer', $stationRule],
                'vehicle_number' => ['required', 'string', 'max:40', Rule::unique('vehicles', 'vehicle_number')->ignore($record?->getKey())],
                'vehicle_type' => ['required', Rule::in(self::VEHICLE_TYPES)],
                'registration_number' => ['nullable', 'string', 'max:40', Rule::unique('vehicles', 'registration_number')->ignore($record?->getKey())],
                'gps_device_id' => ['nullable', 'string', 'max:100', Rule::unique('vehicles', 'gps_device_id')->ignore($record?->getKey())],
                'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
                'status' => ['required', Rule::in(self::VEHICLE_STATUSES)],
                'last_service_at' => ['nullable', 'date'],
            ],
            'teams' => [
                'fire_station_id' => ['required', 'integer', $stationRule],
                'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->where('fire_station_id', $request->input('fire_station_id'))->ignore($record?->getKey())],
                'leader_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'contact_number' => ['nullable', 'string', 'max:32'],
                'status' => ['required', Rule::in(self::TEAM_STATUSES)],
                'member_ids' => ['sometimes', 'array'],
                'member_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            ],
            default => abort(404),
        };

        $data = $request->validate($rules);

        if ($kind === 'teams') {
            $stationId = (int) $data['fire_station_id'];
            $userIds = collect($data['member_ids'] ?? []);
            if (isset($data['leader_user_id'])) {
                $userIds->push((int) $data['leader_user_id']);
            }

            $validUserIds = User::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query
                    ->whereHas('fireStations', fn ($stations) => $stations->where('fire_stations.id', $stationId))
                    ->orWhereHas('managedFireStations', fn ($stations) => $stations->where('id', $stationId)))
                ->whereIn('id', $userIds->unique())
                ->pluck('id');

            if ($validUserIds->count() !== $userIds->unique()->count()) {
                throw ValidationException::withMessages([
                    'member_ids' => 'Team leaders and members must be active users assigned to the selected station.',
                ]);
            }
        }

        return $data;
    }

    private function attributes(string $kind, array $data): array
    {
        unset($data['station_ids'], $data['member_ids']);

        return $data;
    }

    private function syncRelations(Model $record, string $kind, array $data): void
    {
        if ($kind === 'wards') {
            $record->fireStations()->sync($data['station_ids']);
        } elseif ($kind === 'teams') {
            $record->members()->sync($data['member_ids'] ?? []);
        }
    }

    private function authorizeRecord(string $kind, Model $record, User $actor): void
    {
        if (! $actor->hasPermission($kind.'.manage')) {
            abort(403);
        }

        if ($kind === 'stations') {
            if (! $this->canManageAllStations($actor) && ! $actor->canAccessFireStation($record)) {
                abort(404);
            }

            return;
        }

        if (in_array($kind, ['vehicles', 'teams'], true) && ! $this->canManageStation($actor, (int) $record->fire_station_id)) {
            abort(404);
        }

        if ($kind === 'wards' && ! $this->canManageAllStations($actor)) {
            $scope = $this->stationIdsFor($actor);
            if ($record->fireStations()->whereIn('fire_stations.id', $scope)->doesntExist()) {
                abort(404);
            }
        }
    }

    private function canManageStation(User $user, int $stationId): bool
    {
        return $this->canManageAllStations($user) || $this->stationIdsFor($user)->contains($stationId);
    }

    private function canManageAllStations(User $user): bool
    {
        return $user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN');
    }

    private function stationIdsFor(User $user): Collection
    {
        return $user->fireStations()->pluck('fire_stations.id')
            ->merge($user->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(string $kind): string
    {
        return match ($kind) {
            'stations' => FireStation::class,
            'wards' => Ward::class,
            'vehicles' => Vehicle::class,
            'teams' => Team::class,
            default => abort(404),
        };
    }
}
