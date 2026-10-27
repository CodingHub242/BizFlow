<?php

namespace App\Services\Tenant;

use App\Models\User;
use App\Models\Tenant;
use Database\Seeders\BizFlowRolePermissionSeeder;

class TenantProvisioningService
{
    public function __construct(
        private readonly BizFlowRolePermissionSeeder $rolePermissionSeeder,
    ) {
    }

    public function provision(Tenant $tenant): void
    {
        $this->rolePermissionSeeder->provisionTenantRoles($tenant->id);
    }

    public function assignOwner(Tenant $tenant, User $user): void
    {
        if ((int) $user->tenant_id !== (int) $tenant->id) {
            throw new \InvalidArgumentException(
                'The owner must belong to the specified tenant.'
            );
        }
        setPermissionsTeamId($tenant->id);

        $user->assignRole('Owner');
    }
}