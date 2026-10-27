<?php

namespace App\Http\Requests;

use App\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $employeeId = $this->route('employee');

        return [
            'branch_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $tenantId)
                    ),
            ],

            'employee_number' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('employees', 'employee_number')
                    ->where(fn ($query) =>
                        $query->where('tenant_id', $tenantId)
                    )
                    ->ignore($employeeId),
            ],

            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],

            'employment_status' => [
                'sometimes',
                'required',
                Rule::enum(EmploymentStatus::class),
            ],

            'hired_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}