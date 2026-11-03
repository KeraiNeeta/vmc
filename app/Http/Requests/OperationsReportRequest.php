<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class OperationsReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->input('from', now()->subDays(29)->toDateString()),
            'to' => $this->input('to', now()->toDateString()),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'station_id' => ['nullable', 'integer', 'exists:fire_stations,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                    return;
                }

                $from = Carbon::createFromFormat('Y-m-d', $this->input('from'));
                $to = Carbon::createFromFormat('Y-m-d', $this->input('to'));

                if ($to->lt($from)) {
                    $validator->errors()->add('to', 'The end date must be on or after the start date.');
                } elseif ($from->diffInDays($to) > 90) {
                    $validator->errors()->add('to', 'Report date ranges cannot exceed 90 days.');
                }
            },
        ];
    }
}
