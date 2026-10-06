<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_list_pending_businesses(): void
    {
        $admin = $this->createPlatformAdmin();

        $pending = Tenant::create([
            'name' => 'Pending Business',
            'slug' => 'pending-business',
            'email' => 'pending@example.com',
            'phone' => '0240000050',
            'business_type' => 'Retail',
            'status' => TenantStatus::PENDING,
        ]);

        $response = $this
            ->actingAs($admin)
            ->getJson('/api/platform/businesses?status=pending');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_platform_admin_can_view_business(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Review Business',
            TenantStatus::PENDING
        );

        $response = $this
            ->actingAs($admin)
            ->getJson("/api/platform/businesses/{$tenant->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.name', 'Review Business')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_platform_admin_can_approve_business(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Approve Business',
            TenantStatus::PENDING
        );

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/platform/businesses/{$tenant->id}/approve");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'approved',
        ]);
    }

    public function test_platform_admin_can_reject_business_with_reason(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Reject Business',
            TenantStatus::PENDING
        );

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/platform/businesses/{$tenant->id}/reject", [
                'reason' => 'Business information could not be verified.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath(
                'data.rejection_reason',
                'Business information could not be verified.'
            );

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'rejected',
        ]);
    }

    public function test_platform_admin_can_suspend_approved_business_with_reason(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Suspend Business',
            TenantStatus::APPROVED
        );

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/platform/businesses/{$tenant->id}/suspend", [
                'reason' => 'Account requires compliance review.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath(
                'data.suspension_reason',
                'Account requires compliance review.'
            );

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'suspended',
        ]);
    }

    public function test_reject_requires_a_reason(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Validation Business',
            TenantStatus::PENDING
        );

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/platform/businesses/{$tenant->id}/reject", []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_suspend_requires_a_reason(): void
    {
        $admin = $this->createPlatformAdmin();

        $tenant = $this->createTenant(
            'Suspend Validation',
            TenantStatus::APPROVED
        );

        $response = $this
            ->actingAs($admin)
            ->postJson("/api/platform/businesses/{$tenant->id}/suspend", []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_normal_tenant_user_cannot_access_platform_businesses(): void
    {
        $tenant = $this->createTenant(
            'Normal Business',
            TenantStatus::APPROVED
        );

        $user = User::create([
            'name' => 'Normal User',
            'email' => 'normal@example.com',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/platform/businesses');

        $response->assertForbidden();
    }

    private function createPlatformAdmin(): User
    {
        return User::create([
            'name' => 'Platform Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'tenant_id' => null,
        ]);
    }

    private function createTenant(
        string $name,
        TenantStatus $status
    ): Tenant {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '0240000051',
            'business_type' => 'Retail',
            'status' => $status,
        ]);
    }
}