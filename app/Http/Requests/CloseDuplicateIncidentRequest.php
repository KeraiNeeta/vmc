<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseDuplicateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'duplicate_of' => ['required', 'integer', 'exists:emergency_jobs,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
