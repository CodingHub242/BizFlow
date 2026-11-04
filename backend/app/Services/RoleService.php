<?php

namespace App\Services;

use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function create(
        int $tenantId,
        string $name,
        array $permissions = [],
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

        if (in_array($name, BizFlowRolePermissionSeeder::DEFAULT_ROLES, true)) {
            throw ValidationException::withMessages([
                'name' => 'This name is reserved for a default Bizflow role.',
            ]);
        }

        $allowedPermissions = BizFlowRolePermissionSeeder::PERMISSIONS;

        $invalidPermissions = array_diff(
            $permissions,
            $allowedPermissions,
        );

        if ($invalidPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => [
                    'One or more selected permissions are not supported by BizFlow.',
                ],
            ]);
        }

         return DB::transaction(function () use (
            $tenantId,
            $name,
            $permissions,
        ): Role {
            $role = Role::create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'guard_name' => 'web',
            ]);

            if ($permissions !== []) {
                $permissionRecords = Permission::query()
                    ->where('guard_name', 'web')
                    ->whereIn('name', $permissions)
                    ->get();

                if ($permissionRecords->count() !== count(array_unique($permissions))) {
                    throw ValidationException::withMessages([
                        'permissions' => [
                            'Some selected permissions have not been provisioned in BizFlow.',
                        ],
                    ]);
                }

                $role->syncPermissions($permissionRecords);
            }

            return $role->load('permissions');
        });
    }

  public function assignPermissions(
    Role $role,
    int $tenantId,
    array $permissions
): Role {
    setPermissionsTeamId($tenantId);

    $permissions = array_values(array_unique($permissions));

    $invalidPermissions = array_diff(
        $permissions,
        BizFlowRolePermissionSeeder::PERMISSIONS,
    );

    if ($invalidPermissions !== []) {
        throw ValidationException::withMessages([
            'permissions' => [
                'One or more selected permissions are not supported by BizFlow.',
            ],
        ]);
    }

    return DB::transaction(function () use (
        $role,
        $tenantId,
        $permissions
    ): Role {
        $tenantRole = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('guard_name', 'web')
            ->lockForUpdate()
            ->find($role->id);

        if (!$tenantRole) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role does not belong to this tenant.',
            ]);
        }

        $permissionRecords = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissions)
            ->get();

        if ($permissionRecords->count() !== count($permissions)) {
            throw ValidationException::withMessages([
                'permissions' => [
                    'Some selected permissions have not been provisioned in BizFlow.',
                ],
            ]);
        }

        $tenantRole->syncPermissions($permissionRecords);

        return $tenantRole->fresh()->load('permissions');
    });
}
}