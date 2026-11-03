<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.catalog_item_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'items.*.quantity_requested' => [
                'required',
                'numeric',
                'gt:0',
            ],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}