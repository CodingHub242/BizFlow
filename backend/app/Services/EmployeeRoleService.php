<?php


namespace App\Services;

use App\Models\Employee;
use Spatie\Permission\Models\Role;
use Illuminate\Validation\ValidationException;

class EmployeeRoleService
{
    public function assign(
        Employee $employee,
        string $role,
        int $tenantId
    ): void {
        if ((int) $employee->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' =>
                    'The selected employee does not belong to this tenant.',
            ]);
        }

        $user = $employee->user;

        if (!$user || (int) $user->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'employee_id' =>
                    'The employee user does not belong to this tenant.',
            ]);
        }

        // Activate the correct Spatie tenant/team context.
        setPermissionsTeamId($tenantId);

        // Resolve the role explicitly within this tenant.
        $tenantRole = Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->first();

        if (!$tenantRole) {
            throw ValidationException::withMessages([
                'role' => [
                    'The selected role does not exist for this business.',
                ],
            ]);
        }

        // Assign the resolved role model, not an unqualified role name.
        $user->syncRoles([$tenantRole]);
    }
}
