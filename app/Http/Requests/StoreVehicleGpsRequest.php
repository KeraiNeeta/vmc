<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleGpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignment_id' => ['required', 'integer', 'exists:job_assignments,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kph' => ['nullable', 'numeric', 'between:0,300'],
            'recorded_at' => ['nullable', 'date', 'after_or_equal:'.now()->subDay()->toIso8601String(), 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }
}
