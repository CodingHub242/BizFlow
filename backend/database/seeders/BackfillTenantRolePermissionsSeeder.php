<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Http\Controllers\RoleController;

class BackfillTenantRolePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(RoleController::class)
            ->backfillTenantRolePermissions();
    }
}