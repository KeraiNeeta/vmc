<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRouteRequest;
use App\Models\Vehicle;
use App\Services\VehicleTrackingWorkflow;

class VehicleTrackingController extends Controller
{
    public function __construct(private readonly VehicleTrackingWorkflow $tracking) {}

    public function index(VehicleRouteRequest $request)
    {
        $validated = $request->validated();
        $vehicles = $this->tracking->trackedVehicles($request->user());
        $selectedVehicleId = isset($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null;
        $selectedVehicle = null;
        $routeAssignments = collect();
        $route = collect();

        if ($selectedVehicleId !== null) {
            $selectedVehicle = $this->tracking->findVisibleVehicleOrFail(
                $request->user(),
                $selectedVehicleId,
            )->load('fireStation:id,name,code');
            $routeAssignments = $this->tracking->routeAssignments($request->user(), $selectedVehicle);
            $route = $this->tracking->routeHistory($request->user(), $selectedVehicle, $validated);
        }

        $coordinates = $vehicles->map(fn (Vehicle $vehicle): array => [
            'latitude' => (float) $vehicle->latestGpsLog->latitude,
            'longitude' => (float) $vehicle->latestGpsLog->longitude,
        ])->concat($route->map(fn ($point): array => [
            'latitude' => (float) $point->latitude,
            'longitude' => (float) $point->longitude,
        ]));
        $bounds = $this->mapBounds($coordinates);
        $markers = $vehicles->map(function (Vehicle $vehicle) use ($bounds, $selectedVehicleId): array {
            $point = $this->project(
                (float) $vehicle->latestGpsLog->latitude,
                (float) $vehicle->latestGpsLog->longitude,
                $bounds,
            );

            return [
                ...$point,
                'vehicle' => $vehicle,
                'is_selected' => $vehicle->id === $selectedVehicleId,
            ];
        });
        $routePoints = $route->map(fn ($point): array => [
            ...$this->project((float) $point->latitude, (float) $point->longitude, $bounds),
            'log' => $point,
        ]);

        return view('tracking.index', [
            'vehicles' => $vehicles,
            'markers' => $markers,
            'routePoints' => $routePoints,
            'routePath' => $routePoints->map(fn (array $point): string => $point['x'].','.$point['y'])->implode(' '),
            'selectedVehicle' => $selectedVehicle,
            'routeAssignments' => $routeAssignments,
            'filters' => $validated,
        ]);
    }

    private function mapBounds($coordinates): array
    {
        if ($coordinates->isEmpty()) {
            return ['min_lat' => 22.1, 'max_lat' => 22.6, 'min_lng' => 72.9, 'max_lng' => 73.5];
        }

        $minLat = $coordinates->min('latitude');
        $maxLat = $coordinates->max('latitude');
        $minLng = $coordinates->min('longitude');
        $maxLng = $coordinates->max('longitude');
        $latPadding = max(($maxLat - $minLat) * 0.15, 0.01);
        $lngPadding = max(($maxLng - $minLng) * 0.15, 0.01);

        return [
            'min_lat' => $minLat - $latPadding,
            'max_lat' => $maxLat + $latPadding,
            'min_lng' => $minLng - $lngPadding,
            'max_lng' => $maxLng + $lngPadding,
        ];
    }

    private function project(float $latitude, float $longitude, array $bounds): array
    {
        return [
            'x' => round(40 + (($longitude - $bounds['min_lng']) / ($bounds['max_lng'] - $bounds['min_lng'])) * 720, 1),
            'y' => round(440 - (($latitude - $bounds['min_lat']) / ($bounds['max_lat'] - $bounds['min_lat'])) * 380, 1),
        ];
    }
}
