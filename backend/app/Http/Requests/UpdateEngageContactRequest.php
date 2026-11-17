<?php

namespace App\Http\Requests\Engage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEngageContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,archived'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}