<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobCallLog;
use App\Models\JobExternalReference;
use App\Models\JobStatusHistory;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentWorkflow
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function visibleJobs(User $actor): Builder
    {
        $jobs = EmergencyJob::query();

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $this->stationIds($actor);
            $jobs->where(function (Builder $query) use ($stationIds): void {
                $query->whereHas(
                    'ward.fireStations',
                    fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
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
            $query->whereLike('caller_name', '%'.$search.'%');
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

        $job = DB::transaction(function () use ($actor, $data): EmergencyJob {
            $job = EmergencyJob::query()->create([
                ...$data,
                'job_id' => $this->newJobId(),
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

    private function recordStatus(
        EmergencyJob $job,
        ?string $previous,
        string $next,
        User $actor,
        ?string $remarks,
    ): void {
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'previous_status' => $previous,
            'new_status' => $next,
            'changed_by' => $actor->id,
            'changed_at' => now(),
            'remarks' => $remarks,
        ]);
    }

    private function newJobId(): string
    {
        return 'VMC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    private function normalizedPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizedPhoneSql(): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(caller_phone, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";
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
