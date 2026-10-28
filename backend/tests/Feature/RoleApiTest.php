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

    private function assignRole(
    User $user,
    string $roleName = 'Administrator'
): void {
    setPermissionsTeamId($user->tenant_id);

    $permission = Permission::firstOrCreate([
        'name' => 'reports.view',
        'guard_name' => 'web',
    ]);

    $role = Role::query()->firstOrCreate([
        'tenant_id' => $user->tenant_id,
        'name' => $roleName,
        'guard_name' => 'web',
    ]);

    $role->givePermissionTo($permission);
    $user->assignRole($role);
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

    $role->givePermissionTo($permission);
    $user->assignRole($role);

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

}