<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueEmployeeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],

            // Object keyed by employee item request item ID.
            // Example: {"15": 3, "16": 2}
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'numeric', 'gt:0'],
        ];
    }
}