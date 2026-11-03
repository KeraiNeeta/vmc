<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignJobResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array', 'min:1', 'max:10'],
            'assignments.*.fire_station_id' => ['required', 'integer', 'exists:fire_stations,id'],
            'assignments.*.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'assignments.*.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
