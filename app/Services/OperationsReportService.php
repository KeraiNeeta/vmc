<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OperationsReportService
{
    public function report(User $actor, array $filters): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        $stations = $this->visibleStations($actor);
        $stationId = isset($filters['station_id']) ? (int) $filters['station_id'] : null;

        if ($stationId !== null && ! $stations->contains('id', $stationId)) {
            throw ValidationException::withMessages([
                'station_id' => 'The selected station is outside your access scope.',
            ]);
        }

        $jobs = $this->visibleJobs($actor, $stations->modelKeys())
            ->whereBetween('occurred_at', [$from, $to]);

        if ($stationId !== null) {
            $jobs->where(function (Builder $query) use ($stationId): void {
                $query->whereHas(
                    'ward.fireStations',
                    fn (Builder $query) => $query->where('fire_stations.id', $stationId),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $query) => $query->where('fire_station_id', $stationId),
                );
            });
        }

        $total = (clone $jobs)->count();
        $statusCounts = (clone $jobs)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $priorityCounts = (clone $jobs)
            ->selectRaw('priority, COUNT(*) AS aggregate')
            ->groupBy('priority')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'priority');
        $typeCounts = (clone $jobs)
            ->selectRaw('incident_type, COUNT(*) AS aggregate')
            ->groupBy('incident_type')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'incident_type');
        $dailyCounts = (clone $jobs)
            ->selectRaw('DATE(occurred_at) AS report_date, COUNT(*) AS aggregate')
            ->groupByRaw('DATE(occurred_at)')
            ->orderBy('report_date')
            ->pluck('aggregate', 'report_date');
        $responseStats = $this->responseStats($jobs);
        $stationWorkload = $this->stationWorkload($jobs, $stations);

        return [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'station_id' => $stationId,
            ],
            'summary' => [
                'total_jobs' => $total,
                'pending_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_RECEIVED] ?? 0)
                    + (int) ($statusCounts[EmergencyJob::STATUS_ASSIGNED] ?? 0),
                'active_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_EN_ROUTE] ?? 0)
                    + (int) ($statusCounts[EmergencyJob::STATUS_ARRIVED] ?? 0)
                    + (int) ($statusCounts[EmergencyJob::STATUS_IN_PROGRESS] ?? 0),
                'completed_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0),
                'duplicate_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_DUPLICATE] ?? 0),
                'transferred_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_TRANSFERRED_TO_112] ?? 0),
                'critical_jobs' => (int) ($priorityCounts['CRITICAL'] ?? 0),
                'completion_rate' => $total === 0
                    ? 0
                    : round(((int) ($statusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0)) / $total * 100, 1),
                'average_response_minutes' => $responseStats['average_response_minutes'],
                'average_resolution_minutes' => $responseStats['average_resolution_minutes'],
                'jobs_with_response_time' => $responseStats['jobs_with_response_time'],
                'jobs_with_resolution_time' => $responseStats['jobs_with_resolution_time'],
            ],
            'daily' => $this->dailySeries($from, $to, $dailyCounts),
            'statuses' => $this->distribution($statusCounts, $total),
            'priorities' => $this->distribution($priorityCounts, $total),
            'incident_types' => $this->distribution($typeCounts, $total),
            'stations' => $stationWorkload,
            'stations_available' => $stations->map(fn (FireStation $station): array => [
                'id' => $station->id,
                'name' => $station->name,
                'code' => $station->code,
            ])->values()->all(),
        ];
    }

    private function responseStats(Builder $jobs): array
    {
        $events = JobStatusHistory::query()
            ->join('emergency_jobs', 'emergency_jobs.id', '=', 'job_status_history.emergency_job_id')
            ->whereIn('job_status_history.emergency_job_id', (clone $jobs)->select('emergency_jobs.id'))
            ->whereIn('job_status_history.new_status', [
                EmergencyJob::STATUS_EN_ROUTE,
                EmergencyJob::STATUS_COMPLETED,
            ])
            ->get([
                'job_status_history.emergency_job_id',
                'job_status_history.job_assignment_id',
                'job_status_history.new_status',
                'job_status_history.changed_at',
                'emergency_jobs.occurred_at',
            ])
            ->groupBy('emergency_job_id');

        $responseMinutes = [];
        $resolutionMinutes = [];

        foreach ($events as $jobEvents) {
            $occurredAt = Carbon::parse($jobEvents->first()->occurred_at);
            $firstResponse = $jobEvents
                ->where('new_status', EmergencyJob::STATUS_EN_ROUTE)
                ->map(fn ($event) => Carbon::parse($event->changed_at))
                ->filter(fn (Carbon $changedAt): bool => $changedAt->greaterThanOrEqualTo($occurredAt))
                ->sort()
                ->first();

            if ($firstResponse) {
                $responseMinutes[] = $occurredAt->diffInSeconds($firstResponse) / 60;
            }

            $completedAt = $jobEvents
                ->where('new_status', EmergencyJob::STATUS_COMPLETED)
                ->whereNull('job_assignment_id')
                ->map(fn ($event) => Carbon::parse($event->changed_at))
                ->filter(fn (Carbon $changedAt): bool => $changedAt->greaterThanOrEqualTo($occurredAt))
                ->sort()
                ->first();

            if ($completedAt) {
                $resolutionMinutes[] = $occurredAt->diffInSeconds($completedAt) / 60;
            }
        }

        return [
            'average_response_minutes' => $responseMinutes === [] ? null : round(array_sum($responseMinutes) / count($responseMinutes), 1),
            'average_resolution_minutes' => $resolutionMinutes === [] ? null : round(array_sum($resolutionMinutes) / count($resolutionMinutes), 1),
            'jobs_with_response_time' => count($responseMinutes),
            'jobs_with_resolution_time' => count($resolutionMinutes),
        ];
    }

    private function stationWorkload(Builder $jobs, Collection $stations): array
    {
        $counts = JobAssignment::query()
            ->whereIn('emergency_job_id', (clone $jobs)->select('emergency_jobs.id'))
            ->selectRaw('fire_station_id, COUNT(*) AS deployments')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_deployments', [JobAssignment::STATUS_COMPLETED])
            ->groupBy('fire_station_id')
            ->get()
            ->keyBy('fire_station_id');

        return $stations->map(fn (FireStation $station): array => [
            'id' => $station->id,
            'name' => $station->name,
            'code' => $station->code,
            'deployments' => (int) ($counts[$station->id]->deployments ?? 0),
            'completed_deployments' => (int) ($counts[$station->id]->completed_deployments ?? 0),
        ])->all();
    }

    private function dailySeries(Carbon $from, Carbon $to, Collection $counts): array
    {
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $day->format('M j'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $days;
    }

    private function distribution(Collection $counts, int $total): array
    {
        return $counts->map(fn ($count, string $label): array => [
            'label' => $label,
            'count' => (int) $count,
            'percentage' => $total === 0 ? 0 : round(((int) $count) / $total * 100, 1),
        ])->values()->all();
    }

    private function visibleJobs(User $actor, array $stationIds): Builder
    {
        $jobs = EmergencyJob::query();
        if ($this->canViewAllStations($actor)) {
            return $jobs;
        }

        return $jobs->where(function (Builder $query) use ($stationIds): void {
            $query->whereHas(
                'ward.fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            )->orWhereHas(
                'assignments',
                fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
            );
        });
    }

    private function visibleStations(User $actor): Collection
    {
        $stations = FireStation::query()->orderBy('name');

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $actor->fireStations()->pluck('fire_stations.id')
                ->merge($actor->managedFireStations()->pluck('id'))
                ->unique()
                ->values();
            $stations->whereKey($stationIds);
        }

        return $stations->get(['id', 'name', 'code']);
    }

    private function canViewAllStations(User $actor): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN');
    }
}
