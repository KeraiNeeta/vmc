<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogIndexRequest;
use App\Http\Resources\DirectoryUserResource;
use App\Http\Resources\FireStationResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\WardResource;
use App\Models\FireStation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CatalogController extends Controller
{
    public function fireStations(CatalogIndexRequest $request): JsonResponse
    {
        $stations = FireStation::query()
            ->withCount([
                'wards as wards_count' => fn (Builder $query) => $query->where('wards.is_active', true),
                'users as active_staff_count' => fn (Builder $query) => $query->where('users.is_active', true),
            ]);
        $this->applyStationScope($request->user(), $stations);
        $this->applySearch($stations, $request->validated('search'), ['code', 'name', 'address']);

        return $this->paginated(
            $request,
            $stations->orderBy('name'),
            FireStationResource::class,
        );
    }

    public function wards(CatalogIndexRequest $request): JsonResponse
    {
        $wards = Ward::query()->with('fireStations:id,name');
        $user = $request->user();

        if (! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('CONTROL_ROOM_ADMIN')) {
            $stationIds = $this->stationIds($user);
            $wards->whereHas(
                'fireStations',
                fn (Builder $query) => $query->whereIn('fire_stations.id', $stationIds),
            );
        }

        $this->applySearch($wards, $request->validated('search'), ['code', 'name', 'description']);

        return $this->paginated($request, $wards->orderBy('name'), WardResource::class);
    }

    public function users(CatalogIndexRequest $request): JsonResponse
    {
        $users = User::query()->with('roles');
        $user = $request->user();

        if (! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('CONTROL_ROOM_ADMIN')) {
            $stationIds = $this->stationIds($user);
            $users->where(
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

        $this->applySearch($users, $request->validated('search'), ['name', 'email']);

        return $this->paginated($request, $users->orderBy('name'), DirectoryUserResource::class);
    }

    public function roles(CatalogIndexRequest $request): JsonResponse
    {
        $roles = Role::query()->with([
            'permissions' => fn (Builder|Relation $query) => $query->orderBy('name'),
        ]);

        $this->applySearch($roles, $request->validated('search'), ['code', 'name', 'description']);

        return $this->paginated($request, $roles->orderBy('name'), RoleResource::class);
    }

    public function vehicles(CatalogIndexRequest $request): JsonResponse
    {
        $vehicles = Vehicle::query()->with('fireStation:id,name,code');
        $this->applyStationScope($request->user(), $vehicles, 'fire_station_id');
        $this->applySearch($vehicles, $request->validated('search'), [
            'vehicle_number',
            'vehicle_type',
            'registration_number',
            'status',
        ]);

        return $this->paginated($request, $vehicles->orderBy('vehicle_number'), VehicleResource::class);
    }

    public function teams(CatalogIndexRequest $request): JsonResponse
    {
        $teams = Team::query()
            ->with(['fireStation:id,name,code', 'leader:id,name'])
            ->withCount([
                'members as active_members_count' => fn (Builder $members) => $members
                    ->whereNull('team_members.left_at'),
            ]);
        $this->applyStationScope($request->user(), $teams, 'fire_station_id');
        $this->applySearch($teams, $request->validated('search'), ['name', 'contact_number', 'status']);

        return $this->paginated($request, $teams->orderBy('name'), TeamResource::class);
    }

    private function applyStationScope(User $user, Builder $query, string $stationColumn = 'id'): void
    {
        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN')) {
            return;
        }

        $query->whereIn($stationColumn, $this->stationIds($user));
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

    /**
     * @param  list<string>  $columns
     */
    private function applySearch(Builder $query, ?string $search, array $columns): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $nested) use ($columns, $search): void {
            foreach ($columns as $column) {
                $nested->orWhereLike($column, '%'.$search.'%');
            }
        });
    }

    /**
     * @param  class-string<JsonResource>  $resource
     */
    private function paginated(CatalogIndexRequest $request, Builder $query, string $resource): JsonResponse
    {
        $perPage = (int) $request->validated('per_page', 15);
        $records = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'data' => $resource::collection($records->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }
}
