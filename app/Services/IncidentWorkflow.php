<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobCallLog;
use App\Models\JobExternalReference;
use App\Models\JobStatusHistory;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use App\Notifications\DispatchAssigned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentWorkflow
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function visibleJobs(User $actor): Builder
    {
        return $this->scopeJobsToActor(EmergencyJob::query(), $actor);
    }

    public function visibleArchivedJobs(User $actor): Builder
    {
        return $this->scopeJobsToActor(EmergencyJob::onlyTrashed(), $actor);
    }

    public function archive(User $actor, EmergencyJob $job): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if (! $this->isArchivable($job) || $job->assignments()->whereNull('released_at')->exists()) {
                throw ValidationException::withMessages([
                    'job' => 'Only closed incidents with no active unit assignments can be archived.',
                ]);
            }

            $job->delete();
            $this->auditLogger->record($actor, 'jobs.archived', $job, ['job_id' => $job->job_id]);

            return $job;
        });
    }

    public function restoreArchived(User $actor, int $jobId): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $jobId): EmergencyJob {
            $job = $this->visibleArchivedJobs($actor)->lockForUpdate()->findOrFail($jobId);
            $job->restore();
            $this->auditLogger->record($actor, 'jobs.restored', $job, ['job_id' => $job->job_id]);

            return $job;
        });
    }

    public function isArchivable(EmergencyJob $job): bool
    {
        return in_array($job->status, [
            EmergencyJob::STATUS_COMPLETED,
            EmergencyJob::STATUS_DUPLICATE,
            EmergencyJob::STATUS_TRANSFERRED_TO_112,
        ], true);
    }

    private function scopeJobsToActor(Builder $jobs, User $actor): Builder
    {
        if (! $this->canViewAllStations($actor)) {
            $stationIds = $this->stationIds($actor);
            $jobs->where(function (Builder $query) use ($stationIds): void {
                $query->whereHas(
                    'ward',
                    fn (Builder $wards) => $wards->withTrashed()->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
                );
            });
        }

        return $jobs;
    }

    public function callers(User $actor, string $search): Collection
    {
        $query = $this->visibleJobs($actor)
            ->with('ward:id,name,code')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->where('status', '!=', EmergencyJob::STATUS_DUPLICATE);

        $digits = preg_replace('/\D+/', '', $search) ?? '';
        if (strlen($digits) >= 3) {
            $query->whereRaw($this->normalizedPhoneSql().' LIKE ?', ['%'.$digits.'%']);
        } else {
            $query->where(function (Builder $matches) use ($search): void {
                $matches->whereLike('caller_name', '%'.$search.'%')
                    ->orWhereLike('society_name', '%'.$search.'%');
            });
        }

        return $query->orderByDesc('occurred_at')->limit(10)->get();
    }

    public function possibleDuplicates(User $actor, string $phone, ?int $exceptId = null): Collection
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 7) {
            return new Collection;
        }

        return $this->visibleJobs($actor)
            ->with('ward:id,name,code')
            ->where('occurred_at', '>=', now()->subHours(48))
            ->where('status', '!=', EmergencyJob::STATUS_DUPLICATE)
            ->whereRaw($this->normalizedPhoneSql().' = ?', [$digits])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();
    }

    public function findVisibleJobOrFail(User $actor, int $id): EmergencyJob
    {
        return $this->visibleJobs($actor)->findOrFail($id);
    }

    public function assignableStations(User $actor, EmergencyJob $job): Collection
    {
        $stations = FireStation::query()
            ->where('is_active', true)
            ->when(
                ! $this->canViewAllStations($actor),
                fn (Builder $query) => $query->whereKey($this->stationIds($actor)),
            )
            ->when(
                $job->ward_id !== null,
                fn (Builder $query) => $query->whereHas(
                    'wards',
                    fn (Builder $wards) => $wards->where('wards.id', $job->ward_id),
                ),
            )
            ->with([
                'vehicles' => fn (Builder|Relation $vehicles) => $vehicles
                    ->where('status', Vehicle::STATUS_AVAILABLE)
                    ->whereDoesntHave('assignments', fn (Builder $assignments) => $assignments->whereNull('released_at'))
                    ->orderBy('vehicle_number'),
                'teams' => fn (Builder|Relation $teams) => $teams
                    ->where('status', Team::STATUS_AVAILABLE)
                    ->whereDoesntHave('assignments', fn (Builder $assignments) => $assignments->whereNull('released_at'))
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return $stations->filter(fn (FireStation $station): bool => $station->vehicles->isNotEmpty());
    }

    public function canUpdateAssignment(User $actor, JobAssignment $assignment): bool
    {
        if ($this->canViewAllStations($actor)) {
            return true;
        }

        if ($actor->hasRole('VEHICLE_DRIVER')) {
            $team = $assignment->team;

            return $team !== null && (
                $assignment->team_leader_user_id === $actor->id
                || $team->members()
                    ->where('users.id', $actor->id)
                    ->whereNull('team_members.left_at')
                    ->exists()
            );
        }

        if ($this->stationIds($actor)->contains($assignment->fire_station_id)) {
            return true;
        }

        $team = $assignment->team;

        return $team !== null && (
            $team->leader_user_id === $actor->id
            || $team->members()->where('users.id', $actor->id)->exists()
        );
    }

    public function register(User $actor, array $data): EmergencyJob
    {
        $ward = Ward::query()->whereKey($data['ward_id'])->where('is_active', true)->first();
        if (! $ward) {
            throw ValidationException::withMessages(['ward_id' => 'Select an active ward.']);
        }

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $this->stationIds($actor);
            if (! $ward->fireStations()->whereIn('fire_stations.id', $stationIds)->exists()) {
                throw ValidationException::withMessages(['ward_id' => 'The selected ward is outside your station scope.']);
            }
        }

        $job = DB::transaction(function () use ($actor, $data, $ward): EmergencyJob {
            $job = EmergencyJob::query()->create([
                ...$data,
                'job_id' => $this->newJobId($data['society_name'], $ward->code),
                'occurred_at' => $data['occurred_at'],
                'status' => EmergencyJob::STATUS_RECEIVED,
                'created_by' => $actor->id,
            ]);

            JobCallLog::query()->create([
                'emergency_job_id' => $job->id,
                'caller_name' => $job->caller_name,
                'caller_phone' => $job->caller_phone,
                'call_source' => $job->call_source,
                'received_at' => now(),
                'notes' => $job->remarks,
                'created_by' => $actor->id,
            ]);

            $this->recordStatus($job, null, EmergencyJob::STATUS_RECEIVED, $actor, 'Incident registered.');
            $this->auditLogger->record($actor, 'jobs.created', $job, [
                'job_id' => $job->job_id,
                'ward_id' => $job->ward_id,
                'priority' => $job->priority,
            ]);

            return $job;
        });

        return $job->load('ward');
    }

    public function closeAsDuplicate(User $actor, EmergencyJob $job, int $originalId, ?string $remarks): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $originalId, $remarks): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);
            $original = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($originalId);

            if ($job->is($original)) {
                throw ValidationException::withMessages(['duplicate_of' => 'An incident cannot be a duplicate of itself.']);
            }

            if ($original->status === EmergencyJob::STATUS_DUPLICATE) {
                throw ValidationException::withMessages(['duplicate_of' => 'Choose the original incident, not another duplicate.']);
            }

            if ($job->status !== EmergencyJob::STATUS_RECEIVED || $job->assignments()->exists()) {
                throw ValidationException::withMessages(['job' => 'Only unassigned, newly received incidents can be closed as duplicates.']);
            }

            if ($this->normalizedPhone($job->caller_phone) !== $this->normalizedPhone($original->caller_phone)) {
                throw ValidationException::withMessages(['duplicate_of' => 'The original incident must have the same caller phone number.']);
            }

            $job->update(['status' => EmergencyJob::STATUS_DUPLICATE]);
            JobExternalReference::query()->create([
                'emergency_job_id' => $job->id,
                'reference_type' => 'DUPLICATE_OF',
                'reference_number' => $original->job_id,
                'details' => $remarks,
                'confirmed_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->recordStatus($job, EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_DUPLICATE, $actor, $remarks);
            $this->auditLogger->record($actor, 'jobs.duplicate_closed', $job, [
                'job_id' => $job->job_id,
                'duplicate_of' => $original->job_id,
            ]);

            return $job->load('ward', 'externalReferences');
        });
    }

    public function transferTo112(User $actor, EmergencyJob $job, string $referenceNumber, ?string $details): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $referenceNumber, $details): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if ($job->status !== EmergencyJob::STATUS_RECEIVED || $job->assignments()->exists()) {
                throw ValidationException::withMessages(['job' => 'Only unassigned, newly received incidents can be transferred to 112.']);
            }

            $job->update(['status' => EmergencyJob::STATUS_TRANSFERRED_TO_112]);
            JobExternalReference::query()->create([
                'emergency_job_id' => $job->id,
                'reference_type' => '112',
                'reference_number' => $referenceNumber,
                'details' => $details,
                'transferred_at' => now(),
                'created_by' => $actor->id,
            ]);
            $this->recordStatus(
                $job,
                EmergencyJob::STATUS_RECEIVED,
                EmergencyJob::STATUS_TRANSFERRED_TO_112,
                $actor,
                $details,
            );
            $this->auditLogger->record($actor, 'jobs.transferred_to_112', $job, [
                'job_id' => $job->job_id,
                'reference_number' => $referenceNumber,
            ]);

            return $job->load('ward', 'externalReferences');
        });
    }

    public function assignResources(User $actor, EmergencyJob $job, array $data): EmergencyJob
    {
        return DB::transaction(function () use ($actor, $job, $data): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);

            if (! in_array($job->status, [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED], true)) {
                throw ValidationException::withMessages([
                    'assignments' => 'Resources can only be assigned to received or assigned incidents.',
                ]);
            }

            $vehicleIds = array_column($data['assignments'], 'vehicle_id');
            if (count($vehicleIds) !== count(array_unique($vehicleIds))) {
                throw ValidationException::withMessages(['assignments' => 'A vehicle can only be assigned once to an incident.']);
            }

            $teamIds = array_filter(array_column($data['assignments'], 'team_id'));
            if (count($teamIds) !== count(array_unique($teamIds))) {
                throw ValidationException::withMessages(['assignments' => 'A team can only be assigned once to an incident.']);
            }

            $initialStatus = $job->status;

            foreach ($data['assignments'] as $resource) {
                $station = FireStation::query()
                    ->whereKey($resource['fire_station_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $station || (! $this->canViewAllStations($actor) && ! $this->stationIds($actor)->contains($station->id))) {
                    throw ValidationException::withMessages([
                        'assignments' => 'The selected station is outside your access scope or inactive.',
                    ]);
                }

                if ($job->ward_id && ! $station->wards()->where('wards.id', $job->ward_id)->exists()) {
                    throw ValidationException::withMessages([
                        'assignments' => 'The selected station does not cover this incident ward.',
                    ]);
                }

                $vehicle = Vehicle::query()
                    ->whereKey($resource['vehicle_id'])
                    ->where('fire_station_id', $station->id)
                    ->lockForUpdate()
                    ->first();

                if (! $vehicle || $vehicle->status !== Vehicle::STATUS_AVAILABLE
                    || $vehicle->assignments()->whereNull('released_at')->exists()) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Each selected vehicle must be available at its selected station.',
                    ]);
                }

                $team = null;
                if (! empty($resource['team_id'])) {
                    $team = Team::query()
                        ->whereKey($resource['team_id'])
                        ->where('fire_station_id', $station->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $team || $team->status !== Team::STATUS_AVAILABLE
                        || $team->assignments()->whereNull('released_at')->exists()) {
                        throw ValidationException::withMessages([
                            'assignments' => 'Each selected team must be available at its selected station.',
                        ]);
                    }
                }

                $assignment = JobAssignment::query()->create([
                    'emergency_job_id' => $job->id,
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team?->id,
                    'team_leader_user_id' => $team?->leader_user_id,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                    'status' => JobAssignment::STATUS_ASSIGNED,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);
                $team?->update(['status' => Team::STATUS_DEPLOYED]);
                $this->recordStatus(
                    $job,
                    null,
                    JobAssignment::STATUS_ASSIGNED,
                    $actor,
                    $data['remarks'] ?? 'Response resource assigned.',
                    $assignment,
                );
                $this->auditLogger->record($actor, 'jobs.resource_assigned', $assignment, [
                    'job_id' => $job->job_id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team?->id,
                    'fire_station_id' => $station->id,
                ]);

                if ($team) {
                    $recipientIds = collect([$assignment->team_leader_user_id])
                        ->merge($team->members()->whereNull('team_members.left_at')->pluck('users.id'))
                        ->filter()
                        ->unique();
                    $dispatch = [
                        'assignment_id' => $assignment->id,
                        'job_id' => $job->job_id,
                        'incident_type' => $job->incident_type,
                        'priority' => $job->priority,
                        'caller_name' => $job->caller_name,
                        'caller_phone' => $job->caller_phone,
                        'society_name' => $job->society_name,
                        'incident_address' => $job->incident_address,
                        'fire_station' => $station->name,
                        'vehicle_number' => $vehicle->vehicle_number,
                        'message' => 'A new emergency response job has been assigned to your team.',
                        'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    ];

                    User::query()->whereKey($recipientIds)->get()
                        ->each(fn (User $recipient) => $recipient->notify(new DispatchAssigned($dispatch)));
                }
            }

            if ($initialStatus !== EmergencyJob::STATUS_ASSIGNED) {
                $job->update(['status' => EmergencyJob::STATUS_ASSIGNED]);
                $this->recordStatus(
                    $job,
                    $initialStatus,
                    EmergencyJob::STATUS_ASSIGNED,
                    $actor,
                    $data['remarks'] ?? 'Response resources assigned.',
                );
            }

            return $job->load([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'assignments.teamLeader',
                'statusHistory.actor',
                'statusHistory.assignment.vehicle',
            ]);
        });
    }

    public function updateAssignmentStatus(
        User $actor,
        EmergencyJob $job,
        int $assignmentId,
        array $data,
    ): EmergencyJob {
        return DB::transaction(function () use ($actor, $job, $assignmentId, $data): EmergencyJob {
            $job = $this->visibleJobs($actor)->lockForUpdate()->findOrFail($job->id);
            $assignment = $job->assignments()->lockForUpdate()->findOrFail($assignmentId);

            $team = $assignment->team;
            if (! $this->canUpdateAssignment($actor, $assignment)) {
                throw ValidationException::withMessages(['assignment' => 'This assignment is outside your access scope.']);
            }

            $allowedNext = [
                JobAssignment::STATUS_ASSIGNED => JobAssignment::STATUS_EN_ROUTE,
                JobAssignment::STATUS_EN_ROUTE => JobAssignment::STATUS_ARRIVED,
                JobAssignment::STATUS_ARRIVED => JobAssignment::STATUS_IN_PROGRESS,
                JobAssignment::STATUS_IN_PROGRESS => JobAssignment::STATUS_COMPLETED,
            ];
            $previousAssignmentStatus = $assignment->status;
            $nextStatus = $data['status'];
            if ($assignment->released_at !== null || ($allowedNext[$previousAssignmentStatus] ?? null) !== $nextStatus) {
                throw ValidationException::withMessages([
                    'status' => 'Invalid status transition. Follow assigned → en route → arrived → in progress → completed.',
                ]);
            }

            $previousJobStatus = $job->status;
            $assignment->update([
                'status' => $nextStatus,
                'released_at' => $nextStatus === JobAssignment::STATUS_COMPLETED ? now() : null,
                'remarks' => $data['remarks'] ?? $assignment->remarks,
            ]);
            $this->recordStatus(
                $job,
                $previousAssignmentStatus,
                $nextStatus,
                $actor,
                $data['remarks'] ?? null,
                $assignment,
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
            );

            if ($nextStatus === JobAssignment::STATUS_COMPLETED) {
                $assignment->vehicle()->update(['status' => Vehicle::STATUS_AVAILABLE]);
                if ($team && ! $team->assignments()->whereNull('released_at')->exists()) {
                    $team->update(['status' => Team::STATUS_AVAILABLE]);
                }
            }

            $nextJobStatus = $this->aggregateJobStatus($job);
            if ($nextJobStatus !== $previousJobStatus) {
                $job->update(['status' => $nextJobStatus]);
                $this->recordStatus($job, $previousJobStatus, $nextJobStatus, $actor, $data['remarks'] ?? null);
            }

            $this->auditLogger->record($actor, 'jobs.assignment_status_updated', $assignment, [
                'job_id' => $job->job_id,
                'previous_status' => $previousAssignmentStatus,
                'new_status' => $nextStatus,
            ]);

            return $job->load([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'assignments.teamLeader',
                'statusHistory.actor',
                'statusHistory.assignment.vehicle',
            ]);
        });
    }

    private function aggregateJobStatus(EmergencyJob $job): string
    {
        $statuses = $job->assignments()->pluck('status');
        if ($statuses->isNotEmpty()
            && $statuses->every(fn (string $status): bool => $status === JobAssignment::STATUS_COMPLETED)) {
            return EmergencyJob::STATUS_COMPLETED;
        }

        $rank = [
            JobAssignment::STATUS_ASSIGNED => 0,
            JobAssignment::STATUS_EN_ROUTE => 1,
            JobAssignment::STATUS_ARRIVED => 2,
            JobAssignment::STATUS_IN_PROGRESS => 3,
            JobAssignment::STATUS_COMPLETED => 3,
        ];
        $status = $statuses
            ->filter(fn (string $value): bool => $value !== JobAssignment::STATUS_COMPLETED)
            ->sortByDesc(fn (string $value): int => $rank[$value] ?? 0)
            ->first();

        return $status ?? EmergencyJob::STATUS_RECEIVED;
    }

    private function recordStatus(
        EmergencyJob $job,
        ?string $previous,
        string $next,
        User $actor,
        ?string $remarks,
        ?JobAssignment $assignment = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ): void {
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'job_assignment_id' => $assignment?->id,
            'previous_status' => $previous,
            'new_status' => $next,
            'changed_by' => $actor->id,
            'changed_at' => now(),
            'remarks' => $remarks,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    private function newJobId(string $societyName, string $wardCode): string
    {
        $location = Str::slug($societyName);
        $location = $location !== '' ? $location : Str::slug($wardCode);
        $location = Str::limit($location !== '' ? $location : 'area', 14, '');

        return 'VMC-'.Str::upper($location).'-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }

    private function normalizedPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        return strlen($digits) === 11 && str_starts_with($digits, '0')
            ? substr($digits, 1)
            : $digits;
    }

    private function normalizedPhoneSql(): string
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(caller_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";

        return "CASE WHEN LENGTH({$digits}) = 12 AND SUBSTR({$digits}, 1, 2) = '91' THEN SUBSTR({$digits}, 3) WHEN LENGTH({$digits}) = 11 AND SUBSTR({$digits}, 1, 1) = '0' THEN SUBSTR({$digits}, 2) ELSE {$digits} END";
    }

    private function canViewAllStations(User $actor): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function stationIds(User $actor): \Illuminate\Support\Collection
    {
        return $actor->fireStations()->pluck('fire_stations.id')
            ->merge($actor->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
    }
}
