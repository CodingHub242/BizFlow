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
            Rule::exists('users', 'id')
                ->where(fn ($query) =>
                    $query->where(
                        'tenant_id',
                        $this->user()->tenant_id
                    )
                ),
        ],

            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) =>
                        $query->where(
                            'tenant_id',
                            $this->user()->tenant_id
                        )
                    ),
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