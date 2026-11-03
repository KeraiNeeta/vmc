<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferIncidentTo112Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('job_external_references', 'reference_number')
                    ->where(fn ($query) => $query->where('reference_type', '112')),
            ],
            'details' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
