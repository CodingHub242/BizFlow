<?php

namespace Tests\Unit;

use App\Models\Tenant;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Tenant\TenantProvisioningService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;

   
public function test_creating_a_tenant_provisions_all_default_roles(): void
{
    $tenant = Tenant::create([
        'name' => 'Test Business',
        'slug' => 'test-business',
        'email' => 'test@example.com',
        'status' => 'approved',
    ]);

    // Exercise the actual tenant provisioning workflow.
    app(TenantProvisioningService::class)->provision($tenant);

    $this->assertSame(
        count(BizFlowRolePermissionSeeder::DEFAULT_ROLES),
        Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('guard_name', 'web')
            ->whereIn(
                'name',
                BizFlowRolePermissionSeeder::DEFAULT_ROLES
            )
            ->count()
    );

    foreach (BizFlowRolePermissionSeeder::DEFAULT_ROLES as $roleName) {
        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'name' => $roleName,
            'guard_name' => 'web',
        ]);
    }
}

}
