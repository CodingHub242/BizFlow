<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnEmployeeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1'],

            'items.*.issue_item_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.inventory_batch_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.quantity_returned' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'items.*.return_condition' => [
                'required',
                Rule::in([
                    'restockable',
                    'damaged',
                    'unusable',
                ]),
            ],

            'items.*.notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}