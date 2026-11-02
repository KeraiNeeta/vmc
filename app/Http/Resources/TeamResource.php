<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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
            'leader_user_id' => $this->leader_user_id,
            'leader' => $this->whenLoaded('leader', fn () => $this->leader
                ? ['id' => $this->leader->id, 'name' => $this->leader->name]
                : null),
            'name' => $this->name,
            'contact_number' => $this->contact_number,
            'status' => $this->status,
            'active_members_count' => (int) $this->resource->getAttribute('active_members_count'),
            'members' => $this->whenLoaded('members', fn () => $this->members
                ->map(fn ($member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'designation' => $member->pivot->designation,
                    'qualification' => $member->pivot->qualification,
                    'joined_at' => $member->pivot->joined_at,
                ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
