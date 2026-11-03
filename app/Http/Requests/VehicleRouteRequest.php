<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class VehicleRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:job_assignments,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                    return;
                }

                $from = $this->filled('from') ? Carbon::parse($this->input('from')) : null;
                $to = $this->filled('to') ? Carbon::parse($this->input('to')) : now();

                if ($from && $this->filled('to') && $to->lt($from)) {
                    $validator->errors()->add('to', 'The end of the route range must be after its start.');
                }

                if ($from && $from->diffInDays($to) > 7) {
                    $validator->errors()->add('to', 'Route history range cannot exceed seven days.');
                }
            },
        ];
    }
}
