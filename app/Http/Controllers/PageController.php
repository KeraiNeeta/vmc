<?php

namespace App\Http\Controllers;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobCallLog;
use App\Models\JobStatusHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PageController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        abort_if($user && ! $user->hasPermission('dashboard.view'), 403);

        if (! $user) {
            return view('pages.dashboard', [
                'summary' => [
                    'total_stations' => 0,
                    'active_stations' => 0,
                    'active_wards' => 0,
                    'active_users' => 0,
                    'system_roles' => 0,
                    'permissions' => 0,
                    'vehicles' => 0,
                    'available_vehicles' => 0,
                    'teams' => 0,
                    'active_jobs' => 0,
                    'pending_jobs' => 0,
                    'completed_jobs' => 0,
                    'transferred_jobs' => 0,
                    'total_jobs' => 0,
                ],
                'stations' => collect(),
                'recentJobs' => collect(),
                'activityItems' => collect(),
                'wardStats' => collect(),
                'mapMarkers' => collect(),
                'selectedJob' => null,
                'canViewJobs' => false,
                'preview' => true,
            ]);
        }

        $stationIds = $this->stationIds($user);
        $allStations = $this->canViewAllStations($user);
        $canViewVehicles = $user->hasPermission('vehicles.view');
        $canViewTeams = $user->hasPermission('teams.view');
        $canViewJobs = $user->hasPermission('jobs.view');
        $stationsQuery = FireStation::query();
        $wardsQuery = Ward::query()->where('is_active', true);
        $usersQuery = User::query()->where('is_active', true);
        $stationCountRelations = [
            'wards as wards_count' => fn (Builder $query) => $query->where('wards.is_active', true),
            'users as active_staff_count' => fn (Builder $query) => $query->where('users.is_active', true),
        ];

        if ($canViewVehicles) {
            $stationCountRelations['vehicles as available_vehicles_count'] = fn (Builder $query) => $query
                ->where('vehicles.status', Vehicle::STATUS_AVAILABLE);
        }

        if ($canViewTeams) {
            $stationCountRelations['teams as active_teams_count'] = fn (Builder $query) => $query
                ->whereIn('teams.status', ['AVAILABLE', 'DEPLOYED']);
        }

        if (! $allStations) {
            $stationsQuery->whereKey($stationIds);
            $wardsQuery->whereHas(
                'fireStations',
                fn (Builder $query) => $query->whereIn('fire_stations.id', $stationIds),
            );
            $usersQuery->where(
                fn (Builder $query) => $query
                    ->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    )
                    ->orWhereHas(
                        'managedFireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
            );
        }

        $stations = $stationsQuery
            ->withCount($stationCountRelations)
            ->orderBy('name')
            ->get([
                'id',
                'code',
                'name',
                'address',
                'latitude',
                'longitude',
                'is_active',
            ]);
        $vehiclesQuery = Vehicle::query();
        $teamsQuery = Team::query();
        $jobsQuery = $this->visibleJobs($user);

        if (! $allStations) {
            $vehiclesQuery->whereIn('fire_station_id', $stationIds);
            $teamsQuery->whereIn('fire_station_id', $stationIds);
        }

        $pendingStatuses = [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED];
        $activeStatuses = [EmergencyJob::STATUS_EN_ROUTE, EmergencyJob::STATUS_ARRIVED, EmergencyJob::STATUS_IN_PROGRESS];
        $activeJobsQuery = (clone $jobsQuery)->whereIn('status', $activeStatuses);
        $recentJobs = $canViewJobs
            ? (clone $jobsQuery)->with('ward')->orderByDesc('occurred_at')->limit(6)->get()
            : collect();
        $selectedJobId = $canViewJobs
            ? (clone $activeJobsQuery)->orderByDesc('occurred_at')->value('id')
                ?? (clone $jobsQuery)->orderByDesc('occurred_at')->value('id')
            : null;
        $selectedJob = $selectedJobId
            ? (clone $jobsQuery)->with([
                'ward',
                'assignments.fireStation',
                'assignments.vehicle',
                'assignments.team',
                'statusHistory',
            ])->find($selectedJobId)
            : null;
        $pendingByWard = $canViewJobs
            ? (clone $jobsQuery)->whereIn('status', $pendingStatuses)
                ->whereNotNull('ward_id')
                ->selectRaw('ward_id, COUNT(*) AS aggregate')
                ->groupBy('ward_id')
                ->pluck('aggregate', 'ward_id')
            : collect();
        $activeByWard = $canViewJobs
            ? (clone $jobsQuery)->whereIn('status', $activeStatuses)
                ->whereNotNull('ward_id')
                ->selectRaw('ward_id, COUNT(*) AS aggregate')
                ->groupBy('ward_id')
                ->pluck('aggregate', 'ward_id')
            : collect();
        $wardStats = $canViewJobs
            ? (clone $wardsQuery)->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Ward $ward): array => [
                    'ward' => $ward,
                    'pending' => (int) ($pendingByWard[$ward->id] ?? 0),
                    'active' => (int) ($activeByWard[$ward->id] ?? 0),
                ])
            : collect();
        $mapMarkers = collect();

        foreach ($stations as $station) {
            if ($station->latitude !== null && $station->longitude !== null) {
                $mapMarkers->push([
                    'kind' => 'station',
                    'label' => $station->code,
                    'latitude' => (float) $station->latitude,
                    'longitude' => (float) $station->longitude,
                ]);
            }
        }

        if ($canViewJobs) {
            foreach ((clone $activeJobsQuery)->whereNotNull('latitude')->whereNotNull('longitude')
                ->orderByDesc('occurred_at')->limit(12)->get(['job_id', 'latitude', 'longitude']) as $job) {
                $mapMarkers->push([
                    'kind' => 'incident',
                    'label' => $job->job_id,
                    'latitude' => (float) $job->latitude,
                    'longitude' => (float) $job->longitude,
                ]);
            }
        }

        if ($canViewVehicles) {
            foreach ((clone $vehiclesQuery)->whereNotNull('latitude')->whereNotNull('longitude')
                ->orderBy('vehicle_number')->limit(12)->get(['vehicle_number', 'latitude', 'longitude']) as $vehicle) {
                $mapMarkers->push([
                    'kind' => 'vehicle',
                    'label' => $vehicle->vehicle_number,
                    'latitude' => (float) $vehicle->latitude,
                    'longitude' => (float) $vehicle->longitude,
                ]);
            }
        }

        if ($mapMarkers->isNotEmpty()) {
            $minLatitude = $mapMarkers->min('latitude');
            $maxLatitude = $mapMarkers->max('latitude');
            $minLongitude = $mapMarkers->min('longitude');
            $maxLongitude = $mapMarkers->max('longitude');
            $latitudeRange = max($maxLatitude - $minLatitude, 0.0001);
            $longitudeRange = max($maxLongitude - $minLongitude, 0.0001);
            $mapMarkers = $mapMarkers->map(function (array $marker) use (
                $minLatitude,
                $minLongitude,
                $latitudeRange,
                $longitudeRange,
            ): array {
                $marker['x'] = round(12 + (($marker['longitude'] - $minLongitude) / $longitudeRange * 76), 2);
                $marker['y'] = round(12 + (1 - (($marker['latitude'] - $minLatitude) / $latitudeRange)) * 76, 2);

                return $marker;
            });
        }

        return view('pages.dashboard', [
            'summary' => [
                'total_stations' => (clone $stationsQuery)->count(),
                'active_stations' => (clone $stationsQuery)->where('is_active', true)->count(),
                'active_wards' => $wardsQuery->count(),
                'active_users' => $usersQuery->count(),
                'system_roles' => Role::query()->where('is_system', true)->count(),
                'permissions' => Permission::query()->count(),
                'vehicles' => $canViewVehicles ? (clone $vehiclesQuery)->count() : null,
                'available_vehicles' => $canViewVehicles
                    ? (clone $vehiclesQuery)->where('status', Vehicle::STATUS_AVAILABLE)->count()
                    : null,
                'teams' => $canViewTeams ? (clone $teamsQuery)->count() : null,
                'active_jobs' => $canViewJobs ? $activeJobsQuery->count() : null,
                'pending_jobs' => $canViewJobs ? (clone $jobsQuery)->whereIn('status', $pendingStatuses)->count() : null,
                'completed_jobs' => $canViewJobs
                    ? (clone $jobsQuery)->where('status', EmergencyJob::STATUS_COMPLETED)->count()
                    : null,
                'transferred_jobs' => $canViewJobs
                    ? (clone $jobsQuery)->where('status', EmergencyJob::STATUS_TRANSFERRED_TO_112)->count()
                    : null,
                'total_jobs' => $canViewJobs ? (clone $jobsQuery)->count() : null,
            ],
            'stations' => $stations,
            'recentJobs' => $recentJobs,
            'activityItems' => $canViewJobs ? $this->dashboardActivityItems($user) : collect(),
            'wardStats' => $wardStats,
            'mapMarkers' => $mapMarkers,
            'selectedJob' => $selectedJob,
            'canViewJobs' => $canViewJobs,
            'preview' => false,
        ]);
    }

    public function dashboardActivity(Request $request)
    {
        return response()->json([
            'data' => $this->dashboardActivityItems($request->user()),
        ]);
    }

    public function catalog(Request $request, string $kind)
    {
        $catalog = [
            'stations' => ['title' => 'Fire stations', 'permission' => 'stations.view'],
            'wards' => ['title' => 'Ward management', 'permission' => 'wards.view'],
            'users' => ['title' => 'User management', 'permission' => 'users.view'],
            'roles' => ['title' => 'Roles & permissions', 'permission' => 'roles.view'],
            'vehicles' => ['title' => 'Vehicles', 'permission' => 'vehicles.view'],
            'teams' => ['title' => 'Teams & members', 'permission' => 'teams.view'],
            'jobs' => ['title' => 'Emergency incidents', 'permission' => 'jobs.view'],
        ];
        abort_unless(isset($catalog[$kind]), 404);

        $user = $request->user();
        abort_if($user && ! $user->hasPermission($catalog[$kind]['permission']), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($validated['search'] ?? '');
        $records = null;

        if ($user) {
            $records = match ($kind) {
                'stations' => $this->stationCatalog($user, $search),
                'wards' => $this->wardCatalog($user, $search),
                'users' => $this->userCatalog($user, $search),
                'roles' => $this->roleCatalog($search),
                'vehicles' => $this->vehicleCatalog($user, $search),
                'teams' => $this->teamCatalog($user, $search),
                'jobs' => $this->jobCatalog($user, $search),
            };
        }

        return view('pages.catalog', [
            'kind' => $kind,
            'title' => $catalog[$kind]['title'],
            'search' => $search,
            'records' => $records,
            'canManage' => $user?->hasPermission($kind.'.manage') ?? false,
            'canCreate' => $kind === 'jobs' && ($user?->hasPermission('jobs.create') ?? false),
        ]);
    }

    public function module(Request $request, string $page)
    {
        $modules = [
            'jobs' => ['title' => 'Emergency jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 3'],
            'jobs/new' => ['title' => 'Register an emergency', 'category' => 'Emergency dispatch', 'phase' => 'Phase 3'],
            'jobs/pending' => ['title' => 'Pending jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 3'],
            'jobs/active' => ['title' => 'Active jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/scheduled' => ['title' => 'Scheduled jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 3'],
            'jobs/assigned' => ['title' => 'Assigned jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/en-route' => ['title' => 'En route', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/arrived' => ['title' => 'Arrived', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/in-progress' => ['title' => 'In-progress response', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/completed' => ['title' => 'Completed jobs', 'category' => 'Emergency dispatch', 'phase' => 'Phase 4'],
            'jobs/duplicates' => ['title' => 'Duplicate calls', 'category' => '112 coordination', 'phase' => 'Phase 3'],
            'jobs/transferred' => ['title' => 'Transferred to 112', 'category' => '112 coordination', 'phase' => 'Phase 3'],
            'tracking' => ['title' => 'Live GPS tracking', 'category' => 'Field operations', 'phase' => 'Phase 5'],
            'vehicles' => ['title' => 'Vehicles', 'category' => 'Field operations', 'phase' => 'Phase 2'],
            'teams' => ['title' => 'Teams & members', 'category' => 'Field operations', 'phase' => 'Phase 2'],
            'settings' => ['title' => 'System settings', 'category' => 'Administration', 'phase' => 'Phase 8'],
        ];
        abort_unless(isset($modules[$page]), 404);
        $permission = match (true) {
            $page === 'jobs/new' => 'jobs.create',
            str_starts_with($page, 'jobs/') => 'jobs.view',
            $page === 'tracking' => 'gps.view',
            $page === 'settings' => 'settings.manage',
            default => 'jobs.view',
        };
        abort_if($request->user() && ! $request->user()->hasPermission($permission), 403);

        return view('pages.module', ['module' => $modules[$page]]);
    }

    private function stationCatalog(User $user, string $search)
    {
        $query = FireStation::query()->withCount([
            'wards as wards_count' => fn (Builder $wards) => $wards->where('wards.is_active', true),
            'users as active_staff_count' => fn (Builder $users) => $users->where('users.is_active', true),
        ]);

        if (! $this->canViewAllStations($user)) {
            $query->whereKey($this->stationIds($user));
        }

        return $this->search($query, $search, ['code', 'name', 'address'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function wardCatalog(User $user, string $search)
    {
        $query = Ward::query()->with('fireStations:id,name');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->whereHas(
                'fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            );
        }

        return $this->search($query, $search, ['code', 'name', 'description'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function userCatalog(User $user, string $search)
    {
        $query = User::query()->with('roles');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->where(
                fn (Builder $users) => $users
                    ->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    )
                    ->orWhereHas(
                        'managedFireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
            );
        }

        return $this->search($query, $search, ['name', 'email'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function roleCatalog(string $search)
    {
        return $this->search(
            Role::query()->with(['permissions' => fn ($query) => $query->orderBy('name')]),
            $search,
            ['code', 'name', 'description'],
        )->orderBy('name')->paginate(10)->withQueryString();
    }

    private function vehicleCatalog(User $user, string $search)
    {
        $query = Vehicle::query()->with('fireStation:id,name,code');

        if (! $this->canViewAllStations($user)) {
            $query->whereIn('fire_station_id', $this->stationIds($user));
        }

        return $this->search($query, $search, ['vehicle_number', 'registration_number', 'vehicle_type', 'status'])
            ->orderBy('vehicle_number')
            ->paginate(10)
            ->withQueryString();
    }

    private function teamCatalog(User $user, string $search)
    {
        $query = Team::query()
            ->with(['fireStation:id,name,code', 'leader:id,name'])
            ->withCount([
                'members as active_members_count' => fn (Builder $members) => $members
                    ->whereNull('team_members.left_at'),
            ]);

        if (! $this->canViewAllStations($user)) {
            $query->whereIn('fire_station_id', $this->stationIds($user));
        }

        return $this->search($query, $search, ['name', 'contact_number', 'status'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function jobCatalog(User $user, string $search)
    {
        $query = EmergencyJob::query()->with('ward:id,name,code');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->where(function (Builder $jobs) use ($stationIds): void {
                $jobs->whereHas(
                    'ward.fireStations',
                    fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
                );
            });
        }

        return $this->search($query, $search, [
            'job_id',
            'caller_name',
            'caller_phone',
            'incident_type',
            'society_name',
            'incident_address',
        ])->orderByDesc('occurred_at')->paginate(10)->withQueryString();
    }

    /**
     * @param  list<string>  $columns
     */
    private function search(Builder $query, string $search, array $columns): Builder
    {
        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($columns, $search): void {
            foreach ($columns as $column) {
                $nested->orWhereLike($column, '%'.$search.'%');
            }
        });
    }

    /**
     * @return Collection<int, int>
     */
    private function stationIds(User $user): Collection
    {
        return $user->fireStations()->pluck('fire_stations.id')
            ->merge($user->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
    }

    private function canViewAllStations(User $user): bool
    {
        return $user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN');
    }

    private function visibleJobs(User $user): Builder
    {
        $jobs = EmergencyJob::query();

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
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

    /**
     * @return Collection<int, array<string, int|string|null>>
     */
    private function dashboardActivityItems(User $user)
    {
        $since = now()->subDay();
        $jobs = $this->visibleJobs($user)
            ->with('ward:id,name')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $calls = JobCallLog::query()
            ->with(['emergencyJob' => fn (Builder|Relation $query) => $query
                ->with('ward:id,name')
                ->whereIn('emergency_jobs.id', $this->visibleJobs($user)->select('emergency_jobs.id'))])
            ->where('received_at', '>=', $since)
            ->whereHas(
                'emergencyJob',
                fn (Builder $query) => $query->whereIn(
                    'emergency_jobs.id',
                    $this->visibleJobs($user)->select('emergency_jobs.id'),
                ),
            )
            ->orderByDesc('received_at')
            ->limit(10)
            ->get();
        $statusEvents = JobStatusHistory::query()
            ->with(['emergencyJob' => fn (Builder|Relation $query) => $query
                ->with('ward:id,name')
                ->whereIn('emergency_jobs.id', $this->visibleJobs($user)->select('emergency_jobs.id'))])
            ->where('changed_at', '>=', $since)
            ->whereHas(
                'emergencyJob',
                fn (Builder $query) => $query->whereIn(
                    'emergency_jobs.id',
                    $this->visibleJobs($user)->select('emergency_jobs.id'),
                ),
            )
            ->orderByDesc('changed_at')
            ->limit(10)
            ->get();

        $jobItems = $jobs->map(fn (EmergencyJob $job): array => [
            'key' => 'job-'.$job->id,
            'type' => 'NEW JOB',
            'job_id' => $job->job_id,
            'caller' => $job->caller_name,
            'phone' => $job->caller_phone,
            'incident' => str_replace('_', ' ', $job->incident_type),
            'society' => $job->society_name ?: ($job->ward?->name ?? 'Area not recorded'),
            'priority' => $job->priority,
            'status' => str_replace('_', ' ', $job->status),
            'time' => $job->created_at?->toIso8601String(),
            'url' => '/jobs/'.$job->id,
        ]);
        $callItems = $calls
            ->filter(fn (JobCallLog $call): bool => $call->emergencyJob !== null)
            ->map(fn (JobCallLog $call): array => [
                'key' => 'call-'.$call->id,
                'type' => 'NEW CALL',
                'job_id' => $call->emergencyJob->job_id,
                'caller' => $call->caller_name,
                'phone' => $call->caller_phone,
                'incident' => str_replace('_', ' ', $call->emergencyJob->incident_type),
                'society' => $call->emergencyJob->society_name ?: ($call->emergencyJob->ward?->name ?? 'Area not recorded'),
                'priority' => $call->emergencyJob->priority,
                'status' => str_replace('_', ' ', $call->emergencyJob->status),
                'time' => $call->received_at?->toIso8601String(),
                'url' => '/jobs/'.$call->emergency_job_id,
            ]);
        $statusItems = $statusEvents
            ->filter(fn (JobStatusHistory $event): bool => $event->emergencyJob !== null)
            ->map(fn (JobStatusHistory $event): array => [
                'key' => 'event-'.$event->id,
                'type' => $event->new_status === EmergencyJob::STATUS_RECEIVED ? 'NEW JOB' : 'STATUS UPDATE',
                'job_id' => $event->emergencyJob->job_id,
                'caller' => $event->emergencyJob->caller_name,
                'phone' => $event->emergencyJob->caller_phone,
                'incident' => str_replace('_', ' ', $event->emergencyJob->incident_type),
                'society' => $event->emergencyJob->society_name ?: ($event->emergencyJob->ward?->name ?? 'Area not recorded'),
                'priority' => $event->emergencyJob->priority,
                'status' => str_replace('_', ' ', $event->new_status),
                'remarks' => $event->remarks,
                'time' => $event->changed_at?->toIso8601String(),
                'url' => '/jobs/'.$event->emergency_job_id,
            ]);

        return $jobItems->concat($callItems)->concat($statusItems)
            ->sortByDesc('time')
            ->take(10)
            ->values();
    }
}
