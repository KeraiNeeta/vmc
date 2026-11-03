<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmergencyJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_id' => $this->job_id,
            'caller_name' => $this->caller_name,
            'caller_phone' => $this->caller_phone,
            'call_source' => $this->call_source,
            'incident_type' => $this->incident_type,
            'priority' => $this->priority,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'ward' => $this->whenLoaded('ward', fn (): ?array => $this->ward ? [
                'id' => $this->ward->id,
                'code' => $this->ward->code,
                'name' => $this->ward->name,
            ] : null),
            'society_name' => $this->society_name,
            'incident_address' => $this->incident_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'call_logs' => $this->whenLoaded('callLogs'),
            'assignments' => $this->whenLoaded('assignments', fn () => JobAssignmentResource::collection($this->assignments)),
            'status_history' => $this->whenLoaded('statusHistory'),
            'external_references' => $this->whenLoaded('externalReferences'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
