<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Services\EmployeeService;
use App\EmploymentStatus;
use App\Models\Employee;
use App\Http\Requests\StoreEmployeeAccountRequest;
use App\Services\EmployeeAccountService;
use App\Http\Requests\UpdateEmployeeRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use App\Services\EmployeeRoleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;


class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with([
                'user:id,name,email',
                'branch:id,name',
            ])
            ->orderBy('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $employees->getCollection()
                ->map(fn (Employee $employee) => $this->employeeData($employee)),
            'meta' => [
                'current_page' => $employees->currentPage(),
                'last_page' => $employees->lastPage(),
                'per_page' => $employees->perPage(),
                'total' => $employees->total(),
            ],
        ]);
    }

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

    public function show(Request $request, int $employee): JsonResponse
    {
        $record = Employee::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['user:id,name,email', 'branch:id,name'])
            ->findOrFail($employee);
            

        return response()->json([
            'success' => true,
            'data' => $this->employeeData($record),
        ]);
    }

    private function employeeData(Employee $employee): array
    {
        $user = $employee->user;

        $roleName = null;

        if ($user) {
            setPermissionsTeamId((int) $employee->tenant_id);

            $roleName = $user->getRoleNames()->first();
        }

        return [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'name' => $employee->user?->name,
            'email' => $employee->user?->email,
            'branch_id' => $employee->branch_id,
            'branch_name' => $employee->branch?->name,
            'employee_number' => $employee->employee_number,
            'phone' => $employee->phone,
            'job_title' => $employee->job_title,
            'employment_status' => $employee->employment_status->value,
            'hired_at' => $employee->hired_at?->toDateString(),
            'role' => $roleName,
            'created_at' => $employee->created_at,
        ];
    }

    public function update(UpdateEmployeeRequest $request,int $employee,EmployeeService $employeeService): JsonResponse 
    {
        $record = $employeeService->update(
            tenantId: $request->user()->tenant_id,
            employeeId: $employee,
            data: $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully.',
            'data' => $this->employeeData($record),
        ]);
    }


    public function storeAccount(StoreEmployeeAccountRequest $request,EmployeeAccountService $service): JsonResponse 
    {
        $employee = $service->create(
            tenantId: $request->user()->tenant_id,
            actor: $request->user(),
            data: $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Employee account created successfully.',
            'data' => $this->employeeData($employee),
        ], 201);
    }

   public function assignRole(
    Request $request,
    int $employee,
    EmployeeRoleService $employeeRoleService
): JsonResponse {
    $user = $request->user();

    $tenantId = (int) $user->tenant_id;

    abort_unless(
        $tenantId > 0,
        403,
        'A valid tenant context is required.'
    );

    setPermissionsTeamId($tenantId);

    abort_unless(
        $user->hasPermissionTo('employees.manage_roles', 'web'),
        403,
        'You do not have permission to manage employee roles.'
    );

    $validated = $request->validate([
        'role' => [
            'required',
            'string',
            Rule::exists('roles', 'name')
                ->where('tenant_id', $tenantId)
                ->where('guard_name', 'web'),
            Rule::notIn(['Owner', 'Administrator']),
        ],
    ]);

    $employeeRecord = Employee::query()
        ->where('tenant_id', $tenantId)
        ->with('user')
        ->findOrFail($employee);

    $employeeRoleService->assign(
        $employeeRecord,
        $validated['role'],
        $tenantId,
    );

    return response()->json([
        'success' => true,
        'message' => 'Employee role updated successfully.',
    ]);
}

}