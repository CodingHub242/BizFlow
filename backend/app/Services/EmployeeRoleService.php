<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class EmployeeRoleService
{
    public function assign(
        Employee $employee,
        string $role,
        int $tenantId
    ): void {
        if ($tenantId <= 0 || (int) $employee->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The selected employee does not belong to this tenant.',
            ]);
        }

        $user = $employee->user;

        if (!$user || (int) $user->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' => 'The employee user does not belong to this tenant.',
            ]);
        }

        if (in_array($role, ['Owner', 'Administrator'], true)) {
            throw ValidationException::withMessages([
                'role' => 'This role cannot be assigned through employee role management.',
            ]);
        }

        setPermissionsTeamId($tenantId);

        $tenantRole = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->first();

        if (!$tenantRole) {
            throw ValidationException::withMessages([
                'role' => 'The selected role does not exist for this business.',
            ]);
        }

        DB::transaction(function () use ($user, $tenantRole): void {
            $user->syncRoles([$tenantRole]);
        });
    }
}