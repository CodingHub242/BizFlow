<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function create(
        int $tenantId,
        string $name
    ): Role {
        setPermissionsTeamId($tenantId);

        $exists = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This role already exists for this tenant.',
            ]);
        }

        return Role::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    public function assignPermissions(
        Role $role,
        int $tenantId,
        array $permissions
    ): Role {
        if ((int) $role->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role does not belong to this tenant.',
            ]);
        }

        setPermissionsTeamId($tenantId);

        $role->syncPermissions($permissions);

        return $role->fresh();
    }
}