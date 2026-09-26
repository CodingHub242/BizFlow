<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMigrationMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'entity_type' => [
                'required',
                'string',
                Rule::in([
                    'customers',
                    'suppliers',
                    'catalog_items',
                    'invoices',
                    'expenses',
                    'payments',
                ]),
            ],

            'field_mapping' => [
                'required',
                'array',
            ],

            'field_mapping.*' => [
                'nullable',
                'string',
            ],
        ];
    }
}