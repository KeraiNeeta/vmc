<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->map(fn (Role $role): array => ['code' => $role->code, 'name' => $role->name])
                ->values()),
            'permissions' => $this->whenLoaded('roles', fn () => $this->roles
                ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
                ->unique()
                ->sort()
                ->values()),
            'fire_station_ids' => $this->whenLoaded('fireStations', function (): array {
                $stationIds = $this->fireStations->modelKeys();

                if ($this->resource->relationLoaded('managedFireStations')) {
                    $stationIds = array_merge($stationIds, $this->managedFireStations->modelKeys());
                }

                return array_values(array_unique($stationIds));
            }),
        ];
    }
}
