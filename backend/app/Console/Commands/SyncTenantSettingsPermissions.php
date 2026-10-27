<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncTenantSettingsPermissions extends Command
{
    protected $signature = 'bizflow:sync-settings-permissions';

    protected $description = 'Assign settings permissions to existing tenant roles';

    public function handle(): int
    {
        $permissions = [
            'settings.view',
            'settings.update',
            'settings.update_logo',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $assignments = [
            'Owner' => $permissions,
            'Administrator' => $permissions,
            'Manager' => ['settings.view'],
        ];

        Tenant::query()
            ->select('id')
            ->chunkById(100, function ($tenants) use ($assignments) {
                foreach ($tenants as $tenant) {
                    setPermissionsTeamId($tenant->id);

                    foreach ($assignments as $roleName => $permissionNames) {
                        $role = Role::query()
                            ->where('name', $roleName)
                            ->where('guard_name', 'web')
                            ->first();

                        if (!$role) {
                            continue;
                        }

                        // Only add these permissions; preserve existing ones.
                        $role->givePermissionTo($permissionNames);
                    }
                }
            });

        app(\Spatie\Permission\PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $this->info('Settings permissions synced successfully.');

        return self::SUCCESS;
    }
}
