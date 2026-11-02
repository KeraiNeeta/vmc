<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FireStationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'contact_number' => $this->contact_number,
            'is_active' => $this->is_active,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'wards_count' => (int) $this->resource->getAttribute('wards_count'),
            'active_staff_count' => (int) $this->resource->getAttribute('active_staff_count'),
            'created_at' => $this->created_at,
        ];
    }
}
