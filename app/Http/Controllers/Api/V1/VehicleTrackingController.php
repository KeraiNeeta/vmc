<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleGpsRequest;
use App\Http\Requests\VehicleRouteRequest;
use App\Http\Resources\VehicleGpsLogResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\VehicleTrackingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleTrackingController extends Controller
{
    public function __construct(private readonly VehicleTrackingWorkflow $tracking) {}

    public function index(Request $request): JsonResponse
    {
        $vehicles = $this->tracking->trackedVehicles($request->user());

        return response()->json([
            'data' => VehicleResource::collection($vehicles)->resolve($request),
        ]);
    }

    public function route(VehicleRouteRequest $request, Vehicle $vehicle): JsonResponse
    {
        $points = $this->tracking->routeHistory($request->user(), $vehicle, $request->validated());

        return response()->json([
            'data' => VehicleGpsLogResource::collection($points)->resolve($request),
            'meta' => [
                'vehicle_id' => $vehicle->id,
                'count' => $points->count(),
                'from' => $points->first()?->recorded_at?->toIso8601String(),
                'to' => $points->last()?->recorded_at?->toIso8601String(),
            ],
        ]);
    }

    public function store(StoreVehicleGpsRequest $request, Vehicle $vehicle): JsonResponse
    {
        $point = $this->tracking->recordLocation($request->user(), $vehicle, $request->validated());

        return (new VehicleGpsLogResource($point))
            ->response()
            ->setStatusCode(201);
    }
}
