<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

class EmployeeRoleService
{
    public function assign(Employee $employee,string $role,int $tenantId): void 
    {
        if ($employee->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee does not belong to this tenant.',
            ]);
        }

        $user = $employee->user;

        if (!$user || $user->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The employee user does not belong to this tenant.',
            ]);
        }

        // Spatie Permission is configured with tenant_id as the team key.
        setPermissionsTeamId($tenantId);

        $user->syncRoles([$role]);
    }
}