<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FleetVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_number' => $this->vehicle_number,
            'vehicle_type' => $this->vehicle_type,
            'status' => $this->status,
            'fire_station' => $this->whenLoaded('fireStation', fn (): ?array => $this->fireStation ? [
                'id' => $this->fireStation->id,
                'code' => $this->fireStation->code,
                'name' => $this->fireStation->name,
            ] : null),
            'location' => [
                'latitude' => $this->latitude === null ? null : (float) $this->latitude,
                'longitude' => $this->longitude === null ? null : (float) $this->longitude,
                'recorded_at' => $this->whenLoaded('latestGpsLog', fn () => $this->latestGpsLog?->recorded_at?->toIso8601String()),
            ],
            'assignments' => $this->whenLoaded('assignments', fn () => JobAssignmentResource::collection($this->assignments)),
        ];
    }
}
