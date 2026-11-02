<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WardResource extends JsonResource
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
            'description' => $this->description,
            'is_active' => $this->is_active,
            'fire_stations' => $this->whenLoaded('fireStations', fn () => $this->fireStations
                ->map(fn ($station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
