<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FireStation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAllStations = $user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN');
        $stationIds = $canViewAllStations
            ? collect()
            : $user->fireStations()->pluck('fire_stations.id')
                ->merge($user->managedFireStations()->pluck('id'))
                ->unique()
                ->values();

        $stationsQuery = FireStation::query();
        $wardsQuery = Ward::query()->where('is_active', true);
        $usersQuery = User::query()->where('is_active', true);

        if (! $canViewAllStations) {
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
            ->withCount([
                'wards as wards_count' => fn (Builder $query) => $query->where('wards.is_active', true),
                'users as active_staff_count' => fn (Builder $query) => $query->where('users.is_active', true),
            ])
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

        return response()->json([
            'data' => [
                'summary' => [
                    'total_stations' => (clone $stationsQuery)->count(),
                    'active_stations' => (clone $stationsQuery)->where('is_active', true)->count(),
                    'active_wards' => $wardsQuery->count(),
                    'active_users' => $usersQuery->count(),
                    'system_roles' => Role::query()->where('is_system', true)->count(),
                    'permissions' => Permission::query()->count(),
                ],
                'stations' => $stations
                    ->map(fn (FireStation $station): array => [
                        'id' => $station->id,
                        'code' => $station->code,
                        'name' => $station->name,
                        'address' => $station->address,
                        'latitude' => $station->latitude === null ? null : (float) $station->latitude,
                        'longitude' => $station->longitude === null ? null : (float) $station->longitude,
                        'is_active' => $station->is_active,
                        'wards_count' => (int) $station->wards_count,
                        'active_staff_count' => (int) $station->active_staff_count,
                    ])
                    ->values(),
                'operations_available' => [
                    'emergency_jobs' => false,
                    'vehicle_tracking' => false,
                    'reports' => false,
                ],
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
