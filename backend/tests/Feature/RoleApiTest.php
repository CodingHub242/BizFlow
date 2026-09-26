<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Database\Seeders\BizFlowRolePermissionSeeder;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_role_creation_permission_can_create_custom_role(): void
    {
        $tenant = Tenant::factory()->create();


        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        setPermissionsTeamId($tenant->id);

        $role = Role::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Administrator')
            ->firstOrFail();

        $role->givePermissionTo('roles.create');

        $user->assignRole($role);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/roles', [
                'name' => 'Store Supervisor',
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
    }
}