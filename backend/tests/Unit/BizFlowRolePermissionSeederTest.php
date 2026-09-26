<?php

namespace Tests\Unit;

use App\Models\Tenant;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BizFlowRolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_roles_are_provisioned_for_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        app(BizFlowRolePermissionSeeder::class)
            ->provisionTenantRoles($tenant->id);

        $this->assertSame(
            7,
            Role::query()
                ->where('tenant_id', $tenant->id)
                ->count()
        );

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
            'guard_name' => 'web',
        ]);

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Salesperson',
            'guard_name' => 'web',
        ]);
    }

    public function test_roles_are_isolated_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $seeder = app(BizFlowRolePermissionSeeder::class);

        $seeder->provisionTenantRoles($tenantA->id);
        $seeder->provisionTenantRoles($tenantB->id);

        $this->assertSame(
            7,
            Role::query()
                ->where('tenant_id', $tenantA->id)
                ->count()
        );

        $this->assertSame(
            7,
            Role::query()
                ->where('tenant_id', $tenantB->id)
                ->count()
        );

        $this->assertSame(
            1,
            Role::query()
                ->where('tenant_id', $tenantA->id)
                ->where('name', 'Owner')
                ->count()
        );

        $this->assertSame(
            1,
            Role::query()
                ->where('tenant_id', $tenantB->id)
                ->where('name', 'Owner')
                ->count()
        );
    }

    public function test_default_roles_receive_expected_permissions(): void
{
    $tenant = Tenant::factory()->create();

    app(BizFlowRolePermissionSeeder::class)
        ->provisionTenantRoles($tenant->id);

    setPermissionsTeamId($tenant->id);

    $salesperson = Role::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'Salesperson')
        ->firstOrFail();

    $this->assertTrue(
        $salesperson->hasPermissionTo('customers.create')
    );

    $this->assertTrue(
        $salesperson->hasPermissionTo('sales.create')
    );

    $this->assertFalse(
        $salesperson->hasPermissionTo('products.change_cost')
    );

    $this->assertFalse(
        $salesperson->hasPermissionTo('reports.view_profit')
    );

    $this->assertFalse(
        $salesperson->hasPermissionTo('roles.create')
    );
}
}