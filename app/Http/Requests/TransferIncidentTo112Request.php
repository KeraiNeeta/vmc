<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferIncidentTo112Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference_number' => ['required', 'string', 'max:100'],
            'details' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
