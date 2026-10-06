<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use App\Services\Tenant\TenantProvisioningService;
use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_tenant_provisions_default_roles(): void
    {
        $tenant = Tenant::create([
            'name' => 'Sandbox Business',
            'slug' => 'sandbox-business',
            'email' => 'sandbox@example.com',
            'phone' => '0240000000',
            'business_type' => 'Retail',
            'status' => 'approved',
        ]);

        app(TenantProvisioningService::class)->provision($tenant);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Sandbox Business',
        ]);

        foreach ([
            'Owner',
            'Administrator',
            'Manager',
            'Salesperson',
            'Cashier',
            'Inventory Officer',
            'Accountant',
        ] as $roleName) {
            $this->assertDatabaseHas('roles', [
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }
    }
    public function test_creating_a_tenant_provisions_role_permissions(): void
{
    $tenant = Tenant::create([
        'name' => 'Permission Sandbox',
        'slug' => 'permission-sandbox',
        'email' => 'permissions@example.com',
        'phone' => '0240000001',
        'business_type' => 'Retail',
        'status' => 'approved',
    ]);

    app(TenantProvisioningService::class)->provision($tenant);

    setPermissionsTeamId($tenant->id);

    $owner = Role::where('name', 'Owner')
        ->where('guard_name', 'web')
        ->firstOrFail();

    $this->assertTrue(
        $owner->hasPermissionTo('branches.view')
    );

    $this->assertTrue(
        $owner->hasPermissionTo('inventory.receive')
    );

    $this->assertTrue(
        $owner->hasPermissionTo('products.change_cost')
    );

    $this->assertTrue(
        $owner->hasPermissionTo('migration.import')
    );
}
public function test_role_assignments_are_isolated_between_tenants(): void
{
    $tenantOne = Tenant::create([
        'name' => 'Tenant One',
        'slug' => 'tenant-one',
        'email' => 'tenant1@example.com',
        'phone' => '0240000002',
        'business_type' => 'Retail',
       'status' => 'approved',
    ]);

    $tenantTwo = Tenant::create([
        'name' => 'Tenant Two',
        'slug' => 'tenant-two',
        'email' => 'tenant2@example.com',
        'phone' => '0240000003',
        'business_type' => 'Construction',
        'status' => 'approved',
    ]);

    $userOne = \App\Models\User::create([
        'name' => 'Tenant One Owner',
        'email' => 'owner1@example.com',
        'password' => bcrypt('password'),
        'tenant_id' => $tenantOne->id,
    ]);

    $userTwo = \App\Models\User::create([
        'name' => 'Tenant Two Owner',
        'email' => 'owner2@example.com',
        'password' => bcrypt('password'),
        'tenant_id' => $tenantTwo->id,
    ]);

    $provisioning = app(TenantProvisioningService::class);

    $provisioning->provision($tenantOne);
    $provisioning->provision($tenantTwo);

    setPermissionsTeamId($tenantOne->id);
    $userOne->assignRole('Owner');

    setPermissionsTeamId($tenantTwo->id);
    $userTwo->assignRole('Owner');

    $tenantOneOwnerRole = Role::where('name', 'Owner')
        ->where('guard_name', 'web')
        ->where('tenant_id', $tenantOne->id)
        ->firstOrFail();

    $tenantTwoOwnerRole = Role::where('name', 'Owner')
        ->where('guard_name', 'web')
        ->where('tenant_id', $tenantTwo->id)
        ->firstOrFail();

    $this->assertNotSame(
        $tenantOneOwnerRole->id,
        $tenantTwoOwnerRole->id
    );

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $tenantOneOwnerRole->id,
        'model_id' => $userOne->id,
        'model_type' => \App\Models\User::class,
        'tenant_id' => $tenantOne->id,
    ]);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $tenantTwoOwnerRole->id,
        'model_id' => $userTwo->id,
        'model_type' => \App\Models\User::class,
        'tenant_id' => $tenantTwo->id,
    ]);
}
public function test_new_tenant_defaults_to_pending(): void
{
    $tenant = Tenant::create([
        'name' => 'Pending Business',
        'slug' => 'pending-business',
        'email' => 'pending@example.com',
        'phone' => '0240000010',
        'business_type' => 'Retail',
    ]);

    $this->assertEquals(
        \App\TenantStatus::PENDING,
        $tenant->fresh()->status
    );
}
}