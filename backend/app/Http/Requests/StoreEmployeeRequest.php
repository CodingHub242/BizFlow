<?php

namespace App\Http\Requests;

use App\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
            ],

            'employee_number' => [
                'required',
                'string',
                'max:100',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'job_title' => [
                'nullable',
                'string',
                'max:100',
            ],

            'employment_status' => [
                'nullable',
                Rule::enum(EmploymentStatus::class),
            ],

            'hired_at' => [
                'nullable',
                'date',
            ],
        ];
    }
}