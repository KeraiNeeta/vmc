<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fire_station' => $this->whenLoaded('fireStation', fn (): ?array => $this->fireStation ? [
                'id' => $this->fireStation->id,
                'code' => $this->fireStation->code,
                'name' => $this->fireStation->name,
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn (): ?array => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'vehicle_number' => $this->vehicle->vehicle_number,
                'vehicle_type' => $this->vehicle->vehicle_type,
            ] : null),
            'team' => $this->whenLoaded('team', fn (): ?array => $this->team ? [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ] : null),
            'team_leader' => $this->whenLoaded('teamLeader', fn (): ?array => $this->teamLeader ? [
                'id' => $this->teamLeader->id,
                'name' => $this->teamLeader->name,
            ] : null),
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
