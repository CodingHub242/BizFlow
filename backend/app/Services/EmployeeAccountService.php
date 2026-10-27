<?php

namespace App\Services;

use App\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeAccountService
{
    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly EmployeeRoleService $employeeRoleService,
    ) {}

    public function create(
        int $tenantId,
        User $actor,
        array $data
    ): Employee {
        if ((int) $actor->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'tenant_id' => 'You cannot create employees for another business.',
            ]);
        }

        // Role assignment requires explicit permission.
        if (isset($data['role'])) {
            setPermissionsTeamId($tenantId);

            if (! $actor->hasPermissionTo('employees.manage_roles', 'web')) {
                throw ValidationException::withMessages([
                    'role' => 'You are not authorized to assign employee roles.',
                ]);
            }

            // Do not allow privileged roles through employee creation.
            if (in_array($data['role'], ['Owner', 'Administrator'], true)) {
                throw ValidationException::withMessages([
                    'role' => 'This role cannot be assigned through employee creation.',
                ]);
            }
        }

        return DB::transaction(function () use ($tenantId, $data) {
            $user = User::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $employee = $this->employeeService->create(
                tenantId: $tenantId,
                userId: $user->id,
                branchId: $data['branch_id'] ?? null,
                employeeNumber: $data['employee_number'],
                phone: $data['phone'] ?? null,
                jobTitle: $data['job_title'] ?? null,
                employmentStatus: EmploymentStatus::from(
                    $data['employment_status'] ?? 'active'
                ),
                hiredAt: $data['hired_at'] ?? null,
            );

            if (isset($data['role'])) {
                $this->employeeRoleService->assign(
                    $employee,
                    $data['role'],
                    $tenantId,
                );
            }

            return $employee->load([
                'user:id,name,email',
                'branch:id,name',
            ]);
        });
    }
}
