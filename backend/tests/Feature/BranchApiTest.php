<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    private function assignRole(
        User $user,
        string $roleName = 'Manager'
    ): void {
        setPermissionsTeamId($user->tenant_id);

        $role = Role::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('name', $roleName)
            ->firstOrFail();

        $user->assignRole($role);
    }

    public function test_authenticated_user_can_create_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', [
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'address' => 'Accra',
                'phone' => '0244000000',
                'email' => 'main@example.com',
            ]);

        $response
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'address' => 'Accra',
            'phone' => '0244000000',
            'email' => 'main@example.com',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_branch(): void
    {
        $response = $this->postJson('/api/branches', [
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);

        $response->assertStatus(401);

        $this->assertDatabaseCount('branches', 0);
    }

    public function test_branch_is_created_under_authenticated_users_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', [
                'name' => 'Tenant A Branch',
                'code' => 'A-MAIN',
                'tenant_id' => $tenantB->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Branch',
            'code' => 'A-MAIN',
        ]);

        $this->assertDatabaseMissing('branches', [
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant A Branch',
            'code' => 'A-MAIN',
        ]);
    }

    public function test_branch_creation_requires_name_and_code(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'code',
            ]);

        $this->assertDatabaseCount('branches', 0);
    }

    public function test_branch_creation_rejects_invalid_email(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', [
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'email' => 'not-an-email',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
            ]);
    }

    public function test_branch_code_must_be_unique_within_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing Branch',
            'code' => 'MAIN',
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', [
                'name' => 'Another Branch',
                'code' => 'MAIN',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'code',
            ]);
    }

    public function test_same_branch_code_is_allowed_for_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Main',
            'code' => 'MAIN',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/branches', [
                'name' => 'Tenant A Main',
                'code' => 'MAIN',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Main',
            'code' => 'MAIN',
        ]);
    }

    public function test_authenticated_user_can_list_their_tenant_branches(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->count(2)->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches');

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_branch_list_does_not_include_other_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Tenant A Branch',
            'code' => 'A',
        ]);

        Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Branch',
            'code' => 'B',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches');

        $response
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Tenant A Branch',
                'code' => 'A',
            ])
            ->assertJsonMissing([
                'name' => 'Tenant B Branch',
                'code' => 'B',
            ]);
    }

    public function test_unauthenticated_user_cannot_list_branches(): void
    {
        $response = $this->getJson('/api/branches');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_view_their_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson("/api/branches/{$branch->id}");

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonFragment([
                'id' => $branch->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
            ]);
    }

    public function test_user_cannot_view_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Private Branch',
            'code' => 'PRIVATE',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson("/api/branches/{$branch->id}");

        $response->assertStatus(404);
    }

    public function test_authenticated_user_can_update_their_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Branch',
            'code' => 'OLD',
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/api/branches/{$branch->id}", [
                'name' => 'Updated Branch',
                'code' => 'UPDATED',
            ]);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
            'name' => 'Updated Branch',
            'code' => 'UPDATED',
        ]);
    }

    public function test_user_cannot_update_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Branch',
            'code' => 'B',
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/api/branches/{$branch->id}", [
                'name' => 'Hacked Branch',
            ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Branch',
        ]);
    }

    public function test_branch_code_can_be_changed_when_new_code_is_unique(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/api/branches/{$branch->id}", [
                'code' => 'MAIN-2',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'code' => 'MAIN-2',
        ]);
    }

    public function test_branch_code_cannot_be_changed_to_existing_code_in_same_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing Branch',
            'code' => 'EXISTING',
        ]);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch To Update',
            'code' => 'CURRENT',
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson("/api/branches/{$branch->id}", [
                'code' => 'EXISTING',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'code',
            ]);
    }

    public function test_authenticated_user_can_delete_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user, 'Administrator');

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Branch To Delete',
            'code' => 'DELETE',
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/api/branches/{$branch->id}");

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertSoftDeleted('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_user_cannot_delete_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user, 'Administrator');

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Branch',
            'code' => 'B',
        ]);

        $response = $this
            ->actingAs($user)
            ->deleteJson("/api/branches/{$branch->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenantB->id,
            'deleted_at' => null,
        ]);
    }

    public function test_authenticated_user_can_restore_deleted_branch(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Restorable Branch',
            'code' => 'RESTORE',
        ]);

        $branch->delete();

        $response = $this
            ->actingAs($user)
            ->postJson("/api/branches/{$branch->id}/restore");

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenant->id,
            'deleted_at' => null,
        ]);
    }

    public function test_user_cannot_restore_branch_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assignRole($user);

        $branch = Branch::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Deleted Branch',
            'code' => 'B-DELETED',
        ]);

        $branch->delete();

        $response = $this
            ->actingAs($user)
            ->postJson("/api/branches/{$branch->id}/restore");

        $response->assertStatus(404);

        $this->assertSoftDeleted('branches', [
            'id' => $branch->id,
            'tenant_id' => $tenantB->id,
        ]);
    }

    public function test_authenticated_user_can_search_branches(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Accra Main Branch',
            'code' => 'ACC-MAIN',
        ]);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Kumasi Branch',
            'code' => 'KSI',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches?search=Accra');

        $response
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Accra Main Branch',
                'code' => 'ACC-MAIN',
            ])
            ->assertJsonMissing([
                'name' => 'Kumasi Branch',
            ]);
    }

    public function test_authenticated_user_can_filter_branches_by_active_status(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Active Branch',
            'code' => 'ACTIVE',
            'is_active' => true,
        ]);

        Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inactive Branch',
            'code' => 'INACTIVE',
            'is_active' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches?is_active=1');

        $response
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'name' => 'Active Branch',
                'is_active' => true,
            ])
            ->assertJsonMissing([
                'name' => 'Inactive Branch',
            ]);
    }

    public function test_branch_pagination_works(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        Branch::factory()->count(20)->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches?per_page=15');

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 15,
                    'total' => 20,
                ],
            ])
            ->assertJsonCount(15, 'data');
    }

    public function test_branch_list_rejects_invalid_per_page(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches?per_page=0');

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'per_page',
            ]);
    }

    public function test_branch_search_rejects_excessively_long_value(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assignRole($user);

        $search = str_repeat('a', 256);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/branches?search=' . urlencode($search));

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'search',
            ]);
    }
}