<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleTrackingWorkflow
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function trackedVehicles(User $actor): Collection
    {
        return $this->visibleVehicles($actor)
            ->whereHas('gpsLogs')
            ->with([
                'fireStation:id,name,code',
                'latestGpsLog',
                'assignments' => function ($assignments) use ($actor): void {
                    $assignments->whereNull('released_at')
                        ->with('emergencyJob:id,job_id,incident_type,priority');
                    if ($this->isDriverRestricted($actor)) {
                        $assignments->where(function (Builder $membership) use ($actor): void {
                            $membership->where('team_leader_user_id', $actor->id)
                                ->orWhereHas('team.members', function (Builder $members) use ($actor): void {
                                    $members->where('users.id', $actor->id)
                                        ->whereNull('team_members.left_at');
                                });
                        });
                    }
                },
            ])
            ->orderBy('vehicle_number')
            ->get();
    }

    public function findVisibleVehicleOrFail(User $actor, int $vehicleId): Vehicle
    {
        return $this->visibleVehicles($actor)->findOrFail($vehicleId);
    }

    public function routeAssignments(User $actor, Vehicle $vehicle): Collection
    {
        $vehicle = $this->findVisibleVehicleOrFail($actor, $vehicle->id);

        return $this->isDriverRestricted($actor)
            ? $this->driverAssignments($actor, $vehicle)->load('emergencyJob:id,job_id')->sortByDesc('assigned_at')->values()
            : $vehicle->assignments()->with('emergencyJob:id,job_id')->orderByDesc('assigned_at')->get();
    }

    public function routeHistory(
        User $actor,
        Vehicle $vehicle,
        array $filters = [],
    ): Collection {
        $vehicle = $this->findVisibleVehicleOrFail($actor, $vehicle->id);
        $query = $vehicle->gpsLogs()->with('assignment.emergencyJob:id,job_id');

        if ($this->isDriverRestricted($actor)) {
            $assignmentIds = $this->driverAssignments($actor, $vehicle)->pluck('id');
            $query->whereIn('job_assignment_id', $assignmentIds);
        }

        if (! empty($filters['assignment_id'])) {
            $assignment = $vehicle->assignments()->find($filters['assignment_id']);
            abort_unless($assignment, 404);
            if ($this->isDriverRestricted($actor)) {
                abort_unless($this->driverAssignments($actor, $vehicle)->contains('id', $assignment->id), 404);
            }

            $query->where('job_assignment_id', $assignment->id);
        }

        $query
            ->when(
                ! empty($filters['from']),
                fn ($logs) => $logs->where('recorded_at', '>=', $filters['from']),
                fn ($logs) => $logs->where('recorded_at', '>=', now()->subDay()),
            )
            ->when(! empty($filters['to']), fn ($logs) => $logs->where('recorded_at', '<=', $filters['to']))
            ->orderByDesc('recorded_at')
            ->limit((int) ($filters['limit'] ?? 500));

        return $query->get()->sortBy('recorded_at')->values();
    }

    public function recordLocation(User $actor, Vehicle $vehicle, array $data): VehicleGpsLog
    {
        return DB::transaction(function () use ($actor, $vehicle, $data): VehicleGpsLog {
            $vehicle = $this->visibleVehicles($actor)->lockForUpdate()->findOrFail($vehicle->id);
            $assignment = $vehicle->assignments()
                ->whereKey($data['assignment_id'])
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if (! $assignment || ! $this->canSubmitForAssignment($actor, $assignment)) {
                throw ValidationException::withMessages([
                    'assignment_id' => 'An active assignment for this vehicle and your driver team is required.',
                ]);
            }

            $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
            $latestRecordedAt = $vehicle->gpsLogs()->max('recorded_at');
            if ($latestRecordedAt !== null && $recordedAt->lt($latestRecordedAt)) {
                throw ValidationException::withMessages([
                    'recorded_at' => 'GPS updates must not be older than the latest point already recorded.',
                ]);
            }

            $log = $vehicle->gpsLogs()->create([
                'job_assignment_id' => $assignment->id,
                'received_by' => $actor->id,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'speed_kph' => $data['speed_kph'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            $vehicle->update([
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);

            $this->auditLogger->record($actor, 'vehicles.gps_recorded', $log, [
                'vehicle_id' => $vehicle->id,
                'assignment_id' => $assignment->id,
            ]);

            return $log->load('vehicle:id,vehicle_number', 'assignment:id,emergency_job_id');
        });
    }

    private function canSubmitForAssignment(User $actor, JobAssignment $assignment): bool
    {
        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN')) {
            return true;
        }

        if (! $actor->hasRole('VEHICLE_DRIVER')) {
            return false;
        }

        $team = $assignment->team;

        return $team !== null && (
            $team->leader_user_id === $actor->id
            || $team->members()
                ->where('users.id', $actor->id)
                ->whereNull('team_members.left_at')
                ->exists()
        );
    }

    private function visibleVehicles(User $actor): Builder
    {
        $vehicles = Vehicle::query();
        if ($actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN')) {
            return $vehicles;
        }

        if ($this->isDriverRestricted($actor)) {
            $vehicles->whereHas('assignments', function (Builder $assignments) use ($actor): void {
                $assignments->where(function (Builder $membership) use ($actor): void {
                    $membership->where('team_leader_user_id', $actor->id)
                        ->orWhereHas('team.members', function (Builder $members) use ($actor): void {
                            $members->where('users.id', $actor->id)
                                ->whereNull('team_members.left_at');
                        });
                });
            });
        } else {
            $stationIds = $actor->fireStations()->pluck('fire_stations.id')
                ->merge($actor->managedFireStations()->pluck('id'))
                ->unique()
                ->values();
            $vehicles->whereIn('fire_station_id', $stationIds);
        }

        return $vehicles;
    }

    private function driverAssignments(User $actor, Vehicle $vehicle): Collection
    {
        return $vehicle->assignments()
            ->where(function (Builder $assignments) use ($actor): void {
                $assignments->where('team_leader_user_id', $actor->id)
                    ->orWhereHas('team.members', function (Builder $members) use ($actor): void {
                        $members->where('users.id', $actor->id)
                            ->whereNull('team_members.left_at');
                    });
            })
            ->get(['id', 'vehicle_id', 'team_id', 'team_leader_user_id']);
    }

    private function isDriverRestricted(User $actor): bool
    {
        return ! $actor->hasRole('SUPER_ADMIN')
            && ! $actor->hasRole('CONTROL_ROOM_ADMIN')
            && $actor->hasRole('VEHICLE_DRIVER');
    }
}
