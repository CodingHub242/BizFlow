<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

     protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BizFlowRolePermissionSeeder::class);
    }


    private function assignRole(
        User $user,
        string $permissionName,
        string $roleName = 'Administrator'
    ): Role {
        setPermissionsTeamId($user->tenant_id);

        $permission = Permission::findByName($permissionName, 'web');

        $role = Role::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $role;
    }

    public function test_user_with_role_creation_permission_can_create_custom_role(): void
{
    $this->seed(BizFlowRolePermissionSeeder::class);

    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    setPermissionsTeamId($tenant->id);

    // Retrieve the already-provisioned permission.
    $permission = Permission::findByName('roles.create', 'web');

    // Create the role used to authorize this API request.
    $role = Role::query()->firstOrCreate([
        'tenant_id' => $tenant->id,
        'name' => 'Administrator',
        'guard_name' => 'web',
    ]);

   // $role->givePermissionTo($permission);
    $this->assignRole($user, 'roles.create');

    $response = $this
        ->actingAs($user)
        ->postJson('/api/roles', [
            'name' => 'Store Supervisor',
            'permissions' => [
                'customers.view',
                'sales.view',
                'sales.create',
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => [
                'tenant_id' => $tenant->id,
                'name' => 'Store Supervisor',
                'guard_name' => 'web',
               
            ],
        ]);

    $this->assertDatabaseHas('roles', [
        'tenant_id' => $tenant->id,
        'name' => 'Store Supervisor',
        'guard_name' => 'web',
    ]);

    // Confirm permissions are stored through Spatie's relationship.
    $createdRole = Role::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'Store Supervisor')
        ->firstOrFail();

    $this->assertEqualsCanonicalizing(
        ['customers.view', 'sales.view', 'sales.create'],
        $createdRole->permissions->pluck('name')->all()
    );
}

public function test_user_with_roles_view_permission_can_list_tenant_roles(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user, 'roles.view');

    setPermissionsTeamId($tenant->id);

    Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sales Supervisor',
        'guard_name' => 'web',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/roles');

    $response
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'Sales Supervisor',
            'tenant_id' => $tenant->id,
        ]);
}

public function test_user_with_roles_update_permission_can_update_tenant_role_permissions(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user, 'roles.update');

    setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sales Supervisor',
        'guard_name' => 'web',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => [
                'customers.view',
                'sales.view',
                'sales.create',
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonFragment([
            'name' => 'Sales Supervisor',
        ]);

    $role->refresh();

    $this->assertEqualsCanonicalizing(
        ['customers.view', 'sales.view', 'sales.create'],
        $role->permissions->pluck('name')->all()
    );
}

public function test_tenant_cannot_update_another_tenants_role(): void
{
    $tenantOne = Tenant::factory()->create();
    $tenantTwo = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenantOne->id,
    ]);

    $this->assignRole($user, 'roles.update');

    setPermissionsTeamId($tenantTwo->id);

    $otherRole = Role::create([
        'tenant_id' => $tenantTwo->id,
        'name' => 'Other Tenant Role',
        'guard_name' => 'web',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/roles/{$otherRole->id}/permissions", [
            'permissions' => ['sales.view'],
        ]);

    $response->assertNotFound();

    $this->assertCount(0, $otherRole->fresh()->permissions);
}

public function test_tenant_cannot_update_global_role_template(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user, 'roles.update');

    setPermissionsTeamId($tenant->id);

    $globalRole = Role::query()
        ->whereNull('tenant_id')
        ->where('name', 'Owner')
        ->where('guard_name', 'web')
        ->firstOrFail();

    $originalPermissions = $globalRole->permissions
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    $response = $this
        ->actingAs($user)
        ->putJson("/api/roles/{$globalRole->id}/permissions", [
            'permissions' => ['sales.view'],
        ]);

    $response->assertNotFound();

    $this->assertEqualsCanonicalizing(
        $originalPermissions,
        $globalRole->fresh()->permissions->pluck('name')->all()
    );
}

public function test_role_permissions_update_rejects_unsupported_permissions(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user, 'roles.update');

    setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sales Supervisor',
        'guard_name' => 'web',
    ]);

    $response = $this
        ->actingAs($user)
        ->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => ['unsupported.permission'],
        ]);

    $response->assertUnprocessable();
}

public function test_empty_permissions_array_removes_all_role_permissions(): void
{
    $tenant = Tenant::factory()->create();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $this->assignRole($user, 'roles.update');

    setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sales Supervisor',
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo('sales.view');

    $response = $this
        ->actingAs($user)
        ->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => [],
        ]);

    $response->assertOk();

    $this->assertCount(0, $role->fresh()->permissions);
}

}