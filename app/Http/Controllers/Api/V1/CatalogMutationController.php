<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FireStationResource;
use App\Http\Resources\TeamResource;
use App\Http\Resources\VehicleResource;
use App\Http\Resources\WardResource;
use App\Models\FireStation;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Services\MasterDataManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CatalogMutationController extends Controller
{
    public function store(Request $request, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request->user(), $kind);
        $record = $manager->create($request, $kind, $request->user(), $auditLogger);

        return response()->json([
            'data' => $this->resource($kind, $record)->resolve($request),
        ], 201);
    }

    public function update(Request $request, string $id, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request->user(), $kind);
        $record = $manager->find($kind, $id);
        $record = $manager->update($request, $kind, $record, $request->user(), $auditLogger);

        return response()->json([
            'data' => $this->resource($kind, $record)->resolve($request),
        ]);
    }

    public function destroy(Request $request, string $id, string $kind, MasterDataManager $manager, AuditLogger $auditLogger): Response
    {
        $this->authorizePermission($request->user(), $kind);
        $manager->delete($kind, $manager->find($kind, $id), $request->user(), $auditLogger);

        return response()->noContent();
    }

    private function authorizePermission(User $user, string $kind): void
    {
        abort_unless(in_array($kind, ['stations', 'wards', 'vehicles', 'teams'], true), 404);
        abort_unless($user->hasPermission($kind.'.manage'), 403);
    }

    private function resource(string $kind, FireStation|Ward|Vehicle|Team $record): FireStationResource|WardResource|VehicleResource|TeamResource
    {
        return match ($kind) {
            'stations' => new FireStationResource($record->loadCount([
                'wards as wards_count' => fn ($query) => $query->where('wards.is_active', true),
                'users as active_staff_count' => fn ($query) => $query->where('users.is_active', true),
            ])),
            'wards' => new WardResource($record->load('fireStations:id,name')),
            'vehicles' => new VehicleResource($record->load('fireStation:id,name,code')),
            'teams' => new TeamResource($record
                ->load(['fireStation:id,name,code', 'leader:id,name', 'members:id,name'])
                ->loadCount(['members as active_members_count' => fn ($query) => $query->whereNull('team_members.left_at')])),
        };
    }
}
