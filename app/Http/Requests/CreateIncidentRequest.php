<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'caller_name' => ['required', 'string', 'max:255'],
            'caller_phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^[0-9+().\\-\\s]+$/'],
            'call_source' => ['required', 'in:PHONE,112,WALK_IN,OTHER'],
            'incident_type' => ['required', 'in:STRUCTURE_FIRE,VEHICLE_FIRE,ROAD_ACCIDENT,MEDICAL_EMERGENCY,RESCUE,OTHER'],
            'priority' => ['required', 'in:LOW,NORMAL,HIGH,CRITICAL'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'society_name' => ['required', 'string', 'max:255'],
            'incident_address' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
