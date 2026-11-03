<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveEmployeeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_quantities' => ['sometimes', 'array'],
            'approved_quantities.*' => [
                'required',
                'numeric',
                'min:0',
            ],
        ];
    }
}