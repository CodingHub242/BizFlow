<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Services\EmployeeService;
use App\EmploymentStatus;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function store(StoreEmployeeRequest $request,EmployeeService $employeeService): JsonResponse 
    {
        $employee = $employeeService->create(
            tenantId: $request->user()->tenant_id,
            userId: $request->validated('user_id'),
            branchId: $request->validated('branch_id'),
            employeeNumber: $request->validated('employee_number'),
            phone: $request->validated('phone'),
            jobTitle: $request->validated('job_title'),
            employmentStatus: $request->validated('employment_status')
            ? EmploymentStatus::from($request->validated('employment_status'))
            : EmploymentStatus::ACTIVE,
            hiredAt: $request->validated('hired_at'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully.',
            'data' => [
                'id' => $employee->id,
                'tenant_id' => $employee->tenant_id,
                'user_id' => $employee->user_id,
                'branch_id' => $employee->branch_id,
                'employee_number' => $employee->employee_number,
                'phone' => $employee->phone,
                'job_title' => $employee->job_title,
                'employment_status' => $employee->employment_status->value,
                'hired_at' => $employee->hired_at?->toDateString(),
                'created_at' => $employee->created_at,
            ],
        ], 201);
    }
}