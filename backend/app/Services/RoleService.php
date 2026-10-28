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
        if ((int) $role->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role does not belong to this tenant.',
            ]);
        }

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

        setPermissionsTeamId($tenantId);
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

        return $role->fresh()->load('permissions');
    }
}