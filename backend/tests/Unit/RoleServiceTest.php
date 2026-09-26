<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_create_a_custom_role(): void
    {
        $tenant = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $role = app(RoleService::class)->create(
            tenantId: $tenant->id,
            name: 'Store Supervisor',
        );

        $this->assertSame('Store Supervisor', $role->name);
        $this->assertSame($tenant->id, $role->tenant_id);

        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Store Supervisor',
            'guard_name' => 'web',
        ]);
    }

    public function test_duplicate_custom_role_is_rejected_within_same_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $service = app(RoleService::class);

        $service->create(
            tenantId: $tenant->id,
            name: 'Store Supervisor',
        );

        $this->expectException(ValidationException::class);

        $service->create(
            tenantId: $tenant->id,
            name: 'Store Supervisor',
        );
    }

    public function test_same_custom_role_name_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $service = app(RoleService::class);

        $roleA = $service->create(
            tenantId: $tenantA->id,
            name: 'Store Supervisor',
        );

        $roleB = $service->create(
            tenantId: $tenantB->id,
            name: 'Store Supervisor',
        );

        $this->assertNotSame($roleA->id, $roleB->id);
        $this->assertSame($tenantA->id, $roleA->tenant_id);
        $this->assertSame($tenantB->id, $roleB->tenant_id);

        $this->assertSame(
            1,
            Role::query()
                ->where('tenant_id', $tenantA->id)
                ->where('name', 'Store Supervisor')
                ->count()
        );

        $this->assertSame(
            1,
            Role::query()
                ->where('tenant_id', $tenantB->id)
                ->where('name', 'Store Supervisor')
                ->count()
        );
    }

    public function test_tenant_can_assign_permissions_to_its_role(): void
    {
        $tenant = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $role = app(RoleService::class)->create(
            tenantId: $tenant->id,
            name: 'Store Supervisor',
        );

        Permission::findOrCreate('customers.view', 'web');
        Permission::findOrCreate('sales.create', 'web');

        app(RoleService::class)->assignPermissions(
            role: $role,
            tenantId: $tenant->id,
            permissions: [
                'customers.view',
                'sales.create',
            ],
        );

        setPermissionsTeamId($tenant->id);

        $this->assertTrue($role->fresh()->hasPermissionTo('customers.view'));
        $this->assertTrue($role->fresh()->hasPermissionTo('sales.create'));
    }

    public function test_tenant_cannot_modify_role_belonging_to_another_tenant(): void
    {
        $tenantA = Tenant::create([
            'name' => 'Business A',
            'slug' => 'business-a',
        ]);

        $tenantB = Tenant::create([
            'name' => 'Business B',
            'slug' => 'business-b',
        ]);

        $roleB = app(RoleService::class)->create(
            tenantId: $tenantB->id,
            name: 'Store Supervisor',
        );

        Permission::findOrCreate('customers.view', 'web');

        $this->expectException(ValidationException::class);

        app(RoleService::class)->assignPermissions(
            role: $roleB,
            tenantId: $tenantA->id,
            permissions: [
                'customers.view',
            ],
        );
    }
}