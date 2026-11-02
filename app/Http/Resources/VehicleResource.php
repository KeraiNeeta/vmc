<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fire_station_id' => $this->fire_station_id,
            'fire_station' => $this->whenLoaded('fireStation', fn () => [
                'id' => $this->fireStation->id,
                'name' => $this->fireStation->name,
                'code' => $this->fireStation->code,
            ]),
            'vehicle_number' => $this->vehicle_number,
            'vehicle_type' => $this->vehicle_type,
            'registration_number' => $this->registration_number,
            'gps_device_id' => $this->gps_device_id,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'status' => $this->status,
            'last_service_at' => $this->last_service_at,
            'created_at' => $this->created_at,
        ];
    }
}
