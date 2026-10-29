<?php

namespace App\Http\Requests;

use App\EmploymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8'],

            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('tenant_id', $tenantId),
            ],

            'employee_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('employees', 'employee_number')
                    ->where('tenant_id', $tenantId),
            ],

            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:100'],

            'employment_status' => [
                'sometimes',
                Rule::enum(EmploymentStatus::class),
            ],

            'hired_at' => ['nullable', 'date'],

            // Optional initial role, validated against this tenant.
            'role' => [
                'sometimes',
                'string',
                Rule::exists('roles', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('guard_name', 'web'),
            ],
        ];
    }
}
